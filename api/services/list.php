<?php
/**
 * List Services API Endpoint
 * GET /api/services/list
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();

    $services = $db->query(
        "SELECT cs.id, cs.service_name, cs.platform, cs.api_key, cs.status, cs.created_at,
                st.name as service_type, st.code as service_code,
                (SELECT COUNT(*) FROM bot_chat_logs WHERE customer_service_id = cs.id 
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as messages_24h,
                (SELECT SUM(request_count) FROM api_usage_logs WHERE customer_service_id = cs.id 
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as api_calls_24h
         FROM customer_services cs
         JOIN service_types st ON cs.service_type_id = st.id
         WHERE cs.user_id = ?
         ORDER BY cs.created_at DESC",
        [$userId]
    );

    Response::success($services);

} catch (Exception $e) {
    error_log("List Services Error: " . $e->getMessage());
    Response::error('Failed to get services list', 500);
}
