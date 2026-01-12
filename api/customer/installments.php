<?php
/**
 * Customer Installments API
 * 
 * GET  /api/customer/installments           - Get all installments for customer
 * POST /api/customer/installments           - Create new installment plan
 * POST /api/customer/installments?action=pay - Pay installment
 * GET  /api/customer/installments?id=X      - Get specific installment
 * 
 * @version 1.0
 * @date 2026-01-07
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Database.php';

// Verify authentication
$auth = verifyToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $auth['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$installment_id = $_GET['id'] ?? null;

try {
    $db = Database::getInstance();
    $pdo = getDB();
    
    // Check if installment_contracts table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'installment_contracts'");
    if ($tableCheck->rowCount() === 0) {
        // Table doesn't exist yet - return empty data with message
        echo json_encode([
            'success' => true,
            'data' => [],
            'summary' => [
                'total_paid' => 0,
                'total_remaining' => 0,
                'active_count' => 0,
                'overdue_count' => 0
            ],
            'pagination' => [
                'page' => 1,
                'limit' => 20,
                'total' => 0,
                'total_pages' => 0
            ],
            'message' => 'ระบบผ่อนชำระยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ'
        ]);
        exit;
    }
    
    // Get customer_id from user_id (fallback to user_id if no customers table)
    $customer_id = $user_id;
    try {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            $customer_id = $customer['id'];
        }
    } catch (PDOException $e) {
        // customers table doesn't exist, use user_id directly
        $customer_id = $user_id;
    }
    
    if ($method === 'GET') {
        if ($installment_id) {
            // Get specific installment
            getInstallmentDetail($pdo, $installment_id, $customer_id);
        } else {
            // Get all installments for customer
            getAllInstallments($pdo, $customer_id);
        }
    } elseif ($method === 'POST') {
        if ($action === 'pay') {
            // Pay installment
            payInstallment($pdo, $customer_id);
        } else {
            // Create new installment plan
            createInstallment($pdo, $customer_id);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Customer Installments API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'
    ]);
}

/**
 * Get all installments for customer
 */
