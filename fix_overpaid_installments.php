<?php
/**
 * Fix Installment Contracts with Overpayment
 * 
 * สำหรับแก้ไขกรณี:
 * - ลูกค้าจ่ายเกินในงวดก่อนหน้า
 * - งวดสุดท้ายจ่ายน้อยกว่ายอดงวด (เพราะรวมแล้วครบ)
 * - แต่ status ยังไม่เป็น 'completed'
 * 
 * Usage: php fix_overpaid_installments.php
 */

require_once __DIR__ . '/includes/Database.php';

$db = Database::getInstance();

echo "=== Fix Overpaid Installment Contracts ===\n\n";

// Find contracts where:
// - SUM(paid_amount) >= SUM(amount) (fully paid by amount)
// - But status is not 'completed'
$problematicContracts = $db->query("
    SELECT 
        ic.id as contract_id,
        ic.contract_no,
        ic.order_id,
        ic.status as contract_status,
        ic.total_periods,
        ic.paid_periods,
        ic.product_name,
        (SELECT COALESCE(SUM(paid_amount), 0) FROM installment_payments WHERE contract_id = ic.id) as total_paid,
        (SELECT COALESCE(SUM(amount), 0) FROM installment_payments WHERE contract_id = ic.id) as total_required,
        (SELECT COUNT(*) FROM installment_payments WHERE contract_id = ic.id AND status = 'paid') as paid_period_count,
        (SELECT COUNT(*) FROM installment_payments WHERE contract_id = ic.id AND status IN ('pending', 'partial')) as pending_period_count
    FROM installment_contracts ic
    WHERE ic.status != 'completed'
    HAVING total_paid >= total_required AND pending_period_count > 0
");

if (empty($problematicContracts)) {
    echo "✅ No problematic contracts found!\n";
    exit(0);
}

echo "Found " . count($problematicContracts) . " contract(s) with overpayment issues:\n\n";

foreach ($problematicContracts as $contract) {
    echo "📋 Contract: {$contract['contract_no']}\n";
    echo "   Product: {$contract['product_name']}\n";
    echo "   Status: {$contract['contract_status']}\n";
    echo "   Total Paid: ฿" . number_format($contract['total_paid'], 2) . "\n";
    echo "   Total Required: ฿" . number_format($contract['total_required'], 2) . "\n";
    echo "   Overpaid: ฿" . number_format($contract['total_paid'] - $contract['total_required'], 2) . "\n";
    echo "   Periods: {$contract['paid_period_count']}/{$contract['total_periods']} paid, {$contract['pending_period_count']} pending\n";
    
    // Show period details
    $periods = $db->query("
        SELECT period_number, amount, paid_amount, status 
        FROM installment_payments 
        WHERE contract_id = ? 
        ORDER BY period_number
    ", [$contract['contract_id']]);
    
    echo "   Period Details:\n";
    foreach ($periods as $p) {
        $diff = floatval($p['paid_amount']) - floatval($p['amount']);
        $diffStr = $diff > 0 ? "+฿" . number_format($diff, 2) : ($diff < 0 ? "-฿" . number_format(abs($diff), 2) : "");
        echo "     - Period {$p['period_number']}: ฿{$p['paid_amount']}/฿{$p['amount']} ({$p['status']}) {$diffStr}\n";
    }
    echo "\n";
}

// Ask for confirmation
echo "Do you want to fix these contracts? (y/N): ";
$confirm = trim(fgets(STDIN));

if (strtolower($confirm) !== 'y') {
    echo "Aborted.\n";
    exit(0);
}

echo "\n🔧 Fixing contracts...\n\n";

$pdo = $db->getPdo();

foreach ($problematicContracts as $contract) {
    try {
        $pdo->beginTransaction();
        
        // 1. Mark all pending/partial periods as paid
        $stmt = $pdo->prepare("
            UPDATE installment_payments 
            SET status = 'paid',
                paid_amount = amount,
                paid_date = COALESCE(paid_date, CURDATE()),
                updated_at = NOW()
            WHERE contract_id = ? AND status IN ('pending', 'partial')
        ");
        $stmt->execute([$contract['contract_id']]);
        $periodsFixed = $stmt->rowCount();
        
        // 2. Update contract status to completed
        $stmt = $pdo->prepare("
            UPDATE installment_contracts 
            SET status = 'completed',
                paid_periods = total_periods,
                next_due_date = NULL,
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$contract['contract_id']]);
        
        // 3. Update order status
        if ($contract['order_id']) {
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET payment_status = 'paid',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$contract['order_id']]);
        }
        
        $pdo->commit();
        
        echo "✅ Fixed contract {$contract['contract_no']}: {$periodsFixed} period(s) marked as paid\n";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "❌ Error fixing {$contract['contract_no']}: " . $e->getMessage() . "\n";
    }
}

echo "\n🎉 Done!\n";
