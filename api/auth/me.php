<?php
/**
 * Get Current User API Endpoint
 * GET /api/auth/me
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    $user = $db->queryOne(
        "SELECT id, email, full_name, phone, company_name, status, created_at, last_login 
         FROM users 
         WHERE id = ? LIMIT 1",
        [$userId]
    );

    if (!$user) {
        Response::error('User not found', 404);
    }

    // Get active subscription
    $subscription = $db->queryOne(
        "SELECT s.*, sp.name as plan_name, sp.monthly_price 
         FROM subscriptions s 
         JOIN subscription_plans sp ON s.plan_id = sp.id 
         WHERE s.user_id = ? AND s.status = 'active' 
         ORDER BY s.created_at DESC 
         LIMIT 1",
        [$userId]
    );

    $user['subscription'] = $subscription;

    Response::success($user);

} catch (Exception $e) {
    error_log("Get User Error: " . $e->getMessage());
    Response::error('Failed to get user data', 500);
}
