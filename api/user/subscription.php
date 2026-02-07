<?php
/**
 * Get user subscription info
 * Separated API for subscription badge in sidebar
 */
header('Content-Type: application/json');

// Suppress display errors
ini_set('display_errors', '0');

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Auth.php';

// Require authentication
Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    // Get active subscription
    $subscription = $db->queryOne("
        SELECT s.id, s.status, s.current_period_start, s.current_period_end, 
               sp.name as plan_name
        FROM subscriptions s
        JOIN subscription_plans sp ON s.plan_id = sp.id
        WHERE s.user_id = ? AND s.status IN ('active', 'trial')
        ORDER BY s.current_period_end DESC
        LIMIT 1
    ", [$userId]);
    
    if ($subscription) {
        Response::success([
            'has_subscription' => true,
            'subscription' => $subscription
        ]);
    } else {
        Response::success([
            'has_subscription' => false,
            'subscription' => null
        ]);
    }
    
} catch (Exception $e) {
    error_log('Subscription API error: ' . $e->getMessage());
    Response::error('Server error', 500);
}
