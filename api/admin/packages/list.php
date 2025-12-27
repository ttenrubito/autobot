<?php
/**
 * Admin API - List Subscription Packages
 * GET /api/admin/packages/list.php
 */

require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/JWT.php';
require_once __DIR__ . '/../../../includes/Response.php';
require_once __DIR__ . '/../../../includes/AdminAuth.php';

header('Content-Type: application/json');

AdminAuth::require();

try {
    $db = Database::getInstance();
    
    // Get all subscription plans with customer count and revenue
    $packages = $db->query(
        "SELECT sp.*,
                COUNT(DISTINCT s.user_id) as active_customers,
                SUM(CASE WHEN s.status = 'active' THEN sp.monthly_price ELSE 0 END) as monthly_revenue
         FROM subscription_plans sp
         LEFT JOIN subscriptions s ON sp.id = s.plan_id AND s.status = 'active'
         GROUP BY sp.id
         ORDER BY sp.monthly_price ASC"
    );
    
    // Format features JSON for each package
    foreach ($packages as &$package) {
        if ($package['features']) {
            $package['features'] = json_decode($package['features'], true);
        } else {
            $package['features'] = [];
        }
        
        // Convert to proper types
        $package['id'] = (int)$package['id'];
        $package['monthly_price'] = (float)$package['monthly_price'];
        $package['included_requests'] = $package['included_requests'] ? (int)$package['included_requests'] : null;
        $package['overage_rate'] = $package['overage_rate'] ? (float)$package['overage_rate'] : null;
        // New: billing period days (int, default 30 if null)
        $package['billing_period_days'] = isset($package['billing_period_days']) && $package['billing_period_days']
            ? (int)$package['billing_period_days']
            : 30;
        $package['is_active'] = (bool)$package['is_active'];
        $package['active_customers'] = (int)$package['active_customers'];
        $package['monthly_revenue'] = (float)$package['monthly_revenue'];
    }
    
    Response::success($packages);
    
} catch (Exception $e) {
    error_log("Admin Packages List Error: " . $e->getMessage());
    Response::error('Failed to get packages', 500);
}
