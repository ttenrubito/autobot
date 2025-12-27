<?php
/**
 * Cancel Subscription API
 * POST /api/payment/cancel-subscription.php
 * 
 * Purpose: ยกเลิก subscription (ยังใช้งานได้จนถึงสิ้นรอบปัจจุบัน)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/Database.php';
    
    // Verify user authentication
    $token = null;
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }
    
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $tokenData = json_decode(base64_decode($token), true);
    if (!$tokenData || !isset($tokenData['user_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }
    
    $user_id = $tokenData['user_id'];
    $db = Database::getInstance();
    
    // Get active subscription
    $subscription = $db->queryOne(
        "SELECT s.*, sp.name as plan_name
         FROM subscriptions s
         JOIN subscription_plans sp ON s.plan_id = sp.id
         WHERE s.user_id = ? AND s.status IN ('trial', 'active')
         LIMIT 1",
        [$user_id]
    );
    
    if (!$subscription) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No active subscription found']);
        exit;
    }
    
    // Cancel subscription (turn off auto-renew, but keep active until period end)
    $db->execute(
        "UPDATE subscriptions 
         SET auto_renew = FALSE,
             cancelled_at = NOW(),
             updated_at = NOW()
         WHERE id = ?",
        [$subscription['id']]
    );
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Subscription ถูกยกเลิก คุณยังสามารถใช้งานได้จนถึง ' . $subscription['current_period_end'],
        'data' => [
            'plan_name' => $subscription['plan_name'],
            'access_until' => $subscription['current_period_end']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Cancel Subscription Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
