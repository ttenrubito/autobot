<?php
/**
 * Installment Reminders API - Send payment due reminders
 * 
 * Endpoints:
 * POST /api/cron/installment-reminders - Process all pending reminders
 * POST /api/cron/installment-reminders?contract_id=X - Send reminder for specific contract
 * 
 * This API can be called:
 * 1. Manually from admin panel
 * 2. By Cloud Scheduler (cron job) daily at 9:00 AM
 * 
 * Features:
 * - Send reminders 3 days before due date
 * - Send reminders 1 day before due date  
 * - Send overdue notices for late payments (day 1, 3, 7, 14)
 * 
 * @version 1.0
 * @date 2026-01-18
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';

// Optional auth - for scheduled jobs, may use API key instead
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
$validApiKey = getenv('CRON_API_KEY') ?: 'autobot-cron-secret-2026';

// Allow access from admin panel (JWT) or with API key (cron)
$isAuthorized = false;
if ($apiKey === $validApiKey) {
    $isAuthorized = true;
} else {
    // Try JWT auth
    require_once __DIR__ . '/../../includes/auth.php';
    $auth = verifyToken();
    $isAuthorized = $auth['valid'] ?? false;
}

if (!$isAuthorized) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    $db = Database::getInstance();
    $results = [
        'processed' => 0,
        'reminders_sent' => 0,
        'errors' => 0,
        'details' => []
    ];
    
    // Check if specific contract requested
    $contractId = $_GET['contract_id'] ?? null;
    
    if ($contractId) {
        // Process single contract
        $result = processContractReminder($db, (int)$contractId);
        $results['processed'] = 1;
        $results['reminders_sent'] = $result['sent'] ? 1 : 0;
        $results['details'][] = $result;
    } else {
        // Process all pending reminders
        
        // 1. Get contracts with due date in 3 days
        $threeDaysFromNow = date('Y-m-d', strtotime('+3 days'));
        $contracts3Days = getContractsForReminder($db, $threeDaysFromNow, 'before_3_days');
        
        foreach ($contracts3Days as $contract) {
            $result = sendReminder($db, $contract, 'before_3_days', 3);
            $results['processed']++;
            if ($result['sent']) $results['reminders_sent']++;
            if ($result['error']) $results['errors']++;
            $results['details'][] = $result;
        }
        
        // 2. Get contracts with due date tomorrow
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $contracts1Day = getContractsForReminder($db, $tomorrow, 'before_1_day');
        
        foreach ($contracts1Day as $contract) {
            $result = sendReminder($db, $contract, 'before_1_day', 1);
            $results['processed']++;
            if ($result['sent']) $results['reminders_sent']++;
            if ($result['error']) $results['errors']++;
            $results['details'][] = $result;
        }
        
        // 3. Get overdue contracts
        $overdueContracts = getOverdueContracts($db);
        
        foreach ($overdueContracts as $contract) {
            $daysOverdue = $contract['days_overdue'];
            $reminderType = "overdue_{$daysOverdue}_days";
            
            // Update status to overdue if not already
            if ($contract['status'] === 'active') {
                $db->execute("UPDATE installment_contracts SET status = 'overdue' WHERE id = ?", [$contract['id']]);
            }
            
            $result = sendReminder($db, $contract, $reminderType, -$daysOverdue);
            $results['processed']++;
            if ($result['sent']) $results['reminders_sent']++;
            if ($result['error']) $results['errors']++;
            $results['details'][] = $result;
        }
    }
    
    Logger::info('[INSTALLMENT_REMINDERS] Process completed', $results);
    
    echo json_encode([
        'success' => true,
        'message' => "Processed {$results['processed']} contracts, sent {$results['reminders_sent']} reminders",
        'data' => $results
    ]);
    
} catch (Exception $e) {
    Logger::error('[INSTALLMENT_REMINDERS] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error processing reminders',
        'error' => $e->getMessage()
    ]);
}

/**
 * Get contracts for a specific due date that haven't been reminded yet
 */
