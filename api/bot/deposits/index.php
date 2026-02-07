<?php
/**
 * Bot Deposits API
 * 
 * Endpoints:
 * POST /api/bot/deposits                    - Create new deposit (มัดจำใหม่)
 * GET  /api/bot/deposits/{id}               - Get deposit by ID
 * GET  /api/bot/deposits/by-user            - Get deposits by external_user_id
 * POST /api/bot/deposits/{id}/pay           - Submit deposit payment (สลิปมัดจำ)
 * POST /api/bot/deposits/{id}/convert       - Convert to order (แปลงเป็นออเดอร์)
 * POST /api/bot/deposits/{id}/cancel        - Cancel deposit
 * GET  /api/bot/deposits/{id}/status        - Get deposit status
 * 
 * Business Rules:
 * - Default 10% deposit of product price
 * - Valid for 14 days (configurable)
 * - Can convert to full payment or installment order
 * - Auto-expire after validity period
 * 
 * @version 1.0
 * @date 2026-01-10
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Logger.php';

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Normalize path - remove index.php if present
$path = parse_url($uri, PHP_URL_PATH);
$path = preg_replace('#/index\.php$#', '', $path);
$uri_parts = explode('/', trim($path, '/'));

// Expected: /api/bot/deposits/{id?}/{action?}
$deposit_id = $_GET['deposit_id'] ?? (isset($uri_parts[3]) && is_numeric($uri_parts[3]) ? (int) $uri_parts[3] : null);
$action = $_GET['action'] ?? ($uri_parts[3] ?? $uri_parts[4] ?? null);

// Handle by-user as action
if ($action === 'by-user') {
    $deposit_id = null;
}

try {
    $db = Database::getInstance();

    // Route to appropriate handler
    if ($method === 'POST' && !$deposit_id && $action !== 'by-user') {
        createDeposit($db);
    } elseif ($method === 'GET' && $action === 'by-user') {
        getDepositsByUser($db);
    } elseif ($method === 'GET' && $deposit_id && !$action) {
        getDeposit($db, $deposit_id);
    } elseif ($method === 'GET' && $deposit_id && $action === 'status') {
        getDepositStatus($db, $deposit_id);
    } elseif ($method === 'POST' && $deposit_id && $action === 'pay') {
        submitDepositPayment($db, $deposit_id);
    } elseif ($method === 'POST' && $deposit_id && $action === 'convert') {
        convertDepositToOrder($db, $deposit_id);
    } elseif ($method === 'POST' && $deposit_id && $action === 'cancel') {
        cancelDeposit($db, $deposit_id);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }

} catch (Exception $e) {
    Logger::error('Bot Deposits API Error: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Generate unique deposit number
 */
function generateDepositNo(): string
{
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "DEP-{$date}-{$random}";
}

/**
 * Create new deposit
 * 
 * Required: channel_id, external_user_id, platform, product_ref_id, product_name, product_price
 * Optional: deposit_percent (default 10), valid_days (default 14), customer_name, customer_phone
 */
