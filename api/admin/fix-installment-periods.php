<?php
/**
 * Fix installment period status based on accumulated payments
 * GET /api/admin/fix-installment-periods.php?secret=autobot2026fix
 * 
 * This recalculates which periods should be marked as paid
 * based on verified payments linked to the order
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

// Simple secret key for one-time fix (remove after use)
$secret = $_GET['secret'] ?? '';
if ($secret !== 'autobot2026fix') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid secret']);
    exit;
}

try {
    $pdo = getDB();
    $results = [];
    
    // Get all active installment contracts with their order_id
    $contracts = $pdo->query("
        SELECT ic.id, ic.order_id, ic.paid_amount as contract_paid_amount, ic.paid_periods, ic.status,
               o.paid_amount as order_paid_amount
        FROM installment_contracts ic
        LEFT JOIN orders o ON o.id = ic.order_id
        WHERE ic.status = 'active'
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($contracts as $contract) {
        $contractId = $contract['id'];
        $orderId = $contract['order_id'];
        
        // Calculate actual paid amount from verified payments
        $actualPaid = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM payments 
            WHERE order_id = ? AND status = 'verified'
        ");
        $actualPaid->execute([$orderId]);
        $paidAmount = floatval($actualPaid->fetch()['total']);
        
        $contractResult = [
            'contract_id' => $contractId,
            'order_id' => $orderId,
            'contract_paid_amount' => floatval($contract['contract_paid_amount']),
            'order_paid_amount' => floatval($contract['order_paid_amount']),
            'actual_paid_from_payments' => $paidAmount,
            'periods_closed' => 0,
            'actions' => []
        ];
        
        // First, sync paid_amount to installment_contracts
        if ($paidAmount > 0 && floatval($contract['contract_paid_amount']) != $paidAmount) {
            $stmt = $pdo->prepare("UPDATE installment_contracts SET paid_amount = ? WHERE id = ?");
            $stmt->execute([$paidAmount, $contractId]);
            $contractResult['actions'][] = "Synced contract paid_amount to {$paidAmount}";
        }
        
        // Get all periods for this contract
        $periods = $pdo->prepare("
            SELECT id, period_number, amount, status 
            FROM installment_payments 
            WHERE contract_id = ? 
            ORDER BY period_number ASC
        ");
        $periods->execute([$contractId]);
        $allPeriods = $periods->fetchAll(PDO::FETCH_ASSOC);
        
        $remainingAmount = $paidAmount;
        $periodsClosed = 0;
        
        foreach ($allPeriods as $period) {
            $periodAmount = floatval($period['amount']);
            
            if ($period['status'] === 'pending' && $remainingAmount >= $periodAmount) {
                // Close this period
                // ✅ FIX: Use correct column name: paid_date (not paid_at/paid_amount)
                $stmt = $pdo->prepare("
                    UPDATE installment_payments 
                    SET status = 'paid', 
                        paid_date = CURDATE(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$period['id']]);
                
                $remainingAmount -= $periodAmount;
                $periodsClosed++;
                
                $contractResult['actions'][] = "Period {$period['period_number']} closed (amount: {$periodAmount})";
            } elseif ($period['status'] === 'paid') {
                $remainingAmount -= $periodAmount;
            }
        }
        
        $contractResult['periods_closed'] = $periodsClosed;
        
        if ($periodsClosed > 0) {
            // Update contract
            $newPaidCount = $pdo->prepare("
                SELECT COUNT(*) as cnt FROM installment_payments 
                WHERE contract_id = ? AND status = 'paid'
            ");
            $newPaidCount->execute([$contractId]);
            $paidCount = (int)$newPaidCount->fetch()['cnt'];
            
            $nextPending = $pdo->prepare("
                SELECT due_date FROM installment_payments 
                WHERE contract_id = ? AND status = 'pending'
                ORDER BY period_number ASC LIMIT 1
            ");
            $nextPending->execute([$contractId]);
            $next = $nextPending->fetch();
            
            // Check if all periods are complete
            $totalPeriods = $pdo->prepare("
                SELECT COUNT(*) as cnt FROM installment_payments WHERE contract_id = ?
            ");
            $totalPeriods->execute([$contractId]);
            $total = (int)$totalPeriods->fetch()['cnt'];
            
            if ($paidCount >= $total) {
                // Mark contract as completed
                $stmt = $pdo->prepare("
                    UPDATE installment_contracts 
                    SET paid_periods = ?,
                        status = 'completed',
                        next_due_date = NULL,
                        completed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$paidCount, $contractId]);
                $contractResult['actions'][] = "Contract marked as COMPLETED";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE installment_contracts 
                    SET paid_periods = ?,
                        next_due_date = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$paidCount, $next ? $next['due_date'] : null, $contractId]);
            }
            
            $contractResult['new_paid_periods'] = $paidCount;
        }
        
        $results[] = $contractResult;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Fix completed',
        'contracts_checked' => count($contracts),
        'results' => $results
    ]);
    
} catch (Exception $e) {
    error_log("Fix installment periods error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
