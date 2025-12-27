<?php
/**
 * Admin API - Toggle API Service
 * POST /api/admin/services/toggle
 */

require_once '../../../includes/Database.php';
require_once '../../../includes/JWT.php';
require_once '../../../includes/Response.php';
require_once '../../../includes/AdminAuth.php';

header('Content-Type: application/json');

AdminAuth::require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $serviceId = $data['service_id'] ?? null;
    $isEnabled = isset($data['is_enabled']) ? (bool)$data['is_enabled'] : null;
    
    if (!$serviceId || $isEnabled === null) {
        Response::error('Service ID and is_enabled are required', 400);
    }
    
    $db = Database::getInstance();
    
    // Update service status
    $result = $db->execute(
        "UPDATE api_service_config SET is_enabled = ? WHERE id = ?",
        [$isEnabled ? 1 : 0, $serviceId]
    );
    
    if ($result) {
        Response::success([
            'service_id' => $serviceId,
            'is_enabled' => $isEnabled
        ], 'Service status updated');
    } else {
        Response::error('Service not found', 404);
    }
    
} catch (Exception $e) {
    error_log("Admin Service Toggle Error: " . $e->getMessage());
    Response::error('Failed to toggle service', 500);
}
