<?php
/**
 * Service Details API Endpoint
 * GET /api/services/details?id=:id
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    $serviceId = $_GET['id'] ?? null;

    if (!$serviceId) {
        Response::error('Service ID is required', 400);
    }

    // Get service details
    $service = $db->queryOne(
        "SELECT cs.*, st.name as service_type, st.code as service_code, st.billing_unit
         FROM customer_services cs
         JOIN service_types st ON cs.service_type_id = st.id
         WHERE cs.id = ? AND cs.user_id = ?
         LIMIT 1",
        [$serviceId, $userId]
    );

    if (!$service) {
        Response::notFound('Service not found');
    }

    // Get usage statistics
    $stats = $db->queryOne(
        "SELECT 
            (SELECT COUNT(*) FROM bot_chat_logs WHERE customer_service_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as messages_24h,
            (SELECT COUNT(*) FROM bot_chat_logs WHERE customer_service_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as messages_7d,
            (SELECT COUNT(*) FROM bot_chat_logs WHERE customer_service_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as messages_30d,
            (SELECT SUM(request_count) FROM api_usage_logs WHERE customer_service_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as api_calls_24h,
            (SELECT SUM(request_count) FROM api_usage_logs WHERE customer_service_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as api_calls_7d,
            (SELECT SUM(request_count) FROM api_usage_logs WHERE customer_service_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as api_calls_30d",
        [$serviceId, $serviceId, $serviceId, $serviceId, $serviceId, $serviceId]
    );

    $service['statistics'] = $stats;

    Response::success($service);

} catch (Exception $e) {
    error_log("Service Details Error: " . $e->getMessage());
    Response::error('Failed to get service details', 500);
}