function createDeposit($db)
{
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    $required = ['channel_id', 'external_user_id', 'platform', 'product_ref_id', 'product_name', 'product_price'];
    foreach ($required as $field) {
        if (empty($input[$field]) && $input[$field] !== 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            return;
        }
    }

    // Validate platform
    $validPlatforms = ['line', 'facebook', 'web', 'instagram'];
    if (!in_array($input['platform'], $validPlatforms)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid platform']);
        return;
    }

    // Check for existing pending/deposited deposit for same product
    $existing = $db->queryOne(
        "SELECT id, deposit_no, status, deposit_amount, expires_at 
         FROM deposits 
         WHERE channel_id = ? AND external_user_id = ? AND product_ref_id = ? 
         AND status IN ('pending_payment', 'deposited')
         ORDER BY created_at DESC LIMIT 1",
        [(int) $input['channel_id'], (string) $input['external_user_id'], $input['product_ref_id']]
    );

    if ($existing) {
        echo json_encode([
            'success' => true,
            'message' => 'มีรายการมัดจำสินค้านี้อยู่แล้วค่ะ',
            'data' => [
                'id' => $existing['id'],
                'deposit_no' => $existing['deposit_no'],
                'status' => $existing['status'],
                'deposit_amount' => (float) $existing['deposit_amount'],
                'expires_at' => $existing['expires_at']
            ],
            'is_existing' => true
        ]);
        return;
    }

    // Calculate deposit details
    $productPrice = (float) $input['product_price'];
    $depositPercent = (float) ($input['deposit_percent'] ?? 10.00);
    $depositAmount = (float) ($input['deposit_amount'] ?? round($productPrice * ($depositPercent / 100), 2));
    $validDays = (int) ($input['valid_days'] ?? 14);

    // Calculate expiry date
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$validDays} days"));

    $depositNo = generateDepositNo();

    // ✅ Get shop_owner_id from channel_id for data isolation
    $shopOwnerId = null;
    if (!empty($input['channel_id'])) {
        $channel = $db->queryOne(
            "SELECT user_id FROM customer_channels WHERE id = ? LIMIT 1",
            [(int) $input['channel_id']]
        );
        $shopOwnerId = $channel ? $channel['user_id'] : null;
    }

    $sql = "INSERT INTO deposits (
        deposit_no, tenant_id, customer_id, customer_profile_id, shop_owner_id,
        channel_id, external_user_id, platform,
        customer_name, customer_phone, customer_line_name,
        product_ref_id, product_name, product_code, product_price,
        deposit_percent, deposit_amount, valid_days, expires_at,
        status, case_id, admin_notes,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        'pending_payment', ?, ?,
        NOW(), NOW()
    )";

    $params = [
        $depositNo,
        $input['tenant_id'] ?? 'default',
        $input['customer_id'] ?? null,
        $input['customer_profile_id'] ?? null,
        $shopOwnerId,                         // ✅ shop_owner_id for data isolation
        (int) $input['channel_id'],
        (string) $input['external_user_id'],
        $input['platform'],
        $input['customer_name'] ?? null,
        $input['customer_phone'] ?? null,
        $input['customer_line_name'] ?? null,
        $input['product_ref_id'],
        $input['product_name'],
        $input['product_code'] ?? null,
        $productPrice,
        $depositPercent,
        $depositAmount,
        $validDays,
        $expiresAt,
        $input['case_id'] ?? null,
        $input['admin_notes'] ?? null
    ];

    $db->execute($sql, $params);
    $newId = $db->lastInsertId();

    Logger::info('Deposit created', [
        'deposit_id' => $newId,
        'deposit_no' => $depositNo,
        'product_ref_id' => $input['product_ref_id'],
        'deposit_amount' => $depositAmount,
        'expires_at' => $expiresAt
    ]);

    // Get bank accounts for payment info
    $banks = $db->queryAll(
        "SELECT bank_name, account_number, account_name 
         FROM bank_accounts 
         WHERE is_active = 1 
         ORDER BY is_primary DESC, display_order ASC"
    );

    echo json_encode([
        'success' => true,
        'message' => 'สร้างรายการมัดจำเรียบร้อยค่ะ',
        'data' => [
            'id' => $newId,
            'deposit_no' => $depositNo,
            'product_ref_id' => $input['product_ref_id'],
            'product_name' => $input['product_name'],
            'product_price' => $productPrice,
            'deposit_percent' => $depositPercent,
            'deposit_amount' => $depositAmount,
            'remaining_amount' => $productPrice - $depositAmount,
            'valid_days' => $validDays,
            'expires_at' => $expiresAt,
            'status' => 'pending_payment',
            'bank_accounts' => $banks
        ],
        'is_existing' => false
    ]);
}

/**
 * Get deposit by ID
 */
function getDeposit($db, int $depositId)
{
    $deposit = $db->queryOne("SELECT * FROM deposits WHERE id = ?", [$depositId]);

    if (!$deposit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการมัดจำ']);
        return;
    }

    // Calculate remaining time
    $now = time();
    $expiresAt = strtotime($deposit['expires_at']);
    $remainingDays = max(0, ceil(($expiresAt - $now) / 86400));
    $isExpired = $now > $expiresAt;

    // Auto-update status if expired
    if ($isExpired && $deposit['status'] === 'pending_payment') {
        $db->execute(
            "UPDATE deposits SET status = 'expired', updated_at = NOW() WHERE id = ?",
            [$depositId]
        );
        $deposit['status'] = 'expired';
    }

    echo json_encode([
        'success' => true,
        'data' => array_merge($deposit, [
            'product_price' => (float) $deposit['product_price'],
            'deposit_amount' => (float) $deposit['deposit_amount'],
            'remaining_amount' => (float) $deposit['remaining_amount'],
            'remaining_days' => $remainingDays,
            'is_expired' => $isExpired
        ])
    ]);
}

/**
 * Get deposits by user
 */