function getContractsForReminder($db, $dueDate, $reminderType) {
    return $db->queryAll(
        "SELECT c.*, 
            (SELECT COUNT(*) FROM installment_payments WHERE contract_id = c.id AND status = 'paid') as paid_periods,
            (SELECT COALESCE(paid_amount, 0) FROM installment_payments 
             WHERE contract_id = c.id AND status IN ('pending', 'partial') 
             ORDER BY period_number ASC LIMIT 1) as next_period_paid,
            (SELECT amount FROM installment_payments 
             WHERE contract_id = c.id AND status IN ('pending', 'partial') 
             ORDER BY period_number ASC LIMIT 1) as next_period_amount
        FROM installment_contracts c 
        WHERE c.status = 'active' 
        AND c.next_due_date = ?
        AND NOT EXISTS (
            SELECT 1 FROM installment_reminders 
            WHERE contract_id = c.id 
            AND reminder_type = ? 
            AND due_date = c.next_due_date
            AND status = 'sent'
        )",
        [$dueDate, $reminderType]
    );
}

/**
 * Get overdue contracts for reminder
 */
function getOverdueContracts($db) {
    return $db->queryAll(
        "SELECT c.*, 
            (SELECT COUNT(*) FROM installment_payments WHERE contract_id = c.id AND status = 'paid') as paid_periods,
            (SELECT COALESCE(paid_amount, 0) FROM installment_payments 
             WHERE contract_id = c.id AND status IN ('pending', 'partial') 
             ORDER BY period_number ASC LIMIT 1) as next_period_paid,
            (SELECT amount FROM installment_payments 
             WHERE contract_id = c.id AND status IN ('pending', 'partial') 
             ORDER BY period_number ASC LIMIT 1) as next_period_amount,
            DATEDIFF(CURDATE(), c.next_due_date) as days_overdue
        FROM installment_contracts c 
        WHERE c.status IN ('active', 'overdue')
        AND c.next_due_date < CURDATE()
        AND DATEDIFF(CURDATE(), c.next_due_date) IN (1, 3, 7, 14)
        AND NOT EXISTS (
            SELECT 1 FROM installment_reminders 
            WHERE contract_id = c.id 
            AND reminder_type = CONCAT('overdue_', DATEDIFF(CURDATE(), c.next_due_date), '_days')
            AND due_date = c.next_due_date
            AND status = 'sent'
        )"
    );
}

/**
 * Process reminder for a specific contract
 */
function processContractReminder($db, $contractId) {
    $contract = $db->queryOne(
        "SELECT c.*, 
            (SELECT COUNT(*) FROM installment_payments WHERE contract_id = c.id AND status = 'paid') as paid_periods,
            (SELECT COALESCE(paid_amount, 0) FROM installment_payments 
             WHERE contract_id = c.id AND status IN ('pending', 'partial') 
             ORDER BY period_number ASC LIMIT 1) as next_period_paid,
            (SELECT amount FROM installment_payments 
             WHERE contract_id = c.id AND status IN ('pending', 'partial') 
             ORDER BY period_number ASC LIMIT 1) as next_period_amount,
            DATEDIFF(c.next_due_date, CURDATE()) as days_until_due,
            DATEDIFF(CURDATE(), c.next_due_date) as days_overdue
        FROM installment_contracts c 
        WHERE c.id = ?",
        [$contractId]
    );
    
    if (!$contract) {
        return ['sent' => false, 'error' => true, 'message' => 'Contract not found'];
    }
    
    if (!in_array($contract['status'], ['active', 'overdue'])) {
        return ['sent' => false, 'error' => false, 'message' => 'Contract is not active'];
    }
    
    $daysUntil = (int)$contract['days_until_due'];
    $daysOverdue = (int)$contract['days_overdue'];
    
    // Determine reminder type
    if ($daysUntil > 0) {
        $reminderType = "before_{$daysUntil}_days";
    } elseif ($daysUntil === 0) {
        $reminderType = "due_today";
    } else {
        $reminderType = "overdue_{$daysOverdue}_days";
    }
    
    return sendReminder($db, $contract, $reminderType, $daysUntil);
}

/**
 * Send reminder for a contract
 */
