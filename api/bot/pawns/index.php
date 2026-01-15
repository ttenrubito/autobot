<?php
/**
 * Bot Pawns API (ฝากจำนำ/ต่อดอก)
 * 
 * Endpoints:
 * POST /api/bot/pawns                     - Create new pawn (เปิดรายการจำนำ)
 * GET  /api/bot/pawns/{id}                - Get pawn by ID
 * GET  /api/bot/pawns/by-user             - Get pawns by external_user_id
 * POST /api/bot/pawns/{id}/pay-interest   - Pay interest (ชำระดอก/ต่อดอก)
 * POST /api/bot/pawns/{id}/redeem         - Redeem pawn (ไถ่ถอน)
 * GET  /api/bot/pawns/{id}/status         - Get pawn status & schedule
 * GET  /api/bot/pawns/{id}/schedule       - Get payment schedule
 * 
 * Business Rules:
 * - Pawn amount = 65-70% of appraisal value
 * - Interest rate = 2% per month (30 days)
 * - Must pay interest every 30 days
 * - If overdue too long, item is forfeited
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

// Expected: /api/bot/pawns/{id?}/{action?}
$pawn_id = $_GET['pawn_id'] ?? (isset($uri_parts[3]) && is_numeric($uri_parts[3]) ? (int)$uri_parts[3] : null);
$action = $_GET['action'] ?? ($uri_parts[3] ?? $uri_parts[4] ?? null);

// Handle by-user as action
if ($action === 'by-user') {
    $pawn_id = null;
}

try {
    $db = Database::getInstance();
    
    // Route to appropriate handler
    if ($method === 'POST' && !$pawn_id && $action !== 'by-user') {
        createPawn($db);
    } elseif ($method === 'GET' && $action === 'by-user') {
        getPawnsByUser($db);
    } elseif ($method === 'GET' && $pawn_id && !$action) {
        getPawn($db, $pawn_id);
    } elseif ($method === 'GET' && $pawn_id && $action === 'status') {
        getPawnStatus($db, $pawn_id);
    } elseif ($method === 'GET' && $pawn_id && $action === 'schedule') {
        getPawnSchedule($db, $pawn_id);
    } elseif ($method === 'POST' && $pawn_id && ($action === 'pay-interest' || $action === 'pay')) {
        payInterest($db, $pawn_id);
    } elseif ($method === 'POST' && $pawn_id && $action === 'redeem') {
        redeemPawn($db, $pawn_id);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    Logger::error('Bot Pawns API Error: ' . $e->getMessage(), [
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
 * Generate unique pawn number
 */
function generatePawnNo(): string {
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "PWN-{$date}-{$random}";
}

/**
 * Generate unique payment number
 */
function generatePaymentNo(): string {
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "PWNPAY-{$date}-{$random}";
}

/**
 * Calculate interest amount
 */
function calculateInterest(float $pawnAmount, float $interestRate = 2.0): float {
    return round($pawnAmount * ($interestRate / 100), 2);
}

/**
 * Create new pawn (เปิดรายการจำนำ)
 * 
 * Required: channel_id, external_user_id, platform, product_name, appraisal_value
 * Optional: pawn_percent (65-70%), interest_rate (default 2%), customer_name, customer_phone
 */
