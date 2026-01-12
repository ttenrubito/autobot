<?php
/**
 * Migration: Add tenant_id to users table
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$results = [];

try {
    $pdo = getDB();
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'");
    $exists = $stmt->rowCount() > 0;
    
    if ($exists) {
        $results['tenant_id'] = 'already exists';
    } else {
        // Add the column
        $pdo->exec("ALTER TABLE users ADD COLUMN tenant_id VARCHAR(50) DEFAULT 'default' AFTER id");
        $results['tenant_id'] = 'added successfully';
        
        // Update existing users to have 'default' tenant_id
        $pdo->exec("UPDATE users SET tenant_id = 'default' WHERE tenant_id IS NULL");
        $results['updated_existing'] = 'set to default';
    }
    
    echo json_encode([
        'success' => true,
        'results' => $results
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
