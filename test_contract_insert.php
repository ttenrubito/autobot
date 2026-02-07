<?php
/**
 * Test Create Installment Contract with CORRECT schema
 */
require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();
    
    echo "=== TEST INSTALLMENT CONTRACT CREATION (Fixed Schema) ===\n\n";
    
    // Simulate the fixed INSERT from orders.php
    $contractNo = "TEST-" . date("Ymd") . "-" . uniqid();
    $user_id = 1;
    $orderId = 999999;
    $contractChannelId = 1;
    $platformUserIdInput = "test_user_123";
    $platformForDb = "web";
    $productCode = "TEST-001";
    $productName = "Test Product";
    $totalAmount = 10000;
    $grandTotal = 10300; // +3%
    $totalPeriods = 3;
    $avgPerPeriod = 3433.33;
    $customerNameForContract = "Test Customer";
    $customerPhoneForContract = "0812345678";
    $startDate = date("Y-m-d");
    $nextDueDate = date("Y-m-d");

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
    $stmt->execute([
        $contractNo,
        $user_id,
        $orderId,
        $contractChannelId,
        $platformUserIdInput ?: "",
        $platformForDb ?: "web",
        $productCode ?: "",
        $productName,
        $totalAmount,
        $customerNameForContract ?: "",
        $customerPhoneForContract ?: "",
        $totalAmount,
        $grandTotal,
        $totalPeriods,
        $avgPerPeriod,
        $startDate,
        $nextDueDate
    ]);
    
    $contractId = $pdo->lastInsertId();
    echo "✅ SUCCESS! Created contract ID: {$contractId}\n\n";
    
    // Verify
    $stmt2 = $pdo->query("SELECT * FROM installment_contracts WHERE id = {$contractId}");
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    echo "Contract No: {$row['contract_no']}\n";
    echo "Order ID: {$row['order_id']}\n";
    echo "Total Amount: {$row['total_amount']}\n";
    echo "Financed: {$row['financed_amount']}\n";
    echo "Periods: {$row['total_periods']}\n";
    
    // Cleanup
    $pdo->exec("DELETE FROM installment_contracts WHERE id = {$contractId}");
    echo "\n(Test record deleted)\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
