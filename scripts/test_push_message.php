<?php
/**
 * Test Script: Push Message Service
 * 
 * ใช้ทดสอบการส่ง push message ผ่าน LINE และ Facebook
 * 
 * Usage:
 *   php scripts/test_push_message.php line <user_id> <channel_id> "message"
 *   php scripts/test_push_message.php facebook <psid> <channel_id> "message"
 *   php scripts/test_push_message.php test-config <channel_id>
 * 
 * Examples:
 *   php scripts/test_push_message.php test-config 1
 *   php scripts/test_push_message.php line U1234567890abcdef 1 "ทดสอบส่งข้อความ"
 */

// Allow CLI only
if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/services/PushMessageService.php';

use App\Services\PushMessageService;

// Parse arguments
$action = $argv[1] ?? 'help';

echo "\n=== Push Message Service Test ===\n\n";

// Actions that don't need DB
$noDatabaseActions = ['test-bank-config', 'help'];

try {
    // Only connect to DB if needed
    if (!in_array($action, $noDatabaseActions)) {
        $pdo = getDB();
        $pushService = new PushMessageService($pdo);
    }
    
    switch ($action) {
        case 'test-config':
            // Test loading channel config
            $channelId = (int)($argv[2] ?? 1);
            echo "Testing channel config for channel_id: {$channelId}\n";
            
            $stmt = $pdo->prepare("SELECT id, name, platform, config FROM customer_channels WHERE id = ?");
            $stmt->execute([$channelId]);
            $channel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$channel) {
                echo "❌ Channel not found\n";
                exit(1);
            }
            
            echo "✅ Channel found: {$channel['name']} ({$channel['platform']})\n";
            
            $config = json_decode($channel['config'], true);
            if (!$config) {
                echo "❌ Invalid config JSON\n";
                exit(1);
            }
            
            echo "✅ Config parsed successfully\n";
            
            // Check for tokens
            if ($channel['platform'] === 'line') {
                $hasToken = !empty($config['line_channel_access_token']);
                echo $hasToken ? "✅ LINE access token: present\n" : "❌ LINE access token: missing\n";
            } elseif ($channel['platform'] === 'facebook') {
                $hasToken = !empty($config['facebook_page_access_token']);
                echo $hasToken ? "✅ Facebook page token: present\n" : "❌ Facebook page token: missing\n";
            }
            break;
            
        case 'line':
            // Send LINE push message
            $userId = $argv[2] ?? null;
            $channelId = (int)($argv[3] ?? 0);
            $message = $argv[4] ?? 'ทดสอบส่งข้อความจาก Autobot';
            
            if (!$userId || !$channelId) {
                echo "Usage: php test_push_message.php line <user_id> <channel_id> \"message\"\n";
                exit(1);
            }
            
            echo "Sending LINE push message...\n";
            echo "  User ID: {$userId}\n";
            echo "  Channel ID: {$channelId}\n";
            echo "  Message: {$message}\n\n";
            
            $result = $pushService->send('line', $userId, $message, $channelId);
            
            if ($result['success']) {
                echo "✅ Message sent successfully!\n";
            } else {
                echo "❌ Failed to send: " . ($result['error'] ?? 'Unknown error') . "\n";
            }
            
            echo "\nResponse:\n";
            print_r($result);
            break;
            
        case 'facebook':
            // Send Facebook push message
            $psid = $argv[2] ?? null;
            $channelId = (int)($argv[3] ?? 0);
            $message = $argv[4] ?? 'ทดสอบส่งข้อความจาก Autobot';
            
            if (!$psid || !$channelId) {
                echo "Usage: php test_push_message.php facebook <psid> <channel_id> \"message\"\n";
                exit(1);
            }
            
            echo "Sending Facebook push message...\n";
            echo "  PSID: {$psid}\n";
            echo "  Channel ID: {$channelId}\n";
            echo "  Message: {$message}\n\n";
            
            $result = $pushService->send('facebook', $psid, $message, $channelId);
            
            if ($result['success']) {
                echo "✅ Message sent successfully!\n";
            } else {
                echo "❌ Failed to send: " . ($result['error'] ?? 'Unknown error') . "\n";
            }
            
            echo "\nResponse:\n";
            print_r($result);
            break;
            
        case 'test-template':
            // Test placeholder replacement
            $template = "ขอบพระคุณค่ะ คุณ{customer_name}\nยอดชำระ {amount} บาท\nบัญชี: {bank_name} {account_number}";
            $data = [
                'customer_name' => 'สมชาย',
                'amount' => 50000,
                'bank_name' => 'กสิกรไทย',
                'account_number' => '8000029282'
            ];
            
            echo "Template:\n{$template}\n\n";
            echo "Data:\n";
            print_r($data);
            
            $result = $pushService->replacePlaceholders($template, $data);
            echo "\nResult:\n{$result}\n";
            break;
            
        case 'test-bank-config':
            // Test bank accounts config (no DB needed)
            $bankAccounts = require __DIR__ . '/../config/bank_accounts.php';
            
            echo "Bank Accounts:\n";
            foreach ($bankAccounts as $id => $bank) {
                echo "  [{$id}] {$bank['display_text']}\n";
                echo "    Max per slip: " . ($bank['max_per_slip'] ? number_format($bank['max_per_slip']) : 'ไม่จำกัด') . "\n";
            }
            break;
            
        default:
            echo "Usage:\n";
            echo "  php test_push_message.php test-config <channel_id>     - Test channel config\n";
            echo "  php test_push_message.php line <user_id> <channel_id> \"message\"\n";
            echo "  php test_push_message.php facebook <psid> <channel_id> \"message\"\n";
            echo "  php test_push_message.php test-template                 - Test placeholder replacement\n";
            echo "  php test_push_message.php test-bank-config              - Test bank accounts config\n";
            break;
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";
