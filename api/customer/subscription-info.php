<?php
/**
 * Customer API: Get Subscription Info
 * Returns current subscription details for display on payment page
 */

define('INCLUDE_CHECK', true);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// 1) Check customer authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Login required'
    ]);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $db = getDB();
    
    // 2) Get active subscription with plan details
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.plan_id,
            s.status,
            s.current_period_start,
            s.current_period_end,
            s.created_at,
            p.name as plan_name,
            p.description as plan_description,
            p.monthly_price,
            p.included_requests,
            p.features
        FROM subscriptions s
        LEFT JOIN subscription_plans p ON s.plan_id = p.id
        WHERE s.user_id = ?
        ORDER BY s.id DESC
        LIMIT 1
    ");
    
    $stmt->execute([$userId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sub) {
        echo json_encode([
            'success' => false,
            'message' => 'No subscription found'
        ]);
        exit;
    }
    
    // 3) Calculate days remaining
    $endDate = new DateTime($sub['current_period_end']);
    $today = new DateTime();
    $interval = $today->diff($endDate);
    $daysRemaining = $interval->days * ($interval->invert ? -1 : 1); // negative if expired
    
    // 4) Parse features JSON
    $features = [];
    if (!empty($sub['features'])) {
        $features = json_decode($sub['features'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $features = [];
        }
    }
    
    // 5) Return subscription data
    echo json_encode([
        'success' => true,
        'subscription' => [
            'id' => (int)$sub['id'],
            'status' => $sub['status'],
            'current_period_start' => $sub['current_period_start'],
            'current_period_end' => $sub['current_period_end'],
            'days_remaining' => $daysRemaining,
            'created_at' => $sub['created_at']
        ],
        'plan' => [
            'id' => (int)$sub['plan_id'],
            'name' => $sub['plan_name'],
            'description' => $sub['plan_description'],
            'monthly_price' => (float)$sub['monthly_price'],
            'included_requests' => (int)$sub['included_requests'],
            'features' => $features
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Subscription info error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}
