<?php
/**
 * Service Usage API Endpoint
 * GET /api/services/{id}/usage?period=7d
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    $serviceId = $_GET['id'] ?? null;
    $period = $_GET['period'] ?? '7d';

    if (!$serviceId) {
        Response::error('Service ID is required', 400);
    }

    // Verify service ownership
    $service = $db->queryOne(
        "SELECT id FROM customer_services WHERE id = ? AND user_id = ? LIMIT 1",
        [$serviceId, $userId]
    );

    if (!$service) {
        Response::notFound('Service not found');
    }

    // Calculate date range
    $days = 7;
    if ($period === '24h') $days = 1;
    elseif ($period === '30d') $days = 30;
    elseif ($period === '90d') $days = 90;

    // Get daily usage breakdown
    $usage = $db->query(
        "SELECT DATE(created_at) as date,
                COUNT(*) as bot_messages,
                (SELECT COALESCE(SUM(request_count), 0) FROM api_usage_logs 
                 WHERE customer_service_id = ? AND DATE(created_at) = DATE(bcl.created_at)) as api_calls
         FROM bot_chat_logs bcl
         WHERE customer_service_id = ? 
         AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY DATE(created_at)
         ORDER BY date DESC",
        [$serviceId, $serviceId, $days]
    );

    // Get API breakdown by type
    $apiBreakdown = $db->query(
        "SELECT api_type, 
                SUM(request_count) as total_requests,
                ROUND(AVG(response_time), 2) as avg_response_time,
                SUM(cost) as total_cost
         FROM api_usage_logs
         WHERE customer_service_id = ? 
         AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         GROUP BY api_type",
        [$serviceId, $days]
    );

    // Get recent bot messages
    $recentMessages = $db->query(
        "SELECT direction, message_type, message_content, created_at
         FROM bot_chat_logs
         WHERE customer_service_id = ?
         ORDER BY created_at DESC
         LIMIT 50",
        [$serviceId]
    );

    Response::success([
        'daily_usage' => $usage,
        'api_breakdown' => $apiBreakdown,
        'recent_messages' => $recentMessages
    ]);

} catch (Exception $e) {
    error_log("Service Usage Error: " . $e->getMessage());
    Response::error('Failed to get service usage', 500);
}
