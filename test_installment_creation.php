<?php
/**
 * Test Create Installment Order
 * Simulate what happens when creating an installment order
 */
require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();
    
    echo "=== TEST INSTALLMENT ORDER CREATION ===\n\n";
    
    // 1. Check columns
    echo "1. Check orders table columns:\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM orders");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = [];
    foreach ($cols as $c) {
        $columnNames[$c['Field']] = $c['Type'];
    }
    echo "   - order_type: " . (isset($columnNames['order_type']) ? $columnNames['order_type'] : "NOT FOUND") . "\n";
    echo "   - payment_type: " . (isset($columnNames['payment_type']) ? $columnNames['payment_type'] : "NOT FOUND") . "\n";
    echo "   - installment_id: " . (isset($columnNames['installment_id']) ? $columnNames['installment_id'] : "NOT FOUND") . "\n";
    
    // 2. Check if installment_contracts table exists
    echo "\n2. Check installment_contracts table:\n";
    $stmtCheck = $pdo->query("SHOW TABLES LIKE 'installment_contracts'");
    $hasContracts = $stmtCheck->rowCount() > 0;
    echo "   - Table exists: " . ($hasContracts ? "YES" : "NO") . "\n";
    
    // 3. Check installment_payments table
    echo "\n3. Check installment_payments table:\n";
    $stmtCheck = $pdo->query("SHOW TABLES LIKE 'installment_payments'");
    $hasPayments = $stmtCheck->rowCount() > 0;
    echo "   - Table exists: " . ($hasPayments ? "YES" : "NO") . "\n";
    
    // 4. Test INSERT into installment_contracts
    if ($hasContracts) {
        echo "\n4. Test INSERT into installment_contracts:\n";
        
        $testData = [
            'contract_no' => 'TEST-' . date('Ymd') . '-' . uniqid(),
            'customer_id' => 1,
            'order_id' => 999999, // test order
            'tenant_id' => 'default',
            'product_name' => 'Test Product',
            'product_price' => 10000,
            'financed_amount' => 10300, // +3%
            'total_periods' => 3,
            'amount_per_period' => 3433.33,
            'status' => 'active',
            'start_date' => date('Y-m-d'),
            'next_due_date' => date('Y-m-d'),
            'shop_owner_id' => 1
        ];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO installment_contracts (
                    contract_no, customer_id, order_id, tenant_id,
                    product_name, product_price, financed_amount, 
                    total_periods, amount_per_period, paid_periods, paid_amount,
                    status, start_date, next_due_date, shop_owner_id,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $testData['contract_no'],
                $testData['customer_id'],
                $testData['order_id'],
                $testData['tenant_id'],
                $testData['product_name'],
                $testData['product_price'],
                $testData['financed_amount'],
                $testData['total_periods'],
                $testData['amount_per_period'],
                $testData['status'],
                $testData['start_date'],
                $testData['next_due_date'],
                $testData['shop_owner_id']
            ]);
            
            $contractId = $pdo->lastInsertId();
            echo "   ✅ SUCCESS! Created contract ID: {$contractId}\n";
            
            // Delete test record
            $pdo->exec("DELETE FROM installment_contracts WHERE id = {$contractId}");
            echo "   (Test record deleted)\n";
            
        } catch (Exception $e) {
            echo "   ❌ ERROR: " . $e->getMessage() . "\n";
        }
    }
    
    // 5. Show existing installment orders without contracts
    echo "\n5. Installment orders without contracts:\n";
    $typeCol = isset($columnNames['payment_type']) ? 'payment_type' : (isset($columnNames['order_type']) ? 'order_type' : null);
    if ($typeCol) {
        $stmt = $pdo->query("SELECT id, order_number, total_amount, installment_id FROM orders WHERE $typeCol = 'installment' ORDER BY id DESC LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $hasContract = !empty($row['installment_id']);
            echo "   Order #{$row['id']} ({$row['order_number']}): " . 
                 ($hasContract ? "✅ has contract #{$row['installment_id']}" : "❌ NO CONTRACT") . "\n";
        }
    }
    
    echo "\n=== CONCLUSION ===\n";
    if ($hasContracts) {
        echo "installment_contracts table EXISTS.\n";
        echo "But contracts may not be created because:\n";
        echo "1. Order was created BEFORE the contract creation code was deployed\n";
        echo "2. An error occurred during INSERT (check required columns)\n";
        echo "\nSOLUTION: Need to manually create contracts for existing orders,\n";
        echo "or test creating a NEW installment order to verify the code works.\n";
    } else {
        echo "❌ installment_contracts table DOES NOT EXIST!\n";
        echo "Need to run migration SQL to create the table.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
