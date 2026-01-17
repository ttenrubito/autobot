<?php
/**
 * Bot Payments API
 * 
 * Endpoints:
 * POST /api/bot/payments/submit         - Submit payment (with or without order_id)
 * POST /api/bot/payments/draft-order    - Create draft order from payment
 * GET  /api/bot/payments/{id}           - Get payment by ID
 * GET  /api/bot/payments/by-user        - Get payments by external_user_id
 * 
 * @version 2.0
 * @date 2026-01-06
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Logger.php';

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));

// Expected: /api/bot/payments/{action_or_id}
// Also support router-provided params via $_GET
$action_or_id = $_GET['action'] ?? $_GET['payment_id'] ?? ($uri_parts[3] ?? null);

try {
    $db = Database::getInstance();

    // Route to appropriate handler
    if ($method === 'POST' && $action_or_id === 'submit') {
        // POST /api/bot/payments/submit
        submitPayment($db);
    } elseif ($method === 'POST' && $action_or_id === 'draft-order') {
        // POST /api/bot/payments/draft-order
        createDraftOrder($db);
    } elseif ($method === 'GET' && $action_or_id === 'by-user') {
        // GET /api/bot/payments/by-user?channel_id=X&external_user_id=Y
        getPaymentsByUser($db);
    } elseif ($method === 'GET' && is_numeric($action_or_id)) {
        // GET /api/bot/payments/{id}
        getPayment($db, (int) $action_or_id);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }

} catch (Exception $e) {
    Logger::error('Bot Payments API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Generate unique payment number
 */
function generatePaymentNo(): string
{
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "PAY-{$date}-{$random}";
}

/**
 * Generate unique order number
 */
function generateOrderNo(): string
{
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "ORD-{$date}-{$random}";
}

/**
 * Submit payment
 * 
 * Flow:
 * 1. If order_id provided → link to existing order
 * 2. If no order_id but has product_ref_id → create draft order
 * 3. If no order_id and no product_ref_id → create pending payment for admin to link
 * 
 * Required: channel_id, external_user_id, platform, slip_image_url OR amount
 * Optional: order_id, product_ref_id, product_name, payment_type, payment_method, slip_ocr_data
 */
