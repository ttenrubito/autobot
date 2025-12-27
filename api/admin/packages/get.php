<?php
/**
 * Admin API - Get Single Package
 * GET /api/admin/packages/get.php?id={id}
 */

require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/JWT.php';
require_once __DIR__ . '/../../../includes/Response.php';
require_once __DIR__ . '/../../../includes/AdminAuth.php';

header('Content-Type: application/json');

AdminAuth::require();

try {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        Response::error('Package ID is required', 400);
    }
    
    $db = Database::getInstance();
    
    // Get package details
    $package = $db->queryOne(
        "SELECT * FROM subscription_plans WHERE id = ?",
        [$id]
    );
    
    if (!$package) {
        Response::error('Package not found', 404);
    }
    
    // Format features JSON
    if ($package['features']) {
        $package['features'] = json_decode($package['features'], true);
    } else {
        $package['features'] = [];
    }
    
    // Convert to proper types
    $package['id'] = (int)$package['id'];
    $package['monthly_price'] = (float)$package['monthly_price'];
    $package['included_requests'] = $package['included_requests'] ? (int)$package['included_requests'] : null;
    $package['overage_rate'] = $package['overage_rate'] ? (float)$package['overage_rate'] : null;
    $package['is_active'] = (bool)$package['is_active'];
    
    Response::success($package);
    
} catch (Exception $e) {
    error_log("Admin Get Package Error: " . $e->getMessage());
    Response::error('Failed to get package', 500);
}
