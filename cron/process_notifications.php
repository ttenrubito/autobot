<?php
/**
 * Notification Processor - Process scheduled notifications
 * 
 * This script should be run via cron:
 * */5 * * * * php /path/to/autobot/cron/process_notifications.php >> /var/log/autobot-notifications.log 2>&1
 * 
 * Processes notifications for:
 * - Deposit expiry reminders (7 days, 3 days, 1 day before)
 * - Pawn interest due reminders (7 days, 3 days, 1 day before)
 * - Repair status updates
 * - Payment confirmations
 */

// Prevent running in web context
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    die('This script can only be run from command line');
}

// Optional security key for web-based cron
define('CRON_KEY', getenv('CRON_SECRET_KEY') ?: 'your-secret-cron-key-here');
if (php_sapi_name() !== 'cli' && ($_GET['cron_key'] ?? '') !== CRON_KEY) {
    http_response_code(403);
    die('Unauthorized');
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/LineBotClient.php';

class NotificationProcessor
{
    private $db;
    private $lineBotClient;
    private $processedCount = 0;
    private $errorCount = 0;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->lineBotClient = new LineBotClient();
    }
    
    /**
     * Main processing loop
     */
    public function run()
    {
        $this->log("=== Starting notification processing ===");
        
        // 1. Generate scheduled notifications
        $this->generateDepositReminders();
        $this->generatePawnInterestReminders();
        
        // 2. Process pending notifications
        $this->processPendingNotifications();
        
        $this->log("=== Processing complete. Sent: {$this->processedCount}, Errors: {$this->errorCount} ===");
    }
    
    /**
     * Generate deposit expiry reminders
     */
    private function generateDepositReminders()
    {
        $this->log("Checking deposit expiry reminders...");
        
        // Get deposits expiring in 7, 3, or 1 days
        $intervals = [
            ['days' => 7, 'type' => 'deposit_expiry_7d', 'message' => "⏰ แจ้งเตือน: มัดจำ {{product_name}} จะหมดอายุในอีก 7 วัน ({{expires_at}})\nรบกวนมารับสินค้าหรือต่ออายุมัดจำนะคะ 💎"],
            ['days' => 3, 'type' => 'deposit_expiry_3d', 'message' => "⚠️ มัดจำ {{product_name}} จะหมดอายุในอีก 3 วัน!\nกรุณามารับสินค้าหรือติดต่อร้านด่วนค่ะ 📞"],
            ['days' => 1, 'type' => 'deposit_expiry_1d', 'message' => "🚨 ด่วน! มัดจำ {{product_name}} จะหมดอายุพรุ่งนี้!\nกรุณาติดต่อร้านโดยด่วนค่ะ ☎️"],
        ];
        
        foreach ($intervals as $interval) {
            $targetDate = date('Y-m-d', strtotime("+{$interval['days']} days"));
            
            $deposits = $this->db->queryAll(
                "SELECT d.*, c.channel_access_token 
                 FROM deposits d
                 JOIN channels c ON d.channel_id = c.id
                 WHERE DATE(d.expires_at) = ?
                 AND d.status IN ('pending', 'paid')
                 AND NOT EXISTS (
                     SELECT 1 FROM scheduled_notifications sn 
                     WHERE sn.reference_type = 'deposit' 
                     AND sn.reference_id = d.id 
                     AND sn.notification_type = ?
                     AND sn.status IN ('pending', 'sent')
                 )",
                [$targetDate, $interval['type']]
            );
            
            foreach ($deposits as $deposit) {
                $message = str_replace(
                    ['{{product_name}}', '{{expires_at}}', '{{deposit_no}}'],
                    [$deposit['product_name'], $deposit['expires_at'], $deposit['deposit_no']],
                    $interval['message']
                );
                
                $this->createNotification(
                    $deposit['channel_id'],
                    $deposit['external_user_id'],
                    $interval['type'],
                    'deposit',
                    $deposit['id'],
                    $message,
                    now() // Send immediately
                );
            }
        }
    }
    
    /**
     * Generate pawn interest due reminders
     */
    private function generatePawnInterestReminders()
    {
        $this->log("Checking pawn interest reminders...");
        
        $intervals = [
            ['days' => 7, 'type' => 'pawn_interest_7d', 'message' => "⏰ แจ้งเตือน: ดอกเบี้ยจำนำ {{item_description}} ครบกำหนดในอีก 7 วัน ({{next_interest_due}})\nยอดดอกเบี้ย: {{interest_amount}} บาท 💰"],
            ['days' => 3, 'type' => 'pawn_interest_3d', 'message' => "⚠️ ดอกเบี้ยจำนำ {{item_description}} ครบกำหนดในอีก 3 วัน!\nยอดดอกเบี้ย: {{interest_amount}} บาท\nกรุณาชำระเพื่อต่ออายุค่ะ 💳"],
            ['days' => 1, 'type' => 'pawn_interest_1d', 'message' => "🚨 ด่วน! ดอกเบี้ยจำนำ {{item_description}} ครบกำหนดพรุ่งนี้!\nยอด: {{interest_amount}} บาท\nหากไม่ชำระจะถูกคิดค่าปรับค่ะ ⚠️"],
            ['days' => 0, 'type' => 'pawn_interest_due', 'message' => "🔴 ดอกเบี้ยจำนำ {{item_description}} ครบกำหนดวันนี้!\nยอด: {{interest_amount}} บาท\nโปรดชำระโดยด่วนค่ะ 🙏"],
        ];
        
        foreach ($intervals as $interval) {
            $targetDate = date('Y-m-d', strtotime("+{$interval['days']} days"));
            
            $pawns = $this->db->queryAll(
                "SELECT p.*, c.channel_access_token,
                        (p.principal_amount * (p.interest_rate_percent / 100)) as interest_amount
                 FROM pawns p
                 JOIN channels c ON p.channel_id = c.id
                 WHERE DATE(p.next_interest_due) = ?
                 AND p.status = 'active'
                 AND NOT EXISTS (
                     SELECT 1 FROM scheduled_notifications sn 
                     WHERE sn.reference_type = 'pawn' 
                     AND sn.reference_id = p.id 
                     AND sn.notification_type = ?
                     AND sn.status IN ('pending', 'sent')
                 )",
                [$targetDate, $interval['type']]
            );
            
            foreach ($pawns as $pawn) {
                $message = str_replace(
                    ['{{item_description}}', '{{next_interest_due}}', '{{interest_amount}}', '{{pawn_no}}'],
                    [
                        $pawn['item_description'], 
                        $pawn['next_interest_due'], 
                        number_format((float)$pawn['interest_amount']),
                        $pawn['pawn_no']
                    ],
                    $interval['message']
                );
                
                $this->createNotification(
                    $pawn['channel_id'],
                    $pawn['external_user_id'],
                    $interval['type'],
                    'pawn',
                    $pawn['id'],
                    $message,
                    now()
                );
            }
        }
    }
    
    /**
     * Process all pending notifications
     */
    private function processPendingNotifications()
    {
        $this->log("Processing pending notifications...");
        
        $notifications = $this->db->queryAll(
            "SELECT sn.*, c.channel_access_token, c.platform
             FROM scheduled_notifications sn
             JOIN channels c ON sn.channel_id = c.id
             WHERE sn.status = 'pending'
             AND sn.scheduled_at <= NOW()
             ORDER BY sn.scheduled_at ASC
             LIMIT 100"
        );
        
        $this->log("Found " . count($notifications) . " pending notifications");
        
        foreach ($notifications as $notif) {
            try {
                $this->sendNotification($notif);
                
                $this->db->execute(
                    "UPDATE scheduled_notifications SET status = 'sent', sent_at = NOW(), updated_at = NOW() WHERE id = ?",
                    [$notif['id']]
                );
                
                $this->processedCount++;
                $this->log("✓ Sent notification ID: {$notif['id']} to {$notif['external_user_id']}");
                
            } catch (Exception $e) {
                $this->errorCount++;
                $this->db->execute(
                    "UPDATE scheduled_notifications SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?",
                    [$e->getMessage(), $notif['id']]
                );
                $this->log("✗ Failed notification ID: {$notif['id']} - " . $e->getMessage());
            }
            
            // Rate limiting - 100ms between sends
            usleep(100000);
        }
    }
    
    /**
     * Send a single notification
     */
    private function sendNotification(array $notif): bool
    {
        $platform = $notif['platform'] ?? 'line';
        $externalUserId = $notif['external_user_id'];
        $message = $notif['message'];
        $channelAccessToken = $notif['channel_access_token'];
        
        if ($platform === 'line') {
            return $this->sendLineMessage($externalUserId, $message, $channelAccessToken);
        } elseif ($platform === 'facebook') {
            return $this->sendFacebookMessage($externalUserId, $message, $channelAccessToken);
        }
        
        throw new Exception("Unknown platform: {$platform}");
    }
    
    /**
     * Send LINE push message
     */
    private function sendLineMessage(string $userId, string $message, string $accessToken): bool
    {
        $url = 'https://api.line.me/v2/bot/message/push';
        
        $payload = [
            'to' => $userId,
            'messages' => [
                ['type' => 'text', 'text' => $message]
            ]
        ];
        
        $ch = curl_init($url);
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
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("LINE API error: HTTP {$httpCode} - {$response}");
        }
        
        return true;
    }
    
    /**
     * Send Facebook message (placeholder)
     */
    private function sendFacebookMessage(string $userId, string $message, string $accessToken): bool
    {
        // TODO: Implement Facebook Messenger API
        $this->log("Facebook messaging not implemented yet for user: {$userId}");
        return true;
    }
    
    /**
     * Create a notification record
     */
    private function createNotification(
        int $channelId, 
        string $externalUserId, 
        string $type, 
        string $refType, 
        int $refId, 
        string $message, 
        string $scheduledAt
    ): bool {
        try {
            $this->db->execute(
                "INSERT INTO scheduled_notifications 
                 (channel_id, external_user_id, notification_type, reference_type, reference_id, message, scheduled_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')",
                [$channelId, $externalUserId, $type, $refType, $refId, $message, $scheduledAt]
            );
            $this->log("Created notification: {$type} for ref {$refType}:{$refId}");
            return true;
        } catch (Exception $e) {
            $this->log("Failed to create notification: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log message with timestamp
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}\n";
    }
}

// Helper function
function now(): string {
    return date('Y-m-d H:i:s');
}

// Run the processor
$processor = new NotificationProcessor();
$processor->run();
