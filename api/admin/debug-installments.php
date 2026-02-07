<?php
/**
 * API: Debug Installment Contracts
 * 
 * GET /api/admin/debug-installments.php
 */

require_once __DIR__ . '/../../includes/Database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    
    // Get all contracts with their payment details
    $contracts = $db->query("
        SELECT 
            ic.id as contract_id,
            ic.contract_no,
            ic.order_id,
            ic.status as contract_status,
            ic.total_periods,
            ic.paid_periods,
            ic.product_name,
            ic.paid_amount as contract_paid_amount,
            ic.financed_amount,
            o.total_amount as order_total,
            o.paid_amount as order_paid_amount,
            o.payment_status as order_payment_status
        FROM installment_contracts ic
        LEFT JOIN orders o ON ic.order_id = o.id
        ORDER BY ic.id DESC
        LIMIT 10
    ");
    
    $result = [];
    
    foreach ($contracts as $contract) {
        $periods = $db->query("
            SELECT period_number, amount, paid_amount, status, due_date
            FROM installment_payments 
            WHERE contract_id = ?
            ORDER BY period_number
        ", [$contract['contract_id']]);
        
        $totalPeriodAmount = 0;
        $totalPeriodPaid = 0;
        foreach ($periods as $p) {
            $totalPeriodAmount += floatval($p['amount']);
            $totalPeriodPaid += floatval($p['paid_amount']);
        }
        
        $result[] = [
            'contract_no' => $contract['contract_no'],
            'product' => $contract['product_name'],
            'contract_status' => $contract['contract_status'],
            'periods' => [
                'total' => $contract['total_periods'],
                'paid_count' => $contract['paid_periods'],
                'details' => $periods
            ],
            'amounts' => [
                'financed_amount' => $contract['financed_amount'],
                'contract_paid' => $contract['contract_paid_amount'],
                'sum_period_amounts' => $totalPeriodAmount,
                'sum_period_paid' => $totalPeriodPaid,
                'order_total' => $contract['order_total'],
                'order_paid' => $contract['order_paid_amount']
            ],
            'order' => [
                'id' => $contract['order_id'],
                'payment_status' => $contract['order_payment_status']
            ],
            'is_overpaid' => $totalPeriodPaid >= $totalPeriodAmount,
            'should_be_complete' => $totalPeriodPaid >= $totalPeriodAmount && $contract['contract_status'] !== 'completed'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'contracts' => $result
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
