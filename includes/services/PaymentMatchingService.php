<?php
/**
 * PaymentMatchingService
 * 
 * Auto-match payment slips กับ orders หรือ pawns
 * 
 * Flow:
 * 1. รับ payment_id ที่ต้องการ match
 * 2. ดึงข้อมูลจาก OCR (amount, date, ref)
 * 3. หา customer จาก platform_user_id
 * 4. ลองหา order ที่ยอดตรง
 * 5. ถ้าไม่เจอ ลองหา pawn ที่ยอดดอกเบี้ยตรง
 * 6. Return ผลลัพธ์พร้อม confidence score
 * 
 * @package Autobot
 * @author BoxDesign
 * @since 2026-01-31
 */

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Logger.php';

class PaymentMatchingService
{
    private $db;
    private $tenantId;
    
    // Confidence thresholds
    const CONFIDENCE_EXACT = 100;
    const CONFIDENCE_HIGH = 90;
    const CONFIDENCE_MEDIUM = 70;
    const CONFIDENCE_LOW = 50;
    const CONFIDENCE_AUTO_VERIFY_THRESHOLD = 95; // Auto verify if >= this score
    
    public function __construct(string $tenantId = 'default')
    {
        $this->db = Database::getInstance();
        $this->tenantId = $tenantId;
    }
    
