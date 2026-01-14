<?php
/**
 * PaymentService - Handles payment slip processing from chatbot
 * 
 * IMPORTANT: This uses the ACTUAL production schema:
 * - payment_no, order_id, customer_id, tenant_id, amount
 * - payment_type, payment_method, status
 * - slip_image (varchar), payment_details (JSON)
 * - payment_date, source, created_at, updated_at
 */

namespace Autobot\Services;

use PDO;
use Exception;

require_once __DIR__ . '/../GoogleCloudStorage.php';

class PaymentService
{
    private PDO $pdo;
    
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \getDB();
    }
    
    /**
     * Download image from URL and upload to GCS
     */
    private function uploadImageToGCS(string $imageUrl, string $tenantId): ?string
    {
        try {
            $this->log('INFO', 'PaymentService - Downloading image for GCS upload', [
                'url' => substr($imageUrl, 0, 100) . '...'
            ]);
            
            // Download image from URL (Facebook CDN, etc.)
            $ch = curl_init($imageUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $imageContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            
            if ($httpCode !== 200 || empty($imageContent)) {
                $this->log('WARNING', 'PaymentService - Failed to download image', [
                    'http_code' => $httpCode,
                    'url' => $imageUrl
                ]);
                return $imageUrl; // Return original URL as fallback
            }
            
            // Determine file extension from content type
            $ext = 'jpg';
            if (strpos($contentType, 'png') !== false) $ext = 'png';
            elseif (strpos($contentType, 'gif') !== false) $ext = 'gif';
            elseif (strpos($contentType, 'webp') !== false) $ext = 'webp';
            
            $fileName = 'slip_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            
            // Upload to GCS
            $gcs = \GoogleCloudStorage::getInstance();
            $result = $gcs->uploadFile(
                $imageContent,
                $fileName,
                $contentType ?: 'image/jpeg',
                'payment-slips/' . $tenantId,
                ['tenant_id' => $tenantId, 'type' => 'payment_slip']
            );
            
            if ($result['success']) {
                $this->log('INFO', 'PaymentService - Image uploaded to GCS', [
                    'path' => $result['path'],
                    'signed_url' => substr($result['signed_url'] ?? '', 0, 100) . '...'
                ]);
                // Return signed URL for display
                return $result['signed_url'] ?? $result['url'];
            }
            
            $this->log('WARNING', 'PaymentService - GCS upload failed', [
                'error' => $result['error'] ?? 'unknown'
            ]);
            return $imageUrl; // Return original URL as fallback
            
        } catch (Exception $e) {
            $this->log('ERROR', 'PaymentService - GCS upload exception', [
                'error' => $e->getMessage()
            ]);
            return $imageUrl; // Return original URL as fallback
        }
    }
    
    /**
     * Process payment slip from chatbot
     * 
     * @param array $slipData OCR data from Gemini Vision
     * @param array $context Chat context (external_user_id, platform, etc.)
     * @param string|null $imageUrl Slip image URL
     * @return array Result with success status, payment_id, matched_order, etc.
     */
    public function processSlipFromChatbot(array $slipData, array $context, ?string $imageUrl = null): array
    {
        $this->log('INFO', 'PaymentService::processSlipFromChatbot - START', [
            'slipData' => $slipData,
            'context_keys' => array_keys($context),
            'has_imageUrl' => !empty($imageUrl)
        ]);
        
        try {
            // Extract slip data
            $amount = $this->parseAmount($slipData['amount'] ?? null);
            $bank = $slipData['bank'] ?? null;
            $slipDate = $slipData['date'] ?? null;
            $paymentRef = $slipData['ref'] ?? null;
            $senderName = $slipData['sender_name'] ?? null;
            $receiverName = $slipData['receiver_name'] ?? null;
            
            $this->log('INFO', 'PaymentService - Parsed slip data', [
                'amount' => $amount,
                'bank' => $bank,
                'paymentRef' => $paymentRef,
                'senderName' => $senderName
            ]);
            
            // 1. Check for duplicates by payment_ref in payment_details JSON
            if ($paymentRef) {
                $duplicate = $this->checkDuplicate($paymentRef);
                if ($duplicate) {
                    $this->log('INFO', 'PaymentService - Duplicate detected', [
                        'existing_id' => $duplicate['id'],
                        'existing_payment_no' => $duplicate['payment_no']
                    ]);
                    return [
                        'success' => false,
                        'is_duplicate' => true,
                        'existing_payment_id' => $duplicate['id'],
                        'existing_payment_no' => $duplicate['payment_no'],
                        'message' => 'สลิปนี้เคยส่งมาแล้วค่ะ'
                    ];
                }
            }
            
            // 2. Find customer by external_user_id
            $externalUserId = $context['external_user_id'] ?? null;
            $platform = $context['platform'] ?? 'facebook';
            $tenantId = $context['tenant_id'] ?? 'default';
            $channelId = $context['channel']['id'] ?? ($context['channel_id'] ?? null);
            
            // Find or create customer_profile for this chatbot user
            $customer = $this->findOrCreateCustomerProfile($externalUserId, $platform, $tenantId, $senderName);
            // Now payments.customer_id -> customer_profiles.id (after schema migration)
            $customerId = $customer['id'] ?? null;
            $customerName = $customer['full_name'] ?? ($customer['display_name'] ?? $senderName);
            $customerPhone = $customer['phone'] ?? null;
            
            $this->log('INFO', 'PaymentService - Customer lookup', [
                'external_user_id' => $externalUserId,
                'found_customer' => !empty($customer),
                'customer_id' => $customerId
            ]);
            
            // 3. Try to auto-match with pending order
            $matchedOrder = null;
            if ($amount > 0 && ($customerId || $externalUserId)) {
                $matchedOrder = $this->tryAutoMatchOrder($amount, $customerId, $externalUserId);
            }
            
            $this->log('INFO', 'PaymentService - Order matching', [
                'amount' => $amount,
                'matched_order' => $matchedOrder ? $matchedOrder['id'] : null,
                'matched_order_no' => $matchedOrder['order_number'] ?? null
            ]);
            
            // 4. Parse transfer time
            $transferTime = $this->parseTransferTime($slipDate);
            
            // 5. Generate payment_no
            $paymentNo = 'PAY-BOT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            
            // 5.5. Upload image to GCS (if it's an external URL like Facebook CDN)
            $finalImageUrl = $imageUrl;
            if ($imageUrl && (strpos($imageUrl, 'http') === 0)) {
                $gcsUrl = $this->uploadImageToGCS($imageUrl, $tenantId);
                if ($gcsUrl) {
                    $finalImageUrl = $gcsUrl;
                }
            }
            
            // 6. Build payment_details JSON (store ALL extra data here)
            $paymentDetails = json_encode([
                'source' => 'chatbot',
                'platform' => $platform,
                'channel_id' => $channelId,
                'external_user_id' => $externalUserId,
                'payment_ref' => $paymentRef,
                'bank_name' => $bank,
                'sender_name' => $senderName,
                'receiver_name' => $receiverName,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'transfer_time' => $transferTime,
                'ocr_result' => $slipData,
                'matched_order_id' => $matchedOrder['id'] ?? null,
                'original_image_url' => $imageUrl, // Keep original for reference
            ], JSON_UNESCAPED_UNICODE);
            
            // 7. Determine order_id (NULL if no match)
            $orderId = $matchedOrder['id'] ?? null;
            
            // 8. INSERT using ACTUAL production schema
            // Columns that exist: payment_no, order_id, customer_id, tenant_id, amount,
            //                     payment_type, payment_method, status, slip_image,
            //                     payment_details, payment_date, source, created_at, updated_at
            $sql = "
                INSERT INTO payments (
                    payment_no,
                    order_id,
                    customer_id,
                    tenant_id,
                    amount,
                    payment_type,
                    payment_method,
                    status,
                    slip_image,
                    payment_details,
                    payment_date,
                    source,
                    created_at,
                    updated_at
                ) VALUES (
                    :payment_no,
                    :order_id,
                    :customer_id,
                    :tenant_id,
                    :amount,
                    'full',
                    'bank_transfer',
                    'pending',
                    :slip_image,
                    :payment_details,
                    :payment_date,
                    'chatbot',
                    NOW(),
                    NOW()
                )
            ";
            
            $params = [
                ':payment_no' => $paymentNo,
                ':order_id' => $orderId,
                ':customer_id' => $customerId, // Now FK to customer_profiles.id
                ':tenant_id' => $tenantId,
                ':amount' => $amount,
                ':slip_image' => $finalImageUrl, // Use GCS URL if upload succeeded, else original
                ':payment_details' => $paymentDetails,
                ':payment_date' => $transferTime ?: date('Y-m-d H:i:s'),
            ];
            
            $this->log('INFO', 'PaymentService - Executing INSERT', [
                'payment_no' => $paymentNo,
                'customer_id' => $customerId,
                'order_id' => $orderId,
                'amount' => $amount,
                'sql_full' => $sql,
                'params_keys' => array_keys($params)
            ]);
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $paymentId = (int)$this->pdo->lastInsertId();
            
            $this->log('INFO', 'PaymentService - INSERT SUCCESS', [
                'payment_id' => $paymentId,
                'payment_no' => $paymentNo,
                'amount' => $amount,
                'matched_order_id' => $orderId
            ]);
            
            return [
                'success' => true,
                'payment_id' => $paymentId,
                'payment_no' => $paymentNo,
                'amount' => $amount,
                'matched_order' => $matchedOrder,
                'matched_order_no' => $matchedOrder['order_number'] ?? null,
                'message' => $matchedOrder 
                    ? "บันทึกการชำระเงินแล้ว (ออเดอร์ #{$matchedOrder['order_number']})"
                    : "บันทึกการชำระเงินแล้ว รอเจ้าหน้าที่ตรวจสอบ"
            ];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'PaymentService - EXCEPTION', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'เกิดข้อผิดพลาดในการบันทึก กรุณาลองใหม่'
            ];
        }
    }
    
    /**
     * Check if payment_ref already exists within 24 hours
     * Uses JSON_EXTRACT on payment_details column
     */
    private function checkDuplicate(string $paymentRef): ?array
    {
        // payment_ref is stored inside payment_details JSON
        $sql = "
            SELECT id, payment_no, amount, status, created_at
            FROM payments 
            WHERE JSON_UNQUOTE(JSON_EXTRACT(payment_details, '$.payment_ref')) = :payment_ref
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            LIMIT 1
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':payment_ref' => $paymentRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ?: null;
    }
    
    /**
     * Find or create customer profile for chatbot user
     */
    private function findOrCreateCustomerProfile(?string $externalUserId, string $platform, string $tenantId, ?string $displayName): ?array
    {
        if (!$externalUserId) {
            return null;
        }
        
        // First try to find existing
        $sql = "
            SELECT id, full_name, phone, email, platform, display_name, tenant_id
            FROM customer_profiles
            WHERE platform_user_id = :external_id AND tenant_id = :tenant_id
            LIMIT 1
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':external_id' => $externalUserId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            return $row;
        }
        
        // Create new customer_profile
        $sql = "
            INSERT INTO customer_profiles (tenant_id, platform, platform_user_id, display_name, first_seen_at, last_active_at, created_at, updated_at)
            VALUES (:tenant_id, :platform, :external_id, :display_name, NOW(), NOW(), NOW(), NOW())
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':platform' => $platform,
            ':external_id' => $externalUserId,
            ':display_name' => $displayName ?? 'Unknown'
        ]);
        
        $newId = (int)$this->pdo->lastInsertId();
        
        $this->log('INFO', 'PaymentService - Created new customer_profile', [
            'id' => $newId,
            'tenant_id' => $tenantId,
            'platform' => $platform,
            'external_id' => $externalUserId
        ]);
        
        return [
            'id' => $newId,
            'tenant_id' => $tenantId,
            'platform' => $platform,
            'platform_user_id' => $externalUserId,
            'display_name' => $displayName,
            'full_name' => null,
            'phone' => null,
            'email' => null
        ];
    }
    
    /**
     * Find customer by external platform user ID (legacy - without tenant filter)
     */
    private function findCustomerByExternalId(?string $externalUserId): ?array
    {
        if (!$externalUserId) {
            return null;
        }
        
        // customer_profiles schema: id, tenant_id, platform, platform_user_id, display_name, full_name, phone, email
        $sql = "
            SELECT id, tenant_id, full_name, phone, email, platform, display_name
            FROM customer_profiles
            WHERE platform_user_id = :external_id
            LIMIT 1
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':external_id' => $externalUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ?: null;
    }
    
    /**
     * Try to auto-match payment with a pending order
     * Match by amount and customer (within reasonable time window)
     * Note: $customerId is from customer_profiles.id (after schema migration)
     */
    private function tryAutoMatchOrder(float $amount, ?int $customerId, ?string $externalUserId): ?array
    {
        if ($amount <= 0) {
            return null;
        }
        
        // Try to match by customer_id first (orders.customer_id = customer_profiles.id)
        if ($customerId > 0) {
            $sql = "
                SELECT id, order_number, total_amount, status
                FROM orders
                WHERE customer_id = :customer_id
                AND status IN ('pending_payment', 'awaiting_payment')
                AND total_amount = :amount
                AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY created_at DESC
                LIMIT 1
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':customer_id' => $customerId,
                ':amount' => $amount
            ]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                $this->log('INFO', 'PaymentService - Matched order by customer_id', [
                    'customer_id' => $customerId,
                    'order_id' => $order['id'],
                    'order_number' => $order['order_number']
                ]);
                return $order;
            }
        }
        
        // Try to match by external_user_id via customer_profiles
        if ($externalUserId) {
            // orders.customer_id links to customer_profiles.id (not user_id)
            $sql = "
                SELECT o.id, o.order_number, o.total_amount, o.status
                FROM orders o
                JOIN customer_profiles cp ON o.customer_id = cp.id
                WHERE cp.platform_user_id = :external_id
                AND o.status IN ('pending_payment', 'awaiting_payment')
                AND o.total_amount = :amount
                AND o.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY o.created_at DESC
                LIMIT 1
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':external_id' => $externalUserId,
                ':amount' => $amount
            ]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                $this->log('INFO', 'PaymentService - Matched order by external_user_id', [
                    'external_user_id' => $externalUserId,
                    'order_id' => $order['id'],
                    'order_number' => $order['order_number']
                ]);
                return $order;
            }
        }
        
        return null;
    }
    
    /**
     * Parse amount from various formats
     */
    private function parseAmount($amount): float
    {
        if (is_numeric($amount)) {
            return (float)$amount;
        }
        
        if (is_string($amount)) {
            // Remove non-numeric characters except decimal point
            $cleaned = preg_replace('/[^\d.]/', '', $amount);
            return (float)$cleaned;
        }
        
        return 0.0;
    }
    
    /**
     * Parse transfer time from slip date
     */
    private function parseTransferTime(?string $slipDate): ?string
    {
        if (!$slipDate) {
            return null;
        }
        
        // Try strtotime first
        $timestamp = strtotime($slipDate);
        if ($timestamp && $timestamp > 0) {
            return date('Y-m-d H:i:s', $timestamp);
        }
        
        // Try Thai date format: "12 ม.ค. 69, 17:54"
        // This is complex, just return current time for now
        return date('Y-m-d H:i:s');
    }
    
    /**
     * Log helper
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (class_exists('\Logger')) {
            if ($level === 'ERROR') {
                \Logger::error($message, $context);
            } else {
                \Logger::info($message, $context);
            }
        } else {
            error_log("[PaymentService] {$level}: {$message} " . json_encode($context));
        }
    }
}
