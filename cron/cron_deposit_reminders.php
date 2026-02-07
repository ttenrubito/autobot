<?php
/**
 * Cron Job: Deposit Due Date Reminders
 * 
 * ส่งแจ้งเตือนลูกค้าเมื่อใกล้ครบกำหนดต่อดอกเบี้ย (รับฝากสินค้า)
 * - 3 วันก่อนครบกำหนด
 * - 1 วันก่อนครบกำหนด
 * - วันครบกำหนด
 * 
 * Crontab: 0 9 * * * php /opt/lampp/htdocs/autobot/cron/cron_deposit_reminders.php
 * 
 * Business Rules:
 * - ดอกเบี้ย 2% ต่อเดือน
 * - ต้องต่อดอกทุก 30 วัน
 * - หากไม่ต่อดอกเกิน 7 วัน จะถูก mark เป็น expired
 * 
 * @version 1.0
 * @date 2026-01-31
 */

// Don't run via web
if (php_sapi_name() !== 'cli' && !isset($_SERVER['CRON'])) {
    if (!isset($_GET['test'])) {
        http_response_code(403);
        exit('CLI only');
    }
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/services/PushNotificationService.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting Deposit Due Date Reminder Cron\n";

try {
    $db = Database::getInstance();
    $pushService = new PushNotificationService($db);

    $results = [
        'three_day_sent' => 0,
        'one_day_sent' => 0,
        'today_sent' => 0,
        'expired_updated' => 0,
        'errors' => []
    ];

    // ==================== 3-Day Reminder ====================
    echo "Checking 3-day reminders...\n";

    $threeDayDeposits = $db->query(
        "SELECT d.*, 
                cp.platform, cp.platform_user_id, cp.display_name, cp.channel_id,
                DATEDIFF(d.expected_pickup_date, CURDATE()) as days_remaining,
                ROUND(d.deposit_amount * 0.02) as monthly_interest
         FROM deposits d
         LEFT JOIN customer_profiles cp ON d.customer_id = cp.user_id
         WHERE d.status = 'deposited'
           AND DATEDIFF(d.expected_pickup_date, CURDATE()) = 3
           AND cp.platform_user_id IS NOT NULL"
    );

    foreach ($threeDayDeposits as $deposit) {
        try {
            $message = buildDepositReminderMessage($deposit, 3);
            $result = $pushService->sendTextMessage(
                $deposit['platform'] ?? 'line',
                $deposit['platform_user_id'],
                $message,
                $deposit['channel_id']
            );

            if ($result['success']) {
                $results['three_day_sent']++;
                echo "  ✓ Sent 3-day reminder: {$deposit['deposit_no']}\n";
            } else {
                $results['errors'][] = "3-day {$deposit['deposit_no']}: " . ($result['error'] ?? 'Unknown');
                echo "  ✗ Failed: {$deposit['deposit_no']} - " . ($result['error'] ?? 'Unknown') . "\n";
            }
        } catch (Exception $e) {
            $results['errors'][] = "3-day {$deposit['deposit_no']}: " . $e->getMessage();
            echo "  ✗ Error: {$deposit['deposit_no']} - {$e->getMessage()}\n";
        }
    }
    echo "3-day reminders: {$results['three_day_sent']} sent\n\n";

    // ==================== 1-Day Reminder ====================
    echo "Checking 1-day reminders...\n";

    $oneDayDeposits = $db->query(
        "SELECT d.*, 
                cp.platform, cp.platform_user_id, cp.display_name, cp.channel_id,
                DATEDIFF(d.expected_pickup_date, CURDATE()) as days_remaining,
                ROUND(d.deposit_amount * 0.02) as monthly_interest
         FROM deposits d
         LEFT JOIN customer_profiles cp ON d.customer_id = cp.user_id
         WHERE d.status = 'deposited'
           AND DATEDIFF(d.expected_pickup_date, CURDATE()) = 1
           AND cp.platform_user_id IS NOT NULL"
    );

    foreach ($oneDayDeposits as $deposit) {
        try {
            $message = buildDepositReminderMessage($deposit, 1);
            $result = $pushService->sendTextMessage(
                $deposit['platform'] ?? 'line',
                $deposit['platform_user_id'],
                $message,
                $deposit['channel_id']
            );

            if ($result['success']) {
                $results['one_day_sent']++;
                echo "  ✓ Sent 1-day reminder: {$deposit['deposit_no']}\n";
            } else {
                $results['errors'][] = "1-day {$deposit['deposit_no']}: " . ($result['error'] ?? 'Unknown');
            }
        } catch (Exception $e) {
            $results['errors'][] = "1-day {$deposit['deposit_no']}: " . $e->getMessage();
            echo "  ✗ Error: {$deposit['deposit_no']} - {$e->getMessage()}\n";
        }
    }
    echo "1-day reminders: {$results['one_day_sent']} sent\n\n";

    // ==================== Due Today Reminder ====================
    echo "Checking due today reminders...\n";

    $dueTodayDeposits = $db->query(
        "SELECT d.*, 
                cp.platform, cp.platform_user_id, cp.display_name, cp.channel_id,
                ROUND(d.deposit_amount * 0.02) as monthly_interest
         FROM deposits d
         LEFT JOIN customer_profiles cp ON d.customer_id = cp.user_id
         WHERE d.status = 'deposited'
           AND d.expected_pickup_date = CURDATE()
           AND cp.platform_user_id IS NOT NULL"
    );

    foreach ($dueTodayDeposits as $deposit) {
        try {
            $message = buildDepositReminderMessage($deposit, 0);
            $result = $pushService->sendTextMessage(
                $deposit['platform'] ?? 'line',
                $deposit['platform_user_id'],
                $message,
                $deposit['channel_id']
            );

            if ($result['success']) {
                $results['today_sent']++;
                echo "  ✓ Sent today reminder: {$deposit['deposit_no']}\n";
            }
        } catch (Exception $e) {
            $results['errors'][] = "today {$deposit['deposit_no']}: " . $e->getMessage();
            echo "  ✗ Error: {$deposit['deposit_no']} - {$e->getMessage()}\n";
        }
    }
    echo "Due today reminders: {$results['today_sent']} sent\n\n";

    // ==================== Update Expired Status ====================
    echo "Updating expired statuses (7+ days overdue)...\n";

    $expiredResult = $db->execute(
        "UPDATE deposits 
         SET status = 'expired', updated_at = NOW() 
         WHERE status = 'deposited' 
           AND expected_pickup_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
    );

    $results['expired_updated'] = $expiredResult;
    echo "Updated to expired: {$expiredResult} deposits\n\n";

    // ==================== Log to cronjob_logs ====================
    try {
        $db->execute(
            "INSERT INTO cronjob_logs (job_name, status, result_data, execution_time_ms, created_at) 
             VALUES (?, ?, ?, ?, NOW())",
            [
                'deposit-reminders',
                empty($results['errors']) ? 'success' : 'partial',
                json_encode($results),
                0
            ]
        );
    } catch (Exception $e) {
        // Table might not exist, ignore
        echo "Note: Could not log to cronjob_logs: " . $e->getMessage() . "\n";
    }

    // ==================== Summary ====================
    echo "=== Summary ===\n";
    echo "3-day reminders sent: {$results['three_day_sent']}\n";
    echo "1-day reminders sent: {$results['one_day_sent']}\n";
    echo "Due today reminders sent: {$results['today_sent']}\n";
    echo "Deposits marked expired: {$results['expired_updated']}\n";
    if (!empty($results['errors'])) {
        echo "Errors: " . count($results['errors']) . "\n";
    }
    echo "[" . date('Y-m-d H:i:s') . "] Cron completed successfully\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Deposit Reminder Cron Error: " . $e->getMessage());
    exit(1);
}

