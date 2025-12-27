<?php
/**
 * Admin API - Delete Package (Soft Delete)
 * DELETE /api/admin/packages/delete.php?id={id}
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
    
    // Check if package exists
    $package = $db->queryOne("SELECT id, name FROM subscription_plans WHERE id = ?", [$id]);
    if (!$package) {
        Response::error('Package not found', 404);
    }
    
    // Check if package is in use
    $inUse = $db->queryOne(
        "SELECT COUNT(*) as count FROM subscriptions WHERE plan_id = ? AND status = 'active'",
        [$id]
    );
    
    if ($inUse['count'] > 0) {
        // Soft delete - set is_active to false
        $db->execute(
            "UPDATE subscription_plans SET is_active = 0 WHERE id = ?",
            [$id]
        );
        
        Response::success([
            'message' => 'Package deactivated (still in use by ' . $inUse['count'] . ' customer(s))',
            'deactivated' => true
        ]);
    } else {
        // Hard delete if not in use
        $db->execute(
            "DELETE FROM subscription_plans WHERE id = ?",
            [$id]
        );
        
        Response::success([
            'message' => 'Package deleted successfully',
            'deleted' => true
        ]);
    }
    
} catch (Exception $e) {
    error_log("Admin Delete Package Error: " . $e->getMessage());
    Response::error('Failed to delete package: ' . $e->getMessage(), 500);
}
