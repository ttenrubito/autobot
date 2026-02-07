<?php
/**
 * Push Message Service
 * 
 * ส่ง push message ไปหาลูกค้าผ่าน LINE หรือ Facebook
 * 
 * @usage:
 *   $pushService = new PushMessageService($pdo);
 *   $result = $pushService->send('line', 'U1234...', 'สวัสดีครับ', $channelId);
 * 
 * @created 2026-01-16
 */

namespace App\Services;

use PDO;
use Exception;

class PushMessageService {
    private $pdo;
    private $logger;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Send push message to customer via LINE or Facebook
     * 
     * @param string $platform 'line' or 'facebook'
     * @param string $externalUserId User ID on the platform (LINE user ID or Facebook PSID)
     * @param string $message Message text to send
     * @param int $channelId Channel ID to get config from
     * @return array ['success' => bool, 'error' => string|null, 'response' => mixed]
     */
    public function send(string $platform, string $externalUserId, string $message, int $channelId): array {
        // Validate inputs
        if (empty($externalUserId)) {
            return ['success' => false, 'error' => 'Missing external_user_id'];
        }
        
        if (empty($message)) {
            return ['success' => false, 'error' => 'Empty message'];
        }
        
        // Get channel config
        $config = $this->getChannelConfig($channelId);
        if (!$config) {
            return ['success' => false, 'error' => 'Channel config not found'];
        }
        
        // Send based on platform
        $platform = strtolower($platform);
        if ($platform === 'line') {
            return $this->sendLine($externalUserId, $message, $config);
        } elseif ($platform === 'facebook' || $platform === 'fb') {
            return $this->sendFacebook($externalUserId, $message, $config);
        }
        
        return ['success' => false, 'error' => 'Unknown platform: ' . $platform];
    }
    
    /**
     * Send message via LINE Push API
     */
    private function sendLine(string $userId, string $message, array $config): array {
        $accessToken = $config['line_channel_access_token'] ?? null;
        
        if (!$accessToken) {
            $this->log('error', 'LINE Push failed: Missing access token', ['user_id' => $userId]);
            return ['success' => false, 'error' => 'Missing LINE channel access token'];
        }
        
        $payload = [
            'to' => $userId,
            'messages' => [
                ['type' => 'text', 'text' => $message]
            ]
        ];
        
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $this->log('error', 'LINE Push curl error', ['error' => $curlError, 'user_id' => $userId]);
            return ['success' => false, 'error' => 'Curl error: ' . $curlError];
        }
        
        $success = $httpCode === 200;
        
        $this->log(
            $success ? 'info' : 'error',
            $success ? 'LINE Push sent' : 'LINE Push failed',
            [
                'user_id' => $userId,
                'http_code' => $httpCode,
                'response' => $response
            ]
        );
        
        return [
            'success' => $success,
            'http_code' => $httpCode,
            'response' => $response,
            'error' => $success ? null : 'HTTP ' . $httpCode
        ];
    }
    
    /**
     * Send message via Facebook Messenger Send API
     */
    private function sendFacebook(string $psid, string $message, array $config): array {
        // Support both key names: 'facebook_page_access_token' and 'page_access_token'
        $pageToken = $config['facebook_page_access_token'] ?? $config['page_access_token'] ?? null;
        
        if (!$pageToken) {
            $this->log('error', 'FB Push failed: Missing page token', ['psid' => $psid]);
            return ['success' => false, 'error' => 'Missing Facebook page access token'];
        }
        
        $payload = [
            'recipient' => ['id' => $psid],
            'message' => ['text' => $message],
            'messaging_type' => 'MESSAGE_TAG',
            'tag' => 'CONFIRMED_EVENT_UPDATE' // Required for messages outside 24hr window
        ];
        
        $url = 'https://graph.facebook.com/v18.0/me/messages?access_token=' . $pageToken;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $this->log('error', 'FB Push curl error', ['error' => $curlError, 'psid' => $psid]);
            return ['success' => false, 'error' => 'Curl error: ' . $curlError];
        }
        
        $success = $httpCode === 200;
        
        $this->log(
            $success ? 'info' : 'error',
            $success ? 'FB Push sent' : 'FB Push failed',
            [
                'psid' => $psid,
                'http_code' => $httpCode,
                'response' => $response
            ]
        );
        
        return [
            'success' => $success,
            'http_code' => $httpCode,
            'response' => $response,
            'error' => $success ? null : 'HTTP ' . $httpCode
        ];
    }
    
    /**
     * Get channel configuration from database
     */
    private function getChannelConfig(int $channelId): ?array {
        try {
            $stmt = $this->pdo->prepare("SELECT config FROM customer_channels WHERE id = ?");
            $stmt->execute([$channelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row || empty($row['config'])) {
                return null;
            }
            
            return json_decode($row['config'], true) ?: null;
        } catch (Exception $e) {
            $this->log('error', 'Failed to get channel config', [
                'channel_id' => $channelId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Replace placeholders in message template
     * 
     * Supported placeholders:
     * - {customer_name} - ชื่อลูกค้า
     * - {amount} - ยอดเงิน (formatted)
     * - {order_number} - เลข order
     * - {product_name} - ชื่อสินค้า
     * - {bank_name} - ชื่อธนาคาร
     * - {account_name} - ชื่อบัญชี
     * - {account_number} - เลขบัญชี
     * 
     * @param string $template Message template with placeholders
     * @param array $data Data to replace placeholders
     * @return string Message with placeholders replaced
     */
    public function replacePlaceholders(string $template, array $data): string {
        $replacements = [
            '{customer_name}' => $data['customer_name'] ?? 'ลูกค้า',
            '{amount}' => isset($data['amount']) ? number_format($data['amount']) : '0',
            '{order_number}' => $data['order_number'] ?? '-',
            '{product_name}' => $data['product_name'] ?? '-',
            '{bank_name}' => $data['bank_name'] ?? '-',
            '{account_name}' => $data['account_name'] ?? '-',
            '{account_number}' => $data['account_number'] ?? '-',
            '{due_date}' => $data['due_date'] ?? '-',
            '{installment_number}' => $data['installment_number'] ?? '-',
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
    
    /**
     * Log message
     */
    private function log(string $level, string $message, array $context = []): void {
        $logMessage = "[PushMessageService] [{$level}] {$message}";
        if (!empty($context)) {
            $logMessage .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        error_log($logMessage);
    }
}