function createPawn($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['channel_id', 'external_user_id', 'platform', 'product_name', 'appraisal_value'];
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
    
    // Calculate pawn details
    $appraisalValue = (float)$input['appraisal_value'];
    $pawnPercent = (float)($input['pawn_percent'] ?? 65.00);
    
    // Validate pawn percent (65-70%)
    if ($pawnPercent < 50 || $pawnPercent > 80) {
        $pawnPercent = 65.00;
    }
    
    $pawnAmount = (float)($input['pawn_amount'] ?? round($appraisalValue * ($pawnPercent / 100), 2));
    $interestRate = (float)($input['interest_rate'] ?? 2.00);
    $interestPeriodDays = (int)($input['interest_period_days'] ?? 30);
    
    // Calculate first due date
    $nextDueDate = date('Y-m-d', strtotime("+{$interestPeriodDays} days"));
    
    $pawnNo = generatePawnNo();
    
    $sql = "INSERT INTO pawns (
        pawn_no, tenant_id, user_id, platform_user_id, customer_id, customer_profile_id,
        channel_id, external_user_id, platform,
        customer_name, customer_phone, customer_line_name, customer_id_card,
        product_ref_id, product_name, product_description, product_images,
        appraisal_value, pawn_percent, pawn_amount,
        interest_rate, interest_period_days,
        next_due_date, status,
        case_id, admin_notes,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?,
        ?, 'pending_approval',
        ?, ?,
        NOW(), NOW()
    )";
    
    $params = [
        $pawnNo,
        $input['tenant_id'] ?? 'default',
        $input['user_id'] ?? null,
        (string)$input['external_user_id'], // platform_user_id for JOIN
        $input['customer_id'] ?? null,
        $input['customer_profile_id'] ?? null,
        (int)$input['channel_id'],
        (string)$input['external_user_id'],
        $input['platform'],
        $input['customer_name'] ?? null,
        $input['customer_phone'] ?? null,
        $input['customer_line_name'] ?? null,
        $input['customer_id_card'] ?? null,
        $input['product_ref_id'] ?? null,
        $input['product_name'],
        $input['product_description'] ?? null,
        !empty($input['product_images']) ? json_encode($input['product_images']) : null,
        $appraisalValue,
        $pawnPercent,
        $pawnAmount,
        $interestRate,
        $interestPeriodDays,
        $nextDueDate,
        $input['case_id'] ?? null,
        $input['admin_notes'] ?? null
    ];
    
    $db->execute($sql, $params);
    $newId = $db->lastInsertId();
    
    // Calculate interest amount
    $interestAmount = calculateInterest($pawnAmount, $interestRate);
    
    Logger::info('Pawn created', [
        'pawn_id' => $newId,
        'pawn_no' => $pawnNo,
        'pawn_amount' => $pawnAmount,
        'interest_rate' => $interestRate
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'เปิดรายการจำนำเรียบร้อยค่ะ รอเจ้าหน้าที่ตรวจสอบและอนุมัติ',
        'data' => [
            'id' => $newId,
            'pawn_no' => $pawnNo,
            'product_name' => $input['product_name'],
            'appraisal_value' => $appraisalValue,
            'pawn_percent' => $pawnPercent,
            'pawn_amount' => $pawnAmount,
            'interest_rate' => $interestRate,
            'interest_amount' => $interestAmount,
            'interest_period_days' => $interestPeriodDays,
            'next_due_date' => $nextDueDate,
            'status' => 'pending_approval'
        ]
    ]);
}

/**
 * Get pawn by ID
 */
function getPawn($db, int $pawnId) {
    $pawn = $db->queryOne("SELECT * FROM pawns WHERE id = ?", [$pawnId]);
    
    if (!$pawn) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการจำนำ']);
        return;
    }
    
    // Parse JSON fields
    $pawn['product_images'] = $pawn['product_images'] ? json_decode($pawn['product_images'], true) : [];
    
    // Calculate interest and overdue
    $now = new DateTime();
    $nextDue = new DateTime($pawn['next_due_date']);
    $overdueDays = 0;
    $isOverdue = false;
    
    if ($now > $nextDue && $pawn['status'] === 'active') {
        $isOverdue = true;
        $overdueDays = (int)$now->diff($nextDue)->days;
    }
    
    // Get payment history
    $payments = $db->queryAll(
        "SELECT * FROM pawn_payments WHERE pawn_id = ? ORDER BY created_at DESC",
        [$pawnId]
    );
    
    echo json_encode([
        'success' => true,
        'data' => array_merge($pawn, [
            'appraisal_value' => (float)$pawn['appraisal_value'],
            'pawn_amount' => (float)$pawn['pawn_amount'],
            'interest_amount' => (float)$pawn['interest_amount'],
            'total_interest_paid' => (float)$pawn['total_interest_paid'],
            'is_overdue' => $isOverdue,
            'overdue_days' => $overdueDays,
            'payments' => $payments
        ])
    ]);
}

/**
 * Get pawns by user
 */
