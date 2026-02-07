<?php
/**
 * PawnService - Business logic for pawn/loan operations (รับฝาก/จำนำ)
 * 
 * Business Rules:
 * - Loan: 65-70% of appraised value
 * - Interest: 2% per month
 * - Term: 30 days, max 12 extensions
 * - Only items purchased from shop can be pawned
 * 
 * @version 1.0
 * @date 2026-01-31
 */

namespace App\Services;

use PDO;
use Exception;

class PawnService
{
    private PDO $db;
    
    // Business constants
    const DEFAULT_LOAN_PERCENTAGE = 65;
    const MAX_LOAN_PERCENTAGE = 70;
    const DEFAULT_INTEREST_RATE = 2.0;
    const DEFAULT_TERM_DAYS = 30;
    const MAX_EXTENSIONS = 12;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \getDB();
    }
    
    /**
     * Get items eligible for pawning (items purchased from shop that aren't currently pawned)
     * @param int $customerId Customer profile ID
     * @return array List of eligible orders/items
     */
    public function getEligibleItems(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                o.id as order_id, 
                o.order_no, 
                o.product_code, 
                o.product_ref_id,
                o.unit_price, 
                o.product_name,
                o.created_at as purchase_date,
                ROUND(o.unit_price * :loan_pct / 100, 2) as estimated_loan
            FROM orders o
            WHERE o.customer_profile_id = :customer_id
            AND o.status IN ('paid', 'delivered', 'completed')
            AND o.id NOT IN (
                SELECT COALESCE(order_id, 0) FROM pawns 
                WHERE order_id IS NOT NULL 
                AND status NOT IN ('redeemed', 'forfeited', 'cancelled')
            )
            ORDER BY o.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([
            ':customer_id' => $customerId,
            ':loan_pct' => self::DEFAULT_LOAN_PERCENTAGE
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get eligible items by platform user ID
     * @param string $platformUserId LINE/Facebook user ID
     * @return array ['customer' => array|null, 'items' => array]
     */
    public function getEligibleItemsByPlatformUser(string $platformUserId): array
    {
        // Find customer profile
        $customer = $this->getCustomerByPlatformId($platformUserId);
        
        if (!$customer) {
            return ['customer' => null, 'items' => []];
        }
        
        $items = $this->getEligibleItems((int)$customer['id']);
        
        return ['customer' => $customer, 'items' => $items];
    }
    
    /**
     * Get customer by platform user ID or "user:X" format (for API v2)
     */
    private function getCustomerByPlatformId(string $platformUserId): ?array
    {
        // Handle "user:X" format from API v2
        if (preg_match('/^user:(\d+)$/', $platformUserId, $matches)) {
            $userId = (int)$matches[1];
            $stmt = $this->db->prepare("
                SELECT id, display_name, full_name, phone, platform, platform_user_id
                FROM customer_profiles 
                WHERE user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        
        // Standard platform user ID lookup
        $stmt = $this->db->prepare("
            SELECT id, display_name, full_name, phone, platform, platform_user_id
            FROM customer_profiles 
            WHERE platform_user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$platformUserId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Create new pawn from order
     * @param int $orderId Order ID
     * @param int $userId User ID
     * @param array $options Optional: appraised_value, loan_percentage, interest_rate, bank_account_id
     * @return array ['success' => bool, 'pawn_id' => int, 'pawn_no' => string, ...]
     */
    public function createPawn(int $orderId, int $userId, array $options = []): array
    {
        // Get order
        $stmt = $this->db->prepare("
            SELECT * FROM orders 
            WHERE id = ? AND user_id = ? 
            AND status IN ('paid', 'delivered', 'completed')
        ");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            return ['success' => false, 'error' => 'ไม่พบรายการสั่งซื้อ หรือสินค้ายังไม่ได้ชำระเงิน'];
        }
        
        // Check if already pawned
        $existingStmt = $this->db->prepare("
            SELECT id, pawn_no FROM pawns 
            WHERE order_id = ? 
            AND status NOT IN ('redeemed', 'forfeited', 'cancelled')
        ");
        $existingStmt->execute([$orderId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            return ['success' => false, 'error' => "สินค้านี้ถูกจำนำอยู่แล้ว (รหัส: {$existing['pawn_no']})"];
        }
        
        // Calculate loan details
        $appraisedValue = $options['appraised_value'] ?? (float)$order['unit_price'];
        $loanPercentage = $options['loan_percentage'] ?? self::DEFAULT_LOAN_PERCENTAGE;
        $interestRate = $options['interest_rate'] ?? self::DEFAULT_INTEREST_RATE;
        $bankAccountId = $options['bank_account_id'] ?? null;
        
        $loanAmount = $appraisedValue * ($loanPercentage / 100);
        $expectedInterest = $loanAmount * ($interestRate / 100);
        $dueDate = date('Y-m-d', strtotime('+' . self::DEFAULT_TERM_DAYS . ' days'));
        $pawnNo = 'PWN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        $this->db->beginTransaction();
        try {
            $insertStmt = $this->db->prepare("
                INSERT INTO pawns (
                    pawn_no, user_id, customer_profile_id, order_id, product_ref_id,
                    product_name, product_description,
                    appraisal_value, pawn_amount, interest_rate,
                    expected_interest_amount, next_due_date, bank_account_id,
                    status, tenant_id, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    'pending_approval', 'default', NOW()
                )
            ");
            $insertStmt->execute([
                $pawnNo,
                $userId,
                $order['customer_profile_id'] ?? null,
                $orderId,
                $order['product_code'] ?? $order['product_ref_id'],
                $order['product_name'] ?? $order['product_code'],
                $options['item_description'] ?? $order['product_code'],
                $appraisedValue,
                $loanAmount,
                $interestRate,
                $expectedInterest,
                $dueDate,
                $bankAccountId
            ]);
            
            $pawnId = $this->db->lastInsertId();
            $this->db->commit();
            
            return [
                'success' => true,
                'pawn_id' => $pawnId,
                'pawn_no' => $pawnNo,
                'order_id' => $orderId,
                'product_code' => $order['product_code'],
                'appraised_value' => $appraisedValue,
                'loan_amount' => round($loanAmount, 2),
                'interest_rate' => $interestRate,
                'monthly_interest' => round($expectedInterest, 2),
                'due_date' => $dueDate
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Find active pawns for a customer (for slip matching)
     * @param string $platformUserId Platform user ID (LINE/Facebook) or "user:X" format
     * @param int|null $channelId Channel ID
     * @return array List of active/overdue pawns
     */
    public function findActivePawns(string $platformUserId, ?int $channelId = null): array
    {
        // Handle "user:X" format from API v2 - query directly from pawns table
        if (preg_match('/^user:(\d+)$/', $platformUserId, $matches)) {
            $userId = (int)$matches[1];
            $stmt = $this->db->prepare("
                SELECT p.id, p.pawn_no, p.user_id, p.item_name,
                       p.loan_amount, p.interest_rate, p.status, p.due_date,
                       p.created_at, p.updated_at,
                       ROUND(p.loan_amount * p.interest_rate / 100, 2) as expected_interest,
                       p.total_due as full_redemption_amount,
                       DATEDIFF(p.due_date, CURDATE()) as days_until_due
                FROM pawns p
                WHERE p.user_id = ?
                AND p.status IN ('active', 'extended')
                ORDER BY p.due_date ASC, p.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Standard platform user lookup via customer_profiles
        $customer = $this->getCustomerByPlatformId($platformUserId);
        
        if (!$customer) {
            return [];
        }
        
        $stmt = $this->db->prepare("
            SELECT p.id, p.pawn_no, p.user_id, p.item_name,
                   p.loan_amount, p.interest_rate, p.status, p.due_date,
                   p.created_at, p.updated_at,
                   ROUND(p.loan_amount * p.interest_rate / 100, 2) as expected_interest,
                   p.total_due as full_redemption_amount,
                   DATEDIFF(p.due_date, CURDATE()) as days_until_due
            FROM pawns p
            WHERE p.customer_id = ?
            AND p.status IN ('active', 'extended')
            ORDER BY p.due_date ASC, p.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$customer['id']]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Link a payment to a pawn (auto-match from slip)
     * @param int $paymentId Payment ID from payments table
     * @param int $pawnId Pawn ID
     * @param string $paymentType 'interest', 'redemption', 'partial'
     * @param float $amount Payment amount
     * @return array ['success' => bool, 'pawn_payment_id' => int]
     */
    public function linkPaymentToPawn(int $paymentId, int $pawnId, string $paymentType = 'interest', float $amount = 0): array
    {
        $pawn = $this->getPawnById($pawnId);
        if (!$pawn) {
            return ['success' => false, 'error' => 'Pawn not found'];
        }
        
        $loanAmount = (float)($pawn['pawn_amount'] ?? 0);
        $expectedInterest = (float)($pawn['expected_interest_amount'] ?? 0);
        
        // Calculate interest and principal
        $interestAmount = 0;
        $principalAmount = 0;
        
        if ($paymentType === 'redemption' || $paymentType === 'full_redemption') {
            $interestAmount = $expectedInterest;
            $principalAmount = $amount - $expectedInterest;
        } elseif ($paymentType === 'interest') {
            $interestAmount = $amount;
            $principalAmount = 0;
        } else {
            $interestAmount = min($amount, $expectedInterest);
            $principalAmount = max(0, $amount - $expectedInterest);
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO pawn_payments 
                (pawn_id, payment_type, amount, interest_amount, principal_amount, 
                 payment_date, payment_method, notes, verified_at, created_at)
                VALUES (?, ?, ?, ?, ?, CURDATE(), 'bank_transfer', 'Auto-linked from payment', NOW(), NOW())
            ");
            $stmt->execute([$pawnId, $paymentType, $amount, $interestAmount, $principalAmount]);
            
            $pawnPaymentId = $this->db->lastInsertId();
            
            // Update pawn's next_interest_due date if it's an interest payment
            if ($paymentType === 'interest') {
                $updatePawnStmt = $this->db->prepare("
                    UPDATE pawns 
                    SET due_date = DATE_ADD(due_date, INTERVAL 1 MONTH),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updatePawnStmt->execute([$pawnId]);
            }
            
            return ['success' => true, 'pawn_payment_id' => $pawnPaymentId];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get pawn by ID
     */
    public function getPawnById(int $pawnId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM pawns WHERE id = ?");
        $stmt->execute([$pawnId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get pawn by pawn number
     */
    public function getPawnByNo(string $pawnNo): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM pawns WHERE pawn_no = ?");
        $stmt->execute([$pawnNo]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Calculate interest preview
     * @param float $appraisedValue Appraised value
     * @param float $loanPercentage Loan percentage (default 65)
     * @param float $interestRate Monthly interest rate (default 2%)
     * @param int $termDays Term in days (default 30)
     * @return array Calculation details
     */
    public function calculateInterestPreview(
        float $appraisedValue, 
        float $loanPercentage = self::DEFAULT_LOAN_PERCENTAGE,
        float $interestRate = self::DEFAULT_INTEREST_RATE,
        int $termDays = self::DEFAULT_TERM_DAYS
    ): array {
        $loanAmount = $appraisedValue * ($loanPercentage / 100);
        $monthlyInterest = $loanAmount * ($interestRate / 100);
        $periods = ceil($termDays / 30);
        $totalInterest = $monthlyInterest * $periods;
        
        return [
            'appraised_value' => $appraisedValue,
            'loan_percentage' => $loanPercentage,
            'loan_amount' => round($loanAmount, 2),
            'interest_rate' => $interestRate,
            'term_days' => $termDays,
            'monthly_interest' => round($monthlyInterest, 2),
            'total_interest' => round($totalInterest, 2),
            'total_redemption' => round($loanAmount + $totalInterest, 2),
            'due_date' => date('Y-m-d', strtotime("+{$termDays} days"))
        ];
    }
    
    /**
     * Match slip amount to pawn payment types
     * Returns matched pawn and payment type if found
     * @param array $pawns List of active pawns
     * @param float $slipAmount Amount from slip
     * @param float $tolerance Amount tolerance (default 100)
     * @return array|null ['pawn' => array, 'payment_type' => string] or null
     */
    public function matchSlipToPawn(array $pawns, float $slipAmount, float $tolerance = 100): ?array
    {
        foreach ($pawns as $pawn) {
            $expectedInterest = (float)($pawn['expected_interest'] ?? $pawn['expected_interest_amount'] ?? 0);
            $fullRedemption = (float)($pawn['full_redemption_amount'] ?? 0);
            $loanAmount = (float)($pawn['pawn_amount'] ?? 0);
            
            // Check interest payment match
            if ($expectedInterest > 0 && abs($slipAmount - $expectedInterest) <= $tolerance) {
                return ['pawn' => $pawn, 'payment_type' => 'interest'];
            }
            
            // Check full redemption match
            if ($fullRedemption > 0 && abs($slipAmount - $fullRedemption) <= $tolerance) {
                return ['pawn' => $pawn, 'payment_type' => 'full_redemption'];
            }
            
            // Check loan amount match (for new loan confirmation)
            if ($loanAmount > 0 && abs($slipAmount - $loanAmount) <= $tolerance) {
                return ['pawn' => $pawn, 'payment_type' => 'loan_disbursement'];
            }
        }
        
        return null;
    }
    
    /**
     * Verify redemption payment and close pawn
     * @param int $pawnPaymentId Pawn payment ID
     * @param int $verifiedBy Admin user ID
     * @return array ['success' => bool, 'message' => string]
     */
    public function verifyRedemption(int $pawnPaymentId, int $verifiedBy): array
    {
        // Get payment info
        $stmt = $this->db->prepare("
            SELECT pp.*, p.id as pawn_id, p.pawn_no, p.case_id
            FROM pawn_payments pp
            JOIN pawns p ON pp.pawn_id = p.id
            WHERE pp.id = ?
        ");
        $stmt->execute([$pawnPaymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found'];
        }
        
        $this->db->beginTransaction();
        try {
            // Update payment status
            $this->db->prepare("
                UPDATE pawn_payments SET status = 'verified', verified_at = NOW(), verified_by = ? WHERE id = ?
            ")->execute([$verifiedBy, $pawnPaymentId]);
            
            // Update pawn status to redeemed
            $this->db->prepare("
                UPDATE pawns SET status = 'redeemed', redeemed_at = NOW(), updated_at = NOW() WHERE id = ?
            ")->execute([$payment['pawn_id']]);
            
            // Close related case
            if (!empty($payment['case_id'])) {
                $this->db->prepare("
                    UPDATE cases SET status = 'closed', resolved_at = NOW(), resolution = 'ไถ่ถอนสำเร็จ' WHERE id = ?
                ")->execute([$payment['case_id']]);
            }
            
            $this->db->commit();
            return ['success' => true, 'message' => 'อนุมัติไถ่ถอนสำเร็จ'];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
