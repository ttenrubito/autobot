<?php
/**
 * Setup Credit Card API (Without Charging)
 * POST /api/payment/setup-card.php
 *
 * Purpose: เก็บบัตรเครดิตและเริ่ม Trial Period (ยังไม่ตัดเงิน)
 *
 * หมายเหตุสำคัญ:
 * - ผู้ใช้ไม่สามารถเลือก/เปลี่ยนแพ็คเกจเองได้อีกต่อไป
 * - แพ็คเกจของผู้ใช้ต้องถูกกำหนดโดยผู้ดูแลระบบผ่าน admin API
 * - endpoint นี้จะอ้างอิงแพ็คเกจจาก subscriptions ที่เป็น active/trial เท่านั้น
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/Database.php';
    require_once __DIR__ . '/../../includes/OmiseClient.php';
    require_once __DIR__ . '/../../includes/JWT.php';

    // Verify user authentication
    $token = null;
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }

    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // Decode token (from login)
    $tokenData = json_decode(base64_decode($token), true);
    if (!$tokenData || !isset($tokenData['user_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }

    $user_id = $tokenData['user_id'];

    // รับเฉพาะ omise_token จาก frontend ห้ามรับ plan_id จากลูกค้าอีกต่อไป
    $data = json_decode(file_get_contents('php://input'), true);
    $omise_token = $data['omise_token'] ?? '';

    if (!$omise_token) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Omise token is required']);
        exit;
    }

    // ถ้ามี plan_id ติดมาจาก frontend ให้ block ทันที
    if (!empty($data['plan_id'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่อนุญาตให้ลูกค้าเลือกหรือเปลี่ยนแพ็คเกจเอง กรุณาติดต่อผู้ดูแลระบบ'
        ]);
        exit;
    }

    $db = Database::getInstance();

    // ตรวจสอบ user
    $user = $db->queryOne("SELECT * FROM users WHERE id = ?", [$user_id]);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // หา subscription ของ user ที่ admin กำหนดไว้แล้ว
    // อนุญาตเฉพาะ trial/active เท่านั้น (หากยังไม่มี ให้ admin assign ก่อน)
    $existingSub = $db->queryOne(
        "SELECT s.*, sp.name as plan_name, sp.monthly_price
         FROM subscriptions s
         JOIN subscription_plans sp ON s.plan_id = sp.id
         WHERE s.user_id = ? AND s.status IN ('trial', 'active')
         ORDER BY s.created_at DESC
         LIMIT 1",
        [$user_id]
    );

    if ($existingSub) {
        // ผู้ใช้นี้มี subscription trial/active อยู่แล้ว ไม่ให้สร้าง trial ใหม่ซ้ำ
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'ผู้ใช้นี้มีแพ็คเกจทดลองหรือใช้งานอยู่แล้ว'
        ]);
        exit;
    }

    // หาแผนที่ admin กำหนดให้ผู้ใช้ (active subscription ที่ล่าสุด)
    $assignedPlan = $db->queryOne(
        "SELECT s.plan_id, sp.name, sp.monthly_price
         FROM subscriptions s
         JOIN subscription_plans sp ON s.plan_id = sp.id
         WHERE s.user_id = ? AND s.status = 'active'
         ORDER BY s.created_at DESC
         LIMIT 1",
        [$user_id]
    );

    if (!$assignedPlan) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ยังไม่มีแพ็คเกจที่ถูกกำหนดโดยผู้ดูแลระบบ กรุณาติดต่อผู้ดูแลเพื่อกำหนดแพ็คเกจก่อน'
        ]);
        exit;
    }

    $plan_id = $assignedPlan['plan_id'];

    // Initialize Omise client
    $omise = new OmiseClient();

    // Create Omise customer with card (ยังไม่ตัดเงิน)
    try {
        $customer = $omise->createCustomer(
            $user['email'],
            $user['full_name'] . ' - ' . $user['company_name'],
            $omise_token
        );
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create Omise customer: ' . $e->getMessage()
        ]);
        exit;
    }

    $card = $customer['cards']['data'][0] ?? null;
    if (!$card) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No card information returned from Omise'
        ]);
        exit;
    }

    // Begin transaction
    $db->beginTransaction();

    try {
        // บันทึกบัตรเป็น payment method ค่าเริ่มต้น
        $db->execute(
            "INSERT INTO payment_methods (user_id, omise_customer_id, omise_card_id, card_brand, card_last4, card_expiry_month, card_expiry_year, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)",
            [
                $user_id,
                $customer['id'],
                $card['id'],
                $card['brand'],
                $card['last_digits'],
                $card['expiration_month'],
                $card['expiration_year']
            ]
        );

        // กำหนดช่วง trial ใหม่ให้ subscription ตามแพ็คเกจที่ admin assign
        $trial_days = 7;
        $trial_start = date('Y-m-d');
        $trial_end = date('Y-m-d', strtotime('+' . $trial_days . ' days'));
        $next_billing = $trial_end;

        // สร้างแถว subscription แบบ trial ใหม่
        $db->execute(
            "INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end, next_billing_date, trial_end_date, trial_used, auto_renew)
             VALUES (?, ?, 'trial', ?, ?, ?, ?, FALSE, TRUE)",
            [
                $user_id,
                $plan_id,
                $trial_start,
                $trial_end,
                $next_billing,
                $trial_end
            ]
        );

        // อัปเดตสถานะ trial ที่ users
        $db->execute(
            "UPDATE users SET trial_start_date = NOW(), trial_days_remaining = ? WHERE id = ?",
            [$trial_days, $user_id]
        );

        $db->commit();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'บัตรเครดิตถูกบันทึกสำเร็จ และเริ่มใช้งานช่วงทดลองตามแพ็คเกจที่ผู้ดูแลกำหนด',
            'data' => [
                'trial_days' => $trial_days,
                'trial_end_date' => $trial_end,
                'next_billing_date' => $next_billing,
                'plan_name' => $assignedPlan['name'],
                'monthly_price' => (float)$assignedPlan['monthly_price']
            ]
        ]);

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Setup Card Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