function getPawnsByUser($db) {
    $channelId = $_GET['channel_id'] ?? null;
    $externalUserId = $_GET['external_user_id'] ?? null;
    $status = $_GET['status'] ?? null;
    
    if (!$channelId || !$externalUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing channel_id or external_user_id']);
        return;
    }
    
    $sql = "SELECT * FROM pawns WHERE channel_id = ? AND external_user_id = ?";
    $params = [(int)$channelId, (string)$externalUserId];
    
    if ($status) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $pawns = $db->queryAll($sql, $params);
    
    // Add computed fields
    $now = new DateTime();
    foreach ($pawns as &$pawn) {
        $nextDue = new DateTime($pawn['next_due_date']);
        $pawn['is_overdue'] = ($now > $nextDue && $pawn['status'] === 'active');
        $pawn['overdue_days'] = $pawn['is_overdue'] ? (int)$now->diff($nextDue)->days : 0;
        $pawn['appraisal_value'] = (float)$pawn['appraisal_value'];
        $pawn['pawn_amount'] = (float)$pawn['pawn_amount'];
        $pawn['interest_amount'] = (float)$pawn['interest_amount'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $pawns,
        'count' => count($pawns)
    ]);
}

/**
 * Pay interest (ชำระดอก/ต่อดอก)
 */
