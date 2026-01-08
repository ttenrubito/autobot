<?php
/**
 * Bot Installments API
 * 
 * Endpoints:
 * POST /api/bot/installments                   - Create new installment contract
 * GET  /api/bot/installments/{id}              - Get contract by ID
 * GET  /api/bot/installments/by-user           - Get contracts by external_user_id
 * POST /api/bot/installments/{id}/pay          - Submit payment for a period
 * POST /api/bot/installments/{id}/extend       - Request extension
 * GET  /api/bot/installments/{id}/status       - Get contract status & history
 * 
 * @version 1.0
 * @date 2026-01-07
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Logger.php';

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));

// Expected: /api/bot/installments/{id?}/{action?}
// Also support router-provided params via $_GET
$contract_id = $_GET['contract_id'] ?? (isset($uri_parts[3]) && is_numeric($uri_parts[3]) ? (int)$uri_parts[3] : null);
$action = $_GET['action'] ?? ($uri_parts[3] ?? $uri_parts[4] ?? null);

try {
    $db = Database::getInstance();
    
    // Route to appropriate handler
    if ($method === 'POST' && !$contract_id && $action !== 'by-user') {
        // POST /api/bot/installments - Create new installment contract
        createInstallmentContract($db);
    } elseif ($method === 'GET' && $action === 'by-user') {
        // GET /api/bot/installments/by-user?channel_id=X&external_user_id=Y
        getContractsByUser($db);
    } elseif ($method === 'GET' && $contract_id && !$action) {
        // GET /api/bot/installments/{id}
        getContract($db, $contract_id);
    } elseif ($method === 'GET' && $contract_id && $action === 'status') {
        // GET /api/bot/installments/{id}/status
        getContractStatus($db, $contract_id);
    } elseif ($method === 'POST' && $contract_id && $action === 'pay') {
        // POST /api/bot/installments/{id}/pay
        submitPayment($db, $contract_id);
    } elseif ($method === 'POST' && $contract_id && $action === 'extend') {
        // POST /api/bot/installments/{id}/extend
        requestExtension($db, $contract_id);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    Logger::error('Bot Installments API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Generate unique contract number
 */
function generateContractNo(): string {
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "INS-{$date}-{$random}";
}

/**
 * Generate unique payment number
 */
function generatePaymentNo(): string {
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "INSPAY-{$date}-{$random}";
}

/**
 * Create new installment contract
 * 
 * Required: channel_id, external_user_id, platform, product_ref_id, product_name, 
 *           product_price, total_periods, amount_per_period
 * Optional: down_payment, interest_rate, customer_name, customer_phone, start_date
 */
