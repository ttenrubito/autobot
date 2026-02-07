<?php
/**
 * API: Classify Payment (Hybrid A+)
 * 
 * Admin manually classifies a payment to order or pawn
 * Creates appropriate linkage and updates match_status
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Response.php';
require_once __DIR__ . '/../../../includes/Logger.php';
require_once __DIR__ . '/../../../includes/admin_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
    exit;
}

if (!isAdminLoggedIn()) {
    Response::error('Unauthorized', 401);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$paymentId = intval($input['payment_id'] ?? 0);
$classifyType = $input['classify_type'] ?? '';

if (!$paymentId) {
    Response::error('Payment ID required', 400);
    exit;
}

if (!in_array($classifyType, ['order', 'pawn', 'reject'])) {
    Response::error('Invalid classification type', 400);
    exit;
}

$db = Database::getInstance();
$adminUser = $_SESSION['admin_user'] ?? [];
$adminId = $adminUser['id'] ?? 0;

try {
    $db->beginTransaction();
    
    // Get payment details
    $payment = $db->queryOne("SELECT * FROM payments WHERE id = ?", [$paymentId]);
    if (!$payment) {
        $db->rollback();
        Response::error('Payment not found', 404);
        exit;
    }
    
    $amount = floatval($payment['amount']);
    $customerId = $payment['customer_id'];
    
    if ($classifyType === 'order') {
        // Classify as order payment
        $orderId = intval($input['order_id'] ?? 0);
        if (!$orderId) {
            $db->rollback();
            Response::error('Order ID required', 400);
            exit;
        }
        
        // Verify order exists and belongs to customer
        $order = $db->queryOne("
            SELECT * FROM orders WHERE id = ? AND customer_id = ?
        ", [$orderId, $customerId]);
        
        if (!$order) {
            $db->rollback();
            Response::error('Order not found', 404);
            exit;
        }
        
        // Update payment
        $db->execute("
            UPDATE payments SET
                order_id = ?,
                classified_as = 'order',
                match_status = 'manual_matched',
                classified_by = ?,
                classified_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ", [$orderId, $adminId, $paymentId]);
        
        // Update order remaining amount
        $newRemaining = max(0, floatval($order['remaining_amount']) - $amount);
        $newStatus = $newRemaining <= 0 ? 'paid' : $order['status'];
        
        $db->execute("
            UPDATE orders SET
                remaining_amount = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [$newRemaining, $newStatus, $orderId]);
        
        Logger::info('Payment classified as order', [
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'amount' => $amount,
            'admin_id' => $adminId
        ]);
        
        $db->commit();
        Response::success([
            'message' => 'จัดประเภทเป็น Order สำเร็จ',
            'order_id' => $orderId,
            'new_remaining' => $newRemaining
        ]);
        
    } elseif ($classifyType === 'pawn') {
        // Classify as pawn payment
        $pawnId = intval($input['pawn_id'] ?? 0);
        $paymentType = $input['payment_type'] ?? 'interest';
        
        if (!$pawnId) {
            $db->rollback();
            Response::error('Pawn ID required', 400);
            exit;
        }
        
        // Verify pawn exists and belongs to customer
        $pawn = $db->queryOne("
            SELECT * FROM pawns WHERE id = ? AND customer_id = ?
        ", [$pawnId, $customerId]);
        
        if (!$pawn) {
            $db->rollback();
            Response::error('Pawn not found', 404);
            exit;
        }
        
        $loanAmount = floatval($pawn['loan_amount']);
        $interestRate = floatval($pawn['interest_rate']);
        $expectedInterest = floatval($pawn['expected_interest_amount'] ?? $loanAmount * ($interestRate / 100));
        
        // Determine what this payment covers
        $principalPaid = 0;
        $interestPaid = 0;
        $isRedemption = false;
        
        if ($paymentType === 'interest') {
            $interestPaid = min($amount, $expectedInterest);
        } elseif ($paymentType === 'redemption') {
            $interestPaid = $expectedInterest;
            $principalPaid = $loanAmount;
            $isRedemption = true;
        } elseif ($paymentType === 'partial') {
            if ($amount >= $expectedInterest) {
                $interestPaid = $expectedInterest;
                $principalPaid = $amount - $expectedInterest;
            } else {
                $interestPaid = $amount;
            }
        }
        
        // Create pawn payment record
        $pawnPaymentNo = 'PP' . date('Ymd') . rand(1000, 9999);
        $newPaymentDue = date('Y-m-d', strtotime('+30 days'));
        
        $db->execute("
            INSERT INTO pawn_payments (
                pawn_id, payment_no, payment_date, principal_amount, interest_amount,
                total_amount, payment_method, source_payment_id, is_redemption, created_at
            ) VALUES (?, ?, NOW(), ?, ?, ?, 'transfer', ?, ?, NOW())
        ", [
            $pawnId, $pawnPaymentNo, $principalPaid, $interestPaid,
            $amount, $paymentId, $isRedemption ? 1 : 0
        ]);
        
        $pawnPaymentId = $db->lastInsertId();
        
        // Update payment record
        $db->execute("
            UPDATE payments SET
                classified_as = 'pawn',
                match_status = 'manual_matched',
                linked_pawn_payment_id = ?,
                classified_by = ?,
                classified_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ", [$pawnPaymentId, $adminId, $paymentId]);
        
        // Update pawn status
        if ($isRedemption) {
            $db->execute("
                UPDATE pawns SET
                    status = 'redeemed',
                    redeemed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ", [$pawnId]);
        } else {
            // Extend pawn period
            $extensionCount = intval($pawn['extension_count'] ?? 0) + 1;
            $db->execute("
                UPDATE pawns SET
                    extension_count = ?,
                    next_payment_due = ?,
                    current_interest_accrued = 0,
                    last_payment_date = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ", [$extensionCount, $newPaymentDue, $pawnId]);
        }
        
        Logger::info('Payment classified as pawn', [
            'payment_id' => $paymentId,
            'pawn_id' => $pawnId,
            'pawn_payment_id' => $pawnPaymentId,
            'payment_type' => $paymentType,
            'is_redemption' => $isRedemption,
            'amount' => $amount,
            'admin_id' => $adminId
        ]);
        
        $db->commit();
        Response::success([
            'message' => $isRedemption ? 'ไถ่ถอนสำเร็จ' : 'ชำระดอกเบี้ยสำเร็จ',
            'pawn_id' => $pawnId,
            'pawn_payment_id' => $pawnPaymentId,
            'is_redemption' => $isRedemption,
            'next_payment_due' => $isRedemption ? null : $newPaymentDue
        ]);
        
    } elseif ($classifyType === 'reject') {
        // Reject payment
        $reason = $input['reason'] ?? 'ไม่ระบุเหตุผล';
        
        $db->execute("
            UPDATE payments SET
                status = 'rejected',
                classified_as = 'rejected',
                match_status = 'no_match',
                reject_reason = ?,
                classified_by = ?,
                classified_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ", [$reason, $adminId, $paymentId]);
        
        Logger::info('Payment rejected', [
            'payment_id' => $paymentId,
            'reason' => $reason,
            'admin_id' => $adminId
        ]);
        
        $db->commit();
        Response::success([
            'message' => 'ปฏิเสธการชำระเงินสำเร็จ',
            'reason' => $reason
        ]);
    }
    
} catch (Exception $e) {
    $db->rollback();
    Logger::error('Payment classification failed', [
        'payment_id' => $paymentId,
        'error' => $e->getMessage()
    ]);
    Response::error('Classification failed: ' . $e->getMessage(), 500);
}
