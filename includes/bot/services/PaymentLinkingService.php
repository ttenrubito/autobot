<?php
/**
 * PaymentLinkingService
 * 
 * จัดการ linking payments กับ orders, installments, pawns
 * 
 * @package Autobot\Bot\Services
 * @version 1.0.0
 * @date 2026-02-05
 */

namespace Autobot\Bot\Services;

use Database;
use Logger;

class PaymentLinkingService
{
    private $db;
    
    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }
    
    /**
     * Find pending orders for a customer (for slip matching)
     * 
     * @param string $externalUserId Platform user ID
     * @param int|null $channelId Channel ID
     * @param float|null $excludeAmount Amount to exclude (already matched)
     * @return array List of pending orders
     */
    public function findPendingOrdersForCustomer(string $externalUserId, ?int $channelId = null, ?float $excludeAmount = null): array
    {
        try {
            $sql = "SELECT id, order_no, total_amount, product_name, status, created_at
                    FROM orders 
                    WHERE external_user_id = ? 
                    AND status IN ('pending', 'pending_payment', 'confirmed')
                    ORDER BY created_at DESC
                    LIMIT 10";
            
            $orders = $this->db->query($sql, [$externalUserId]);
            
            // Filter out exact amount match if specified
            if ($excludeAmount && $excludeAmount > 0) {
                $orders = array_filter($orders, function($order) use ($excludeAmount) {
                    $orderAmount = (float)($order['total_amount'] ?? 0);
                    return abs($orderAmount - $excludeAmount) > 0.01;
                });
            }
            
            return array_values($orders);
        } catch (\Exception $e) {
            Logger::error('[PAYMENT_LINKING] Error finding pending orders', [
                'error' => $e->getMessage(),
                'external_user_id' => $externalUserId,
            ]);
            return [];
        }
    }
    
    /**
     * Find active installment contracts for a customer
     * 
     * @param string $externalUserId Platform user ID
     * @param int|null $channelId Channel ID
     * @return array List of active installments
     */
    public function findActiveInstallmentsForCustomer(string $externalUserId, ?int $channelId = null): array
    {
        try {
            $sql = "SELECT id, contract_no, product_name, financed_amount, paid_amount,
                           total_periods, paid_periods, amount_per_period, next_due_date, status
                    FROM installment_contracts 
                    WHERE platform_user_id = ? 
                    AND status IN ('active', 'overdue')
                    ORDER BY next_due_date ASC
                    LIMIT 10";
            
            return $this->db->query($sql, [$externalUserId]);
        } catch (\Exception $e) {
            Logger::error('[PAYMENT_LINKING] Error finding active installments', [
                'error' => $e->getMessage(),
                'external_user_id' => $externalUserId,
            ]);
            return [];
        }
    }
    
    /**
     * Find active pawn contracts for a customer
     * 
     * @param string $externalUserId Platform user ID
     * @param int|null $channelId Channel ID
     * @return array List of active pawns
     */
    public function findActivePawnsForCustomer(string $externalUserId, ?int $channelId = null): array
    {
        try {
            $sql = "SELECT id, ticket_no, item_description, principal_amount, 
                           interest_rate, interest_due, due_date, status
                    FROM pawns 
                    WHERE platform_user_id = ? 
                    AND status IN ('active', 'overdue')
                    ORDER BY due_date ASC
                    LIMIT 10";
            
            return $this->db->query($sql, [$externalUserId]);
        } catch (\Exception $e) {
            Logger::error('[PAYMENT_LINKING] Error finding active pawns', [
                'error' => $e->getMessage(),
                'external_user_id' => $externalUserId,
            ]);
            return [];
        }
    }
    
    /**
     * Link payment to order
     * 
     * @param int $paymentId Payment ID
     * @param int $orderId Order ID
     * @return bool Success
     */
    public function linkPaymentToOrder(int $paymentId, int $orderId): bool
    {
        try {
            // Update payment with order reference
            $this->db->execute(
                "UPDATE payments SET order_id = ?, payment_type = 'order', updated_at = NOW() WHERE id = ?",
                [$orderId, $paymentId]
            );
            
            // Get payment amount
            $payment = $this->db->queryOne("SELECT amount FROM payments WHERE id = ?", [$paymentId]);
            $amount = (float)($payment['amount'] ?? 0);
            
            if ($amount > 0) {
                // Update order paid amount
                $this->db->execute(
                    "UPDATE orders SET 
                        paid_amount = COALESCE(paid_amount, 0) + ?,
                        status = CASE 
                            WHEN COALESCE(paid_amount, 0) + ? >= total_amount THEN 'paid'
                            ELSE 'partial_paid'
                        END,
                        updated_at = NOW()
                    WHERE id = ?",
                    [$amount, $amount, $orderId]
                );
            }
            
            Logger::info('[PAYMENT_LINKING] Payment linked to order', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'amount' => $amount,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Logger::error('[PAYMENT_LINKING] Failed to link payment to order', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId,
                'order_id' => $orderId,
            ]);
            return false;
        }
    }
    
    /**
     * Link payment to installment contract
     * 
     * @param int $paymentId Payment ID
     * @param int $installmentId Installment contract ID
     * @param float $amount Payment amount (0 = use payment amount)
     * @return bool Success
     */
    public function linkPaymentToInstallment(int $paymentId, int $installmentId, float $amount = 0): bool
    {
        try {
            // Get payment amount if not specified
            if ($amount <= 0) {
                $payment = $this->db->queryOne("SELECT amount FROM payments WHERE id = ?", [$paymentId]);
                $amount = (float)($payment['amount'] ?? 0);
            }
            
            // Update payment with installment reference
            $this->db->execute(
                "UPDATE payments SET 
                    installment_contract_id = ?, 
                    payment_type = 'installment', 
                    updated_at = NOW() 
                WHERE id = ?",
                [$installmentId, $paymentId]
            );
            
            // Get current installment info
            $contract = $this->db->queryOne(
                "SELECT paid_amount, paid_periods, total_periods, amount_per_period, financed_amount 
                 FROM installment_contracts WHERE id = ?",
                [$installmentId]
            );
            
            if ($contract) {
                $newPaidAmount = (float)$contract['paid_amount'] + $amount;
                $perPeriod = (float)$contract['amount_per_period'];
                $newPaidPeriods = $perPeriod > 0 ? floor($newPaidAmount / $perPeriod) : $contract['paid_periods'];
                $totalPeriods = (int)$contract['total_periods'];
                $financedAmount = (float)$contract['financed_amount'];
                
                // Determine new status
                $newStatus = 'active';
                if ($newPaidAmount >= $financedAmount || $newPaidPeriods >= $totalPeriods) {
                    $newStatus = 'completed';
                }
                
                // Calculate next due date
                $nextDueDate = null;
                if ($newStatus !== 'completed') {
                    $nextDueDate = date('Y-m-d', strtotime('+1 month'));
                }
                
                $this->db->execute(
                    "UPDATE installment_contracts SET 
                        paid_amount = ?,
                        paid_periods = LEAST(?, total_periods),
                        status = ?,
                        next_due_date = ?,
                        last_payment_date = CURDATE(),
                        completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END,
                        updated_at = NOW()
                    WHERE id = ?",
                    [$newPaidAmount, $newPaidPeriods, $newStatus, $nextDueDate, $newStatus, $installmentId]
                );
            }
            
            Logger::info('[PAYMENT_LINKING] Payment linked to installment', [
                'payment_id' => $paymentId,
                'installment_id' => $installmentId,
                'amount' => $amount,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Logger::error('[PAYMENT_LINKING] Failed to link payment to installment', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId,
                'installment_id' => $installmentId,
            ]);
            return false;
        }
    }
    
    /**
     * Link payment to pawn contract
     * 
     * @param int $paymentId Payment ID
     * @param int $pawnId Pawn ID
     * @param string $paymentType 'interest' or 'redemption'
     * @param float $amount Payment amount (0 = use payment amount)
     * @return bool Success
     */
    public function linkPaymentToPawn(int $paymentId, int $pawnId, string $paymentType = 'interest', float $amount = 0): bool
    {
        try {
            // Get payment amount if not specified
            if ($amount <= 0) {
                $payment = $this->db->queryOne("SELECT amount FROM payments WHERE id = ?", [$paymentId]);
                $amount = (float)($payment['amount'] ?? 0);
            }
            
            // Update payment with pawn reference
            $this->db->execute(
                "UPDATE payments SET 
                    pawn_id = ?, 
                    payment_type = ?, 
                    updated_at = NOW() 
                WHERE id = ?",
                [$pawnId, $paymentType, $paymentId]
            );
            
            // Update pawn based on payment type
            if ($paymentType === 'redemption') {
                // Full redemption
                $this->db->execute(
                    "UPDATE pawns SET 
                        status = 'redeemed',
                        redeemed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?",
                    [$pawnId]
                );
            } else {
                // Interest payment - extend due date
                $this->db->execute(
                    "UPDATE pawns SET 
                        interest_paid = COALESCE(interest_paid, 0) + ?,
                        due_date = DATE_ADD(due_date, INTERVAL 1 MONTH),
                        status = 'active',
                        updated_at = NOW()
                    WHERE id = ?",
                    [$amount, $pawnId]
                );
            }
            
            Logger::info('[PAYMENT_LINKING] Payment linked to pawn', [
                'payment_id' => $paymentId,
                'pawn_id' => $pawnId,
                'payment_type' => $paymentType,
                'amount' => $amount,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Logger::error('[PAYMENT_LINKING] Failed to link payment to pawn', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId,
                'pawn_id' => $pawnId,
            ]);
            return false;
        }
    }
    
    /**
     * Get payment type label in Thai
     * 
     * @param string $type Payment type
     * @return string Thai label
     */
    public function getPaymentTypeLabel(string $type): string
    {
        $labels = [
            'order' => 'ชำระค่าสินค้า',
            'installment' => 'ชำระค่างวด',
            'interest' => 'ชำระดอกเบี้ย',
            'redemption' => 'ไถ่ถอน',
            'deposit' => 'มัดจำ',
            'other' => 'อื่นๆ',
        ];
        
        return $labels[$type] ?? $type;
    }
    
    /**
     * Parse amount from text
     * 
     * @param mixed $amount Amount value (string/int/float)
     * @return float Parsed amount
     */
    public function parseAmount($amount): float
    {
        if (is_numeric($amount)) {
            return (float)$amount;
        }
        
        if (is_string($amount)) {
            // Remove currency symbols and commas
            $cleaned = preg_replace('/[฿$,\s]/', '', $amount);
            if (is_numeric($cleaned)) {
                return (float)$cleaned;
            }
        }
        
        return 0.0;
    }
}