function createInstallmentContract($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['channel_id', 'external_user_id', 'platform', 'product_ref_id', 'product_name', 'product_price', 'total_periods'];
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
    
    // Check if there's already an active contract for this product and user
    $existing = $db->queryOne(
        "SELECT id, contract_no, status FROM installment_contracts 
         WHERE channel_id = ? AND external_user_id = ? AND product_ref_id = ? 
         AND status IN ('pending_approval', 'active')",
        [(int)$input['channel_id'], (string)$input['external_user_id'], $input['product_ref_id']]
    );
    
    if ($existing) {
        echo json_encode([
            'success' => true,
            'message' => 'Active installment contract already exists for this product',
            'data' => $existing,
            'is_existing' => true
        ]);
        return;
    }
    
    // Calculate financial details
    $productPrice = (float)$input['product_price'];
    $downPayment = (float)($input['down_payment'] ?? 0);
    $totalPeriods = (int)$input['total_periods'];
    $interestRate = (float)($input['interest_rate'] ?? 0);
    $interestType = $input['interest_type'] ?? 'none';
    
    // Calculate financed amount and amount per period
    $financedAmount = $productPrice - $downPayment;
    
    // Calculate interest if applicable
    $totalInterest = 0;
    if ($interestRate > 0 && $interestType !== 'none') {
        if ($interestType === 'flat') {
            $totalInterest = $financedAmount * ($interestRate / 100) * ($totalPeriods / 12);
        } else { // reducing
            $monthlyRate = ($interestRate / 100) / 12;
            $totalInterest = ($financedAmount * $monthlyRate * $totalPeriods) / 2; // simplified
        }
    }
    
    $totalAmount = $financedAmount + $totalInterest;
    $amountPerPeriod = $input['amount_per_period'] ?? round($totalAmount / $totalPeriods, 2);
    
    // Calculate dates
    $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('+1 month'));
    $endDate = date('Y-m-d', strtotime($startDate . " +{$totalPeriods} months"));
    
    $contractNo = generateContractNo();
    
    $sql = "INSERT INTO installment_contracts (
        contract_no, tenant_id, customer_id, channel_id, external_user_id,
        platform, customer_name, customer_phone, customer_line_name,
        product_ref_id, product_name, product_code, product_price,
        total_amount, down_payment, financed_amount,
        total_periods, amount_per_period,
        interest_rate, interest_type, total_interest,
        start_date, next_due_date, end_date,
        status, case_id, admin_notes,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        'pending_approval', ?, ?,
        NOW(), NOW()
    )";
    
    $params = [
        $contractNo,
        $input['tenant_id'] ?? 'default',
        $input['customer_id'] ?? null,
        (int)$input['channel_id'],
        (string)$input['external_user_id'],
        $input['platform'],
        $input['customer_name'] ?? null,
        $input['customer_phone'] ?? null,
        $input['customer_line_name'] ?? null,
        $input['product_ref_id'],
        $input['product_name'],
        $input['product_code'] ?? null,
        $productPrice,
        $totalAmount,
        $downPayment,
        $financedAmount,
        $totalPeriods,
        $amountPerPeriod,
        $interestRate,
        $interestRate > 0 ? $interestType : 'none',
        $totalInterest,
        $startDate,
        $startDate, // next_due_date = start_date initially
        $endDate,
        $input['case_id'] ?? null,
        $input['admin_notes'] ?? null
    ];
    
    $db->execute($sql, $params);
    $newId = $db->lastInsertId();
    
    Logger::info('Installment contract created', [
        'contract_id' => $newId,
        'contract_no' => $contractNo,
        'product_ref_id' => $input['product_ref_id'],
        'total_amount' => $totalAmount,
        'total_periods' => $totalPeriods
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Installment contract created successfully (pending approval)',
        'data' => [
            'id' => $newId,
            'contract_no' => $contractNo,
            'product_ref_id' => $input['product_ref_id'],
            'product_name' => $input['product_name'],
            'product_price' => $productPrice,
            'down_payment' => $downPayment,
            'financed_amount' => $financedAmount,
            'total_amount' => $totalAmount,
            'total_periods' => $totalPeriods,
            'amount_per_period' => $amountPerPeriod,
            'start_date' => $startDate,
            'next_due_date' => $startDate,
            'status' => 'pending_approval'
        ],
        'is_existing' => false
    ]);
}

/**
 * Get installment contract by ID
 */
function getContract($db, int $contractId) {
    $contract = $db->queryOne("SELECT * FROM installment_contracts WHERE id = ?", [$contractId]);
    
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Installment contract not found']);
        return;
    }
    
    // Get payment history
    $payments = $db->queryAll(
        "SELECT * FROM installment_payments WHERE contract_id = ? ORDER BY period_number ASC, created_at DESC",
        [$contractId]
    );
    
    // Calculate progress
    $progress = calculateProgress($contract);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'contract' => $contract,
            'payments' => $payments,
            'progress' => $progress
        ]
    ]);
}

/**
 * Get installment contracts by user
 */
function getContractsByUser($db) {
    $channelId = $_GET['channel_id'] ?? null;
    $externalUserId = $_GET['external_user_id'] ?? null;
    $status = $_GET['status'] ?? null;
    
    if (!$channelId || !$externalUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'channel_id and external_user_id are required']);
        return;
    }
    
    $sql = "SELECT * FROM installment_contracts 
            WHERE channel_id = ? AND external_user_id = ?";
    $params = [(int)$channelId, (string)$externalUserId];
    
    if ($status) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $contracts = $db->queryAll($sql, $params);
    
    // Add progress for each contract
    foreach ($contracts as &$contract) {
        $contract['progress'] = calculateProgress($contract);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $contracts,
        'count' => count($contracts)
    ]);
}

/**
 * Get contract status with full details
 */
