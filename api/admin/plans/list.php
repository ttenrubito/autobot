<?php
/**
 * Admin API - List Subscription Plans
 * GET /api/admin/plans/list
 */

require_once '../../../includes/Database.php';
require_once '../../../includes/JWT.php';
require_once '../../../includes/Response.php';
require_once '../../../includes/AdminAuth.php';

header('Content-Type: application/json');

AdminAuth::require();

try {
    $db = Database::getInstance();
    
    // Get all subscription plans with customer count
    $plans = $db->query(
        "SELECT sp.*,
                COUNT(DISTINCT s.user_id) as active_customers,
                SUM(CASE WHEN s.status = 'active' THEN s.monthly_price ELSE 0 END) as monthly_revenue
         FROM subscription_plans sp
         LEFT JOIN subscriptions s ON sp.id = s.plan_id
         GROUP BY sp.id
         ORDER BY sp.monthly_price ASC"
    );
    
    Response::success($plans);
    
} catch (Exception $e) {
    error_log("Admin Plans List Error: " . $e->getMessage());
    Response::error('Failed to get plans', 500);
}
