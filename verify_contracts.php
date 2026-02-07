<?php
/**
 * Verify contracts and orders
 */
require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();

    echo "=== VERIFY CONTRACTS ===\n";
    $stmt = $pdo->query("SELECT id, contract_no, customer_id, order_id, shop_owner_id, status FROM installment_contracts");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Contract #{$row['id']}: customer_id={$row['customer_id']}, shop_owner_id=" . ($row['shop_owner_id'] ?? 'NULL') . ", order={$row['order_id']}, status={$row['status']}\n";
    }

    echo "\n=== ORDERS ===\n";
    $stmt = $pdo->query("SELECT id, order_number, user_id, installment_id FROM orders WHERE order_type = 'installment'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Order #{$row['id']}: user_id={$row['user_id']}, installment_id=" . ($row['installment_id'] ?? 'NULL') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
