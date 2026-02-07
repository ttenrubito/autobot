<?php
/**
 * OrderService - Business logic for order operations
 * 
 * @version 1.0
 * @date 2026-01-31
 */

namespace App\Services;

use PDO;
use Exception;

class OrderService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \getDB();
    }
    
    /**
     * Find pending orders for a customer (for slip matching)
     * @param string $platformUserId Platform user ID
     * @param int|null $channelId Channel ID
     * @param float|null $excludeAmount Exclude orders with this exact amount
     * @return array List of pending orders
     */
    public function findPendingOrders(string $platformUserId, ?int $channelId = null, ?float $excludeAmount = null): array
    {
        $sql = "SELECT o.* FROM orders o
                WHERE o.platform_user_id = ?
                AND o.status IN ('pending', 'processing')
                ORDER BY o.created_at DESC
                LIMIT 10";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$platformUserId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter out exact amount match if specified
        if ($excludeAmount && $excludeAmount > 0) {
            $orders = array_filter($orders, function($order) use ($excludeAmount) {
                $orderAmount = (float)($order['total_amount'] ?? 0);
                return abs($orderAmount - $excludeAmount) > 1; // 1 baht tolerance
            });
            $orders = array_values($orders);
        }
        
        return $orders;
    }
    
    /**
     * Link a payment to an order (auto-match from slip)
     * @param int $paymentId Payment ID
     * @param int $orderId Order ID
     * @return array ['success' => bool]
     */
    public function linkPaymentToOrder(int $paymentId, int $orderId): array
    {
        try {
            // Get order info for installment calculation
            $orderStmt = $this->db->prepare("SELECT payment_type, installment_months FROM orders WHERE id = ?");
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            
            $installmentPeriod = null;
            $currentPeriod = null;
            $paymentType = $order['payment_type'] ?? 'full';
            
            // Calculate current_period for installment payments
            if ($paymentType === 'installment') {
                $installmentPeriod = (int)($order['installment_months'] ?? 3);
                
                // Count verified payments for this order
                $countStmt = $this->db->prepare("
                    SELECT COUNT(*) as paid_count 
                    FROM payments 
                    WHERE order_id = ? 
                    AND status = 'verified' 
                    AND payment_type = 'installment'
                ");
                $countStmt->execute([$orderId]);
                $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                $paidCount = (int)($countResult['paid_count'] ?? 0);
                
                // Current period is paid_count + 1
                $currentPeriod = $paidCount + 1;
                if ($currentPeriod > $installmentPeriod) {
                    $currentPeriod = $installmentPeriod;
                }
            }
            
            $stmt = $this->db->prepare("
                UPDATE payments 
                SET order_id = ?, 
                    classified_as = 'order',
                    payment_type = ?,
                    installment_period = ?,
                    current_period = ?,
                    match_status = 'auto_matched',
                    matched_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$orderId, $paymentType, $installmentPeriod, $currentPeriod, $paymentId]);
            
            return ['success' => true, 'current_period' => $currentPeriod, 'installment_period' => $installmentPeriod];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get order by ID
     */
    public function getOrderById(int $orderId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get order by order number
     */
    public function getOrderByNo(string $orderNo): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE order_no = ?");
        $stmt->execute([$orderNo]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get orders by customer
     * @param int|string $customerIdOrPlatformUser Customer profile ID (int) or platform_user_id (string)
     * @param array $statuses Filter by statuses
     * @param int $limit Max results
     * @return array List of orders
     */
    public function getOrdersByCustomer($customerIdOrPlatformUser, array $statuses = [], int $limit = 20): array
    {
        // Handle both int (customer_profile_id) and string (platform_user_id or "user:X")
        if (is_int($customerIdOrPlatformUser)) {
            $sql = "SELECT * FROM orders WHERE customer_profile_id = ?";
            $params = [$customerIdOrPlatformUser];
        } elseif (strpos($customerIdOrPlatformUser, 'user:') === 0) {
            // Format: "user:123" - extract user_id
            $userId = (int)substr($customerIdOrPlatformUser, 5);
            $sql = "SELECT * FROM orders WHERE user_id = ?";
            $params = [$userId];
        } else {
            // Platform user ID (LINE/Facebook)
            $sql = "SELECT * FROM orders WHERE platform_user_id = ?";
            $params = [$customerIdOrPlatformUser];
        }
        
        if (!empty($statuses)) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $sql .= " AND status IN ({$placeholders})";
            $params = array_merge($params, $statuses);
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Match slip amount to pending order
     * @param array $orders List of pending orders
     * @param float $slipAmount Amount from slip
     * @param float $tolerance Amount tolerance (default 1)
     * @return array|null ['order' => array] or null
     */
    public function matchSlipToOrder(array $orders, float $slipAmount, float $tolerance = 1): ?array
    {
        foreach ($orders as $order) {
            $orderAmount = (float)($order['total_amount'] ?? 0);
            
            if ($orderAmount > 0 && abs($slipAmount - $orderAmount) <= $tolerance) {
                return ['order' => $order];
            }
        }
        
        return null;
    }
    
    /**
     * Update order status
     * @param int $orderId Order ID
     * @param string $status New status
     * @return array ['success' => bool]
     */
    public function updateStatus(int $orderId, string $status): array
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?
            ");
            $stmt->execute([$status, $orderId]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
