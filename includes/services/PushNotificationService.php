<?php
/**
 * Push Notification Service
 * 
 * Sends push notifications to customers via LINE and Facebook Messenger
 * 
 * Usage:
 *   $pushService = new PushNotificationService($db);
 *   $pushService->send($platform, $userId, $type, $data);
 * 
 * @version 1.0
 * @date 2026-01-07
 */

class PushNotificationService
{
    private $db;
    private $lineAccessTokens = [];
    private $facebookPageTokens = [];
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Send push notification
     * 
     * @param string $platform 'line' or 'facebook'
     * @param string $platformUserId LINE userId or FB PSID
     * @param string $notificationType Type of notification (from notification_templates)
     * @param array $data Variables to replace in template
     * @param int|null $channelId Optional channel ID for token lookup
     * @return array Result with success status
     */
    public function send(string $platform, string $platformUserId, string $notificationType, array $data, ?int $channelId = null): array
    {
        try {
            // Get template
            $template = $this->getTemplate($notificationType);
            if (!$template) {
                return ['success' => false, 'error' => 'Template not found: ' . $notificationType];
            }
            
            // Build message
            $message = $this->buildMessage($template, $platform, $data);
            
            // Log notification
            $notificationId = $this->logNotification($platform, $platformUserId, $notificationType, $message, $data, $channelId);
            
            // Send based on platform
            if ($platform === 'line') {
                $result = $this->sendLine($platformUserId, $message, $channelId);
            } elseif ($platform === 'facebook') {
                $result = $this->sendFacebook($platformUserId, $message, $channelId);
            } else {
                return ['success' => false, 'error' => 'Unsupported platform: ' . $platform];
            }
            
            // Update log
            $this->updateNotificationStatus($notificationId, $result);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("PushNotificationService Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Queue notification for later sending (async)
     */
    public function queue(string $platform, string $platformUserId, string $notificationType, array $data, ?int $channelId = null): int
    {
        $template = $this->getTemplate($notificationType);
        $message = $template ? $this->buildMessage($template, $platform, $data) : '';
        
        return $this->logNotification($platform, $platformUserId, $notificationType, $message, $data, $channelId, 'pending');
    }
    
    /**
     * Process pending notifications (called by cron/worker)
     */
    public function processPending(int $limit = 50): array
    {
        $pending = $this->db->queryAll(
            "SELECT * FROM push_notifications 
             WHERE status = 'pending' 
               AND (next_retry_at IS NULL OR next_retry_at <= NOW())
               AND retry_count < max_retries
             ORDER BY created_at ASC
             LIMIT ?",
            [$limit]
        );
        
        $results = ['sent' => 0, 'failed' => 0, 'errors' => []];
        
        foreach ($pending as $notification) {
            $result = $this->sendNotification($notification);
            
            if ($result['success']) {
                $results['sent']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'id' => $notification['id'],
                    'error' => $result['error'] ?? 'Unknown error'
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Send a single queued notification
     */
    private function sendNotification(array $notification): array
    {
        $platform = $notification['platform'];
        $platformUserId = $notification['platform_user_id'];
        $message = $notification['message'];
        $channelId = $notification['channel_id'];
        
        if ($platform === 'line') {
            $result = $this->sendLine($platformUserId, $message, $channelId);
        } elseif ($platform === 'facebook') {
            $result = $this->sendFacebook($platformUserId, $message, $channelId);
        } else {
            $result = ['success' => false, 'error' => 'Unsupported platform'];
        }
        
        $this->updateNotificationStatus($notification['id'], $result);
        
        return $result;
    }
    
    /**
     * Send LINE push message
     */
    private function sendLine(string $userId, string $message, ?int $channelId): array
    {
        $accessToken = $this->getLineAccessToken($channelId);
        if (!$accessToken) {
            return ['success' => false, 'error' => 'LINE access token not found'];
        }
        
        $url = 'https://api.line.me/v2/bot/message/push';
        
        $body = [
            'to' => $userId,
            'messages' => [
                ['type' => 'text', 'text' => $message]
            ]
        ];
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ];
        
        $result = $this->httpPost($url, $body, $headers);
        
        if ($result['http_code'] === 200) {
            return ['success' => true, 'response' => $result];
        } else {
            return [
                'success' => false, 
                'error' => 'LINE API error: ' . ($result['body']['message'] ?? 'Unknown'),
                'response' => $result
            ];
        }
    }
    
    /**
     * Send Facebook Messenger push message
     */
    private function sendFacebook(string $psid, string $message, ?int $channelId): array
    {
        $pageToken = $this->getFacebookPageToken($channelId);
        if (!$pageToken) {
            return ['success' => false, 'error' => 'Facebook page token not found'];
        }
        
        $url = 'https://graph.facebook.com/v18.0/me/messages?access_token=' . $pageToken;
        
        $body = [
            'recipient' => ['id' => $psid],
            'message' => ['text' => $message],
            'messaging_type' => 'MESSAGE_TAG',
            'tag' => 'CONFIRMED_EVENT_UPDATE' // For transactional messages outside 24h window
        ];
        
        $headers = [
            'Content-Type: application/json'
        ];
        
        $result = $this->httpPost($url, $body, $headers);
        
        if ($result['http_code'] === 200 && isset($result['body']['message_id'])) {
            return ['success' => true, 'message_id' => $result['body']['message_id'], 'response' => $result];
        } else {
            return [
                'success' => false,
                'error' => 'Facebook API error: ' . ($result['body']['error']['message'] ?? 'Unknown'),
                'response' => $result
            ];
        }
    }
    
    /**
     * Get LINE channel access token
     */
    private function getLineAccessToken(?int $channelId): ?string
    {
        if ($channelId && isset($this->lineAccessTokens[$channelId])) {
            return $this->lineAccessTokens[$channelId];
        }
        
        // Try to get from customer_services table
        if ($channelId) {
            $service = $this->db->queryOne(
                "SELECT config FROM customer_services WHERE id = ? AND platform = 'line'",
                [$channelId]
            );
            
            if ($service && $service['config']) {
                $config = json_decode($service['config'], true);
                $accessToken = $config['channel_access_token'] ?? $config['access_token'] ?? null;
                if ($accessToken) {
                    $this->lineAccessTokens[$channelId] = $accessToken;
                    return $accessToken;
                }
            }
        }
        
        // Fallback: get first active LINE service
        $service = $this->db->queryOne(
            "SELECT config FROM customer_services 
             WHERE platform = 'line' AND status = 'active' AND config IS NOT NULL
             LIMIT 1"
        );
        
        if ($service && $service['config']) {
            $config = json_decode($service['config'], true);
            $accessToken = $config['channel_access_token'] ?? $config['access_token'] ?? null;
            if ($accessToken) {
                return $accessToken;
            }
        }
        
        // Final fallback: environment variable
        $envToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
        return $envToken ?: null;
    }
    
    /**
     * Get Facebook page access token
     */
    private function getFacebookPageToken(?int $channelId): ?string
    {
        if ($channelId && isset($this->facebookPageTokens[$channelId])) {
            return $this->facebookPageTokens[$channelId];
        }
        
        // Try to get from customer_services table
        if ($channelId) {
            $service = $this->db->queryOne(
                "SELECT config FROM customer_services WHERE id = ? AND platform = 'facebook'",
                [$channelId]
            );
            
            if ($service && $service['config']) {
                $config = json_decode($service['config'], true);
                $accessToken = $config['page_access_token'] ?? $config['access_token'] ?? null;
                if ($accessToken) {
                    $this->facebookPageTokens[$channelId] = $accessToken;
                    return $accessToken;
                }
            }
        }
        
        // Fallback: get first active Facebook service
        $service = $this->db->queryOne(
            "SELECT config FROM customer_services 
             WHERE platform = 'facebook' AND status = 'active' AND config IS NOT NULL
             LIMIT 1"
        );
        
        if ($service && $service['config']) {
            $config = json_decode($service['config'], true);
            $accessToken = $config['page_access_token'] ?? $config['access_token'] ?? null;
            if ($accessToken) {
                return $accessToken;
            }
        }
        
        // Final fallback: environment variable
        $envToken = getenv('FACEBOOK_PAGE_ACCESS_TOKEN');
        return $envToken ?: null;
    }
    
    /**
     * Get notification template
     */
    private function getTemplate(string $templateKey): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM notification_templates WHERE template_key = ? AND is_active = 1",
            [$templateKey]
        );
    }
    
    /**
     * Build message from template
     */
    private function buildMessage(array $template, string $platform, array $data): string
    {
        $templateText = $platform === 'line' ? $template['line_template'] : $template['facebook_template'];
        
        if (!$templateText) {
            $templateText = $template['line_template'] ?? $template['facebook_template'] ?? '';
        }
        
        // Replace variables
        foreach ($data as $key => $value) {
            if (is_numeric($value) && strpos($key, 'date') === false && strpos($key, 'period') === false) {
                $value = number_format($value, 2);
            }
            $templateText = str_replace("{{{$key}}}", $value, $templateText);
        }
        
        return $templateText;
    }
    
    /**
     * Log notification to database
     */
    private function logNotification(string $platform, string $platformUserId, string $type, string $message, array $data, ?int $channelId, string $status = 'pending'): int
    {
        $this->db->execute(
            "INSERT INTO push_notifications (
                platform, platform_user_id, channel_id,
                notification_type, message, message_data,
                status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $platform,
                $platformUserId,
                $channelId,
                $type,
                $message,
                json_encode($data),
                $status
            ]
        );
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Update notification status
     */
    private function updateNotificationStatus(int $notificationId, array $result): void
    {
        if ($result['success']) {
            $this->db->execute(
                "UPDATE push_notifications 
                 SET status = 'sent', 
                     sent_at = NOW(), 
                     api_response = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [json_encode($result['response'] ?? null), $notificationId]
            );
        } else {
            $this->db->execute(
                "UPDATE push_notifications 
                 SET status = CASE WHEN retry_count + 1 >= max_retries THEN 'failed' ELSE status END,
                     error_message = ?,
                     retry_count = retry_count + 1,
                     next_retry_at = DATE_ADD(NOW(), INTERVAL POW(2, retry_count + 1) MINUTE),
                     api_response = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    $result['error'] ?? 'Unknown error',
                    json_encode($result['response'] ?? null),
                    $notificationId
                ]
            );
        }
    }
    
    /**
     * HTTP POST helper
     */
    private function httpPost(string $url, array $body, array $headers): array
    {
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        return [
            'http_code' => $httpCode,
            'body' => json_decode($response, true),
            'raw' => $response,
            'curl_error' => $error
        ];
    }
    
    /**
     * Send notification for payment verification
     */
    public function sendPaymentVerified(string $platform, string $platformUserId, array $paymentData, ?int $channelId = null): array
    {
        return $this->send($platform, $platformUserId, 'payment_verified', $paymentData, $channelId);
    }
    
    /**
     * Send notification for payment rejection
     */
    public function sendPaymentRejected(string $platform, string $platformUserId, array $paymentData, ?int $channelId = null): array
    {
        return $this->send($platform, $platformUserId, 'payment_rejected', $paymentData, $channelId);
    }
    
    /**
     * Send notification for installment payment verified
     */
    public function sendInstallmentPaymentVerified(string $platform, string $platformUserId, array $data, ?int $channelId = null): array
    {
        return $this->send($platform, $platformUserId, 'installment_payment_verified', $data, $channelId);
    }
    
    /**
     * Send notification for installment completed
     */
    public function sendInstallmentCompleted(string $platform, string $platformUserId, array $data, ?int $channelId = null): array
    {
        return $this->send($platform, $platformUserId, 'installment_completed', $data, $channelId);
    }
    
    /**
     * Send notification for savings deposit verified
     */
    public function sendSavingsDepositVerified(string $platform, string $platformUserId, array $data, ?int $channelId = null): array
    {
        return $this->send($platform, $platformUserId, 'savings_deposit_verified', $data, $channelId);
    }
    
    /**
     * Send notification for savings goal reached
     */
    public function sendSavingsGoalReached(string $platform, string $platformUserId, array $data, ?int $channelId = null): array
    {
        return $this->send($platform, $platformUserId, 'savings_goal_reached', $data, $channelId);
    }
}
