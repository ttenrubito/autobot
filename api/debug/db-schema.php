<?php
/**
 * Database Schema Check - Debug endpoint
 * Returns table structure for debugging
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();
    
    $tables = ['orders', 'cases', 'payments', 'customer_profiles', 'installment_contracts', 'savings_accounts', 'users', 'channels'];
    $result = [];
    
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("DESCRIBE $table");
            $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columnNames = array_column($cols, 'Field');
            
            $result[$table] = [
                'exists' => true,
                'columns' => $columnNames,
                'has_user_id' => in_array('user_id', $columnNames),
                'has_tenant_id' => in_array('tenant_id', $columnNames),
                'has_channel_id' => in_array('channel_id', $columnNames),
            ];
        } catch (Exception $e) {
            $result[$table] = [
                'exists' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
