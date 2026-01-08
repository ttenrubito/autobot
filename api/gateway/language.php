<?php
/**
 * API Gateway - Google Natural Language API Proxy
 * POST /api/gateway/language/{feature}
 * 
 * Features: sentiment, entities, syntax
 * 
 * Requires header: X-API-Key
 */

require_once '../../includes/Database.php';
require_once '../../includes/Response.php';
require_once '../../includes/Logger.php';
require_once '../../includes/CORS.php';
require_once '../../config-cloud.php';

CORS::setCORSHeaders();
CORS::handlePreflightRequest();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$startTime = microtime(true);

try {
    $headers = getallheaders();
    $apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? '';
    
    if (empty($apiKey)) {
        Logger::warning('API Gateway - Missing API key', ['endpoint' => 'language']);
        Response::error('API key is required', 401);
    }
    
    $db = Database::getInstance();
    
    $keyData = $db->queryOne(
        "SELECT ak.user_id, ak.is_active, u.status as user_status
         FROM api_keys ak
         JOIN users u ON ak.user_id = u.id
         WHERE ak.api_key = ? AND ak.is_active = TRUE
         AND (ak.expires_at IS NULL OR ak.expires_at > NOW())
         LIMIT 1",
        [$apiKey]
    );
    
    if (!$keyData) {
        Logger::warning('API Gateway - Invalid API key', ['endpoint' => 'language']);
        Response::error('Invalid or expired API key', 401);
    }
    
    if ($keyData['user_status'] !== 'active') {
        Logger::warning('API Gateway - Inactive user', ['user_id' => $keyData['user_id']]);
        Response::error('User account is not active', 403);
    }
    
    $userId = $keyData['user_id'];
    
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    $feature = end($pathParts);
    
    $serviceCodeMap = [
        'sentiment' => 'google_nl_sentiment',
        'entities' => 'google_nl_entities',
        'syntax' => 'google_nl_syntax'
    ];
    
    $serviceCode = $serviceCodeMap[$feature] ?? null;
    
    if (!$serviceCode) {
        Logger::warning('API Gateway - Invalid feature', ['feature' => $feature]);
        Response::error('Invalid feature. Use: sentiment, entities, or syntax', 400);
    }
    
    $serviceConfig = $db->queryOne(
        "SELECT * FROM api_service_config WHERE service_code = ? LIMIT 1",
        [$serviceCode]
    );
    
    if (!$serviceConfig || !$serviceConfig['is_enabled']) {
        Logger::info('API Gateway - Service unavailable', ['service' => $serviceCode]);
        Response::error('This service is currently unavailable', 503);
    }
    
    $userAccess = $db->queryOne(
        "SELECT * FROM customer_api_access 
         WHERE user_id = ? AND service_code = ? 
         LIMIT 1",
        [$userId, $serviceCode]
    );
    
    if (!$userAccess || !$userAccess['is_enabled']) {
        Logger::warning('API Gateway - Access denied', ['user_id' => $userId, 'service' => $serviceCode]);
        Response::error('You do not have access to this service', 403);
    }
    
    $todayUsage = $db->queryOne(
        "SELECT COALESCE(SUM(request_count), 0) as total
         FROM api_usage_logs
         WHERE customer_service_id IN (
             SELECT id FROM customer_services WHERE user_id = ?
         )
         AND api_type = ?
         AND DATE(created_at) = CURDATE()",
        [$userId, $serviceCode]
    );
    
    $dailyUsage = (int)($todayUsage['total'] ?? 0);
    
    if ($userAccess['daily_limit'] && $dailyUsage >= $userAccess['daily_limit']) {
        Logger::warning('API Gateway - Rate limit exceeded', ['user_id' => $userId, 'service' => $serviceCode]);
        Response::error('Daily rate limit exceeded', 429);
    }
    
    $requestBody = file_get_contents('php://input');
    $requestData = json_decode($requestBody, true);
    
    if (!$requestData || !isset($requestData['text'])) {
        Logger::warning('API Gateway - Invalid request body', ['user_id' => $userId]);
        Response::error('Text data is required in request body', 400);
    }
    
    // Validate text length
    $textLength = strlen($requestData['text']);
    $maxLength = 100000; // 100KB
    if ($textLength > $maxLength) {
        Logger::warning('API Gateway - Text too long', ['length' => $textLength]);
        Response::error('Text exceeds maximum length', 413);
    }
    
    $googleApiKey = defined('GOOGLE_LANGUAGE_API_KEY') ? GOOGLE_LANGUAGE_API_KEY : '';
    if (empty($googleApiKey)) {
        Logger::error('API Gateway - Google API key not configured');
        Response::error('API configuration error', 500);
    }
    
    $nlRequest = [
        'document' => [
            'type' => 'PLAIN_TEXT',
            'content' => $requestData['text']
        ],
        'encodingType' => 'UTF8'
    ];
    
    $ch = curl_init();
    $timeout = defined('API_REQUEST_TIMEOUT') ? API_REQUEST_TIMEOUT : 30;
    
    curl_setopt($ch, CURLOPT_URL, $serviceConfig['google_api_endpoint'] . '?key=' . $googleApiKey);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($nlRequest));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $apiStartTime = microtime(true);
    $response = curl_exec($ch);
    $apiDuration = microtime(true) - $apiStartTime;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        Logger::error('API Gateway - Google API call failed', ['error' => $curlError, 'service' => $serviceCode]);
        Response::error('External API call failed', 502);
    }
    
    $customerService = $db->queryOne(
        "SELECT id FROM customer_services WHERE user_id = ? LIMIT 1",
        [$userId]
    );
    
    if ($customerService) {
        $db->execute(
            "INSERT INTO api_usage_logs (customer_service_id, api_type, endpoint, request_count, response_time, status_code, cost, created_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, NOW())",
            [
                $customerService['id'],
                $serviceCode,
                $feature,
                round($apiDuration * 1000, 2),
                $httpCode,
                $serviceConfig['cost_per_request']
            ]
        );
    }
    
    $db->execute("UPDATE api_keys SET last_used_at = NOW() WHERE api_key = ?", [$apiKey]);
    
    $totalDuration = microtime(true) - $startTime;
    Logger::info('API Gateway - Request completed', [
        'user_id' => $userId,
        'service' => $serviceCode,
        'feature' => $feature,
        'duration_ms' => round($totalDuration * 1000, 2),
        'api_duration_ms' => round($apiDuration * 1000, 2),
        'status_code' => $httpCode
    ]);
    
    http_response_code($httpCode);
    echo $response;
    
} catch (Exception $e) {
    Logger::error('API Gateway - Exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    Response::error('Gateway error', 500);
}
