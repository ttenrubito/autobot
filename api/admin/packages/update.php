<?php
/**
 * Admin API - Update Package
 * PUT /api/admin/packages/update.php?id={id}
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
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    $db = Database::getInstance();
    
    // Check if package exists
    $exists = $db->queryOne("SELECT id FROM subscription_plans WHERE id = ?", [$id]);
    if (!$exists) {
        Response::error('Package not found', 404);
    }
    
    // Build update query dynamically based on provided fields
    $updates = [];
    $params = [];
    
    if (isset($input['name'])) {
        $updates[] = "name = ?";
        $params[] = $input['name'];
    }
    
    if (isset($input['description'])) {
        $updates[] = "description = ?";
        $params[] = $input['description'];
    }
    
    if (isset($input['monthly_price'])) {
        if ($input['monthly_price'] < 0) {
            Response::error('Monthly price must be positive', 400);
        }
        $updates[] = "monthly_price = ?";
        $params[] = $input['monthly_price'];
    }
    
    // New: billing period days
    if (isset($input['billing_period_days'])) {
        $days = (int)$input['billing_period_days'];
        if ($days <= 0) {
            $days = 30;
        }
        $updates[] = "billing_period_days = ?";
        $params[] = $days;
    }
    
    if (isset($input['included_requests'])) {
        $updates[] = "included_requests = ?";
        $params[] = $input['included_requests'];
    }
    
    if (isset($input['overage_rate'])) {
        $updates[] = "overage_rate = ?";
        $params[] = $input['overage_rate'];
    }
    
    if (isset($input['features'])) {
        $updates[] = "features = ?";
        $params[] = json_encode($input['features']);
    }
    
    if (isset($input['is_active'])) {
        $updates[] = "is_active = ?";
        $params[] = (bool)$input['is_active'];
    }
    
    if (empty($updates)) {
        Response::error('No fields to update', 400);
    }
    
    // Add ID to params for WHERE clause
    $params[] = $id;
    
    // Execute update
    $db->execute(
        "UPDATE subscription_plans SET " . implode(', ', $updates) . " WHERE id = ?",
        $params
    );
    
    Response::success([
        'message' => 'Package updated successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Admin Update Package Error: " . $e->getMessage());
    Response::error('Failed to update package: ' . $e->getMessage(), 500);
}