function payInterest($db, int $pawnId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $pawn = $db->queryOne("SELECT * FROM pawns WHERE id = ?", [$pawnId]);
    
    if (!$pawn) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการจำนำ']);
        return;
    }
    
    if ($pawn['status'] !== 'active' && $pawn['status'] !== 'overdue') {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'ไม่สามารถชำระดอกได้ สถานะปัจจุบัน: ' . $pawn['status']
        ]);
        return;
    }
    
    // Validate slip image
    if (empty($input['slip_image_url'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาส่งรูปสลิปการโอน']);
        return;
    }
    
    $interestAmount = (float)$pawn['interest_amount'];
    $amount = (float)($input['amount'] ?? $interestAmount);
    $periodNumber = (int)$pawn['periods_paid'] + 1;
    
    // Calculate next due date
    $interestPeriodDays = (int)$pawn['interest_period_days'];
    $nextDueDate = date('Y-m-d', strtotime("+{$interestPeriodDays} days"));
    
    $paymentNo = generatePaymentNo();
    
    try {
        $db->beginTransaction();
        
        // Insert payment record
        $sql = "INSERT INTO pawn_payments (
            payment_no, pawn_id, payment_type,
            amount, interest_portion, for_period,
            period_start_date, period_end_date,
            status, slip_image_url, ocr_data,
            payment_ref, sender_name, transfer_time,
            next_due_date, note, case_id,
            created_at, updated_at
        ) VALUES (
            ?, ?, 'interest',
            ?, ?, ?,
            ?, ?,
            'pending', ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            NOW(), NOW()
        )";
        
        $db->execute($sql, [
            $paymentNo,
            $pawnId,
            $amount,
            $amount, // All goes to interest
            $periodNumber,
            $pawn['next_due_date'], // Current period start
            $nextDueDate, // Current period end
            $input['slip_image_url'],
            !empty($input['ocr_data']) ? json_encode($input['ocr_data']) : null,
            $input['payment_ref'] ?? null,
            $input['sender_name'] ?? null,
            $input['transfer_time'] ?? null,
            $nextDueDate,
            $input['note'] ?? null,
            $input['case_id'] ?? null
        ]);
        
        $paymentId = $db->lastInsertId();
        
        // Add admin note
        $db->execute(
            "UPDATE pawns SET 
                admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] ลูกค้าส่งสลิปชำระดอก รอบที่ ', ?),
                updated_at = NOW()
             WHERE id = ?",
            [$periodNumber, $pawnId]
        );
        
        $db->commit();
        
        Logger::info('Pawn interest payment submitted', [
            'pawn_id' => $pawnId,
            'pawn_no' => $pawn['pawn_no'],
            'payment_no' => $paymentNo,
            'amount' => $amount,
            'period' => $periodNumber
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'ได้รับสลิปชำระดอกแล้วค่ะ ✅ รอเจ้าหน้าที่ตรวจสอบนะคะ',
            'data' => [
                'pawn_id' => $pawnId,
                'pawn_no' => $pawn['pawn_no'],
                'payment_id' => $paymentId,
                'payment_no' => $paymentNo,
                'amount' => $amount,
                'period_number' => $periodNumber,
                'next_due_date' => $nextDueDate,
                'status' => 'pending'
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Redeem pawn (ไถ่ถอน)
 */
function redeemPawn($db, int $pawnId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $pawn = $db->queryOne("SELECT * FROM pawns WHERE id = ?", [$pawnId]);
    
    if (!$pawn) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการจำนำ']);
        return;
    }
    
    if ($pawn['status'] !== 'active' && $pawn['status'] !== 'overdue') {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'ไม่สามารถไถ่ถอนได้ สถานะปัจจุบัน: ' . $pawn['status']
        ]);
        return;
    }
    
    // Validate slip image
    if (empty($input['slip_image_url'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาส่งรูปสลิปการโอน']);
        return;
    }
    
    // Calculate redemption amount
    $pawnAmount = (float)$pawn['pawn_amount'];
    $interestAmount = (float)$pawn['interest_amount'];
    
    // Calculate outstanding interest (if overdue)
    $now = new DateTime();
    $nextDue = new DateTime($pawn['next_due_date']);
    $outstandingPeriods = 0;
    
    if ($now > $nextDue) {
        $daysDiff = (int)$now->diff($nextDue)->days;
        $outstandingPeriods = max(1, ceil($daysDiff / (int)$pawn['interest_period_days']));
    }
    
    $outstandingInterest = $interestAmount * $outstandingPeriods;
    $redemptionAmount = $pawnAmount + $outstandingInterest;
    
    $amount = (float)($input['amount'] ?? $redemptionAmount);
    
    $paymentNo = generatePaymentNo();
    
    try {
        $db->beginTransaction();
        
        // Insert redemption payment
        $sql = "INSERT INTO pawn_payments (
            payment_no, pawn_id, payment_type,
            amount, principal_portion, interest_portion,
            status, slip_image_url,
            payment_ref, sender_name, transfer_time,
            note, case_id,
            created_at, updated_at
        ) VALUES (
            ?, ?, 'full_redemption',
            ?, ?, ?,
            'pending', ?,
            ?, ?, ?,
            ?, ?,
            NOW(), NOW()
        )";
        
        $db->execute($sql, [
            $paymentNo,
            $pawnId,
            $amount,
            $pawnAmount,
            $outstandingInterest,
            $input['slip_image_url'],
            $input['payment_ref'] ?? null,
            $input['sender_name'] ?? null,
            $input['transfer_time'] ?? null,
            $input['note'] ?? 'ขอไถ่ถอน',
            $input['case_id'] ?? null
        ]);
        
        $paymentId = $db->lastInsertId();
        
        // Update pawn (status stays until admin verifies)
        $db->execute(
            "UPDATE pawns SET 
                redemption_amount = ?,
                admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] ลูกค้าขอไถ่ถอน ยอด ', ?),
                updated_at = NOW()
             WHERE id = ?",
            [$redemptionAmount, number_format($amount, 2), $pawnId]
        );
        
        $db->commit();
        
        Logger::info('Pawn redemption requested', [
            'pawn_id' => $pawnId,
            'pawn_no' => $pawn['pawn_no'],
            'payment_no' => $paymentNo,
            'redemption_amount' => $redemptionAmount
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'ได้รับคำขอไถ่ถอนแล้วค่ะ ✅ รอเจ้าหน้าที่ตรวจสอบนะคะ',
            'data' => [
                'pawn_id' => $pawnId,
                'pawn_no' => $pawn['pawn_no'],
                'payment_id' => $paymentId,
                'payment_no' => $paymentNo,
                'principal' => $pawnAmount,
                'outstanding_interest' => $outstandingInterest,
                'redemption_amount' => $redemptionAmount,
                'amount_paid' => $amount,
                'status' => 'pending'
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Get pawn status with full details
 */
function getPawnStatus($db, int $pawnId) {
    $pawn = $db->queryOne("SELECT * FROM pawns WHERE id = ?", [$pawnId]);
    
    if (!$pawn) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการจำนำ']);
        return;
    }
    
    // Calculate overdue
    $now = new DateTime();
    $nextDue = new DateTime($pawn['next_due_date']);
    $isOverdue = ($now > $nextDue && in_array($pawn['status'], ['active', 'overdue']));
    $overdueDays = $isOverdue ? (int)$now->diff($nextDue)->days : 0;
    $daysUntilDue = !$isOverdue ? (int)$now->diff($nextDue)->days : 0;
    
    // Get payment history
    $payments = $db->queryAll(
        "SELECT id, payment_no, payment_type, amount, status, created_at 
         FROM pawn_payments WHERE pawn_id = ? ORDER BY created_at DESC LIMIT 10",
        [$pawnId]
    );
    
    // Status messages
    $statusMessages = [
        'pending_approval' => 'รอตรวจสอบและอนุมัติ',
        'active' => 'กำลังจำนำอยู่',
        'overdue' => 'เกินกำหนดชำระดอก',
        'redeemed' => 'ไถ่ถอนแล้ว',
        'forfeited' => 'หลุดจำนำ',
        'cancelled' => 'ยกเลิก'
    ];
    
    // Calculate redemption amount
    $pawnAmount = (float)$pawn['pawn_amount'];
    $interestAmount = (float)$pawn['interest_amount'];
    $outstandingPeriods = $isOverdue ? max(1, ceil($overdueDays / (int)$pawn['interest_period_days'])) : 1;
    $redemptionAmount = $pawnAmount + ($interestAmount * $outstandingPeriods);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $pawn['id'],
            'pawn_no' => $pawn['pawn_no'],
            'product_name' => $pawn['product_name'],
            'appraisal_value' => (float)$pawn['appraisal_value'],
            'pawn_amount' => $pawnAmount,
            'interest_rate' => (float)$pawn['interest_rate'],
            'interest_amount' => $interestAmount,
            'total_interest_paid' => (float)$pawn['total_interest_paid'],
            'periods_paid' => (int)$pawn['periods_paid'],
            'status' => $pawn['status'],
            'status_text' => $statusMessages[$pawn['status']] ?? $pawn['status'],
            'next_due_date' => $pawn['next_due_date'],
            'is_overdue' => $isOverdue,
            'overdue_days' => $overdueDays,
            'days_until_due' => $daysUntilDue,
            'current_interest_due' => $isOverdue ? ($interestAmount * $outstandingPeriods) : $interestAmount,
            'redemption_amount' => $redemptionAmount,
            'recent_payments' => $payments,
            'created_at' => $pawn['created_at']
        ]
    ]);
}

/**
 * Get pawn payment schedule
 */
function getPawnSchedule($db, int $pawnId) {
    $pawn = $db->queryOne("SELECT * FROM pawns WHERE id = ?", [$pawnId]);
    
    if (!$pawn) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการจำนำ']);
        return;
    }
    
    $interestAmount = (float)$pawn['interest_amount'];
    $interestPeriodDays = (int)$pawn['interest_period_days'];
    $periodsPaid = (int)$pawn['periods_paid'];
    
    // Generate schedule for next 6 periods
    $schedule = [];
    $baseDate = new DateTime($pawn['next_due_date']);
    
    for ($i = 0; $i < 6; $i++) {
        $periodNum = $periodsPaid + $i + 1;
        $dueDate = clone $baseDate;
        $dueDate->modify("+{$i} months");
        
        $schedule[] = [
            'period' => $periodNum,
            'due_date' => $dueDate->format('Y-m-d'),
            'amount' => $interestAmount,
            'status' => $i === 0 ? 'current' : 'upcoming'
        ];
    }
    
    // Get paid periods
    $paidPeriods = $db->queryAll(
        "SELECT for_period, amount, status, created_at 
         FROM pawn_payments 
         WHERE pawn_id = ? AND payment_type = 'interest' AND status = 'verified'
         ORDER BY for_period ASC",
        [$pawnId]
    );
    
    echo json_encode([
        'success' => true,
        'data' => [
            'pawn_no' => $pawn['pawn_no'],
            'pawn_amount' => (float)$pawn['pawn_amount'],
            'interest_rate' => (float)$pawn['interest_rate'],
            'interest_amount' => $interestAmount,
            'periods_paid' => $periodsPaid,
            'next_due_date' => $pawn['next_due_date'],
            'paid_periods' => $paidPeriods,
            'upcoming_schedule' => $schedule
        ]
    ]);
}
