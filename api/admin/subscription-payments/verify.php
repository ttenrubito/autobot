<?php
/**
 * Admin API: Verify/Reject Subscription Payment
 * 
 * POST /api/admin/subscription-payments/verify.php
 * 
 * Actions:
 * - verify: Approve payment and extend subscription
 * - reject: Reject payment with reason
 * 
 * Required: Admin session
 */

define('INCLUDE_CHECK', true);
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Send JSON response
function respond($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Check admin authentication (session-based for admin panel)
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    respond(['success' => false, 'message' => 'Unauthorized: Admin access required'], 403);
}

$adminId = $_SESSION['user_id'];
$adminRole = $_SESSION['role'];

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Parse request
$input = json_decode(file_get_contents('php://input'), true);

$paymentId = isset($input['payment_id']) ? (int)$input['payment_id'] : 0;
$action = $input['action'] ?? ''; // 'verify' or 'reject'
$daysToAdd = isset($input['days_added']) ? (int)$input['days_added'] : 0;
$rejectionReason = $input['rejection_reason'] ?? '';
$notes = $input['notes'] ?? '';

// Validate input
if ($paymentId <= 0) {
    respond(['success' => false, 'message' => 'Invalid payment_id'], 400);
}

if (!in_array($action, ['verify', 'reject'])) {
    respond(['success' => false, 'message' => 'Invalid action (must be "verify" or "reject")'], 400);
}

if ($action === 'verify' && $daysToAdd <= 0) {
    respond(['success' => false, 'message' => 'days_added is required for verify action'], 400);
}

if ($action === 'reject' && empty($rejectionReason)) {
    respond(['success' => false, 'message' => 'rejection_reason is required for reject action'], 400);
}

try {
    $db = Database::getInstance();
    
    // Get payment record
    $payment = $db->queryOne(
        "SELECT sp.*, u.email as user_email, u.name as user_name
         FROM subscription_payments sp
         JOIN users u ON u.id = sp.user_id
         WHERE sp.id = ?",
        [$paymentId]
    );
    
    if (!$payment) {
        respond(['success' => false, 'message' => 'Payment not found'], 404);
    }
    
    if ($payment['status'] !== 'pending') {
        respond([
            'success' => false, 
            'message' => 'Payment already processed (status: ' . $payment['status'] . ')'
        ], 400);
    }
    
    $userId = $payment['user_id'];
    
    if ($action === 'verify') {
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Update payment record
            $db->execute(
                "UPDATE subscription_payments 
                 SET status = 'verified',
                     days_added = ?,
                     verified_by = ?,
                     verified_at = NOW(),
                     notes = ?
                 WHERE id = ?",
                [$daysToAdd, $adminId, $notes, $paymentId]
            );
            
            // Get current subscription
            $subscription = $db->queryOne(
                "SELECT id, current_period_end, status
                 FROM subscriptions
                 WHERE user_id = ?
                 ORDER BY id DESC
                 LIMIT 1",
                [$userId]
            );
            
            if ($subscription) {
                // Calculate new end date
                $currentEnd = $subscription['current_period_end'];
                $today = date('Y-m-d');
                
                // If subscription expired, start from today
                $baseDate = ($currentEnd < $today) ? $today : $currentEnd;
                $newEndDate = date('Y-m-d', strtotime($baseDate . ' + ' . $daysToAdd . ' days'));
                
                // Update subscription
                $db->execute(
                    "UPDATE subscriptions 
                     SET current_period_end = ?,
                         status = 'active',
                         updated_at = NOW()
                     WHERE id = ?",
                    [$newEndDate, $subscription['id']]
                );
                
                Logger::info('[VerifyPayment] Subscription extended', [
                    'payment_id' => $paymentId,
                    'user_id' => $userId,
                    'days_added' => $daysToAdd,
                    'old_end' => $currentEnd,
                    'new_end' => $newEndDate,
                    'verified_by' => $adminId
                ]);
            } else {
                // No subscription exists - create one with default plan
                $defaultPlanId = 1; // Standard plan
                
                $db->execute(
                    "INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end, created_at)
                     VALUES (?, ?, 'active', CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY), NOW())",
                    [$userId, $defaultPlanId, $daysToAdd]
                );
                
                Logger::info('[VerifyPayment] New subscription created', [
                    'payment_id' => $paymentId,
                    'user_id' => $userId,
                    'days_added' => $daysToAdd,
                    'verified_by' => $adminId
                ]);
            }
            
            $db->commit();
            
            respond([
                'success' => true,
                'message' => 'Payment verified and subscription extended',
                'data' => [
                    'payment_id' => $paymentId,
                    'user_id' => $userId,
                    'days_added' => $daysToAdd,
                    'new_end_date' => $newEndDate ?? date('Y-m-d', strtotime('+' . $daysToAdd . ' days'))
                ]
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } else {
        // Reject payment
        $db->execute(
            "UPDATE subscription_payments 
             SET status = 'rejected',
                 rejection_reason = ?,
                 verified_by = ?,
                 verified_at = NOW(),
                 notes = ?
             WHERE id = ?",
            [$rejectionReason, $adminId, $notes, $paymentId]
        );
        
        Logger::info('[VerifyPayment] Payment rejected', [
            'payment_id' => $paymentId,
            'user_id' => $userId,
            'reason' => $rejectionReason,
            'rejected_by' => $adminId
        ]);
        
        respond([
            'success' => true,
            'message' => 'Payment rejected',
            'data' => [
                'payment_id' => $paymentId,
                'user_id' => $userId,
                'rejection_reason' => $rejectionReason
            ]
        ]);
    }
    
} catch (Exception $e) {
    Logger::error('[VerifyPayment] Error', [
        'error' => $e->getMessage(),
        'payment_id' => $paymentId ?? null
    ]);
    
    respond([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], 500);
}
