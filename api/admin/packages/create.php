<?php
/**
 * Admin API - Create New Package
 * POST /api/admin/packages/create.php
 */

require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/JWT.php';
require_once __DIR__ . '/../../../includes/Response.php';
require_once __DIR__ . '/../../../includes/AdminAuth.php';

header('Content-Type: application/json');

AdminAuth::require();

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $name = $input['name'] ?? null;
    $monthly_price = $input['monthly_price'] ?? null;
    
    if (!$name || $monthly_price === null) {
        Response::error('Name and monthly price are required', 400);
    }
    
    // Extract and validate fields
    $description = $input['description'] ?? null;
    $included_requests = $input['included_requests'] ?? null;
    $overage_rate = $input['overage_rate'] ?? null;
    $features = $input['features'] ?? [];
    $is_active = isset($input['is_active']) ? (bool)$input['is_active'] : true;
    // New: billing period in days (default 30)
    $billing_period_days = isset($input['billing_period_days']) ? (int)$input['billing_period_days'] : 30;
    if ($billing_period_days <= 0) {
        $billing_period_days = 30;
    }
    
    // Validate price is positive
    if ($monthly_price < 0) {
        Response::error('Monthly price must be positive', 400);
    }
    
    // Convert features array to JSON string
    $features_json = json_encode($features);
    
    $db = Database::getInstance();
    
    // Insert package (with billing_period_days column)
    $db->execute(
        "INSERT INTO subscription_plans 
         (name, description, monthly_price, billing_period_days, included_requests, overage_rate, features, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [
            $name,
            $description,
            $monthly_price,
            $billing_period_days,
            $included_requests,
            $overage_rate,
            $features_json,
            $is_active
        ]
    );
    
    $packageId = $db->lastInsertId();
    
    Response::success([
        'id' => $packageId,
        'message' => 'Package created successfully'
    ], 201);
    
} catch (Exception $e) {
    error_log("Admin Create Package Error: " . $e->getMessage());
    Response::error('Failed to create package: ' . $e->getMessage(), 500);
}
