<?php
/**
 * InstallmentService - Business logic for installment/payment plan operations (ผ่อนชำระ)
 * 
 * @version 1.0
 * @date 2026-01-31
 */

namespace App\Services;

use PDO;
use Exception;

class InstallmentService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \getDB();
    }
    
    /**
     * Get active installment contracts for a customer
     * @param int $customerId Customer profile ID
     * @return array List of active contracts
     */
    public function getActiveContracts(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT ic.*, 
                   ic.installment_amount as expected_payment,
                   DATEDIFF(ic.next_payment_due, CURDATE()) as days_until_due,
                   (ic.total_amount - ic.paid_amount) as remaining_amount
            FROM installment_contracts ic
            WHERE ic.customer_profile_id = ?
            AND ic.status IN ('active', 'overdue')
            ORDER BY ic.next_payment_due ASC, ic.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$customerId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find active installments by platform user ID (for slip matching)
     * @param string $platformUserId LINE/Facebook user ID
     * @param int|null $channelId Channel ID
     * @return array List of active installments
     */
    public function findActiveInstallments(string $platformUserId, ?int $channelId = null): array
    {
        // Look up customer_profile_id from platform_user_id
        $customer = $this->getCustomerByPlatformId($platformUserId);
        
        if (!$customer) {
            return [];
        }
        
        return $this->getActiveContracts((int)$customer['id']);
    }
    
    /**
     * Get customer by platform user ID
     */
    private function getCustomerByPlatformId(string $platformUserId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, display_name, full_name 
            FROM customer_profiles 
            WHERE platform_user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$platformUserId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Link a payment to an installment contract (auto-match from slip)
     * @param int $paymentId Payment ID from payments table
     * @param int $contractId Installment contract ID
     * @param float $amount Payment amount
     * @return array ['success' => bool, 'installment_payment_id' => int]
     */
    public function linkPaymentToInstallment(int $paymentId, int $contractId, float $amount = 0): array
    {
        $contract = $this->getContractById($contractId);
        if (!$contract) {
            return ['success' => false, 'error' => 'Contract not found'];
        }
        
        try {
            // Create installment_payments record
            $stmt = $this->db->prepare("
                INSERT INTO installment_payments 
                (source_payment_id, contract_id, amount, payment_date, payment_method, notes, created_at)
                VALUES (?, ?, ?, NOW(), 'bank_transfer', 'Auto-matched from chatbot', NOW())
            ");
            $stmt->execute([$paymentId, $contractId, $amount]);
            
            $installmentPaymentId = $this->db->lastInsertId();
            
            // Update payment record with classification
            $updateStmt = $this->db->prepare("
                UPDATE payments 
                SET classified_as = 'installment',
                    linked_installment_payment_id = ?,
                    match_status = 'auto_matched',
                    matched_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$installmentPaymentId, $paymentId]);
            
            return ['success' => true, 'installment_payment_id' => $installmentPaymentId];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get installment contract by ID
     */
    public function getContractById(int $contractId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM installment_contracts WHERE id = ?");
        $stmt->execute([$contractId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get installment contract by contract number
     */
    public function getContractByNo(string $contractNo): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM installment_contracts WHERE contract_no = ?");
        $stmt->execute([$contractNo]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Match slip amount to installment payment
     * @param array $contracts List of active contracts
     * @param float $slipAmount Amount from slip
     * @param float $tolerance Amount tolerance (default 100)
     * @return array|null ['contract' => array] or null
     */
    public function matchSlipToInstallment(array $contracts, float $slipAmount, float $tolerance = 100): ?array
    {
        foreach ($contracts as $contract) {
            $expectedPayment = (float)($contract['expected_payment'] ?? $contract['installment_amount'] ?? 0);
            
            if ($expectedPayment > 0 && abs($slipAmount - $expectedPayment) <= $tolerance) {
                return ['contract' => $contract];
            }
        }
        
        return null;
    }
    
    /**
     * Calculate installment plan preview
     * @param float $totalAmount Total amount
     * @param int $periods Number of periods
     * @param float $interestRate Interest rate (default 0)
     * @return array Plan details
     */
    public function calculatePlanPreview(float $totalAmount, int $periods, float $interestRate = 0): array
    {
        $totalInterest = $totalAmount * ($interestRate / 100) * $periods;
        $totalWithInterest = $totalAmount + $totalInterest;
        $installmentAmount = $totalWithInterest / $periods;
        
        $schedule = [];
        for ($i = 1; $i <= $periods; $i++) {
            $schedule[] = [
                'period' => $i,
                'due_date' => date('Y-m-d', strtotime("+{$i} month")),
                'amount' => round($installmentAmount, 2)
            ];
        }
        
        return [
            'total_amount' => $totalAmount,
            'interest_rate' => $interestRate,
            'periods' => $periods,
            'total_interest' => round($totalInterest, 2),
            'total_with_interest' => round($totalWithInterest, 2),
            'installment_amount' => round($installmentAmount, 2),
            'schedule' => $schedule
        ];
    }
    
    /**
     * Verify installment payment
     * @param int $installmentPaymentId Installment payment ID
     * @param int $verifiedBy Admin user ID
     * @return array ['success' => bool, 'message' => string]
     */
    public function verifyPayment(int $installmentPaymentId, int $verifiedBy): array
    {
        $stmt = $this->db->prepare("
            SELECT ip.*, ic.id as contract_id, ic.contract_no, ic.paid_amount, ic.total_amount
            FROM installment_payments ip
            JOIN installment_contracts ic ON ip.contract_id = ic.id
            WHERE ip.id = ?
        ");
        $stmt->execute([$installmentPaymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found'];
        }
        
        $this->db->beginTransaction();
        try {
            // Update payment status
            $this->db->prepare("
                UPDATE installment_payments SET status = 'verified', verified_at = NOW(), verified_by = ? WHERE id = ?
            ")->execute([$verifiedBy, $installmentPaymentId]);
            
            // Update contract paid amount
            $newPaidAmount = (float)$payment['paid_amount'] + (float)$payment['amount'];
            $status = $newPaidAmount >= (float)$payment['total_amount'] ? 'completed' : 'active';
            
            $this->db->prepare("
                UPDATE installment_contracts 
                SET paid_amount = ?, 
                    status = ?,
                    last_payment_date = NOW(),
                    updated_at = NOW() 
                WHERE id = ?
            ")->execute([$newPaidAmount, $status, $payment['contract_id']]);
            
            $this->db->commit();
            return ['success' => true, 'message' => 'อนุมัติการชำระงวดสำเร็จ'];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