function getContractStatus($db, int $contractId) {
    $contract = $db->queryOne("SELECT * FROM installment_contracts WHERE id = ?", [$contractId]);
    
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contract not found']);
        return;
    }
    
    // Get verified payments
    $payments = $db->queryAll(
        "SELECT * FROM installment_payments 
         WHERE contract_id = ? AND status = 'verified'
         ORDER BY period_number ASC",
        [$contractId]
    );
    
    // Get pending payments
    $pendingPayments = $db->queryAll(
        "SELECT * FROM installment_payments 
         WHERE contract_id = ? AND status = 'pending'
         ORDER BY created_at DESC",
        [$contractId]
    );
    
    // Calculate progress
    $progress = calculateProgress($contract);
    
    // Build response message (Thai)
    $statusMessages = [
        'pending_approval' => 'รอการอนุมัติ',
        'active' => 'กำลังผ่อนชำระ',
        'overdue' => 'เกินกำหนดชำระ',
        'completed' => 'ผ่อนครบแล้ว',
        'cancelled' => 'ยกเลิก',
        'defaulted' => 'ผิดนัดชำระ'
    ];
    
    $message = "📋 สัญญา: {$contract['contract_no']}\n";
    $message .= "📦 สินค้า: {$contract['product_name']}\n";
    $message .= "💰 ยอดผ่อน: " . number_format($contract['financed_amount'], 2) . " บาท\n";
    $message .= "📊 ชำระแล้ว: " . number_format($contract['paid_amount'], 2) . "/" . number_format($contract['financed_amount'], 2) . " บาท\n";
    $message .= "📈 ความคืบหน้า: {$progress['percentage']}%\n";
    $message .= "📅 งวดถัดไป: {$contract['next_due_date']} (งวดที่ " . ($contract['paid_periods'] + 1) . "/{$contract['total_periods']})\n";
    $message .= "⏳ คงเหลือ: " . number_format($progress['remaining_amount'], 2) . " บาท";
    
    echo json_encode([
        'success' => true,
        'data' => [
            'contract' => $contract,
            'verified_payments' => $payments,
            'pending_payments' => $pendingPayments,
            'progress' => $progress,
            'status_label' => $statusMessages[$contract['status']] ?? $contract['status']
        ],
        'message' => $message
    ]);
}

/**
 * Submit payment for an installment period
 */
function submitPayment($db, int $contractId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Get contract
    $contract = $db->queryOne("SELECT * FROM installment_contracts WHERE id = ?", [$contractId]);
    
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contract not found']);
        return;
    }
    
    // Validate contract status
    if (!in_array($contract['status'], ['active', 'overdue'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Contract is not active']);
        return;
    }
    
    // Must have slip_image_url or amount
    if (empty($input['slip_image_url']) && empty($input['amount'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Must provide slip_image_url or amount']);
        return;
    }
    
    // Determine period number
    $periodNumber = (int)($input['period_number'] ?? ($contract['paid_periods'] + 1));
    
    // Check if already paid for this period
    $existingPayment = $db->queryOne(
        "SELECT id FROM installment_payments 
         WHERE contract_id = ? AND period_number = ? AND status = 'verified'",
        [$contractId, $periodNumber]
    );
    
    if ($existingPayment) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Period {$periodNumber} is already paid"]);
        return;
    }
    
    $paymentNo = generatePaymentNo();
    $amount = (float)($input['amount'] ?? $contract['amount_per_period']);
    $paymentType = $input['payment_type'] ?? 'regular';
    $slipOcrData = isset($input['slip_ocr_data']) ? json_encode($input['slip_ocr_data']) : null;
    
    // Get due date for this period
    $dueDate = date('Y-m-d', strtotime($contract['start_date'] . " +" . ($periodNumber - 1) . " months"));
    
    $sql = "INSERT INTO installment_payments (
        contract_id, payment_no, period_number,
        amount, payment_type, payment_method,
        due_date, paid_date,
        status, slip_image_url, slip_ocr_data,
        payment_ref, sender_name, transfer_time,
        case_id, notes,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?,
        ?, ?, ?,
        ?, ?,
        'pending', ?, ?,
        ?, ?, ?,
        ?, ?,
        NOW(), NOW()
    )";
    
    $params = [
        $contractId,
        $paymentNo,
        $periodNumber,
        $amount,
        $paymentType,
        $input['payment_method'] ?? 'bank_transfer',
        $dueDate,
        date('Y-m-d'),
        $input['slip_image_url'] ?? null,
        $slipOcrData,
        $input['payment_ref'] ?? $input['slip_ocr_data']['transaction_id'] ?? null,
        $input['sender_name'] ?? $input['slip_ocr_data']['sender_name'] ?? null,
        $input['transfer_time'] ?? $input['slip_ocr_data']['transfer_time'] ?? null,
        $input['case_id'] ?? null,
        $input['notes'] ?? null
    ];
    
    $db->execute($sql, $params);
    $paymentId = $db->lastInsertId();
    
    // Update contract pending amount
    $db->execute(
        "UPDATE installment_contracts SET pending_amount = pending_amount + ?, updated_at = NOW() WHERE id = ?",
        [$amount, $contractId]
    );
    
    Logger::info('Installment payment submitted', [
        'payment_id' => $paymentId,
        'payment_no' => $paymentNo,
        'contract_id' => $contractId,
        'period_number' => $periodNumber,
        'amount' => $amount
    ]);
    
    $message = "ได้รับข้อมูลชำระงวดที่ {$periodNumber} แล้วค่ะ 💳\n";
    $message .= "💰 ยอด: " . number_format($amount, 2) . " บาท\n";
    $message .= "📋 สัญญา: {$contract['contract_no']}\n";
    $message .= "รอเจ้าหน้าที่ตรวจสอบนะคะ";
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'payment_id' => $paymentId,
            'payment_no' => $paymentNo,
            'contract_no' => $contract['contract_no'],
            'period_number' => $periodNumber,
            'amount' => $amount,
            'status' => 'pending'
        ]
    ]);
}

