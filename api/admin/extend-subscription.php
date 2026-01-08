<?php
/**
 * Admin API: Extend Customer Subscription
 * Adds days to subscription period without creating invoices
 * Temporary solution until Omise payment integration is ready
 */

define('INCLUDE_CHECK', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// 1) Check admin authentication
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Admin access required'
    ]);
    exit;
}

// 2) Validate method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

// 3) Parse request
$input = json_decode(file_get_contents('php://input'), true);
$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$addDays = isset($input['add_days']) ? (int)$input['add_days'] : 0;

// 4) Validate input
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid user_id'
    ]);
    exit;
}

if ($addDays <= 0 || $addDays > 3650) { // Max 10 years
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid add_days (must be 1-3650)'
    ]);
    exit;
}

try {
    $db = getDbConnection();
    
    // 5) Get current subscription
    $stmt = $db->prepare("
        SELECT id, current_period_end, status
        FROM subscriptions
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$subscription) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'No subscription found for this user'
        ]);
        exit;
    }
    
    // 6) Calculate new end date
    $currentEnd = $subscription['current_period_end'];
    $updateStmt = $db->prepare("
        UPDATE subscriptions
        SET current_period_end = DATE_ADD(current_period_end, INTERVAL ? DAY),
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$addDays, $subscription['id']]);
    
    // 7) Get updated subscription
    $stmt->execute([$userId]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 8) Calculate days remaining
    $daysRemaining = (new DateTime($updated['current_period_end']))->diff(new DateTime())->days;
    
    // 9) Log activity
    $logStmt = $db->prepare("
        INSERT INTO activity_logs (user_id, action, resource_type, resource_id, details, ip_address)
        VALUES (?, 'extend_subscription', 'subscription', ?, ?, ?)
    ");
    $logStmt->execute([
        $_SESSION['user_id'], // Admin user who made the change
        $subscription['id'],
        json_encode([
            'target_user_id' => $userId,
            'days_added' => $addDays,
            'old_end_date' => $currentEnd,
            'new_end_date' => $updated['current_period_end']
        ]),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // 10) Return success
    echo json_encode([
        'success' => true,
        'message' => "เพิ่มวันใช้งานสำเร็จ +{$addDays} วัน",
        'data' => [
            'old_end_date' => $currentEnd,
            'new_end_date' => $updated['current_period_end'],
            'days_remaining' => $daysRemaining,
            'status' => $updated['status']
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Extend subscription error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}
