<?php
/**
 * Admin Dashboard Statistics API Endpoint
 * GET /api/admin/dashboard/stats
 */

require_once __DIR__ . '/../../../includes/AdminAuth.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Response.php';

// Require admin authentication (Auth classes already loaded by router)
AdminAuth::require();

try {
    $db = Database::getInstance();
    
    // 1. Total Customers
    $totalCustomers = $db->queryOne(
        "SELECT COUNT(*) as count FROM users WHERE status = 'active'"
    )['count'];
    
    // 2. Total Active Services
    $totalServices = $db->queryOne(
        "SELECT COUNT(*) as count FROM customer_services WHERE status = 'active'"
    )['count'];
    
    // 3. Monthly Revenue (paid invoices this month)
    $monthlyRevenue = $db->queryOne(
        "SELECT COALESCE(SUM(total), 0) as total 
         FROM invoices 
         WHERE status = 'paid'
         AND MONTH(paid_at) = MONTH(CURDATE())
         AND YEAR(paid_at) = YEAR(CURDATE())"
    )['total'];
    
    // 4. API Calls Today
    $todayRequests = $db->queryOne(
        "SELECT COALESCE(SUM(request_count), 0) as total 
         FROM api_usage_logs 
         WHERE DATE(created_at) = CURDATE()"
    )['total'];
    
    // 5. Recent Customers (last 10)
    $recentCustomers = $db->query(
        "SELECT 
            u.id,
            u.full_name,
            u.email,
            u.created_at,
            u.status,
            p.name as package_name,
            s.status as subscription_status
         FROM users u
         LEFT JOIN subscriptions s ON u.id = s.user_id AND s.status = 'active'
         LEFT JOIN subscription_plans p ON s.plan_id = p.id
         ORDER BY u.created_at DESC
         LIMIT 10"
    );
    
    // 6. Due Subscriptions (for billing button awareness)
    $dueSubscriptions = $db->queryOne(
        "SELECT COUNT(*) as count 
         FROM subscriptions 
         WHERE status = 'active' 
         AND next_billing_date <= CURDATE()"
    )['count']  ?? 0;
    
    // 7. Active Subscriptions
    $activeSubscriptions = $db->queryOne(
        "SELECT COUNT(*) as count 
         FROM subscriptions 
         WHERE status = 'active'"
    )['count'];
    
    // 8. System Notifications
    $notifications = [];
    
    // Check for due billing
    if ($dueSubscriptions > 0) {
        $notifications[] = [
            'type' => 'warning',
            'message' => "มี {$dueSubscriptions} รอบบิลที่ครบกำหนดแล้ว",
            'action' => 'กดปุ่ม Manual Billing เพื่อประมวลผล',
            'icon' => 'fa-dollar-sign'
        ];
    }
    
    // Check for paused subscriptions (payment failed)
    $pausedSubs = $db->queryOne(
        "SELECT COUNT(*) as count FROM subscriptions WHERE status = 'paused'"
    )['count'];
    
    if ($pausedSubs > 0) {
        $notifications[] = [
            'type' => 'danger',
            'message' => "มี {$pausedSubs} subscription ที่ถูก pause",
            'action' => 'ตรวจสอบปัญหาการชำระเงิน',
            'icon' => 'fa-exclamation-triangle'
        ];
    }
    
    Response::success([
        'overview' => [
            'total_customers' => (int)$totalCustomers,
            'total_services' => (int)$totalServices,
            'monthly_revenue' => (float)$monthlyRevenue,
            'today_requests' => (int)$todayRequests,
            'active_subscriptions' => (int)$activeSubscriptions,
            'due_subscriptions' => (int)$dueSubscriptions
        ],
        'recent_customers' => $recentCustomers,
        'notifications' => $notifications
    ]);
    
} catch (Exception $e) {
    error_log("Admin Dashboard Stats Error: " . $e->getMessage());
    Response::error('Failed to get dashboard statistics', 500);
}
?>
