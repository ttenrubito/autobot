<?php
/**
 * Migration: Add tenant_id to orders table
 * 
 * This is needed for multi-tenant support where each shop/tenant
 * has their own orders that should be isolated from other tenants
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$results = [];

try {
    $pdo = getDB();
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'tenant_id'");
    $exists = $stmt->rowCount() > 0;
    
    if ($exists) {
        $results['tenant_id'] = 'already exists';
        $results['action'] = 'none';
    } else {
        // Add the column
        $pdo->exec("ALTER TABLE orders ADD COLUMN tenant_id VARCHAR(50) DEFAULT 'default' AFTER user_id");
        $results['tenant_id'] = 'added successfully';
        
        // Backfill: Set tenant_id from user's tenant_id
        $stmt = $pdo->query("
            UPDATE orders o 
            INNER JOIN users u ON o.user_id = u.id 
            SET o.tenant_id = COALESCE(u.tenant_id, 'default')
            WHERE o.tenant_id IS NULL OR o.tenant_id = 'default'
        ");
        $results['backfilled'] = $stmt->rowCount() . ' rows updated';
        
        // Add index for performance
        $pdo->exec("ALTER TABLE orders ADD INDEX idx_orders_tenant_id (tenant_id)");
        $results['index'] = 'created';
    }
    
    echo json_encode([
        'success' => true,
        'message' => $exists ? 'tenant_id column already exists in orders table' : 'tenant_id column added to orders table',
        'results' => $results
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
