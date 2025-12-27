<?php
/**
 * Admin Dashboard Statistics API
 * GET /api/admin/stats.php
 * 
 * Returns statistics for admin dashboard:
 * - Total customers (active, suspended)
 * - Active services count
 * - Monthly revenue
 * - Today's API calls
 * - Recent customers
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/Database.php';
    require_once __DIR__ . '/../../includes/AdminAuth.php';

    // Use shared admin auth (JWT-based)
    AdminAuth::require();

    $db = Database::getInstance();
    
    // 1. Total Customers
    $totalCustomers = $db->queryOne(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
         FROM users"
    );
    
    // 2. Active Services Count
    $activeServices = $db->queryOne(
        "SELECT COUNT(*) as count FROM customer_services WHERE status = 'active'"
    );
    
    // 3. Monthly Revenue (current month)
    $monthlyRevenue = $db->queryOne(
        "SELECT COALESCE(SUM(total), 0) as revenue
         FROM invoices
         WHERE status = 'paid'
         AND MONTH(paid_at) = MONTH(CURRENT_DATE())
         AND YEAR(paid_at) = YEAR(CURRENT_DATE())"
    );
    
    // 4. Today's API Calls
    $todayApiCalls = $db->queryOne(
        "SELECT COALESCE(SUM(request_count), 0) as count
         FROM api_usage_logs
         WHERE DATE(created_at) = CURRENT_DATE()"
    );
    
    // 5. Recent Customers (last 10)
    $recentCustomers = $db->query(
        "SELECT 
            u.id,
            u.email,
            u.full_name,
            u.company_name,
            u.status,
            u.created_at,
            sp.name as plan_name,
            (SELECT COUNT(*) FROM customer_services WHERE user_id = u.id) as services_count
         FROM users u
         LEFT JOIN subscriptions s ON u.id = s.user_id AND s.status = 'active'
         LEFT JOIN subscription_plans sp ON s.plan_id = sp.id
         ORDER BY u.created_at DESC
         LIMIT 10"
    );
    
    // 6. Pending Invoices Count
    $pendingInvoices = $db->queryOne(
        "SELECT COUNT(*) as count FROM invoices WHERE status = 'pending'"
    );
    
    // Format response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'totalCustomers' => (int)$totalCustomers['total'],
            'activeCustomers' => (int)$totalCustomers['active'],
            'suspendedCustomers' => (int)$totalCustomers['suspended'],
            'activeServices' => (int)$activeServices['count'],
            'monthlyRevenue' => number_format((float)$monthlyRevenue['revenue'], 2),
            'todayApiCalls' => (int)$todayApiCalls['count'],
            'pendingInvoices' => (int)$pendingInvoices['count'],
            'recentCustomers' => $recentCustomers
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Admin Stats Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fetching statistics: ' . $e->getMessage()]);
}