function submitPayment($db)
{
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    $required = ['channel_id', 'external_user_id', 'platform'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            return;
        }
    }

    // Must have either slip_image_url or amount
    if (empty($input['slip_image_url']) && empty($input['amount'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Must provide either slip_image_url or amount']);
        return;
    }

    $orderId = $input['order_id'] ?? null;
    $customerId = $input['customer_id'] ?? null;
    $productRefId = $input['product_ref_id'] ?? null;
    $amount = (float) ($input['amount'] ?? 0);
    $paymentType = $input['payment_type'] ?? 'full';
    $needsDraftOrder = false;

    // Validate payment_type
    $validPaymentTypes = ['full', 'installment', 'deposit', 'savings_deposit'];
    if (!in_array($paymentType, $validPaymentTypes)) {
        $paymentType = 'full';
    }

    // If order_id provided, validate it exists
    if ($orderId) {
        $order = $db->queryOne("SELECT id, customer_id, total_amount FROM orders WHERE id = ?", [$orderId]);
        if (!$order) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            return;
        }
        $customerId = $customerId ?? $order['customer_id'];
        if (!$amount)
            $amount = (float) $order['total_amount'];
    } else {
        // No order_id - check if we should create a draft order
        if ($productRefId) {
            $needsDraftOrder = true;
        }
        // If no product_ref_id either, we'll create a pending payment for admin to link
    }

    // Create draft order if needed
    if ($needsDraftOrder && !$orderId) {
        $draftOrderResult = createDraftOrderInternal($db, [
            'channel_id' => $input['channel_id'],
            'external_user_id' => $input['external_user_id'],
            'platform' => $input['platform'],
            'customer_id' => $customerId,
            'product_ref_id' => $productRefId,
            'product_name' => $input['product_name'] ?? 'สินค้า (รอระบุ)',
            'product_code' => $input['product_code'] ?? null,
            'total_amount' => $amount ?: null,
            'payment_type' => $paymentType === 'savings_deposit' ? 'savings' : ($paymentType === 'installment' ? 'installment' : 'full'),
            'source' => 'chatbot',
            'notes' => 'Draft order created from payment submission'
        ]);

        if ($draftOrderResult['success']) {
            $orderId = $draftOrderResult['order_id'];
            $customerId = $draftOrderResult['customer_id'];
        }
    }

    // If we still don't have a customer_id, try to find or create one
    if (!$customerId && !$orderId) {
        // Look up existing user by external_user_id via chat_sessions
        $existingSession = $db->queryOne(
            "SELECT cs.id, cs.channel_id, cs.external_user_id FROM chat_sessions cs 
             WHERE cs.channel_id = ? AND cs.external_user_id = ? LIMIT 1",
            [(int) $input['channel_id'], (string) $input['external_user_id']]
        );

        // For now, we'll set customer_id to 0 and let admin link it later
        // In production, you might want to create a user or require login
        $customerId = 0;
    }

    // Create payment record
    $paymentNo = generatePaymentNo();
    $slipOcrData = isset($input['slip_ocr_data']) ? json_encode($input['slip_ocr_data']) : null;
    $paymentDetails = json_encode([
        'channel_id' => $input['channel_id'],
        'external_user_id' => $input['external_user_id'],
        'platform' => $input['platform'],
        'product_ref_id' => $productRefId,
        'product_name' => $input['product_name'] ?? null,
        'slip_ocr_data' => $input['slip_ocr_data'] ?? null,
        'case_id' => $input['case_id'] ?? null,
        'session_id' => $input['session_id'] ?? null,
        'submitted_at' => date('c')
    ]);

    // Use a placeholder order_id if we don't have one (0 means unlinked)
    $orderIdForInsert = $orderId ?: 0;

    // Get customer_profile_id if we have platform_user_id
    $customerProfileId = null;
    if (!empty($input['external_user_id']) && !empty($input['platform'])) {
        $profile = $db->queryOne(
            "SELECT id FROM customer_profiles WHERE platform_user_id = ? AND platform = ? LIMIT 1",
            [$input['external_user_id'], $input['platform']]
        );
        $customerProfileId = $profile ? $profile['id'] : null;
    }

    $sql = "INSERT INTO payments (
        payment_no, order_id, customer_id, tenant_id,
        platform_user_id, platform,
        amount, payment_type, payment_method,
        status, slip_image, payment_details,
        payment_date, source, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?,
        ?, ?,
        ?, ?, ?,
        'pending', ?, ?,
        ?, 'chatbot', NOW(), NOW()
    )";

    $params = [
        $paymentNo,
        $orderIdForInsert,
        $customerProfileId,                   // customer_id (FK to customer_profiles)
        $input['tenant_id'] ?? 'default',
        $input['external_user_id'] ?? null,   // platform_user_id
        $input['platform'] ?? null,           // platform
        $amount,
        $paymentType,
        $input['payment_method'] ?? 'bank_transfer',
        $input['slip_image_url'] ?? null,     // slip_image column
        $paymentDetails,
        $input['payment_time'] ?? null        // payment_date
    ];

    $db->execute($sql, $params);
    $paymentId = $db->lastInsertId();

    // Update case if case_id provided
    if (!empty($input['case_id'])) {
        $db->execute(
            "UPDATE cases SET payment_id = ?, status = 'pending_admin', updated_at = NOW() WHERE id = ?",
            [$paymentId, (int) $input['case_id']]
        );
    }

    Logger::info('Payment submitted', [
        'payment_id' => $paymentId,
        'payment_no' => $paymentNo,
        'order_id' => $orderId,
        'amount' => $amount,
        'has_slip' => !empty($input['slip_image_url'])
    ]);

    echo json_encode([
        'success' => true,
        'message' => $orderId ? 'Payment submitted successfully' : 'Payment submitted, pending order link',
        'data' => [
            'payment_id' => $paymentId,
            'payment_no' => $paymentNo,
            'order_id' => $orderId,
            'amount' => $amount,
            'status' => 'pending',
            'needs_order_link' => !$orderId,
            'draft_order_created' => $needsDraftOrder && $orderId
        ]
    ]);
}

/**
 * Create draft order (internal function)
 */
