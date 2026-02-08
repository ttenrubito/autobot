<?php
/**
 * Slip Verification Service
 * 
 * Wrapper for calling external Slip Verify API (e.g., SlipOK, EasySlip)
 * Uses Google Vision or OCR to extract payment amount from slip images
 * 
 * TODO: User will provide the actual API credentials and endpoint
 * 
 * @date 2026-02-08
 */

require_once __DIR__ . '/Logger.php';

class SlipVerificationService
{
    private static ?SlipVerificationService $instance = null;

    // API Configuration (to be set from config)
    private string $apiEndpoint = '';
    private string $apiKey = '';
    private bool $enabled = false;

    /**
     * Private constructor (Singleton)
     */
    private function __construct()
    {
        // Load configuration
        $configPath = __DIR__ . '/../config-slip-verify.php';
        if (file_exists($configPath)) {
            require_once $configPath;

            $this->apiEndpoint = defined('SLIP_VERIFY_API_ENDPOINT') ? SLIP_VERIFY_API_ENDPOINT : '';
            $this->apiKey = defined('SLIP_VERIFY_API_KEY') ? SLIP_VERIFY_API_KEY : '';
            $this->enabled = defined('SLIP_VERIFY_ENABLED') ? SLIP_VERIFY_ENABLED : false;
        }

        Logger::info('[SlipVerify] Service initialized', [
            'enabled' => $this->enabled,
            'has_endpoint' => !empty($this->apiEndpoint),
            'has_key' => !empty($this->apiKey)
        ]);
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): SlipVerificationService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if service is enabled and configured
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiEndpoint) && !empty($this->apiKey);
    }

    /**
     * Verify a slip image and extract payment information
     * 
     * @param string $imageUrl URL to the slip image (GCS signed URL)
     * @param string|null $imagePath Local file path (alternative to URL)
     * @param string|null $imageBase64 Base64 encoded image (alternative)
     * 
     * @return array [
     *   'success' => bool,
     *   'verified' => bool,         // true if slip is valid
     *   'amount' => float|null,     // extracted amount in THB
     *   'ref_no' => string|null,    // transaction reference number
     *   'date' => string|null,      // transaction date (Y-m-d H:i:s)
     *   'sender_name' => string|null,
     *   'sender_bank' => string|null,
     *   'receiver_name' => string|null,
     *   'receiver_bank' => string|null,
     *   'receiver_account' => string|null,
     *   'raw_response' => array,    // original API response
     *   'error' => string|null
     * ]
     */
    public function verifySlip(
        ?string $imageUrl = null,
        ?string $imagePath = null,
        ?string $imageBase64 = null
    ): array {
        // Return mock if not enabled
        if (!$this->isEnabled()) {
            Logger::info('[SlipVerify] Service not enabled, returning unverified');
            return [
                'success' => true,
                'verified' => false,
                'amount' => null,
                'ref_no' => null,
                'date' => null,
                'sender_name' => null,
                'sender_bank' => null,
                'receiver_name' => null,
                'receiver_bank' => null,
                'receiver_account' => null,
                'raw_response' => [],
                'error' => 'Slip verification service not configured'
            ];
        }

        try {
            // Prepare request based on available input
            $requestData = [];

            if (!empty($imageUrl)) {
                $requestData['url'] = $imageUrl;
            } elseif (!empty($imagePath) && file_exists($imagePath)) {
                $imageContent = file_get_contents($imagePath);
                $requestData['data'] = base64_encode($imageContent);
            } elseif (!empty($imageBase64)) {
                $requestData['data'] = $imageBase64;
            } else {
                return [
                    'success' => false,
                    'verified' => false,
                    'amount' => null,
                    'error' => 'No image provided'
                ];
            }

            Logger::info('[SlipVerify] Calling API', [
                'endpoint' => $this->apiEndpoint,
                'has_url' => !empty($requestData['url'] ?? ''),
                'has_data' => !empty($requestData['data'] ?? '')
            ]);

            // Call the API
            $ch = curl_init($this->apiEndpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($requestData),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                    'x-authorization: ' . $this->apiKey,  // Alternative header format
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                Logger::error('[SlipVerify] cURL error', ['error' => $error]);
                return [
                    'success' => false,
                    'verified' => false,
                    'amount' => null,
                    'error' => 'API connection error: ' . $error
                ];
            }

            if ($httpCode !== 200) {
                Logger::warning('[SlipVerify] API error', [
                    'http_code' => $httpCode,
                    'response' => substr($response, 0, 500)
                ]);
                return [
                    'success' => false,
                    'verified' => false,
                    'amount' => null,
                    'error' => 'API returned status ' . $httpCode
                ];
            }

            $data = json_decode($response, true);

            if (!$data) {
                return [
                    'success' => false,
                    'verified' => false,
                    'amount' => null,
                    'error' => 'Invalid API response'
                ];
            }

            // Parse response - adapt this based on actual API response format
            // Common formats: SlipOK, EasySlip, custom
            $result = $this->parseApiResponse($data);

            Logger::info('[SlipVerify] Verification complete', [
                'verified' => $result['verified'],
                'amount' => $result['amount'],
                'ref_no' => $result['ref_no'] ?? null
            ]);

            return $result;

        } catch (Exception $e) {
            Logger::error('[SlipVerify] Exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'verified' => false,
                'amount' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Parse API response into standard format
     * 
     * TODO: Adjust this based on the actual API used
     * 
     * @param array $data Raw API response
     * @return array Standardized result
     */
    private function parseApiResponse(array $data): array
    {
        // Template for common API formats
        // SlipOK format example:
        // {
        //   "success": true,
        //   "data": {
        //     "amount": 2500.00,
        //     "transRef": "202602081234567890",
        //     "transDate": "2026-02-08",
        //     "transTime": "14:30:00",
        //     "sender": { "name": "นาย ก", "bank": { "name": "SCB" } },
        //     "receiver": { "name": "บริษัท X", "bank": { "name": "KBANK" }, "account": "123-456-7890" }
        //   }
        // }

        $isSuccess = $data['success'] ?? $data['status'] ?? false;
        $slipData = $data['data'] ?? $data['result'] ?? $data;

        return [
            'success' => true,
            'verified' => (bool) $isSuccess,
            'amount' => isset($slipData['amount']) ? floatval($slipData['amount']) : null,
            'ref_no' => $slipData['transRef'] ?? $slipData['ref'] ?? $slipData['reference'] ?? null,
            'date' => $this->formatDate($slipData['transDate'] ?? null, $slipData['transTime'] ?? null),
            'sender_name' => $slipData['sender']['name'] ?? $slipData['senderName'] ?? null,
            'sender_bank' => $slipData['sender']['bank']['name'] ?? $slipData['senderBank'] ?? null,
            'receiver_name' => $slipData['receiver']['name'] ?? $slipData['receiverName'] ?? null,
            'receiver_bank' => $slipData['receiver']['bank']['name'] ?? $slipData['receiverBank'] ?? null,
            'receiver_account' => $slipData['receiver']['account'] ?? $slipData['receiverAccount'] ?? null,
            'raw_response' => $data,
            'error' => $isSuccess ? null : ($data['message'] ?? 'Verification failed')
        ];
    }

    /**
     * Format date from API response
     */
    private function formatDate(?string $date, ?string $time): ?string
    {
        if (empty($date)) {
            return null;
        }

        $datetime = $date;
        if (!empty($time)) {
            $datetime .= ' ' . $time;
        }

        try {
            $dt = new DateTime($datetime);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return $datetime;
        }
    }
}
