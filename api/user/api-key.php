<?php
/**
 * User API Key Management
 * GET /api/user/api-key
 */

require_once '../../includes/Database.php';
require_once '../../includes/Auth.php';
require_once '../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    // Get user's API key
    $apiKey = $db->queryOne(
        "SELECT api_key, name, is_active, last_used_at, created_at, expires_at
         FROM api_keys
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 1",
        [$userId]
    );
    
    // Get enabled services for this user
    $services = $db->query(
        "SELECT caa.service_code, asc.service_name, caa.is_enabled, caa.daily_limit, caa.monthly_limit
         FROM customer_api_access caa
         JOIN api_service_config asc ON caa.service_code = asc.service_code
         WHERE caa.user_id = ?
         ORDER BY asc.service_name",
        [$userId]
    );
    
    Response::success([
        'api_key' => $apiKey,
        'services' => $services
    ]);
    
} catch (Exception $e) {
    error_log("Get API Key Error: " . $e->getMessage());
    Response::error('Failed to get API key', 500);
}