    /**
     * Main entry point - match a payment to order or pawn
     * 
     * @param int $paymentId Payment ID to match
     * @return array Match result with type, id, confidence, candidates
     */
    public function matchPayment(int $paymentId): array
    {
        $result = [
            'success' => false,
            'matched_type' => null,
            'matched_id' => null,
            'confidence' => 0,
            'candidates' => [],
            'match_status' => 'pending',
            'error' => null
        ];
        
        try {
            // 1. Get payment details
            $payment = $this->getPayment($paymentId);
            if (!$payment) {
                $result['error'] = 'Payment not found';
                return $result;
            }
            
            // 2. Get customer ID (may need to lookup from platform_user_id)
            $customerId = $this->resolveCustomerId($payment);
            
            // 3. Parse OCR data
            $ocrData = $this->parseOcrData($payment['payment_details']);
            $amount = floatval($payment['amount']);
            
            // 4. Build match attempts log
            $attempts = [
                'searched_at' => date('Y-m-d H:i:s'),
                'payment_id' => $paymentId,
                'amount' => $amount,
                'customer_id' => $customerId,
                'ocr_ref' => $ocrData['ref'] ?? null,
                'candidates' => []
            ];
            
            // 5. Try matching orders first
            $orderCandidates = $this->findOrderCandidates($customerId, $amount, $ocrData);
            $attempts['candidates']['orders'] = $orderCandidates;
            
            // 6. Try matching pawns
            $pawnCandidates = $this->findPawnCandidates($customerId, $amount, $ocrData);
            $attempts['candidates']['pawns'] = $pawnCandidates;
            
            // 7. Determine best match
            $bestMatch = $this->determineBestMatch($orderCandidates, $pawnCandidates);
            
            // 8. Update payment record
            $this->updatePaymentMatch($paymentId, $bestMatch, $attempts);
            
            // 9. Build result
            $result['success'] = true;
            $result['matched_type'] = $bestMatch['type'];
            $result['matched_id'] = $bestMatch['id'];
            $result['confidence'] = $bestMatch['confidence'];
            $result['match_status'] = $bestMatch['type'] ? 'auto_matched' : 'no_match';
            $result['candidates'] = [
                'orders' => $orderCandidates,
                'pawns' => $pawnCandidates
            ];
            $result['attempts'] = $attempts;
            
            Logger::info('PaymentMatching completed', [
                'payment_id' => $paymentId,
                'matched_type' => $bestMatch['type'],
                'matched_id' => $bestMatch['id'],
                'confidence' => $bestMatch['confidence']
            ]);
            
        } catch (Exception $e) {
            Logger::error('PaymentMatching failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Get payment record
     */
    private function getPayment(int $paymentId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM payments WHERE id = ? AND tenant_id = ?",
            [$paymentId, $this->tenantId]
        );
    }
    
    /**
     * Resolve customer_id from payment
     * ถ้าไม่มี customer_id ให้หาจาก platform_user_id
     */
    private function resolveCustomerId(array $payment): ?int
    {
        if (!empty($payment['customer_id'])) {
            return intval($payment['customer_id']);
        }
        
        // Try to find from platform_user_id
        if (!empty($payment['platform_user_id'])) {
            $customer = $this->db->queryOne(
                "SELECT id FROM customer_profiles 
                 WHERE platform_user_id = ? 
                 AND tenant_id = ?",
                [$payment['platform_user_id'], $this->tenantId]
            );
            
            if ($customer) {
                return intval($customer['id']);
            }
        }
        
        return null;
    }
    
    /**
     * Parse OCR data from payment_details JSON
     */
    private function parseOcrData(?string $paymentDetails): array
    {
        if (empty($paymentDetails)) {
            return [];
        }
        
        $data = json_decode($paymentDetails, true);
        if (!$data) {
            return [];
        }
        
        return $data['ocr_result'] ?? $data;
    }
    
    /**
     * Find order candidates that might match this payment
     */
    private function findOrderCandidates(?int $customerId, float $amount, array $ocrData): array
    {
        $candidates = [];
        
        if (!$customerId || $amount <= 0) {
            return $candidates;
        }
        
        // Query 1: Exact remaining amount match
        $exactMatches = $this->db->query(
            "SELECT o.id, o.order_no, o.product_code, o.unit_price, o.total_amount,
                    o.paid_amount, o.remaining_amount, o.installment_amount,
                    o.status, o.created_at
             FROM orders o
             WHERE o.customer_id = ?
             AND o.tenant_id = ?
             AND o.status IN ('pending', 'partial', 'confirmed')
             AND o.remaining_amount = ?
             ORDER BY o.created_at DESC
             LIMIT 5",
            [$customerId, $this->tenantId, $amount]
        );
        
        foreach ($exactMatches as $order) {
            $candidates[] = [
                'id' => intval($order['id']),
                'order_no' => $order['order_no'],
                'product_code' => $order['product_code'],
                'remaining_amount' => floatval($order['remaining_amount']),
                'match_reason' => 'exact_remaining_amount',
                'confidence' => self::CONFIDENCE_EXACT
            ];
        }
        
        // Query 2: Installment amount match
        $installmentMatches = $this->db->query(
            "SELECT o.id, o.order_no, o.product_code, o.unit_price, o.total_amount,
                    o.paid_amount, o.remaining_amount, o.installment_amount,
                    o.status, o.created_at
             FROM orders o
             WHERE o.customer_id = ?
             AND o.tenant_id = ?
             AND o.status IN ('pending', 'partial')
             AND o.installment_amount = ?
             AND o.id NOT IN (SELECT id FROM orders WHERE remaining_amount = ?)
             ORDER BY o.created_at DESC
             LIMIT 5",
            [$customerId, $this->tenantId, $amount, $amount]
        );
        
        foreach ($installmentMatches as $order) {
            $candidates[] = [
                'id' => intval($order['id']),
                'order_no' => $order['order_no'],
                'product_code' => $order['product_code'],
                'installment_amount' => floatval($order['installment_amount']),
                'match_reason' => 'installment_amount',
                'confidence' => self::CONFIDENCE_HIGH
            ];
        }
        
        // Query 3: Close amount match (within 5% or 100 baht)
        $tolerance = max($amount * 0.05, 100);
        $closeMatches = $this->db->query(
            "SELECT o.id, o.order_no, o.product_code, o.remaining_amount,
                    o.installment_amount, o.status,
                    ABS(o.remaining_amount - ?) as diff
             FROM orders o
             WHERE o.customer_id = ?
             AND o.tenant_id = ?
             AND o.status IN ('pending', 'partial')
             AND o.remaining_amount > 0
             AND ABS(o.remaining_amount - ?) <= ?
             AND o.remaining_amount != ?
             ORDER BY diff ASC
             LIMIT 3",
            [$amount, $customerId, $this->tenantId, $amount, $tolerance, $amount]
        );
        
        foreach ($closeMatches as $order) {
            $diff = floatval($order['diff']);
            $confidence = self::CONFIDENCE_MEDIUM - ($diff / $tolerance * 20);
            
            $candidates[] = [
                'id' => intval($order['id']),
                'order_no' => $order['order_no'],
                'product_code' => $order['product_code'],
                'remaining_amount' => floatval($order['remaining_amount']),
                'difference' => $diff,
                'match_reason' => 'close_amount',
                'confidence' => max(intval($confidence), self::CONFIDENCE_LOW)
            ];
        }
        
        // Sort by confidence descending
        usort($candidates, fn($a, $b) => $b['confidence'] - $a['confidence']);
        
        return $candidates;
    }
    
    /**
     * Find pawn candidates that might match this payment
     */
    private function findPawnCandidates(?int $customerId, float $amount, array $ocrData): array
    {
        $candidates = [];
        
        if (!$customerId || $amount <= 0) {
            return $candidates;
        }
        
        // Query 1: Exact interest amount match
        $interestMatches = $this->db->query(
            "SELECT p.id, p.pawn_no, p.product_code, p.item_name, p.loan_amount,
                    p.interest_rate, p.expected_interest_amount, p.due_date,
                    p.next_payment_due, p.status
             FROM pawns p
             WHERE p.customer_id = ?
             AND p.tenant_id = ?
             AND p.status IN ('active', 'overdue')
             AND p.expected_interest_amount = ?
             ORDER BY p.next_payment_due ASC
             LIMIT 5",
            [$customerId, $this->tenantId, $amount]
        );
        
        foreach ($interestMatches as $pawn) {
            $candidates[] = [
                'id' => intval($pawn['id']),
                'pawn_no' => $pawn['pawn_no'],
                'product_code' => $pawn['product_code'],
                'item_name' => $pawn['item_name'],
                'expected_interest' => floatval($pawn['expected_interest_amount']),
                'loan_amount' => floatval($pawn['loan_amount']),
                'match_reason' => 'exact_interest_amount',
                'payment_type' => 'interest',
                'confidence' => self::CONFIDENCE_EXACT
            ];
        }
        
        // Query 2: Loan amount match (redemption payment)
        $redemptionMatches = $this->db->query(
            "SELECT p.id, p.pawn_no, p.product_code, p.item_name, p.loan_amount,
                    p.interest_rate, p.expected_interest_amount, p.due_date, p.status
             FROM pawns p
             WHERE p.customer_id = ?
             AND p.tenant_id = ?
             AND p.status IN ('active', 'overdue')
             AND p.loan_amount = ?
             ORDER BY p.due_date ASC
             LIMIT 5",
            [$customerId, $this->tenantId, $amount]
        );
        
        foreach ($redemptionMatches as $pawn) {
            $candidates[] = [
                'id' => intval($pawn['id']),
                'pawn_no' => $pawn['pawn_no'],
                'product_code' => $pawn['product_code'],
                'item_name' => $pawn['item_name'],
                'loan_amount' => floatval($pawn['loan_amount']),
                'match_reason' => 'exact_loan_amount',
                'payment_type' => 'redemption',
                'confidence' => self::CONFIDENCE_HIGH
            ];
        }
        
        // Query 3: Loan + Interest (full redemption)
        $fullRedemption = $this->db->query(
            "SELECT p.id, p.pawn_no, p.product_code, p.item_name, p.loan_amount,
                    p.expected_interest_amount,
                    (p.loan_amount + COALESCE(p.expected_interest_amount, 0)) as total_redemption,
                    p.status
             FROM pawns p
             WHERE p.customer_id = ?
             AND p.tenant_id = ?
             AND p.status IN ('active', 'overdue')
             AND (p.loan_amount + COALESCE(p.expected_interest_amount, 0)) = ?
             LIMIT 5",
            [$customerId, $this->tenantId, $amount]
        );
        
        foreach ($fullRedemption as $pawn) {
            $candidates[] = [
                'id' => intval($pawn['id']),
                'pawn_no' => $pawn['pawn_no'],
                'product_code' => $pawn['product_code'],
                'item_name' => $pawn['item_name'],
                'total_redemption' => floatval($pawn['total_redemption']),
                'match_reason' => 'full_redemption_amount',
                'payment_type' => 'redemption',
                'confidence' => self::CONFIDENCE_EXACT
            ];
        }
        
        // Sort by confidence descending
        usort($candidates, fn($a, $b) => $b['confidence'] - $a['confidence']);
        
        return $candidates;
    }
    
    /**
     * Determine the best match from order and pawn candidates
     */
    private function determineBestMatch(array $orderCandidates, array $pawnCandidates): array
    {
        $bestOrder = !empty($orderCandidates) ? $orderCandidates[0] : null;
        $bestPawn = !empty($pawnCandidates) ? $pawnCandidates[0] : null;
        
        // No candidates found
        if (!$bestOrder && !$bestPawn) {
            return [
                'type' => null,
                'id' => null,
                'confidence' => 0,
                'reason' => 'no_candidates_found'
            ];
        }
        
        // Only order candidates
        if ($bestOrder && !$bestPawn) {
            return [
                'type' => 'order',
                'id' => $bestOrder['id'],
                'confidence' => $bestOrder['confidence'],
                'reason' => $bestOrder['match_reason']
            ];
        }
        
        // Only pawn candidates
        if ($bestPawn && !$bestOrder) {
            return [
                'type' => 'pawn',
                'id' => $bestPawn['id'],
                'confidence' => $bestPawn['confidence'],
                'reason' => $bestPawn['match_reason'],
                'payment_type' => $bestPawn['payment_type'] ?? 'interest'
            ];
        }
        
        // Both have candidates - compare confidence
        if ($bestOrder['confidence'] >= $bestPawn['confidence']) {
            return [
                'type' => 'order',
                'id' => $bestOrder['id'],
                'confidence' => $bestOrder['confidence'],
                'reason' => $bestOrder['match_reason']
            ];
        } else {
            return [
                'type' => 'pawn',
                'id' => $bestPawn['id'],
                'confidence' => $bestPawn['confidence'],
                'reason' => $bestPawn['match_reason'],
                'payment_type' => $bestPawn['payment_type'] ?? 'interest'
            ];
        }
    }
    
    /**
     * Update payment record with match result
     */
    private function updatePaymentMatch(int $paymentId, array $bestMatch, array $attempts): void
    {
        $attemptsJson = json_encode($attempts, JSON_UNESCAPED_UNICODE);
        
        if ($bestMatch['type'] === 'order') {
            $this->db->execute(
                "UPDATE payments SET 
                    order_id = ?,
                    classified_as = 'order',
                    match_status = 'auto_matched',
                    match_attempts = ?,
                    matched_at = NOW()
                 WHERE id = ?",
                [$bestMatch['id'], $attemptsJson, $paymentId]
            );
        } elseif ($bestMatch['type'] === 'pawn') {
            // For pawn, we classify but don't create pawn_payment yet
            // That will be done when admin verifies or auto-verify triggers
            $this->db->execute(
                "UPDATE payments SET 
                    classified_as = 'pawn',
                    match_status = 'auto_matched',
                    match_attempts = ?,
                    matched_at = NOW()
                 WHERE id = ?",
                [$attemptsJson, $paymentId]
            );
        } else {
            // No match
            $this->db->execute(
                "UPDATE payments SET 
                    match_status = 'no_match',
                    match_attempts = ?
                 WHERE id = ?",
                [$attemptsJson, $paymentId]
            );
        }
    }
    
    /**
     * Manually classify a payment (by admin)
     */
    public function classifyPayment(int $paymentId, string $type, int $targetId, int $adminUserId): array
    {
        $result = ['success' => false, 'error' => null];
        
        try {
            $payment = $this->getPayment($paymentId);
            if (!$payment) {
                $result['error'] = 'Payment not found';
                return $result;
            }
            
            if ($type === 'order') {
                $this->db->execute(
                    "UPDATE payments SET 
                        order_id = ?,
                        classified_as = 'order',
                        match_status = 'manual_matched',
                        matched_at = NOW(),
                        matched_by = ?
                     WHERE id = ?",
                    [$targetId, $adminUserId, $paymentId]
                );
                $result['success'] = true;
                
            } elseif ($type === 'pawn') {
                // Create pawn_payment record
                $pawnPaymentId = $this->createPawnPayment($payment, $targetId);
                
                $this->db->execute(
                    "UPDATE payments SET 
                        classified_as = 'pawn',
                        linked_pawn_payment_id = ?,
                        match_status = 'manual_matched',
                        matched_at = NOW(),
                        matched_by = ?
                     WHERE id = ?",
                    [$pawnPaymentId, $adminUserId, $paymentId]
                );
                
                $result['success'] = true;
                $result['pawn_payment_id'] = $pawnPaymentId;
                
            } else {
                $result['error'] = 'Invalid classification type';
            }
            
        } catch (Exception $e) {
            Logger::error('Payment classification failed', [
                'payment_id' => $paymentId,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Create pawn_payment record from payment slip
     */
    private function createPawnPayment(array $payment, int $pawnId): int
    {
        // Get pawn details to determine payment type
        $pawn = $this->db->queryOne(
            "SELECT * FROM pawns WHERE id = ?",
            [$pawnId]
        );
        
        $amount = floatval($payment['amount']);
        $loanAmount = floatval($pawn['loan_amount'] ?? 0);
        $expectedInterest = floatval($pawn['expected_interest_amount'] ?? 0);
        
        // Determine payment type based on amount
        if ($amount >= $loanAmount + $expectedInterest) {
            $paymentType = 'redemption';
            $interestAmount = $expectedInterest;
            $principalAmount = $amount - $expectedInterest;
        } elseif ($amount >= $loanAmount) {
            $paymentType = 'redemption';
            $interestAmount = $amount - $loanAmount;
            $principalAmount = $loanAmount;
        } elseif ($amount == $expectedInterest) {
            $paymentType = 'interest';
            $interestAmount = $amount;
            $principalAmount = 0;
        } else {
            $paymentType = 'partial';
            $interestAmount = min($amount, $expectedInterest);
            $principalAmount = max(0, $amount - $expectedInterest);
        }
        
        $this->db->execute(
            "INSERT INTO pawn_payments 
             (source_payment_id, pawn_id, payment_type, amount, interest_amount, 
              principal_amount, payment_date, payment_method, slip_image, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'bank_transfer', ?, ?, NOW())",
            [
                $payment['id'],
                $pawnId,
                $paymentType,
                $amount,
                $interestAmount,
                $principalAmount,
                $payment['payment_date'] ?? date('Y-m-d H:i:s'),
                $payment['slip_image'],
                'Auto-created from payment #' . $payment['payment_no']
            ]
        );
        
        $pawnPaymentId = $this->db->lastInsertId();
        
        // Update pawn status if needed
        if ($paymentType === 'redemption') {
            $this->db->execute(
                "UPDATE pawns SET status = 'redeemed', redeemed_at = NOW() WHERE id = ?",
                [$pawnId]
            );
        } elseif ($paymentType === 'interest' || $paymentType === 'extension') {
            // Extend next payment due by 30 days
            $this->db->execute(
                "UPDATE pawns SET 
                    next_payment_due = DATE_ADD(COALESCE(next_payment_due, due_date), INTERVAL 30 DAY),
                    total_interest_paid = total_interest_paid + ?,
                    extension_count = extension_count + 1
                 WHERE id = ?",
                [$interestAmount, $pawnId]
            );
        }
        
        return $pawnPaymentId;
    }
    
    /**
     * Get pending payments for classification
     */
    public function getPendingClassification(): array
    {
        return $this->db->query(
            "SELECT p.*, 
                    cp.name as customer_name,
                    cp.phone as customer_phone,
                    p.match_attempts
             FROM payments p
             LEFT JOIN customer_profiles cp ON p.customer_id = cp.id
             WHERE p.tenant_id = ?
             AND p.match_status IN ('pending', 'no_match')
             AND p.status = 'pending'
             ORDER BY p.created_at DESC",
            [$this->tenantId]
        );
    }
    
    /**
     * Auto-process all pending payments
     */
    public function processAllPending(): array
    {
        $results = [
            'processed' => 0,
            'matched' => 0,
            'no_match' => 0,
            'errors' => 0
        ];
        
        $pendingPayments = $this->db->query(
            "SELECT id FROM payments 
             WHERE tenant_id = ? 
             AND match_status = 'pending'
             AND classified_as = 'unknown'",
            [$this->tenantId]
        );
        
        foreach ($pendingPayments as $payment) {
            $result = $this->matchPayment($payment['id']);
            $results['processed']++;
            
            if ($result['error']) {
                $results['errors']++;
            } elseif ($result['matched_type']) {
                $results['matched']++;
            } else {
                $results['no_match']++;
            }
        }
        
        return $results;
    }
}
