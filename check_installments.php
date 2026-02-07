<?php
/**
 * Debug script to check installment orders and contracts
 */
require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();
    
    // First check what columns exist
    echo "=== ORDERS TABLE STRUCTURE ===\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM orders");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = [];
    foreach ($cols as $c) {
        $columnNames[] = $c['Field'];
        if (strpos($c['Field'], 'payment') !== false || strpos($c['Field'], 'installment') !== false || strpos($c['Field'], 'order_type') !== false) {
            echo "{$c['Field']}: {$c['Type']}\n";
        }
    }
    
    // Check which column exists
    $hasPaymentType = in_array('payment_type', $columnNames);
    $hasOrderType = in_array('order_type', $columnNames);
    echo "\npayment_type column: " . ($hasPaymentType ? "YES" : "NO") . "\n";
    echo "order_type column: " . ($hasOrderType ? "YES" : "NO") . "\n";
    
    // Check orders with installment using existing column
    echo "\n=== INSTALLMENT ORDERS ===\n";
    $typeCol = $hasPaymentType ? 'payment_type' : ($hasOrderType ? 'order_type' : null);
    if ($typeCol) {
        $stmt = $pdo->query("SELECT id, order_number, total_amount, $typeCol as order_type, installment_id, paid_amount, status, created_at FROM orders WHERE $typeCol = 'installment' ORDER BY id DESC LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) == 0) {
            echo "No installment orders found.\n";
        }
        foreach ($rows as $row) {
            echo "Order #{$row['id']}: {$row['order_number']} | Amount: {$row['total_amount']} | installment_id: " . ($row['installment_id'] ?: 'NULL') . " | Status: {$row['status']} | Created: {$row['created_at']}\n";
        }
    } else {
        echo "No payment_type or order_type column found!\n";
    }
    
    // Check installment_contracts
    echo "\n=== INSTALLMENT_CONTRACTS ===\n";
    $stmt = $pdo->query("SELECT id, contract_no, customer_id, order_id, financed_amount, status, created_at FROM installment_contracts LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) == 0) {
        echo "No installment contracts found - TABLE IS EMPTY!\n";
    }
    foreach ($rows as $row) {
        echo "Contract #{$row['id']}: {$row['contract_no']} | Order: " . ($row['order_id'] ?: 'NULL') . " | Amount: {$row['financed_amount']} | Status: {$row['status']}\n";
    }
    
    // Check if tables exist
    echo "\n=== INSTALLMENT TABLES ===\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'installment%'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        $count = $countStmt->fetchColumn();
        echo "$table: $count rows\n";
    }
    
    // Check column exists in orders table
    echo "\n=== ORDERS TABLE COLUMNS (installment related) ===\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM orders WHERE Field LIKE '%installment%'");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']}: {$col['Type']} | Default: " . ($col['Default'] ?? 'NULL') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
