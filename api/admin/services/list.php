<?php
/**
 * Admin API - List API Services
 * GET /api/admin/services/list
 */

require_once '../../../includes/Database.php';
require_once '../../../includes/JWT.php';
require_once '../../../includes/Response.php';
require_once '../../../includes/AdminAuth.php';

header('Content-Type: application/json');

AdminAuth::require();

try {
    $db = Database::getInstance();
    
    // Get all API services with usage stats
    $services = $db->query(
        "SELECT asc.*,
                COUNT(DISTINCT caa.user_id) as enabled_customers,
                COALESCE(SUM(aul.request_count), 0) as total_requests_today,
                COALESCE(SUM(aul.cost), 0) as total_cost_today
         FROM api_service_config asc
         LEFT JOIN customer_api_access caa ON asc.service_code = caa.service_code AND caa.is_enabled = TRUE
         LEFT JOIN api_usage_logs aul ON aul.api_type = asc.service_code AND DATE(aul.created_at) = CURDATE()
         GROUP BY asc.id
         ORDER BY asc.service_name ASC"
    );
    
    Response::success($services);
    
} catch (Exception $e) {
    error_log("Admin Services List Error: " . $e->getMessage());
    Response::error('Failed to get services', 500);
}
