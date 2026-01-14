<?php
/**
 * Debug: Check orders table columns
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();
    
    // Get columns
    $stmt = $pdo->query("SHOW COLUMNS FROM orders");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get sample data
    $stmt = $pdo->query("SELECT * FROM orders LIMIT 1");
    $sample = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get count
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM orders");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    echo json_encode([
        'success' => true,
        'columns' => $columns,
        'count' => $count,
        'sample_keys' => $sample ? array_keys($sample) : [],
        'has_customer_name' => in_array('customer_name', $columns),
        'has_customer_phone' => in_array('customer_phone', $columns),
        'has_tenant_id' => in_array('tenant_id', $columns),
        'has_user_id' => in_array('user_id', $columns),
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
