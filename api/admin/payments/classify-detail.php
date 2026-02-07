<?php
/**
 * API: Get Payment Classification Details
 * 
 * Returns full payment details with order and pawn candidates for classification
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Response.php';
require_once __DIR__ . '/../../../includes/admin_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
    exit;
}

if (!isAdminLoggedIn()) {
    Response::error('Unauthorized', 401);
    exit;
}

$paymentId = intval($_GET['id'] ?? 0);
if (!$paymentId) {
    Response::error('Payment ID required', 400);
    exit;
}

$db = Database::getInstance();

try {
    // Get payment details
    $payment = $db->queryOne("
        SELECT 
            p.*,
            c.name as customer_name,
            c.platform,
            c.platform_user_id
        FROM payments p
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE p.id = ?
    ", [$paymentId]);
    
    if (!$payment) {
        Response::error('Payment not found', 404);
        exit;
    }
    
    $customerId = $payment['customer_id'];
    $amount = floatval($payment['amount']);
    
    // Find order candidates for this customer
    $orderCandidates = [];
    if ($customerId) {
        // Get pending orders
        $orders = $db->query("
            SELECT 
                o.id,
                o.order_no,
                o.product_code,
                o.total_amount,
                o.deposit_amount,
                o.remaining_amount,
                o.installment_amount,
                o.status,
                o.created_at
            FROM orders o
            WHERE o.customer_id = ?
            AND o.status IN ('pending', 'payment_pending', 'processing', 'installment')
            ORDER BY o.created_at DESC
            LIMIT 20
        ", [$customerId]);
        
        foreach ($orders as $order) {
            $remaining = floatval($order['remaining_amount']);
            $installment = floatval($order['installment_amount'] ?? 0);
            $total = floatval($order['total_amount']);
            
            $confidence = 0;
            $matchReason = '';
            
            // Check for exact matches
            if (abs($amount - $remaining) < 0.01) {
                $confidence = 100;
                $matchReason = 'ยอดเต็มตรงกัน';
            } elseif ($installment > 0 && abs($amount - $installment) < 0.01) {
                $confidence = 95;
                $matchReason = 'ค่างวดตรงกัน';
            } elseif (abs($amount - $total) < 0.01) {
                $confidence = 90;
                $matchReason = 'ยอดรวมตรงกัน';
            } elseif ($amount <= $remaining && $amount > $remaining * 0.3) {
                $confidence = 70;
                $matchReason = 'ชำระบางส่วน';
            } elseif ($amount <= $remaining) {
                $confidence = 50;
                $matchReason = 'ยอดใกล้เคียง';
            }
            
            if ($confidence > 0) {
                $order['confidence'] = $confidence;
                $order['match_reason'] = $matchReason;
                $orderCandidates[] = $order;
            }
        }
        
        // Sort by confidence
        usort($orderCandidates, fn($a, $b) => $b['confidence'] - $a['confidence']);
    }
    
    // Find pawn candidates for this customer
    $pawnCandidates = [];
    if ($customerId) {
        $pawns = $db->query("
            SELECT 
                p.id,
                p.pawn_no,
                p.item_name,
                p.product_code,
                p.loan_amount,
                p.interest_rate,
                p.current_interest_accrued,
                p.expected_interest_amount,
                p.status,
                p.next_payment_due,
                p.created_at
            FROM pawns p
            WHERE p.customer_id = ?
            AND p.status IN ('active', 'overdue')
            ORDER BY p.next_payment_due ASC
            LIMIT 20
        ", [$customerId]);
        
        foreach ($pawns as $pawn) {
            $loanAmount = floatval($pawn['loan_amount']);
            $expectedInterest = floatval($pawn['expected_interest_amount'] ?? 0);
            $redemptionAmount = $loanAmount + $expectedInterest;
            
            $confidence = 0;
            $matchReason = '';
            
            // Check for matches
            if (abs($amount - $expectedInterest) < 0.01) {
                $confidence = 100;
                $matchReason = 'ดอกเบี้ยตรงกัน';
            } elseif (abs($amount - $redemptionAmount) < 1) {
                $confidence = 95;
                $matchReason = 'ยอดไถ่ถอนตรงกัน';
            } elseif (abs($amount - $loanAmount) < 1) {
                $confidence = 90;
                $matchReason = 'ยอดเงินกู้ตรงกัน';
            } elseif ($amount >= $expectedInterest * 0.9 && $amount <= $expectedInterest * 1.1) {
                $confidence = 75;
                $matchReason = 'ใกล้เคียงดอกเบี้ย';
            } elseif ($amount > $expectedInterest) {
                $confidence = 50;
                $matchReason = 'มากกว่าดอกเบี้ย';
            }
            
            if ($confidence > 0) {
                $pawn['confidence'] = $confidence;
                $pawn['match_reason'] = $matchReason;
                $pawn['expected_interest'] = $expectedInterest;
                $pawn['redemption_amount'] = $redemptionAmount;
                $pawnCandidates[] = $pawn;
            }
        }
        
        // Sort by confidence
        usort($pawnCandidates, fn($a, $b) => $b['confidence'] - $a['confidence']);
    }
    
    Response::success([
        'payment' => $payment,
        'order_candidates' => $orderCandidates,
        'pawn_candidates' => $pawnCandidates
    ]);
    
} catch (Exception $e) {
    Response::error('Database error: ' . $e->getMessage(), 500);
}
