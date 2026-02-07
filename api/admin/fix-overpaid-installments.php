<?php
/**
 * API: Fix Overpaid Installment Contracts
 * 
 * GET /api/admin/fix-overpaid-installments.php?run=1&confirm=yes
 */

require_once __DIR__ . '/../../includes/Database.php';

header('Content-Type: application/json');

// Simple security check
if (!isset($_GET['run']) || $_GET['run'] !== '1' || !isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    echo json_encode([
        'success' => false,
        'message' => 'Add ?run=1&confirm=yes to execute fix',
        'usage' => '/api/admin/fix-overpaid-installments.php?run=1&confirm=yes'
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();
    
    // Find contracts where:
    // - Order paid amount >= Order total amount (fully paid by order)
    // - OR SUM(paid_amount) >= SUM(amount) in periods
    // - But contract status is not 'completed'
    $problematicContracts = $db->query("
        SELECT 
            ic.id as contract_id,
            ic.contract_no,
            ic.order_id,
            ic.status as contract_status,
            ic.total_periods,
            ic.paid_periods,
            ic.product_name,
            o.total_amount as order_total,
            o.paid_amount as order_paid,
            (SELECT COALESCE(SUM(paid_amount), 0) FROM installment_payments WHERE contract_id = ic.id) as total_period_paid,
            (SELECT COALESCE(SUM(amount), 0) FROM installment_payments WHERE contract_id = ic.id) as total_period_required,
            (SELECT COUNT(*) FROM installment_payments WHERE contract_id = ic.id AND status = 'paid') as paid_period_count,
            (SELECT COUNT(*) FROM installment_payments WHERE contract_id = ic.id AND status IN ('pending', 'partial')) as pending_period_count
        FROM installment_contracts ic
        LEFT JOIN orders o ON ic.order_id = o.id
        WHERE ic.status != 'completed'
        HAVING (
            (order_paid >= order_total AND order_total > 0)
            OR (total_period_paid >= total_period_required)
        ) AND pending_period_count > 0
    ");
    
    if (empty($problematicContracts)) {
        echo json_encode([
            'success' => true,
            'message' => 'No problematic contracts found',
            'fixed' => 0
        ]);
        exit;
    }
    
    $results = [];
    $fixedCount = 0;
    
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
            
            $results[] = [
                'contract_no' => $contract['contract_no'],
                'product_name' => $contract['product_name'],
                'order_paid' => $contract['order_paid'],
                'order_total' => $contract['order_total'],
                'period_paid' => $contract['total_period_paid'],
                'period_required' => $contract['total_period_required'],
                'overpaid_by_order' => floatval($contract['order_paid']) - floatval($contract['order_total']),
                'periods_fixed' => $periodsFixed,
                'status' => 'fixed'
            ];
            $fixedCount++;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $results[] = [
                'contract_no' => $contract['contract_no'],
                'error' => $e->getMessage(),
                'status' => 'error'
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Fixed {$fixedCount} contract(s)",
        'fixed' => $fixedCount,
        'total_found' => count($problematicContracts),
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