function sendReminder($db, $contract, $reminderType, $daysUntil) {
    $nextPeriod = ((int)($contract['paid_periods'] ?? 0)) + 1;
    
    // Prepare message
    $message = buildReminderMessage($contract, $reminderType, $nextPeriod, $daysUntil);
    
    // Get platform info
    $platform = $contract['platform'] ?? 'facebook';
    $platformUserId = $contract['platform_user_id'] ?? $contract['external_user_id'];
    $channelId = $contract['channel_id'];
    
    if (!$platformUserId) {
        return [
            'sent' => false,
            'error' => true,
            'contract_id' => $contract['id'],
            'message' => 'No platform_user_id found'
        ];
    }
    
    try {
        // Send push notification
        $pushResult = sendPushNotification($db, $platform, $platformUserId, $channelId, $message);
        
        // Record reminder
        $db->execute(
            "INSERT INTO installment_reminders (contract_id, reminder_type, due_date, period_number, message_sent, sent_at, status, created_at) 
             VALUES (?, ?, ?, ?, ?, NOW(), 'sent', NOW())",
            [$contract['id'], $reminderType, $contract['next_due_date'], $nextPeriod, $message]
        );
        
        // Log to push_notifications table
        $db->execute(
            "INSERT INTO push_notifications (platform, platform_user_id, channel_id, notification_type, message, sent_at, delivery_status, created_at)
             VALUES (?, ?, ?, 'installment_reminder', ?, NOW(), ?, NOW())",
            [$platform, $platformUserId, $channelId, $message, $pushResult['success'] ? 'sent' : 'failed']
        );
        
        Logger::info('[INSTALLMENT_REMINDER] Sent', [
            'contract_id' => $contract['id'],
            'contract_no' => $contract['contract_no'],
            'reminder_type' => $reminderType,
            'platform' => $platform
        ]);
        
        return [
            'sent' => true,
            'error' => false,
            'contract_id' => $contract['id'],
            'contract_no' => $contract['contract_no'],
            'reminder_type' => $reminderType,
            'period' => $nextPeriod
        ];
        
    } catch (Exception $e) {
        Logger::error('[INSTALLMENT_REMINDER] Failed to send', [
            'contract_id' => $contract['id'],
            'error' => $e->getMessage()
        ]);
        
        return [
            'sent' => false,
            'error' => true,
            'contract_id' => $contract['id'],
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Build reminder message based on type
 */
function buildReminderMessage($contract, $reminderType, $periodNumber, $daysUntil) {
    $productName = $contract['product_name'] ?? 'สินค้า';
    
    // Calculate remaining amount (supports partial payments)
    $periodAmount = floatval($contract['next_period_amount'] ?? $contract['amount_per_period'] ?? 0);
    $periodPaid = floatval($contract['next_period_paid'] ?? 0);
    $remainingAmount = $periodAmount - $periodPaid;
    
    // Format amounts
    $amountDisplay = number_format($remainingAmount, 0);
    $hasPartial = $periodPaid > 0;
    $partialNote = $hasPartial ? " (จ่ายแล้ว " . number_format($periodPaid, 0) . " บาท)" : "";
    
    $dueDate = date('d/m/Y', strtotime($contract['next_due_date']));
    $totalPeriods = $contract['total_periods'] ?? 3;
    $customerName = $contract['customer_name'] ?? 'คุณลูกค้า';
    
    if (strpos($reminderType, 'before_') === 0) {
        // Before due date reminder
        $days = abs($daysUntil);
        return "🔔 แจ้งเตือนผ่อนชำระ\n\n" .
            "สวัสดีค่ะ {$customerName}\n" .
            "อีก {$days} วัน จะถึงกำหนดชำระงวดที่ {$periodNumber}/{$totalPeriods}\n\n" .
            "📦 สินค้า: {$productName}\n" .
            "💰 ยอดชำระ: {$amountDisplay} บาท{$partialNote}\n" .
            "📅 กำหนดชำระ: {$dueDate}\n\n" .
            "พร้อมส่งสลิปมาได้เลยนะคะ 🙏";
            
    } elseif ($reminderType === 'due_today') {
        return "⏰ ถึงกำหนดชำระวันนี้!\n\n" .
            "สวัสดีค่ะ {$customerName}\n" .
            "วันนี้ถึงกำหนดชำระงวดที่ {$periodNumber}/{$totalPeriods} แล้วค่ะ\n\n" .
            "📦 สินค้า: {$productName}\n" .
            "💰 ยอดชำระ: {$amountDisplay} บาท{$partialNote}\n\n" .
            "รบกวนชำระและส่งสลิปมาภายในวันนี้นะคะ 🙏";
            
    } else {
        // Overdue reminder
        $daysOverdue = abs($daysUntil);
        return "⚠️ เลยกำหนดชำระ {$daysOverdue} วัน\n\n" .
            "สวัสดีค่ะ {$customerName}\n" .
            "งวดที่ {$periodNumber}/{$totalPeriods} เลยกำหนดชำระมา {$daysOverdue} วันแล้วค่ะ\n\n" .
            "📦 สินค้า: {$productName}\n" .
            "💰 ยอดค้างชำระ: {$amountDisplay} บาท{$partialNote}\n" .
            "📅 กำหนดชำระ: {$dueDate}\n\n" .
            "รบกวนชำระโดยเร็วนะคะ หากมีปัญหาติดต่อทางร้านได้ค่ะ 🙏";
    }
}

/**
 * Send push notification to platform
 */
function sendPushNotification($db, $platform, $platformUserId, $channelId, $message) {
    $channel = null;
    
    // Try by channel_id first
    if ($channelId) {
        $channel = $db->queryOne("SELECT * FROM customer_channels WHERE id = ?", [$channelId]);
    }
    
    // Fallback: find channel by platform type
    if (!$channel && $platform) {
        $channel = $db->queryOne(
            "SELECT * FROM customer_channels WHERE type = ? AND status = 'active' ORDER BY id DESC LIMIT 1", 
            [$platform]
        );
    }
    
    if (!$channel) {
        throw new Exception("Channel not found for platform: {$platform}, channel_id: {$channelId}");
    }
    
    $config = json_decode($channel['config'] ?? '{}', true);
    
    if ($platform === 'facebook') {
        return sendFacebookMessage($platformUserId, $message, $config);
    } elseif ($platform === 'line') {
        return sendLineMessage($platformUserId, $message, $config);
    } else {
        throw new Exception("Unsupported platform: {$platform}");
    }
}

/**
 * Send Facebook message
 */
function sendFacebookMessage($psid, $message, $config) {
    $pageAccessToken = $config['page_access_token'] ?? null;
    
    if (!$pageAccessToken) {
        throw new Exception("Missing Facebook page_access_token");
    }
    
    $url = "https://graph.facebook.com/v18.0/me/messages?access_token=" . urlencode($pageAccessToken);
    
    $data = [
        'recipient' => ['id' => $psid],
        'message' => ['text' => $message],
        'messaging_type' => 'MESSAGE_TAG',
        'tag' => 'ACCOUNT_UPDATE'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($httpCode !== 200 || isset($result['error'])) {
        Logger::error('[FB_PUSH] Failed', ['response' => $response, 'http_code' => $httpCode]);
        return ['success' => false, 'error' => $result['error']['message'] ?? 'Unknown error'];
    }
    
    return ['success' => true, 'message_id' => $result['message_id'] ?? null];
}

/**
 * Send LINE message
 */
function sendLineMessage($userId, $message, $config) {
    $channelAccessToken = $config['channel_access_token'] ?? null;
    
    if (!$channelAccessToken) {
        throw new Exception("Missing LINE channel_access_token");
    }
    
    $url = "https://api.line.me/v2/bot/message/push";
    
    $data = [
        'to' => $userId,
        'messages' => [
            ['type' => 'text', 'text' => $message]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $channelAccessToken
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        Logger::error('[LINE_PUSH] Failed', ['response' => $response, 'http_code' => $httpCode]);
        return ['success' => false, 'error' => $response];
    }
    
    return ['success' => true];
}
