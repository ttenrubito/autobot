<?php
/**
 * Dashboard Statistics API Endpoint
 * GET /api/dashboard/stats
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();

    // Get total active services
    $totalServices = $db->queryOne(
        "SELECT COUNT(*) as count FROM customer_services WHERE user_id = ? AND status = 'active'",
        [$userId]
    )['count'];

    // Get total bot messages today
    $botMessagesToday = $db->queryOne(
        "SELECT COUNT(*) as count 
         FROM bot_chat_logs bcl
         JOIN customer_services cs ON bcl.customer_service_id = cs.id
         WHERE cs.user_id = ? AND DATE(bcl.created_at) = CURDATE()",
        [$userId]
    )['count'];

    // Get total API calls today
    $apiCallsToday = $db->queryOne(
        "SELECT SUM(request_count) as total 
         FROM api_usage_logs aul
         JOIN customer_services cs ON aul.customer_service_id = cs.id
         WHERE cs.user_id = ? AND DATE(aul.created_at) = CURDATE()",
        [$userId]
    )['total'] ?? 0;

    // Get usage trend (last 7 days)
    $usageTrend = $db->query(
        "SELECT d.date, 
                COALESCE(SUM(aul.request_count), 0) as api_calls,
                COALESCE((SELECT COUNT(*) FROM bot_chat_logs bcl2 
                 JOIN customer_services cs2 ON bcl2.customer_service_id = cs2.id
                 WHERE cs2.user_id = ? AND DATE(bcl2.created_at) = d.date), 0) as bot_messages
         FROM (
             SELECT DISTINCT DATE(aul.created_at) as date
             FROM api_usage_logs aul
             JOIN customer_services cs ON aul.customer_service_id = cs.id
             WHERE cs.user_id = ? AND aul.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
         ) d
         LEFT JOIN api_usage_logs aul ON DATE(aul.created_at) = d.date
         LEFT JOIN customer_services cs ON aul.customer_service_id = cs.id AND cs.user_id = ?
         GROUP BY d.date
         ORDER BY d.date DESC",
        [$userId, $userId, $userId]
    );

    // Get recent activities
    $recentActivities = $db->query(
        "SELECT action, resource_type, created_at 
         FROM activity_logs 
         WHERE user_id = ? 
         ORDER BY created_at DESC 
         LIMIT 10",
        [$userId]
    );

    // Get service breakdown
    $serviceBreakdown = $db->query(
        "SELECT cs.service_name, st.name as service_type, cs.platform, cs.status,
                (SELECT COUNT(*) FROM bot_chat_logs WHERE customer_service_id = cs.id 
                 AND DATE(created_at) = CURDATE()) as today_messages,
                (SELECT SUM(request_count) FROM api_usage_logs WHERE customer_service_id = cs.id 
                 AND DATE(created_at) = CURDATE()) as today_api_calls
         FROM customer_services cs
         JOIN service_types st ON cs.service_type_id = st.id
         WHERE cs.user_id = ?
         ORDER BY cs.created_at DESC",
        [$userId]
    );

    Response::success([
        'overview' => [
            'total_services' => (int)$totalServices,
            'bot_messages_today' => (int)$botMessagesToday,
            'api_calls_today' => (int)$apiCallsToday
        ],
        'usage_trend' => $usageTrend,
        'recent_activities' => $recentActivities,
        'service_breakdown' => $serviceBreakdown
    ]);

} catch (Exception $e) {
    error_log("Dashboard Stats Error: " . $e->getMessage());
    Response::error('Failed to get dashboard statistics', 500);
}
