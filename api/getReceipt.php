<?php
/**
 * Receipt/Payment Slip Verification API
 * Called by chatbot when customer sends payment slip image
 * 
 * This API:
 * 1. Receives slip data from Gemini Vision analysis (amount, bank, date, etc.)
 * 2. Saves to payments table with status 'pending'
 * 3. Tries to auto-match with existing orders
 * 4. Returns status for bot to respond to customer
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Logger.php';

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    Logger::info('Receipt Verification API called', ['data' => $data]);
    
    // Extract data from request (sent by chatbot after Gemini analysis)
    $amount = $data['amount'] ?? null;
    $time = $data['time'] ?? null;
    $senderName = $data['sender_name'] ?? null;
    $paymentRef = $data['payment_ref'] ?? null;
    $slipImageUrl = $data['slip_image_url'] ?? null;
    $bank = $data['bank'] ?? null;
    $visionText = $data['vision_text'] ?? null;
    $geminiDetails = $data['gemini_details'] ?? [];
    
    // Context from chatbot
    $channelId = $data['channel_id'] ?? null;
    $externalUserId = $data['external_user_id'] ?? null;
    $customerId = $data['customer_id'] ?? null;
    $customerProfileId = $data['customer_profile_id'] ?? null;
    $customerName = $data['customer_name'] ?? $senderName;
    $customerPhone = $data['customer_phone'] ?? null;
    $customerPlatform = $data['customer_platform'] ?? null;
    $customerAvatar = $data['customer_avatar'] ?? null;
    
    // Parse amount to float
    $amountFloat = null;
    if ($amount) {
        $amountFloat = (float)preg_replace('/[^0-9.]/', '', str_replace([',', ' ', 'บาท'], '', $amount));
    }
    
    // Get database connection
    $pdo = getDB();
    
    // Get tenant/user info from channel
    $userId = null;
    $tenantId = 'default';
    
    if ($channelId) {
        $stmt = $pdo->prepare("SELECT user_id FROM channels WHERE id = ? LIMIT 1");
        $stmt->execute([$channelId]);
        $channel = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($channel) {
            $userId = $channel['user_id'];
            
            // Get tenant_id from user
            $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $tenantId = $user['tenant_id'] ?? 'default';
            }
        }
    }
    
    // Try to find customer_profile_id if not provided
    if (!$customerProfileId && $externalUserId && $channelId) {
        $stmt = $pdo->prepare("
            SELECT id, display_name, phone, avatar_url 
            FROM customer_profiles 
            WHERE platform_user_id = ? AND channel_id = ?
            LIMIT 1
        ");
        $stmt->execute([$externalUserId, $channelId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($profile) {
            $customerProfileId = $profile['id'];
            $customerName = $customerName ?: $profile['display_name'];
            $customerPhone = $customerPhone ?: $profile['phone'];
            $customerAvatar = $customerAvatar ?: $profile['avatar_url'];
        }
    }
    
    // Generate payment reference number
    $paymentNo = 'PAY-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    // ==========================================
    // Try to auto-match with existing orders
    // ==========================================
    $matchedOrderId = null;
    $matchedOrderNo = null;
    $matchConfidence = 0;
    
    if ($userId && $customerProfileId) {
        // Strategy 1: Find pending orders for this customer
        $stmt = $pdo->prepare("
            SELECT id, order_number, total_amount, paid_amount, status 
            FROM orders 
            WHERE user_id = ? 
              AND customer_profile_id = ?
              AND status IN ('pending', 'confirmed', 'processing')
              AND (paid_amount IS NULL OR paid_amount < total_amount)
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$userId, $customerProfileId]);
        $pendingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pendingOrders as $order) {
            $remainingAmount = $order['total_amount'] - ($order['paid_amount'] ?? 0);
            
            // Exact amount match
            if ($amountFloat && abs($remainingAmount - $amountFloat) < 1) {
                $matchedOrderId = $order['id'];
                $matchedOrderNo = $order['order_number'];
                $matchConfidence = 0.95;
                break;
            }
            
            // Partial match (within 10%)
            if ($amountFloat && $remainingAmount > 0 && abs($remainingAmount - $amountFloat) / $remainingAmount < 0.1) {
                $matchedOrderId = $order['id'];
                $matchedOrderNo = $order['order_number'];
                $matchConfidence = 0.7;
            }
        }
        
        // Strategy 2: If no match by amount, use most recent pending order
        if (!$matchedOrderId && count($pendingOrders) === 1) {
            $matchedOrderId = $pendingOrders[0]['id'];
            $matchedOrderNo = $pendingOrders[0]['order_number'];
            $matchConfidence = 0.5;
        }
    }
    
    // ==========================================
    // Save payment to database
    // ==========================================
    $ocrData = json_encode([
        'amount' => $amount,
        'amount_float' => $amountFloat,
        'bank' => $bank,
        'time' => $time,
        'sender_name' => $senderName,
        'payment_ref' => $paymentRef,
        'vision_text' => $visionText,
        'gemini_details' => $geminiDetails,
        'match_confidence' => $matchConfidence
    ], JSON_UNESCAPED_UNICODE);
    
    $stmt = $pdo->prepare("
        INSERT INTO payments (
            payment_no,
            user_id,
            tenant_id,
            customer_profile_id,
            payment_type,
            reference_type,
            reference_id,
            order_id,
            amount,
            payment_method,
            slip_image_url,
            ocr_data,
            payment_ref,
            sender_name,
            transfer_time,
            status,
            customer_name,
            customer_phone,
            customer_platform,
            customer_platform_id,
            customer_avatar,
            source,
            note,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, 'full', 'order', ?, ?, ?, 'bank_transfer', ?, ?, ?, ?, ?, 'pending',
            ?, ?, ?, ?, ?, 'chatbot', ?, NOW(), NOW()
        )
    ");
    
    $transferTime = $time ? date('Y-m-d H:i:s', strtotime($time)) : date('Y-m-d H:i:s');
    $note = $matchedOrderId 
        ? "Auto-matched with order #{$matchedOrderNo} (confidence: " . round($matchConfidence * 100) . "%)"
        : "รอจับคู่กับ order";
    
    $stmt->execute([
        $paymentNo,
        $userId,
        $tenantId,
        $customerProfileId,
        $matchedOrderId,      // reference_id
        $matchedOrderId,      // order_id
        $amountFloat,
        $slipImageUrl,
        $ocrData,
        $paymentRef,
        $senderName,
        $transferTime,
        $customerName,
        $customerPhone,
        $customerPlatform,
        $externalUserId,
        $customerAvatar,
        $note
    ]);
    
    $paymentId = $pdo->lastInsertId();
    
    Logger::info('Payment saved from chatbot slip', [
        'payment_id' => $paymentId,
        'payment_no' => $paymentNo,
        'amount' => $amountFloat,
        'matched_order_id' => $matchedOrderId,
        'match_confidence' => $matchConfidence,
        'customer_profile_id' => $customerProfileId
    ]);
    
    // ==========================================
    // Build response for chatbot
    // ==========================================
    $response = [
        'success' => true,
        'status' => 'pending_review',
        'payment_id' => $paymentId,
        'payment_no' => $paymentNo,
        'matched_amount' => $amountFloat,
        'matched_order_id' => $matchedOrderId,
        'matched_order_no' => $matchedOrderNo,
        'match_confidence' => $matchConfidence,
        'note' => $matchedOrderId 
            ? "พบออเดอร์ #{$matchedOrderNo} ที่ตรงกัน รอเจ้าหน้าที่ตรวจสอบ"
            : "รอเจ้าหน้าที่ตรวจสอบและจับคู่กับออเดอร์",
        'received_at' => date('Y-m-d H:i:s'),
        'data' => [
            'amount' => $amount,
            'amount_float' => $amountFloat,
            'bank' => $bank,
            'time' => $time,
            'sender_name' => $senderName,
            'payment_ref' => $paymentRef,
            'has_slip_image' => !empty($slipImageUrl)
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    Logger::error('Receipt verification error: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
    ]);
    
    // Even on error, return success with pending status so bot can respond nicely
    echo json_encode([
        'success' => true,
        'status' => 'pending_review',
        'payment_id' => null,
        'payment_no' => 'PAY-' . date('Ymd') . '-ERR',
        'note' => 'รอเจ้าหน้าที่ตรวจสอบ',
        'error_detail' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
