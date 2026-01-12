<?php
/**
 * Debug: Check cases table schema
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

try {
    $pdo = getDB();
    
    // Get columns
    $stmt = $pdo->query("SHOW COLUMNS FROM cases");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get column names
    $columnNames = array_column($columns, 'Field');
    
    // Check for tenant_id
    $hasTenantId = in_array('tenant_id', $columnNames);
    
    echo json_encode([
        'success' => true,
        'has_tenant_id' => $hasTenantId,
        'column_names' => $columnNames,
        'full_schema' => $columns
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