function getDepositsByUser($db)
{
    $channelId = $_GET['channel_id'] ?? null;
    $externalUserId = $_GET['external_user_id'] ?? null;
    $status = $_GET['status'] ?? null;

    if (!$channelId || !$externalUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing channel_id or external_user_id']);
        return;
    }

    $sql = "SELECT * FROM deposits WHERE channel_id = ? AND external_user_id = ?";
    $params = [(int) $channelId, (string) $externalUserId];

    if ($status) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY created_at DESC";

    $deposits = $db->queryAll($sql, $params);

    // Add computed fields
    $now = time();
    foreach ($deposits as &$dep) {
        $expiresAt = strtotime($dep['expires_at']);
        $dep['remaining_days'] = max(0, ceil(($expiresAt - $now) / 86400));
        $dep['is_expired'] = $now > $expiresAt;
        $dep['product_price'] = (float) $dep['product_price'];
        $dep['deposit_amount'] = (float) $dep['deposit_amount'];
        $dep['remaining_amount'] = (float) $dep['remaining_amount'];
    }

    echo json_encode([
        'success' => true,
        'data' => $deposits,
        'count' => count($deposits)
    ]);
}

/**
 * Submit deposit payment (ส่งสลิปมัดจำ)
 */
function submitDepositPayment($db, int $depositId)
{
    $input = json_decode(file_get_contents('php://input'), true);

    // Get deposit
    $deposit = $db->queryOne("SELECT * FROM deposits WHERE id = ?", [$depositId]);

    if (!$deposit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการมัดจำ']);
        return;
    }

    // Check status
    if ($deposit['status'] !== 'pending_payment') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถชำระได้ สถานะปัจจุบัน: ' . $deposit['status']
        ]);
        return;
    }

    // Check if expired
    if (time() > strtotime($deposit['expires_at'])) {
        $db->execute(
            "UPDATE deposits SET status = 'expired', updated_at = NOW() WHERE id = ?",
            [$depositId]
        );
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'รายการมัดจำหมดอายุแล้วค่ะ']);
        return;
    }

    // Validate slip image
    if (empty($input['slip_image_url'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาส่งรูปสลิปการโอน']);
        return;
    }

    // Update deposit with payment info (status stays pending until admin verifies)
    $db->execute(
        "UPDATE deposits SET 
            payment_slip_url = ?,
            payment_ref = ?,
            admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] ลูกค้าส่งสลิป'),
            updated_at = NOW()
         WHERE id = ?",
        [
            $input['slip_image_url'],
            $input['payment_ref'] ?? null,
            $depositId
        ]
    );

    Logger::info('Deposit payment submitted', [
        'deposit_id' => $depositId,
        'deposit_no' => $deposit['deposit_no'],
        'slip_url' => $input['slip_image_url']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'ได้รับสลิปมัดจำแล้วค่ะ ✅ รอเจ้าหน้าที่ตรวจสอบนะคะ',
        'data' => [
            'id' => $depositId,
            'deposit_no' => $deposit['deposit_no'],
            'deposit_amount' => (float) $deposit['deposit_amount'],
            'product_name' => $deposit['product_name'],
            'status' => 'pending_payment'
        ]
    ]);
}

/**
 * Convert deposit to order (Admin action after payment verified)
 */
