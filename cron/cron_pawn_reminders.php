<?php
/**
 * Cron Job: Pawn Due Date Reminders
 * 
 * Sends push notifications to customers for upcoming pawn due dates.
 * Should run daily (e.g., 9:00 AM)
 * 
 * Crontab: 0 9 * * * php /path/to/cron_pawn_reminders.php
 * 
 * @version 1.0
 * @date 2026-01-31
 */

// Don't run via web
if (php_sapi_name() !== 'cli' && !isset($_SERVER['CRON'])) {
    // Allow testing via ?test=1
    if (!isset($_GET['test'])) {
        http_response_code(403);
        exit('CLI only');
    }
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/services/PushNotificationService.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting Pawn Due Date Reminder Cron\n";

try {
    $db = Database::getInstance();
    $pushService = new PushNotificationService($db);

    // ==================== 3-Day Reminder ====================
    echo "Checking 3-day reminders...\n";

    $threeDayPawns = $db->query(
        "SELECT p.*, 
                cp.platform, cp.platform_user_id, cp.display_name, cp.channel_id,
                DATEDIFF(p.due_date, CURDATE()) as days_remaining,
                (p.loan_amount * p.interest_rate / 100) as monthly_interest
         FROM pawns p
         LEFT JOIN customer_profiles cp ON p.customer_id = cp.id
         WHERE p.status IN ('active', 'extended')
           AND DATEDIFF(p.due_date, CURDATE()) = 3
           AND cp.platform_user_id IS NOT NULL"
    );

    $sent3Day = 0;
    foreach ($threeDayPawns as $pawn) {
        try {
            $result = $pushService->sendPawnDueReminder(
                $pawn['platform'] ?? 'line',
                $pawn['platform_user_id'],
                [
                    'pawn_no' => $pawn['pawn_no'],
                    'item_name' => $pawn['item_description'] ?? $pawn['item_name'] ?? 'สินค้า',
                    'monthly_interest' => $pawn['monthly_interest'],
                    'due_date' => $pawn['due_date'],
                    'days_remaining' => 3
                ],
                $pawn['channel_id']
            );

            if ($result['success']) {
                $sent3Day++;
                echo "  ✓ Sent 3-day reminder: {$pawn['pawn_no']}\n";
            } else {
                echo "  ✗ Failed: {$pawn['pawn_no']} - " . ($result['error'] ?? 'Unknown') . "\n";
            }
        } catch (Exception $e) {
            echo "  ✗ Error: {$pawn['pawn_no']} - {$e->getMessage()}\n";
        }
    }
    echo "3-day reminders: {$sent3Day} sent\n\n";

    // ==================== 1-Day Reminder ====================
    echo "Checking 1-day reminders...\n";

    $oneDayPawns = $db->query(
        "SELECT p.*, 
                cp.platform, cp.platform_user_id, cp.display_name, cp.channel_id,
                DATEDIFF(p.due_date, CURDATE()) as days_remaining,
                (p.loan_amount * p.interest_rate / 100) as monthly_interest
         FROM pawns p
         LEFT JOIN customer_profiles cp ON p.customer_id = cp.id
         WHERE p.status IN ('active', 'extended')
           AND DATEDIFF(p.due_date, CURDATE()) = 1
           AND cp.platform_user_id IS NOT NULL"
    );

    $sent1Day = 0;
    foreach ($oneDayPawns as $pawn) {
        try {
            $result = $pushService->sendPawnDueReminder(
                $pawn['platform'] ?? 'line',
                $pawn['platform_user_id'],
                [
                    'pawn_no' => $pawn['pawn_no'],
                    'item_name' => $pawn['item_description'] ?? $pawn['item_name'] ?? 'สินค้า',
                    'monthly_interest' => $pawn['monthly_interest'],
                    'due_date' => $pawn['due_date'],
                    'days_remaining' => 1
                ],
                $pawn['channel_id']
            );

            if ($result['success']) {
                $sent1Day++;
                echo "  ✓ Sent 1-day reminder: {$pawn['pawn_no']}\n";
            } else {
                echo "  ✗ Failed: {$pawn['pawn_no']} - " . ($result['error'] ?? 'Unknown') . "\n";
            }
        } catch (Exception $e) {
            echo "  ✗ Error: {$pawn['pawn_no']} - {$e->getMessage()}\n";
        }
    }
    echo "1-day reminders: {$sent1Day} sent\n\n";

    // ==================== Update Overdue Status ====================
    echo "Updating overdue statuses...\n";

    $overdueResult = $db->execute(
        "UPDATE pawns 
         SET status = 'overdue', updated_at = NOW() 
         WHERE status = 'active' 
           AND due_date < CURDATE()"
    );

    echo "Updated to overdue: " . $overdueResult . " pawns\n\n";

    // ==================== Auto-Forfeit (after 30 days overdue) ====================
    echo "Checking for auto-forfeit (30+ days overdue)...\n";

    $forfeitPawns = $db->query(
        "SELECT p.*, 
                cp.platform, cp.platform_user_id, cp.channel_id,
                DATEDIFF(CURDATE(), p.due_date) as days_overdue
         FROM pawns p
         LEFT JOIN customer_profiles cp ON p.customer_id = cp.id
         WHERE p.status = 'overdue'
           AND DATEDIFF(CURDATE(), p.due_date) >= 30"
    );

    $forfeited = 0;
    foreach ($forfeitPawns as $pawn) {
        try {
            // Update status to forfeited
            $db->execute(
                "UPDATE pawns SET status = 'forfeited', forfeited_date = NOW(), updated_at = NOW() WHERE id = ?",
                [$pawn['id']]
            );
            $forfeited++;

            // Send notification if possible
            if ($pawn['platform_user_id']) {
                $pushService->sendPawnForfeited(
                    $pawn['platform'] ?? 'line',
                    $pawn['platform_user_id'],
                    [
                        'pawn_no' => $pawn['pawn_no'],
                        'item_name' => $pawn['item_description'] ?? 'สินค้า'
                    ],
                    $pawn['channel_id']
                );
            }

            echo "  ✓ Forfeited: {$pawn['pawn_no']} (overdue {$pawn['days_overdue']} days)\n";
        } catch (Exception $e) {
            echo "  ✗ Error forfeiting: {$pawn['pawn_no']} - {$e->getMessage()}\n";
        }
    }
    echo "Forfeited: {$forfeited} pawns\n\n";

    // ==================== Summary ====================
    echo "=== Summary ===\n";
    echo "3-day reminders sent: {$sent3Day}\n";
    echo "1-day reminders sent: {$sent1Day}\n";
    echo "Pawns forfeited: {$forfeited}\n";
    echo "[" . date('Y-m-d H:i:s') . "] Cron completed successfully\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