/**
 * Request extension for installment contract
 */
function requestExtension($db, int $contractId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Get contract
    $contract = $db->queryOne("SELECT * FROM installment_contracts WHERE id = ?", [$contractId]);
    
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contract not found']);
        return;
    }
    
    // Validate contract status
    if (!in_array($contract['status'], ['active', 'overdue'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Contract cannot be extended']);
        return;
    }
    
    $extensionMonths = (int)($input['extension_months'] ?? 1);
    $extensionReason = $input['reason'] ?? 'ขอเลื่อนกำหนดชำระ';
    
    // Create extension payment record (fee if applicable)
    $extensionFee = (float)($input['extension_fee'] ?? 0);
    
    if ($extensionFee > 0) {
        $paymentNo = generatePaymentNo();
        
        $db->execute(
            "INSERT INTO installment_payments (
                contract_id, payment_no, period_number,
                amount, payment_type, is_extension,
                extension_months, extension_reason,
                status, slip_image_url, slip_ocr_data,
                created_at, updated_at
            ) VALUES (?, ?, 0, ?, 'extension_fee', TRUE, ?, ?, 'pending', ?, ?, NOW(), NOW())",
            [
                $contractId,
                $paymentNo,
                $extensionFee,
                $extensionMonths,
                $extensionReason,
                $input['slip_image_url'] ?? null,
                isset($input['slip_ocr_data']) ? json_encode($input['slip_ocr_data']) : null
            ]
        );
    }
    
    // Calculate new due date
    $newDueDate = date('Y-m-d', strtotime($contract['next_due_date'] . " +{$extensionMonths} months"));
    
    // Update contract (admin will finalize)
    $adminNote = "Extension requested: {$extensionMonths} month(s). Reason: {$extensionReason}. New due date: {$newDueDate}";
    $db->execute(
        "UPDATE installment_contracts 
         SET admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n', ?),
             updated_at = NOW()
         WHERE id = ?",
        [$adminNote, $contractId]
    );
    
    Logger::info('Installment extension requested', [
        'contract_id' => $contractId,
        'contract_no' => $contract['contract_no'],
        'extension_months' => $extensionMonths,
        'new_due_date' => $newDueDate
    ]);
    
    $message = "ได้รับคำขอเลื่อนกำหนดชำระแล้วค่ะ\n";
    $message .= "📋 สัญญา: {$contract['contract_no']}\n";
    $message .= "📅 เลื่อน: {$extensionMonths} เดือน\n";
    $message .= "📅 กำหนดใหม่: {$newDueDate}\n";
    if ($extensionFee > 0) {
        $message .= "💰 ค่าธรรมเนียม: " . number_format($extensionFee, 2) . " บาท\n";
    }
    $message .= "รอเจ้าหน้าที่อนุมัตินะคะ";
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'contract_no' => $contract['contract_no'],
            'extension_months' => $extensionMonths,
            'original_due_date' => $contract['next_due_date'],
            'requested_due_date' => $newDueDate,
            'extension_fee' => $extensionFee,
            'status' => 'pending_approval'
        ]
    ]);
}

/**
 * Calculate progress for a contract
 */
function calculateProgress($contract): array {
    $financedAmount = (float)$contract['financed_amount'];
    $paidAmount = (float)$contract['paid_amount'];
    $pendingAmount = (float)($contract['pending_amount'] ?? 0);
    $remainingAmount = $financedAmount - $paidAmount;
    $percentage = $financedAmount > 0 ? round(($paidAmount / $financedAmount) * 100, 1) : 0;
    
    return [
        'financed_amount' => $financedAmount,
        'paid_amount' => $paidAmount,
        'pending_amount' => $pendingAmount,
        'remaining_amount' => max(0, $remainingAmount),
        'percentage' => $percentage,
        'paid_periods' => (int)$contract['paid_periods'],
        'total_periods' => (int)$contract['total_periods'],
        'remaining_periods' => max(0, (int)$contract['total_periods'] - (int)$contract['paid_periods']),
        'is_completed' => $paidAmount >= $financedAmount,
        'is_overdue' => $contract['status'] === 'overdue'
    ];
}
