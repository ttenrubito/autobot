<?php
/**
 * Pawn Overdue Reminder Cron Job
 * 
 * Sends push messages to customers with overdue pawns
 * Run via: Cloud Scheduler → /cron/pawn-overdue-reminder.php
 * 
 * Security: Requires X-CloudScheduler-JobName header or secret key
 */

define('INCLUDE_CHECK', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/services/PushMessageService.php';

use App\Services\PushMessageService;

// Security check
$isCloudScheduler = !empty($_SERVER['HTTP_X_CLOUDSCHEDULER_JOBNAME']);
$secretKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';

if (!$isCloudScheduler && $secretKey !== 'cron_pawn_overdue_2025') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

header('Content-Type: application/json');

try {
    $pdo = getDB();
    $pushService = new PushMessageService($pdo);
    
    // Get all overdue pawns that haven't been reminded today
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.pawn_no,
            p.loan_amount,
            p.interest_rate,
            p.due_date,
            p.user_id,
            p.customer_profile_id,
            DATEDIFF(CURDATE(), p.due_date) as days_overdue,
            (p.loan_amount * p.interest_rate / 100) * CEIL(DATEDIFF(CURDATE(), p.due_date) / 30) as overdue_interest,
            cp.platform,
            cp.platform_user_id,
            cp.display_name,
            cc.id as channel_id
        FROM pawns p
        LEFT JOIN customer_profiles cp ON p.customer_profile_id = cp.id
        LEFT JOIN customer_channels cc ON cp.channel_id = cc.id
        WHERE p.status IN ('active', 'overdue')
        AND p.due_date < CURDATE()
        AND cp.platform_user_id IS NOT NULL
        AND (
            p.last_reminder_sent IS NULL 
            OR DATE(p.last_reminder_sent) < CURDATE()
        )
        ORDER BY p.due_date ASC
        LIMIT 100
    ");
    $stmt->execute();
    $overduePawns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [
        'total_overdue' => count($overduePawns),
        'sent' => 0,
        'failed' => 0,
        'details' => []
    ];
    
    foreach ($overduePawns as $pawn) {
        // Format message
        $daysOverdue = (int)$pawn['days_overdue'];
        $overdueInterest = number_format((float)$pawn['overdue_interest'], 0);
        $loanAmount = number_format((float)$pawn['loan_amount'], 0);
        
        $message = "🔔 แจ้งเตือนจำนำเกินกำหนด\n\n";
        $message .= "คุณ {$pawn['display_name']} สินค้าจำนำรหัส {$pawn['pawn_no']} ";
        $message .= "เกินกำหนดชำระ {$daysOverdue} วันแล้ว\n\n";
        $message .= "💰 เงินต้น: ฿{$loanAmount}\n";
        $message .= "📊 ดอกเบี้ยค้าง: ฿{$overdueInterest}\n\n";
        $message .= "กรุณาติดต่อชำระเพื่อต่อดอกหรือไถ่ถอน\n";
        $message .= "📞 ติดต่อร้านได้เลยค่ะ";
        
        // Send push message
        if ($pawn['platform_user_id'] && $pawn['channel_id']) {
            $result = $pushService->send(
                $pawn['platform'],
                $pawn['platform_user_id'],
                $message,
                (int)$pawn['channel_id']
            );
            
            if ($result['success'] ?? false) {
                $results['sent']++;
                
                // Update last_reminder_sent
                $updateStmt = $pdo->prepare("UPDATE pawns SET last_reminder_sent = NOW() WHERE id = ?");
                $updateStmt->execute([$pawn['id']]);
                
                $results['details'][] = [
                    'pawn_no' => $pawn['pawn_no'],
                    'status' => 'sent'
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'pawn_no' => $pawn['pawn_no'],
                    'status' => 'failed',
                    'error' => $result['error'] ?? 'Unknown error'
                ];
            }
        } else {
            $results['failed']++;
            $results['details'][] = [
                'pawn_no' => $pawn['pawn_no'],
                'status' => 'skipped',
                'reason' => 'No platform_user_id or channel_id'
            ];
        }
        
        // Rate limiting - small delay between messages
        usleep(100000); // 100ms
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Pawn overdue reminder completed",
        'results' => $results
    ]);
    
} catch (Exception $e) {
    error_log("Pawn overdue reminder error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