/**
 * Build reminder message for deposit
 */
function buildDepositReminderMessage($deposit, $daysRemaining)
{
    $interest = number_format($deposit['monthly_interest'] ?? 0, 0);
    $itemName = $deposit['item_name'] ?? 'สินค้า';
    $depositNo = $deposit['deposit_no'];
    $dueDate = date('d/m/Y', strtotime($deposit['expected_pickup_date']));
    
    if ($daysRemaining === 0) {
        return "🔔 แจ้งเตือน: วันนี้ครบกำหนดต่อดอกค่ะ\n\n" .
               "📦 สินค้า: {$itemName}\n" .
               "📋 เลขที่: {$depositNo}\n" .
               "💰 ดอกเบี้ย: ฿{$interest}\n\n" .
               "กรุณาต่อดอกภายในวันนี้เพื่อรักษาสิทธิ์ค่ะ 🙏\n" .
               "📞 โทร: 085-196-5466";
    } elseif ($daysRemaining === 1) {
        return "⚠️ เตือน: พรุ่งนี้ครบกำหนดต่อดอกแล้วค่ะ!\n\n" .
               "📦 สินค้า: {$itemName}\n" .
               "📋 เลขที่: {$depositNo}\n" .
               "📅 ครบกำหนด: {$dueDate}\n" .
               "💰 ดอกเบี้ย: ฿{$interest}\n\n" .
               "อย่าลืมมาต่อดอกนะคะ 😊";
    } else {
        return "🔔 แจ้งเตือน: อีก {$daysRemaining} วันครบกำหนดต่อดอกค่ะ\n\n" .
               "📦 สินค้า: {$itemName}\n" .
               "📋 เลขที่: {$depositNo}\n" .
               "📅 ครบกำหนด: {$dueDate}\n" .
               "💰 ดอกเบี้ย: ฿{$interest}\n\n" .
               "สะดวกมาต่อดอกได้ตลอดเวลาทำการค่ะ 🏪";
    }
}
