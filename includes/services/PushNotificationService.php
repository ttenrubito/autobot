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

        // Try to get from customer_channels table
        if ($channelId) {
            $service = $this->db->queryOne(
                "SELECT config FROM customer_channels WHERE id = ? AND type = 'line'",
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

        // Fallback: get first active LINE channel
        $service = $this->db->queryOne(
            "SELECT config FROM customer_channels 
             WHERE type = 'line' AND status = 'active' AND config IS NOT NULL
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
        error_log("[PushNotificationService] getFacebookPageToken called with channelId=" . ($channelId ?? 'NULL'));

        if ($channelId && isset($this->facebookPageTokens[$channelId])) {
            return $this->facebookPageTokens[$channelId];
        }

        // Try to get from customer_channels table
        if ($channelId) {
            $service = $this->db->queryOne(
                "SELECT config FROM customer_channels WHERE id = ? AND type = 'facebook'",
                [$channelId]
            );

            error_log("[PushNotificationService] FB Channel {$channelId} config: " . ($service ? substr($service['config'] ?? 'NULL', 0, 100) : 'NOT_FOUND'));

            if ($service && $service['config']) {
                $config = json_decode($service['config'], true);
                $accessToken = $config['page_access_token'] ?? $config['access_token'] ?? null;
                if ($accessToken) {
                    $this->facebookPageTokens[$channelId] = $accessToken;
                    return $accessToken;
                }
            }
        }

        // Fallback: get first active Facebook channel
        $service = $this->db->queryOne(
            "SELECT config FROM customer_channels 
             WHERE type = 'facebook' AND status = 'active' AND config IS NOT NULL
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
        $template = $this->db->queryOne(
            "SELECT * FROM notification_templates WHERE template_key = ? AND is_active = 1",
            [$templateKey]
        );

        // ✅ DEBUG: Log template lookup
        if ($template) {
            error_log("[PushNotificationService] getTemplate: Found '{$templateKey}', line_template length=" . strlen($template['line_template'] ?? ''));
        } else {
            error_log("[PushNotificationService] getTemplate: Template '{$templateKey}' NOT FOUND in database!");
        }

        return $template;
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

        // Replace variables - ✅ FIX: Use {{key}} format (2 brackets, not 3)
        foreach ($data as $key => $value) {
            // Don't re-format if already formatted or if it's a date/string field
            if (is_numeric($value) && strpos($key, 'date') === false && strpos($key, 'period') === false && strpos($key, '_due') === false && strpos($key, 'expiry') === false) {
                // Only format raw numbers, not already formatted ones
                if (strpos((string) $value, ',') === false) {
                    $value = number_format((float) $value, 0);
                }
            }
            // ✅ FIX: Template uses {{key}}, not {{{key}}}
            $templateText = str_replace("{{" . $key . "}}", (string) $value, $templateText);
        }

        // ✅ Clean up any unreplaced variables - replace with empty or placeholder
        $templateText = preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $templateText);

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

    /**
     * Send notification for order created
     * Automatically selects template based on order type
     * 
     * @param string $platform 'line' or 'facebook'
     * @param string $platformUserId User ID on the platform
     * @param string $orderType 'full_payment', 'installment', 'savings', 'deposit'
     * @param array $orderData Order details including:
     *   - product_name, total_amount, order_number
     *   - For installment: period_1_amount, period_1_due, period_2_amount, period_3_amount, total_periods
     *   - For savings: target_amount, current_balance
     * @param int|null $channelId Channel ID for token lookup
     * @return array Result with success status
     */
    public function sendOrderCreated(string $platform, string $platformUserId, string $orderType, array $orderData, ?int $channelId = null): array
    {
        // Map order type to template key
        $templateMap = [
            'full_payment' => 'order_created_full',
            'full' => 'order_created_full',
            'installment' => 'order_created_installment',
            'savings' => 'order_created_savings',
            'savings_completion' => 'order_created_savings',
            'deposit' => 'order_created_deposit', // Use dedicated deposit template
        ];

        $templateKey = $templateMap[$orderType] ?? 'order_created_full';

        // ✅ DEBUG: Log template selection
        error_log("[PushNotificationService] sendOrderCreated: orderType={$orderType}, templateKey={$templateKey}, platform={$platform}");

        return $this->send($platform, $platformUserId, $templateKey, $orderData, $channelId);
    }

    /**
     * Send direct text message (no template)
     * Use this for custom messages that don't fit templates
     */
    public function sendDirectMessage(string $platform, string $platformUserId, string $message, ?int $channelId = null): array
    {
        try {
            // Log notification
            $notificationId = $this->logNotification($platform, $platformUserId, 'direct_message', $message, ['raw_message' => $message], $channelId);

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
            error_log("PushNotificationService Direct Message Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== PAWN NOTIFICATIONS ====================

    /**
     * Send notification for new pawn created
     * 
     * @param string $platform 'line' or 'facebook'
     * @param string $platformUserId User ID on the platform
     * @param array $pawnData Pawn details:
     *   - pawn_no, item_name, loan_amount, interest_rate, monthly_interest, due_date
     * @param int|null $channelId Channel ID for token lookup
     * @return array Result with success status
     */
    public function sendPawnCreated(string $platform, string $platformUserId, array $pawnData, ?int $channelId = null): array
    {
        // Build message directly since we may not have template yet
        $pawnNo = $pawnData['pawn_no'] ?? '-';
        $itemName = $pawnData['item_name'] ?? 'สินค้า';
        $loanAmount = number_format((float) ($pawnData['loan_amount'] ?? 0), 0);
        $interestRate = $pawnData['interest_rate'] ?? 2;
        $monthlyInterest = number_format((float) ($pawnData['monthly_interest'] ?? 0), 0);
        $dueDate = $pawnData['due_date'] ?? '-';

        $message = "✅ บันทึกจำนำเรียบร้อย\n\n";
        $message .= "🏷️ รหัส: {$pawnNo}\n";
        $message .= "📦 สินค้า: {$itemName}\n";
        $message .= "💰 เงินต้น: ฿{$loanAmount}\n";
        $message .= "📈 ดอกเบี้ย: {$interestRate}% (฿{$monthlyInterest}/เดือน)\n";
        $message .= "📅 ครบกำหนด: {$dueDate}\n\n";
        $message .= "💳 ช่องทางชำระ:\n";
        $message .= "กรุณาติดต่อร้านเพื่อชำระดอกเบี้ย";

        return $this->sendDirectMessage($platform, $platformUserId, $message, $channelId);
    }

    /**
     * Send notification for pawn interest payment verified
     */
    public function sendPawnInterestVerified(string $platform, string $platformUserId, array $data, ?int $channelId = null): array
    {
        $pawnNo = $data['pawn_no'] ?? '-';
        $amount = number_format((float) ($data['amount'] ?? 0), 0);
        $months = $data['months'] ?? 1;
        $newDueDate = $data['new_due_date'] ?? '-';

        $message = "✅ ต่อดอกสำเร็จ\n\n";
        $message .= "🏷️ รหัส: {$pawnNo}\n";
        $message .= "💰 ยอดชำระ: ฿{$amount}\n";
        $message .= "🔄 ต่อดอก: {$months} เดือน\n";
        $message .= "📅 ครบกำหนดใหม่: {$newDueDate}\n\n";
        $message .= "ขอบคุณที่ใช้บริการครับ 🙏";

        return $this->sendDirectMessage($platform, $platformUserId, $message, $channelId);
    }

    /**
     * Send notification for pawn redemption verified
     */
    public function sendPawnRedemptionVerified(string $platform, string $platformUserId, array $data, ?int $channelId = null): array
    {
        $pawnNo = $data['pawn_no'] ?? '-';
        $itemName = $data['item_name'] ?? 'สินค้า';
        $principal = number_format((float) ($data['principal'] ?? 0), 0);
        $interest = number_format((float) ($data['interest'] ?? 0), 0);
        $total = number_format((float) ($data['total'] ?? 0), 0);

        $message = "🎉 ไถ่ถอนสำเร็จ!\n\n";
        $message .= "🏷️ รหัส: {$pawnNo}\n";
        $message .= "📦 สินค้า: {$itemName}\n";
        $message .= "💰 เงินต้น: ฿{$principal}\n";
        $message .= "💸 ดอกเบี้ย: ฿{$interest}\n";
        $message .= "📊 รวม: ฿{$total}\n\n";
        $message .= "กรุณามารับสินค้าได้ที่ร้านครับ 🏪";

        return $this->sendDirectMessage($platform, $platformUserId, $message, $channelId);
    }

    /**
     * Send notification for pawn due date reminder
     */
    public function sendPawnDueReminder(string $platform, string $platformUserId, array $data, ?int $channelId = null): array
    {
        $pawnNo = $data['pawn_no'] ?? '-';
        $itemName = $data['item_name'] ?? 'สินค้า';
        $monthlyInterest = number_format((float) ($data['monthly_interest'] ?? 0), 0);
        $dueDate = $data['due_date'] ?? '-';
        $daysRemaining = $data['days_remaining'] ?? 0;

        $message = "⏰ แจ้งเตือนครบกำหนดจำนำ\n\n";
        $message .= "🏷️ รหัส: {$pawnNo}\n";
        $message .= "📦 สินค้า: {$itemName}\n";
        $message .= "📅 ครบกำหนด: {$dueDate}\n";
        $message .= "⏳ เหลือ: {$daysRemaining} วัน\n";
        $message .= "💰 ดอกเบี้ย: ฿{$monthlyInterest}\n\n";
        $message .= "กรุณาชำระดอกเบี้ยก่อนครบกำหนด\nเพื่อไม่ให้สินค้าหลุดจำนำครับ 🙏";

        return $this->sendDirectMessage($platform, $platformUserId, $message, $channelId);
    }

    /**
     * Send notification for pawn forfeited
     */
    public function sendPawnForfeited(string $platform, string $platformUserId, array $data, ?int $channelId = null): array
    {
        $pawnNo = $data['pawn_no'] ?? '-';
        $itemName = $data['item_name'] ?? 'สินค้า';

        $message = "❌ หลุดจำนำ\n\n";
        $message .= "🏷️ รหัส: {$pawnNo}\n";
        $message .= "📦 สินค้า: {$itemName}\n\n";
        $message .= "สินค้าหลุดจำนำเนื่องจากไม่ชำระดอกเบี้ยตามกำหนด\n";
        $message .= "หากมีข้อสงสัย กรุณาติดต่อร้านครับ";

        return $this->sendDirectMessage($platform, $platformUserId, $message, $channelId);
    }
}