function convertDepositToOrder($db, int $depositId)
{
    $input = json_decode(file_get_contents('php://input'), true);

    $deposit = $db->queryOne("SELECT * FROM deposits WHERE id = ?", [$depositId]);

    if (!$deposit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการมัดจำ']);
        return;
    }

    if ($deposit['status'] !== 'deposited') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ต้องยืนยันรับมัดจำก่อนถึงจะแปลงเป็นออเดอร์ได้'
        ]);
        return;
    }

    // Determine order type
    $orderType = $input['order_type'] ?? 'full_payment'; // full_payment, installment

    try {
        $db->beginTransaction();

        // Generate order number
        $orderNo = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        // Create order
        $sql = "INSERT INTO orders (
            order_number, user_id, platform_user_id, customer_profile_id,
            order_type, deposit_id,
            subtotal, total_amount, paid_amount,
            status, payment_status,
            customer_name, customer_phone, customer_platform, customer_platform_id,
            note, admin_note,
            created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            'pending_payment', 'partial',
            ?, ?, ?, ?,
            ?, ?,
            NOW(), NOW()
        )";

        $remainingAmount = (float) $deposit['product_price'] - (float) $deposit['deposit_amount'];

        $db->execute($sql, [
            $orderNo,
            $deposit['user_id'] ?? null, // Shop owner's user_id
            $deposit['external_user_id'], // platform_user_id for JOIN
            $deposit['customer_profile_id'],
            $orderType,
            $depositId,
            $deposit['product_price'],
            $deposit['product_price'],
            $deposit['deposit_amount'], // Already paid deposit amount
            $deposit['customer_name'],
            $deposit['customer_phone'],
            $deposit['platform'],
            $deposit['external_user_id'],
            "แปลงมาจากมัดจำ {$deposit['deposit_no']}",
            $input['admin_note'] ?? null
        ]);

        $orderId = $db->lastInsertId();

        // Add order item
        $db->execute(
            "INSERT INTO order_items (order_id, product_ref_id, product_name, quantity, price, total)
             VALUES (?, ?, ?, 1, ?, ?)",
            [
                $orderId,
                $deposit['product_ref_id'],
                $deposit['product_name'],
                $deposit['product_price'],
                $deposit['product_price']
            ]
        );

        // Update deposit status
        $db->execute(
            "UPDATE deposits SET 
                status = 'converted',
                converted_order_id = ?,
                converted_at = NOW(),
                updated_at = NOW()
             WHERE id = ?",
            [$orderId, $depositId]
        );

        $db->commit();

        Logger::info('Deposit converted to order', [
            'deposit_id' => $depositId,
            'deposit_no' => $deposit['deposit_no'],
            'order_id' => $orderId,
            'order_no' => $orderNo
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'แปลงมัดจำเป็นออเดอร์เรียบร้อยค่ะ',
            'data' => [
                'deposit_id' => $depositId,
                'deposit_no' => $deposit['deposit_no'],
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'remaining_amount' => $remainingAmount
            ]
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Cancel deposit
 */
function cancelDeposit($db, int $depositId)
{
    $input = json_decode(file_get_contents('php://input'), true);

    $deposit = $db->queryOne("SELECT * FROM deposits WHERE id = ?", [$depositId]);

    if (!$deposit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการมัดจำ']);
        return;
    }

    if (!in_array($deposit['status'], ['pending_payment', 'deposited'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ไม่สามารถยกเลิกได้']);
        return;
    }

    $db->execute(
        "UPDATE deposits SET 
            status = 'cancelled',
            admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] ยกเลิก: ', ?),
            updated_at = NOW()
         WHERE id = ?",
        [$input['reason'] ?? 'ไม่ระบุเหตุผล', $depositId]
    );

    Logger::info('Deposit cancelled', [
        'deposit_id' => $depositId,
        'deposit_no' => $deposit['deposit_no'],
        'reason' => $input['reason'] ?? null
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'ยกเลิกรายการมัดจำเรียบร้อยค่ะ',
        'data' => ['id' => $depositId, 'status' => 'cancelled']
    ]);
}

/**
 * Get deposit status with details
 */
function getDepositStatus($db, int $depositId)
{
    $deposit = $db->queryOne("SELECT * FROM deposits WHERE id = ?", [$depositId]);

    if (!$deposit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการมัดจำ']);
        return;
    }

    $now = time();
    $expiresAt = strtotime($deposit['expires_at']);
    $remainingDays = max(0, ceil(($expiresAt - $now) / 86400));
    $isExpired = $now > $expiresAt;

    // Get related order if converted
    $order = null;
    if ($deposit['converted_order_id']) {
        $order = $db->queryOne(
            "SELECT id, order_number, status, total_amount, paid_amount 
             FROM orders WHERE id = ?",
            [$deposit['converted_order_id']]
        );
    }

    $statusMessages = [
        'pending_payment' => 'รอชำระเงินมัดจำ',
        'deposited' => 'มัดจำเรียบร้อยแล้ว',
        'converted' => 'แปลงเป็นออเดอร์แล้ว',
        'expired' => 'หมดอายุ',
        'cancelled' => 'ยกเลิกแล้ว',
        'refunded' => 'คืนเงินแล้ว'
    ];

    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $deposit['id'],
            'deposit_no' => $deposit['deposit_no'],
            'product_name' => $deposit['product_name'],
            'product_price' => (float) $deposit['product_price'],
            'deposit_amount' => (float) $deposit['deposit_amount'],
            'remaining_amount' => (float) $deposit['remaining_amount'],
            'status' => $deposit['status'],
            'status_text' => $statusMessages[$deposit['status']] ?? $deposit['status'],
            'expires_at' => $deposit['expires_at'],
            'remaining_days' => $remainingDays,
            'is_expired' => $isExpired,
            'has_slip' => !empty($deposit['payment_slip_url']),
            'converted_order' => $order,
            'created_at' => $deposit['created_at']
        ]
    ]);
}
