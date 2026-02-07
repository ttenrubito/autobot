<?php
/**
 * Dashboard Statistics API Endpoint
 * GET /api/dashboard/stats
 * 
 * Business-focused dashboard: Today's metrics, Action Items, Comparisons
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    // Get user's channel IDs for filtering
    $userChannels = $db->query(
        "SELECT id FROM customer_channels WHERE user_id = ? AND is_deleted = 0",
        [$userId]
    );
    $channelIds = array_column($userChannels, 'id');
    
    // If no channels, use user_id filter only
    $hasChannels = !empty($channelIds);
    $channelPlaceholders = $hasChannels ? implode(',', array_fill(0, count($channelIds), '?')) : '0';

    // ========== TODAY'S METRICS ==========
    
    // Today's Revenue (verified payments only) - filter by user_id
    $todayRevenue = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM payments 
         WHERE DATE(created_at) = CURDATE() AND status = 'verified' AND user_id = ?",
        [$userId]
    )['total'] ?? 0;

    // Yesterday's Revenue (for comparison)
    $yesterdayRevenue = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM payments 
         WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status = 'verified' AND user_id = ?",
        [$userId]
    )['total'] ?? 0;

    // Today's Orders
    $todayOrders = $db->queryOne(
        "SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE() AND user_id = ?",
        [$userId]
    )['count'] ?? 0;

    // Yesterday's Orders
    $yesterdayOrders = $db->queryOne(
        "SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND user_id = ?",
        [$userId]
    )['count'] ?? 0;

    // Today's Verified Payments Count
    $todayPaymentsCount = $db->queryOne(
        "SELECT COUNT(*) as count FROM payments 
         WHERE DATE(created_at) = CURDATE() AND status = 'verified' AND user_id = ?",
        [$userId]
    )['count'] ?? 0;

    // ========== ACTION ITEMS (URGENT) ==========
    
    // Pending Slips (need verification) - filter by user_id
    $pendingSlips = $db->queryOne(
        "SELECT COUNT(*) as count FROM payments WHERE status = 'pending' AND user_id = ?",
        [$userId]
    )['count'] ?? 0;

    // Verifying Slips
    $verifyingSlips = $db->queryOne(
        "SELECT COUNT(*) as count FROM payments WHERE status = 'verifying' AND user_id = ?",
        [$userId]
    )['count'] ?? 0;

    // Orders waiting for payment
    $ordersAwaitingPayment = $db->queryOne(
        "SELECT COUNT(*) as count FROM orders WHERE status = 'awaiting_payment' AND user_id = ?",
        [$userId]
    )['count'] ?? 0;

    // Orders confirmed (ready to ship)
    $ordersToShip = $db->queryOne(
        "SELECT COUNT(*) as count FROM orders WHERE status = 'confirmed' AND user_id = ?",
        [$userId]
    )['count'] ?? 0;

    // Open Cases - filter by user_id or channel_id
    $openCases = 0;
    try {
        if ($hasChannels) {
            $openCases = $db->queryOne(
                "SELECT COUNT(*) as count FROM cases WHERE status = 'open' AND (user_id = ? OR channel_id IN ($channelPlaceholders))",
                array_merge([$userId], $channelIds)
            )['count'] ?? 0;
        } else {
            $openCases = $db->queryOne(
                "SELECT COUNT(*) as count FROM cases WHERE status = 'open' AND user_id = ?",
                [$userId]
            )['count'] ?? 0;
        }
    } catch (Exception $e) {
        // Table might not exist
    }

    // ========== USAGE TREND (Last 7 days) ==========
    $usageTrend = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        
        $orderCount = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM orders WHERE DATE(created_at) = ? AND user_id = ?",
            [$date, $userId]
        )['cnt'] ?? 0;
        
        $revenueData = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as amt FROM payments 
             WHERE DATE(created_at) = ? AND status = 'verified' AND user_id = ?",
            [$date, $userId]
        );
        
        $usageTrend[] = [
            'date' => $date,
            'orders' => (int)$orderCount,
            'revenue' => (float)($revenueData['amt'] ?? 0)
        ];
    }

    // ========== PENDING SLIPS LIST (for quick action) ==========
    $pendingSlipsList = $db->query(
        "SELECT 
            p.id,
            p.payment_no,
            p.amount,
            p.status,
            p.payment_method,
            p.created_at,
            COALESCE(cp.display_name, cp.full_name, 'ไม่ระบุ') as customer_name,
            o.order_number
         FROM payments p
         LEFT JOIN customer_profiles cp ON p.customer_id = cp.id
         LEFT JOIN orders o ON p.order_id = o.id
         WHERE p.status IN ('pending', 'verifying') AND p.user_id = ?
         ORDER BY p.created_at ASC
         LIMIT 5",
        [$userId]
    );

    // ========== RECENT ORDERS ==========
    $recentOrders = $db->query(
        "SELECT 
            o.id,
            o.order_number,
            COALESCE(cp.display_name, cp.full_name, 'ไม่ระบุ') as customer_name,
            o.total_amount,
            o.status,
            o.payment_type,
            o.created_at
         FROM orders o
         LEFT JOIN customer_profiles cp ON o.customer_id = cp.id
         WHERE o.user_id = ?
         ORDER BY o.created_at DESC
         LIMIT 5",
        [$userId]
    );

    // ========== WEEKLY SUMMARY ==========
    $thisWeekRevenue = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM payments 
         WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) AND status = 'verified' AND user_id = ?",
        [$userId]
    )['total'] ?? 0;

    $lastWeekRevenue = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM payments 
         WHERE YEARWEEK(created_at, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1) AND status = 'verified' AND user_id = ?",
        [$userId]
    )['total'] ?? 0;

    $thisWeekOrders = $db->queryOne(
        "SELECT COUNT(*) as count FROM orders 
         WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) AND user_id = ?",
        [$userId]
    )['count'] ?? 0;

    Response::success([
        'today' => [
            'revenue' => (float)$todayRevenue,
            'revenue_yesterday' => (float)$yesterdayRevenue,
            'orders' => (int)$todayOrders,
            'orders_yesterday' => (int)$yesterdayOrders,
            'payments_count' => (int)$todayPaymentsCount
        ],
        'action_items' => [
            'pending_slips' => (int)$pendingSlips,
            'verifying_slips' => (int)$verifyingSlips,
            'orders_awaiting_payment' => (int)$ordersAwaitingPayment,
            'orders_to_ship' => (int)$ordersToShip,
            'open_cases' => (int)$openCases
        ],
        'weekly' => [
            'this_week_revenue' => (float)$thisWeekRevenue,
            'last_week_revenue' => (float)$lastWeekRevenue,
            'this_week_orders' => (int)$thisWeekOrders
        ],
        'usage_trend' => $usageTrend,
        'pending_slips_list' => $pendingSlipsList,
        'recent_orders' => $recentOrders
    ]);

} catch (Exception $e) {
    error_log("Dashboard Stats Error: " . $e->getMessage());
    Response::error('Failed to get dashboard statistics', 500);
}
