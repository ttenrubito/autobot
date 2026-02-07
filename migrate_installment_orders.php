<?php
/**
 * Migrate existing installment orders to create installment_contracts
 * 
 * This script creates installment_contracts for orders that have payment_type/order_type = 'installment'
 * but don't have a linked installment_id
 */
require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();
    
    echo "=== MIGRATE INSTALLMENT ORDERS TO CONTRACTS ===\n\n";
    
    // Find the correct column name (payment_type or order_type)
    $stmt = $pdo->query("SHOW COLUMNS FROM orders");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $typeCol = in_array('payment_type', $cols) ? 'payment_type' : 
               (in_array('order_type', $cols) ? 'order_type' : null);
    
    if (!$typeCol) {
        echo "❌ No payment_type or order_type column found!\n";
        exit(1);
    }
    
    echo "Using column: {$typeCol}\n\n";
    
    // Get default channel_id
    $stmt = $pdo->query("SELECT id FROM customer_channels WHERE status = 'active' ORDER BY id ASC LIMIT 1");
    $channelRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $defaultChannelId = $channelRow ? (int)$channelRow['id'] : 1;
    echo "Default channel_id: {$defaultChannelId}\n\n";
    
    // Find installment orders without contracts
    $stmt = $pdo->query("
        SELECT o.* FROM orders o
        WHERE o.{$typeCol} = 'installment'
          AND (o.installment_id IS NULL OR o.installment_id = 0)
        ORDER BY o.id ASC
    ");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($orders) . " installment orders without contracts:\n\n";
    
    $created = 0;
    $errors = 0;
    
    foreach ($orders as $order) {
        echo "Processing Order #{$order['id']} ({$order['order_number']})... ";
        
        try {
            // Calculate installment details (3 periods, +3% fee)
            $totalAmount = (float)$order['total_amount'];
            $serviceFeeRate = 0.03;
            $serviceFee = round($totalAmount * $serviceFeeRate);
            $grandTotal = $totalAmount + $serviceFee;
            $totalPeriods = 3;
            $avgPerPeriod = round($grandTotal / $totalPeriods, 2);
            
            // Generate contract number
            $contractNo = 'INS-' . date('Ymd', strtotime($order['created_at'])) . '-' . strtoupper(substr(md5($order['id']), 0, 5));
            
            // Check if contract already exists with this order_id
            $checkStmt = $pdo->prepare("SELECT id FROM installment_contracts WHERE order_id = ?");
            $checkStmt->execute([$order['id']]);
            if ($checkStmt->fetch()) {
                echo "⏭️ Already has contract\n";
                continue;
            }
            
            // Insert contract
            $stmt = $pdo->prepare("
                INSERT INTO installment_contracts (
                    contract_no, tenant_id, customer_id, order_id,
                    channel_id, external_user_id, platform,
                    product_ref_id, product_name, product_price,
                    customer_name, customer_phone,
                    total_amount, down_payment, financed_amount,
                    total_periods, amount_per_period, paid_periods, paid_amount,
                    status, start_date, next_due_date,
                    created_at, updated_at
                ) VALUES (
                    ?, 'default', ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, 0, ?,
                    ?, ?, 0, 0,
                    'active', ?, ?,
                    NOW(), NOW()
                )
            ");
            
            $startDate = date('Y-m-d', strtotime($order['created_at']));
            $nextDueDate = $startDate; // First payment due immediately
            
            $stmt->execute([
                $contractNo,
                $order['user_id'] ?? 1,
                $order['id'],
                $defaultChannelId,
                '', // external_user_id (we don't have it for old orders)
                $order['platform'] ?? 'web',
                $order['product_code'] ?? '',
                $order['product_name'] ?? 'Unknown Product',
                $totalAmount,
                $order['customer_name'] ?? '',
                $order['customer_phone'] ?? '',
                $totalAmount,
                $grandTotal,
                $totalPeriods,
                $avgPerPeriod,
                $startDate,
                $nextDueDate
            ]);
            
            $contractId = $pdo->lastInsertId();
            
            // Update order with contract ID
            $pdo->prepare("UPDATE orders SET installment_id = ? WHERE id = ?")->execute([$contractId, $order['id']]);
            
            echo "✅ Created contract #{$contractId}\n";
            $created++;
            
        } catch (Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\n=== SUMMARY ===\n";
    echo "Created: {$created}\n";
    echo "Errors: {$errors}\n";
    echo "Total processed: " . count($orders) . "\n";
    
} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