function getAllInstallments($pdo, $customer_id) {
    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    // Get total count first
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM installment_contracts WHERE customer_id = ?");
    $countStmt->execute([$customer_id]);
    $total = (int)$countStmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            c.customer_name,
            c.customer_avatar,
            c.platform as customer_platform,
            (c.total_periods - c.paid_periods) as remaining_periods,
            (c.financed_amount - c.paid_amount) as remaining_amount,
            ROUND((c.paid_periods / NULLIF(c.total_periods, 0)) * 100, 1) as progress_percent,
            CASE 
                WHEN c.next_due_date < CURDATE() AND c.status = 'active' THEN 1
                ELSE 0
            END as is_overdue
        FROM installment_contracts c
        WHERE c.customer_id = ?
        ORDER BY c.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$customer_id, $limit, $offset]);
    $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary from all records
    $summaryStmt = $pdo->prepare("
        SELECT 
            SUM(paid_amount) as total_paid,
            SUM(financed_amount - paid_amount) as total_remaining,
            SUM(CASE WHEN status IN ('active', 'overdue') THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN status = 'overdue' OR (next_due_date < CURDATE() AND status = 'active') THEN 1 ELSE 0 END) as overdue_count
        FROM installment_contracts
        WHERE customer_id = ?
    ");
    $summaryStmt->execute([$customer_id]);
    $summaryData = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    $totalPaid = (float)($summaryData['total_paid'] ?? 0);
    $totalRemaining = (float)($summaryData['total_remaining'] ?? 0);
    $activeCount = (int)($summaryData['active_count'] ?? 0);
    $overdueCount = (int)($summaryData['overdue_count'] ?? 0);
    
    foreach ($installments as &$i) {
        $i['paid_amount'] = (float)($i['paid_amount'] ?? 0);
        $i['financed_amount'] = (float)($i['financed_amount'] ?? 0);
        $i['amount_per_period'] = (float)($i['amount_per_period'] ?? 0);
        $i['remaining_amount'] = (float)($i['remaining_amount'] ?? 0);
        $i['progress_percent'] = (float)($i['progress_percent'] ?? 0);
        $i['is_overdue'] = (bool)$i['is_overdue'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $installments,
        'summary' => [
            'total_paid' => $totalPaid,
            'total_remaining' => $totalRemaining,
            'active_count' => $activeCount,
            'overdue_count' => $overdueCount
        ],
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Get specific installment detail
 */
function getInstallmentDetail($pdo, $installment_id, $customer_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM installment_contracts 
        WHERE id = ? AND customer_id = ?
    ");
    $stmt->execute([$installment_id, $customer_id]);
    $installment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$installment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูล']);
        return;
    }
    
    // Get payments history
    $stmt = $pdo->prepare("
        SELECT * FROM installment_payments 
        WHERE contract_id = ?
        ORDER BY period_number ASC, created_at DESC
    ");
    $stmt->execute([$installment_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate pending amount from payments
    $pendingAmount = 0;
    foreach ($payments as $p) {
        if ($p['status'] === 'pending' || $p['status'] === 'pending_verification') {
            $pendingAmount += (float)$p['amount'];
        }
    }
    
    // Calculate progress
    $installment['paid_amount'] = (float)($installment['paid_amount'] ?? 0);
    $installment['financed_amount'] = (float)($installment['financed_amount'] ?? 0);
    $installment['pending_amount'] = $pendingAmount;
    $installment['remaining_amount'] = max(0, $installment['financed_amount'] - $installment['paid_amount']);
    $installment['remaining_periods'] = $installment['total_periods'] - $installment['paid_periods'];
    $installment['progress_percent'] = $installment['total_periods'] > 0 
        ? round(($installment['paid_periods'] / $installment['total_periods']) * 100, 1) 
        : 0;
    $installment['is_overdue'] = $installment['next_due_date'] && 
        strtotime($installment['next_due_date']) < strtotime('today') &&
        $installment['status'] === 'active';
    
    // Build schedule from contract data + payments
    $totalPeriods = (int)$installment['total_periods'];
    $amountPerPeriod = (float)$installment['amount_per_period'];
    $startDate = $installment['first_payment_date'] ?? $installment['started_at'] ?? $installment['created_at'];
    
    $schedule = [];
    for ($i = 1; $i <= $totalPeriods; $i++) {
        // Calculate due date for this period
        $dueDate = date('Y-m-d', strtotime($startDate . ' +' . ($i - 1) . ' months'));
        
        // Find payment for this period
        $payment = null;
        foreach ($payments as $p) {
            if ((int)$p['period_number'] === $i) {
                $payment = $p;
                break;
            }
        }
        
        // Determine status
        $status = 'pending';
        if ($payment) {
            $status = $payment['status'];
        } elseif (strtotime($dueDate) < strtotime('today')) {
            $status = 'overdue';
        }
        
        $schedule[] = [
            'period_number' => $i,
            'due_date' => $dueDate,
            'amount_due' => $amountPerPeriod,
            'amount' => $payment['amount'] ?? null,
            'status' => $status,
            'paid_at' => $payment['created_at'] ?? null,
            'payment_date' => $payment['paid_date'] ?? null,
            'notes' => $payment['notes'] ?? null,
            'rejection_reason' => $payment['rejection_reason'] ?? null,
            'payment_method' => $payment['payment_method'] ?? null
        ];
    }
    
    echo json_encode([
        'success' => true, 
        'data' => [
            'contract' => $installment,
            'payments' => $schedule,
            'payment_history' => $payments
        ]
    ]);
}

/**
 * Create new installment plan (customer-initiated)
 */
function createInstallment($pdo, $customer_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($input['name'] ?? '');
    $totalAmount = (float)($input['total_amount'] ?? 0);
    $totalTerms = (int)($input['total_terms'] ?? 0);
    $startDate = $input['start_date'] ?? date('Y-m-d');
    $note = trim($input['note'] ?? '');
    
    if (empty($name) || $totalAmount <= 0 || $totalTerms <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    // Validate terms (reasonable range: 1-60 months)
    if ($totalTerms < 1 || $totalTerms > 60) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'จำนวนงวดต้องอยู่ระหว่าง 1-60 งวด']);
        return;
    }
    
    $perPeriod = round($totalAmount / $totalTerms, 2);
    
    // Generate contract number
    $contractNo = 'IC-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    
    // Calculate first due date (1 month from start)
    $firstDueDate = date('Y-m-d', strtotime($startDate . ' +1 month'));
    
    $pdo->beginTransaction();
    
    try {
        // Create contract (pending approval)
        $stmt = $pdo->prepare("
            INSERT INTO installment_contracts 
            (contract_no, tenant_id, customer_id, channel_id, external_user_id, platform,
             product_name, product_ref_id, product_price, total_amount, down_payment,
             financed_amount, total_periods, amount_per_period, 
             contract_date, start_date, next_due_date, status, admin_notes, created_at)
            VALUES (?, 'default', ?, 1, '', 'web',
                    ?, ?, ?, ?, 0,
                    ?, ?, ?,
                    ?, ?, ?, 'pending_approval', ?, NOW())
        ");
        
        $stmt->execute([
            $contractNo,
            $customer_id,
            $name,
            $contractNo, // product_ref_id
            $totalAmount,
            $totalAmount,
            $totalAmount,
            $totalTerms,
            $perPeriod,
            date('Y-m-d'),
            $startDate,
            $firstDueDate,
            $note
        ]);
        
        $contractId = $pdo->lastInsertId();
        
        // Create payment schedule
        $dueDate = $firstDueDate;
        for ($period = 1; $period <= $totalTerms; $period++) {
            $stmt = $pdo->prepare("
                INSERT INTO installment_schedules 
                (contract_id, period_number, due_date, amount, status, created_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$contractId, $period, $dueDate, $perPeriod]);
            
            $dueDate = date('Y-m-d', strtotime($dueDate . ' +1 month'));
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'สร้างแผนผ่อนสำเร็จ รอการอนุมัติ',
            'data' => [
                'id' => $contractId,
                'contract_no' => $contractNo,
                'status' => 'pending_approval',
                'amount_per_period' => $perPeriod
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Pay installment period
 */
function payInstallment($pdo, $customer_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $installmentId = (int)($input['installment_id'] ?? 0);
    $amount = (float)($input['amount'] ?? 0);
    $note = trim($input['note'] ?? '');
    
    if ($installmentId <= 0 || $amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    // Verify ownership
    $stmt = $pdo->prepare("
        SELECT id, status, paid_periods, total_periods, amount_per_period, next_due_date 
        FROM installment_contracts 
        WHERE id = ? AND customer_id = ?
    ");
    $stmt->execute([$installmentId, $customer_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลสัญญา']);
        return;
    }
    
    if (!in_array($contract['status'], ['active', 'overdue'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'สัญญานี้ไม่สามารถชำระได้']);
        return;
    }
    
    $nextPeriod = $contract['paid_periods'] + 1;
    
    // Generate payment number
    $paymentNo = 'ICPAY-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    
    $stmt = $pdo->prepare("
        INSERT INTO installment_payments 
        (contract_id, payment_no, period_number, amount, due_date, status, extension_reason, created_at)
        VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())
    ");
    
    $stmt->execute([
        $installmentId, 
        $paymentNo,
        $nextPeriod, 
        $amount, 
        $contract['next_due_date'],
        $note ?: null
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'บันทึกการชำระเงินแล้ว รอตรวจสอบ',
        'data' => [
            'payment_no' => $paymentNo,
            'period_number' => $nextPeriod,
            'amount' => $amount,
            'status' => 'pending'
        ]
    ]);
}
