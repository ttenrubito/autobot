<?php
/**
 * BackendApiService - Central API calling service
 * 
 * Handles all HTTP API calls with:
 * - Retry logic
 * - Timeout handling
 * - Logging
 * - Error handling
 * 
 * @version 1.0
 * @date 2026-01-23
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../Logger.php';

class BackendApiService
{
    protected $db;
    protected $defaultTimeout = 8;
    protected $defaultConnectTimeout = 3;
    protected $maxRetries = 2;

    public function __construct()
    {
        $this->db = \Database::getInstance();
    }

    /**
     * Call backend API endpoint
     * 
     * @param array $config Bot config containing backend_api settings
     * @param string $endpointKey Endpoint key from config or direct path
     * @param array $payload Request payload
     * @param array $context Chat context for logging
     * @return array ['ok' => bool, 'data' => array, 'error' => string, 'status' => int]
     */
    public function call(array $config, string $endpointKey, array $payload, array $context): array
    {
        $backendCfg = $config['backend_api'] ?? [];
        
        if (empty($backendCfg['enabled'])) {
            return ['ok' => false, 'error' => 'backend_disabled', 'status' => 0];
        }

        // Resolve Endpoint URL
        $url = $this->resolveEndpointUrl($backendCfg, $endpointKey);
        if (!$url) {
            return ['ok' => false, 'error' => 'endpoint_not_found', 'status' => 0];
        }

        // Build headers
        $headers = $this->buildHeaders($backendCfg);

        // Execute with retry
        $startTime = microtime(true);
        $result = $this->executeWithRetry($url, $payload, $headers, $backendCfg);
        $duration = (int)((microtime(true) - $startTime) * 1000);

        // Log usage
        $this->logUsage($context, $endpointKey, $result['status'], $duration);

        // Log errors
        if (!$result['ok']) {
            \Logger::error("[BackendApiService] API call failed", [
                'url' => $url,
                'status' => $result['status'],
                'error' => $result['error'] ?? 'unknown'
            ]);
        }

        return $result;
    }

    /**
     * Call multiple endpoints (for parallel calls in future)
     */
    public function callMultiple(array $config, array $calls, array $context): array
    {
        $results = [];
        foreach ($calls as $key => $call) {
            $results[$key] = $this->call(
                $config,
                $call['endpoint'],
                $call['payload'] ?? [],
                $context
            );
        }
        return $results;
    }

    /**
     * Resolve endpoint URL from config
     */
    protected function resolveEndpointUrl(array $backendCfg, string $endpointKey): ?string
    {
        $endpoints = $backendCfg['endpoints'] ?? [];
        $endpointPath = $endpoints[$endpointKey] ?? $endpointKey;
        
        if (!$endpointPath) {
            return null;
        }

        // If already a full URL, return as-is
        if (preg_match('~^https?://~i', $endpointPath)) {
            return $endpointPath;
        }

        // Prepend base URL
        $baseUrl = rtrim($backendCfg['base_url'] ?? '', '/');
        return $baseUrl . '/' . ltrim($endpointPath, '/');
    }

    /**
     * Build HTTP headers
     */
    protected function buildHeaders(array $backendCfg): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        // API Key auth
        if (!empty($backendCfg['api_key'])) {
            $headers[] = 'Authorization: Bearer ' . $backendCfg['api_key'];
        }

        // Custom headers from config
        if (!empty($backendCfg['headers']) && is_array($backendCfg['headers'])) {
            foreach ($backendCfg['headers'] as $name => $value) {
                $headers[] = "{$name}: {$value}";
            }
        }

        return $headers;
    }

    /**
     * Execute HTTP request with retry logic
     */
    protected function executeWithRetry(string $url, array $payload, array $headers, array $backendCfg): array
    {
        $timeout = (int)($backendCfg['timeout_seconds'] ?? $this->defaultTimeout);
        $connectTimeout = (int)($backendCfg['connect_timeout'] ?? $this->defaultConnectTimeout);

        $lastError = null;
        $lastStatus = 0;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            // Backoff on retry
            if ($attempt > 0) {
                usleep(500 * 1000 * $attempt); // 500ms, 1s, etc.
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2, // ✅ Verify hostname matches certificate
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3
            ]);

            $raw = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            $lastStatus = $status;

            // cURL error
            if ($curlErrno) {
                $lastError = $curlError;
                \Logger::warning("[BackendApiService] cURL error (attempt {$attempt})", [
                    'url' => $url,
                    'errno' => $curlErrno,
                    'error' => $curlError
                ]);
                continue;
            }

            // Success
            if ($status >= 200 && $status < 300) {
                $data = json_decode($raw, true);
                
                // ✅ Check for JSON decode errors (protect against HTML error pages, etc.)
                if (json_last_error() !== JSON_ERROR_NONE) {
                    \Logger::warning("[BackendApiService] Invalid JSON response", [
                        'url' => $url,
                        'status' => $status,
                        'json_error' => json_last_error_msg(),
                        'raw_preview' => substr($raw, 0, 200)
                    ]);
                    return [
                        'ok' => false,
                        'error' => 'invalid_json_response',
                        'status' => $status,
                        'raw' => $raw
                    ];
                }
                
                // Handle different API response formats
                $isOk = $data['ok'] ?? $data['success'] ?? true;
                $responseData = $data['data'] ?? $data;
                
                return [
                    'ok' => (bool)$isOk,
                    'data' => $responseData,
                    'status' => $status,
                    'raw' => $raw
                ];
            }

            // Non-retryable error (4xx except 429)
            if ($status >= 400 && $status < 500 && $status !== 429) {
                $data = json_decode($raw, true);
                return [
                    'ok' => false,
                    'error' => $data['message'] ?? $data['error'] ?? "http_{$status}",
                    'status' => $status,
                    'data' => $data
                ];
            }

            // Retryable error (5xx, 429)
            $lastError = "http_{$status}";
            \Logger::warning("[BackendApiService] Retryable error (attempt {$attempt})", [
                'url' => $url,
                'status' => $status
            ]);
        }

        return [
            'ok' => false,
            'error' => $lastError ?? 'max_retries_exceeded',
            'status' => $lastStatus
        ];
    }

    /**
     * Log API usage for analytics
     */
    protected function logUsage(array $context, string $endpoint, int $status, int $duration): void
    {
        try {
            // ✅ Use customer_service_id (matches actual schema)
            $customerServiceId = $context['channel']['customer_service_id'] 
                ?? $context['customer_service_id'] 
                ?? null;
            
            if (!$customerServiceId) {
                return;
            }

            $endpointTrunc = substr($endpoint, 0, 255);
            
            $this->db->execute(
                "INSERT INTO api_usage_logs 
                 (customer_service_id, api_type, endpoint, request_count, response_time, status_code, created_at) 
                 VALUES (?, 'backend', ?, 1, ?, ?, NOW())",
                [$customerServiceId, $endpointTrunc, $duration, $status]
            );
        } catch (\Exception $e) {
            // Silently ignore logging errors
            \Logger::warning("[BackendApiService] Failed to log usage", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Health check for backend API
     */
    public function healthCheck(array $config): array
    {
        $backendCfg = $config['backend_api'] ?? [];
        
        if (empty($backendCfg['enabled'])) {
            return ['ok' => false, 'error' => 'backend_disabled'];
        }

        $healthEndpoint = $backendCfg['endpoints']['health'] ?? '/health';
        $url = $this->resolveEndpointUrl($backendCfg, $healthEndpoint);
        
        if (!$url) {
            return ['ok' => false, 'error' => 'no_health_endpoint'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2
        ]);
        
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status
        ];
    }
}