function createDraftOrderInternal($db, array $data): array
{
    $orderNo = generateOrderNo();

    // Get or create customer
    $customerId = $data['customer_id'] ?? null;
    $userId = $data['user_id'] ?? null;

    // ✅ Lookup user_id from customer_profiles if not provided
    if (!$userId && !empty($data['external_user_id']) && !empty($data['platform'])) {
        $customerProfile = $db->queryOne(
            "SELECT id, user_id FROM customer_profiles WHERE platform_user_id = ? AND platform = ? LIMIT 1",
            [$data['external_user_id'], $data['platform']]
        );
        if ($customerProfile) {
            $userId = $customerProfile['user_id'] ?? null;
            $customerId = $customerId ?? $customerProfile['id'];
        }
    }

    if (!$customerId) {
        // Create placeholder user directly (without channel linking)
        $db->execute(
            "INSERT INTO users (email, full_name, phone, password_hash, created_at, updated_at) VALUES (?, ?, ?, '', NOW(), NOW())",
            [
                'chatbot_' . $data['external_user_id'] . '@placeholder.local',
                'Customer ' . substr($data['external_user_id'], -8),
                ''
            ]
        );
        $customerId = $db->lastInsertId();
    }

    $totalAmount = (float) ($data['total_amount'] ?? 0);
    $unitPrice = $totalAmount; // unit_price = total_amount for single item

    $sql = "INSERT INTO orders (
        order_no, user_id, platform_user_id, customer_id, tenant_id,
        product_name, product_code, product_ref_id,
        quantity, unit_price, total_amount,
        payment_type, status, source, notes,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?,
        1, ?, ?,
        ?, 'pending', ?, ?,
        NOW(), NOW()
    )";

    $params = [
        $orderNo,
        $userId,                           // ✅ Now properly looked up
        $data['external_user_id'] ?? null, // platform_user_id for JOIN
        $customerId,
        $data['tenant_id'] ?? 'default',
        $data['product_name'] ?? 'สินค้า (รอระบุ)',
        $data['product_code'] ?? null,
        $data['product_ref_id'] ?? null,
        $unitPrice,                        // ✅ unit_price
        $totalAmount,
        $data['payment_type'] ?? 'full',
        $data['source'] ?? 'chatbot',
        $data['notes'] ?? 'Draft order'
    ];

    $db->execute($sql, $params);
    $orderId = $db->lastInsertId();

    Logger::info('Draft order created', [
        'order_id' => $orderId,
        'order_no' => $orderNo,
        'customer_id' => $customerId
    ]);

    return [
        'success' => true,
        'order_id' => $orderId,
        'order_no' => $orderNo,
        'customer_id' => $customerId
    ];
}

/**
 * Create draft order (API endpoint)
 */
function createDraftOrder($db)
{
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    $required = ['channel_id', 'external_user_id', 'platform'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            return;
        }
    }

    $result = createDraftOrderInternal($db, $input);

    echo json_encode([
        'success' => true,
        'message' => 'Draft order created',
        'data' => [
            'order_id' => $result['order_id'],
            'order_no' => $result['order_no'],
            'customer_id' => $result['customer_id']
        ]
    ]);
}

/**
 * Get payment by ID
 */
function getPayment($db, int $paymentId)
{
    $payment = $db->queryOne("SELECT * FROM payments WHERE id = ?", [$paymentId]);

    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        return;
    }

    // Decode JSON fields
    $payment['payment_details'] = $payment['payment_details'] ? json_decode($payment['payment_details'], true) : null;

    // Get order info if linked
    if ($payment['order_id'] && $payment['order_id'] > 0) {
        $order = $db->queryOne(
            "SELECT order_no, product_name, product_code, total_amount, status FROM orders WHERE id = ?",
            [$payment['order_id']]
        );
        $payment['order'] = $order;
    }

    echo json_encode([
        'success' => true,
        'data' => $payment
    ]);
}

/**
 * Get payments by user
 */
function getPaymentsByUser($db)
{
    $channelId = $_GET['channel_id'] ?? null;
    $externalUserId = $_GET['external_user_id'] ?? null;
    $status = $_GET['status'] ?? null;

    if (!$channelId || !$externalUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing channel_id or external_user_id']);
        return;
    }

    // Try to find payments via payment_details JSON
    $sql = "SELECT p.*, o.order_no, o.product_name, o.status as order_status
            FROM payments p
            LEFT JOIN orders o ON p.order_id = o.id
            WHERE JSON_EXTRACT(p.payment_details, '$.channel_id') = ?
              AND JSON_EXTRACT(p.payment_details, '$.external_user_id') = ?";
    $params = [(int) $channelId, (string) $externalUserId];

    if ($status) {
        $sql .= " AND p.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY p.created_at DESC";

    $payments = $db->queryAll($sql, $params);

    foreach ($payments as &$payment) {
        $payment['payment_details'] = $payment['payment_details'] ? json_decode($payment['payment_details'], true) : null;
    }

    echo json_encode([
        'success' => true,
        'data' => $payments,
        'count' => count($payments)
    ]);
}
