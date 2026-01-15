<?php
/**
 * Customer Pawns API (ฝากจำนำ)
 * 
 * GET  /api/customer/pawns              - Get all pawns for customer
 * GET  /api/customer/pawns?id=X         - Get specific pawn detail
 * POST /api/customer/pawns?action=pay-interest - Submit interest payment
 * 
 * Database Schema (pawns table):
 * - id, channel_id, external_user_id, user_id, customer_profile_id
 * - pawn_no, item_description, category, brand, model
 * - appraisal_value, loan_percentage, principal_amount
 * - interest_rate_percent, contract_start_date, next_interest_due
 * - status (pending, active, overdue, redeemed, forfeited)
 * - redeemed_at, forfeited_at
 * - note, created_at, updated_at
 * 
 * Database Schema (pawn_payments table):
 * - id, pawn_id, payment_type (interest, redemption)
 * - amount, slip_image_url
 * - status (pending, verified, rejected)
 * - period_start, period_end
 * - verified_by, verified_at, rejection_reason
 * - note, created_at, updated_at
 * 
 * @version 1.0
 * @date 2026-01-10
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

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
$pawn_id = $_GET['id'] ?? null;

try {
    $pdo = getDB();
    
    // Check if pawns table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'pawns'");
    if ($tableCheck->rowCount() === 0) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'summary' => [
                'total_principal' => 0,
                'active_count' => 0,
                'overdue_count' => 0
            ],
            'pagination' => [
                'page' => 1,
                'limit' => 20,
                'total' => 0,
                'total_pages' => 0
            ],
            'message' => 'ระบบฝากจำนำยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ'
        ]);
        exit;
    }
    
    if ($method === 'GET') {
        if ($pawn_id) {
            getPawnDetail($pdo, $pawn_id, $user_id);
        } else {
            getAllPawns($pdo, $user_id);
        }
    } elseif ($method === 'POST') {
        if ($action === 'pay-interest') {
            submitInterestPayment($pdo, $user_id);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Customer Pawns API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่',
        'error' => $e->getMessage()
    ]);
}

/**
 * Detect schema type (production vs localhost)
 * Returns column mappings based on actual table structure
 */
function getPawnsSchema($pdo) {
    static $schema = null;
    if ($schema !== null) return $schema;
    
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM pawns LIKE 'item_type'");
        $hasItemType = $stmt->rowCount() > 0;
        
        if ($hasItemType) {
            // Production schema
            $schema = [
                'type' => 'production',
                'category' => 'item_type',
                'name' => 'item_name',
                'description' => 'item_description',
                'appraisal' => 'appraised_value',
                'principal' => 'loan_amount',
                'interest_rate' => 'interest_rate',
                'start_date' => 'pawn_date',
                'due_date' => 'due_date',
                'redeemed' => 'redeemed_date',
                'forfeited' => 'forfeited_date',
                'notes' => 'notes'
            ];
        } else {
            // Localhost schema
            $schema = [
                'type' => 'localhost',
                'category' => 'product_name',
                'name' => 'product_name',
                'description' => 'product_description',
                'appraisal' => 'appraisal_value',
                'principal' => 'pawn_amount',
                'interest_rate' => 'interest_rate',
                'start_date' => 'created_at',
                'due_date' => 'next_due_date',
                'redeemed' => 'redeemed_at',
                'forfeited' => 'COALESCE(NULL, NULL)',
                'notes' => 'admin_notes'
            ];
        }
    } catch (Exception $e) {
        // Default to production
        $schema = ['type' => 'production'];
    }
    
    return $schema;
}

/**
 * Get all pawns for customer
 */
function getAllPawns($pdo, $user_id) {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    
    // Use user_id to filter by shop owner
    $where = ['p.user_id = ?'];
    $params = [$user_id];
    
    if ($status) {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM pawns p WHERE $where_clause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Get schema info
    $s = getPawnsSchema($pdo);
    
    // Build dynamic SQL based on schema
    $sql = "
        SELECT 
            p.id,
            p.pawn_no,
            p.{$s['category']} as category,
            p.{$s['name']} as item_name,
            p.{$s['description']} as item_description,
            p.{$s['appraisal']} as appraisal_value,
            p.{$s['principal']} as principal_amount,
            p.{$s['interest_rate']} as interest_rate_percent,
            p.{$s['start_date']} as contract_start_date,
            p.{$s['due_date']} as next_interest_due,
            p.status,
            p.{$s['redeemed']} as redeemed_at,
            p.{$s['notes']} as note,
            p.created_at,
            -- Calculate current interest due
            (p.{$s['principal']} * p.{$s['interest_rate']} / 100) as monthly_interest,
            -- Days until due / overdue
            DATEDIFF(p.{$s['due_date']}, CURDATE()) as days_until_due
        FROM pawns p
        WHERE $where_clause
        ORDER BY p.{$s['due_date']} ASC, p.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    // Get pawns
    $stmt = $pdo->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $pawns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add status display and calculations
    $statusLabels = [
        'pending' => 'รอดำเนินการ',
        'active' => 'กำลังดำเนินการ',
        'overdue' => 'เกินกำหนด',
        'redeemed' => 'ไถ่ถอนแล้ว',
        'forfeited' => 'หลุดจำนำ',
        'extended' => 'ต่อสัญญาแล้ว',
        'expired' => 'หมดอายุ',
        'sold' => 'ขายแล้ว',
        'cancelled' => 'ยกเลิก'
    ];
    
    foreach ($pawns as &$p) {
        $p['status_display'] = $statusLabels[$p['status']] ?? $p['status'];
        $p['principal_amount'] = (float)$p['principal_amount'];
        $p['appraisal_value'] = (float)$p['appraisal_value'];
        $p['monthly_interest'] = (float)$p['monthly_interest'];
        $p['total_interest_paid'] = (float)$p['total_interest_paid'];
        $p['days_until_due'] = (int)$p['days_until_due'];
        $p['is_overdue'] = $p['days_until_due'] < 0 && in_array($p['status'], ['active', 'overdue']);
        $p['interest_rate_percent'] = (float)$p['interest_rate_percent'];
        
        // Calculate redemption amount (principal + any outstanding interest)
        $outstandingMonths = $p['is_overdue'] ? ceil(abs($p['days_until_due']) / 30) : 0;
        $p['outstanding_interest'] = $outstandingMonths * $p['monthly_interest'];
        $p['redemption_amount'] = $p['principal_amount'] + $p['outstanding_interest'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $pawns,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ],
        'summary' => getSummary($pdo, $user_id)
    ]);
}

/**
 * Get summary statistics
 */
function getSummary($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN status IN ('active', 'overdue', 'extended') THEN 1 END) as active_count,
            COUNT(CASE WHEN status = 'overdue' OR (status = 'active' AND due_date < CURDATE()) THEN 1 END) as overdue_count,
            SUM(CASE WHEN status IN ('active', 'overdue', 'extended') THEN loan_amount ELSE 0 END) as total_principal,
            SUM(CASE WHEN status = 'redeemed' THEN loan_amount ELSE 0 END) as total_redeemed
        FROM pawns
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'active_count' => (int)($summary['active_count'] ?? 0),
        'overdue_count' => (int)($summary['overdue_count'] ?? 0),
        'total_principal' => (float)($summary['total_principal'] ?? 0),
        'total_redeemed' => (float)($summary['total_redeemed'] ?? 0)
    ];
}

/**
 * Get specific pawn detail
 */
function getPawnDetail($pdo, $pawn_id, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            p.loan_amount as principal_amount,
            p.interest_rate as interest_rate_percent,
            p.appraised_value as appraisal_value,
            p.due_date as next_interest_due,
            p.pawn_date as contract_start_date,
            (p.loan_amount * p.interest_rate / 100) as monthly_interest,
            DATEDIFF(p.due_date, CURDATE()) as days_until_due
        FROM pawns p
        WHERE p.id = ? AND p.user_id = ?
    ");
    $stmt->execute([$pawn_id, $user_id]);
    $pawn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pawn) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการจำนำ']);
        return;
    }
    
    $statusLabels = [
        'pending' => 'รอดำเนินการ',
        'active' => 'กำลังดำเนินการ',
        'overdue' => 'เกินกำหนด',
        'redeemed' => 'ไถ่ถอนแล้ว',
        'forfeited' => 'หลุดจำนำ'
    ];
    
    $pawn['status_display'] = $statusLabels[$pawn['status']] ?? $pawn['status'];
    $pawn['principal_amount'] = (float)$pawn['principal_amount'];
    $pawn['appraisal_value'] = (float)$pawn['appraisal_value'];
    $pawn['monthly_interest'] = (float)$pawn['monthly_interest'];
    $pawn['days_until_due'] = (int)$pawn['days_until_due'];
    $pawn['is_overdue'] = $pawn['days_until_due'] < 0 && in_array($pawn['status'], ['active', 'overdue']);
    
    // Calculate outstanding interest
    $outstandingMonths = $pawn['is_overdue'] ? ceil(abs($pawn['days_until_due']) / 30) : 0;
    $pawn['outstanding_interest'] = $outstandingMonths * $pawn['monthly_interest'];
    $pawn['redemption_amount'] = $pawn['principal_amount'] + $pawn['outstanding_interest'];
    
    // Get payment history
    $paymentStmt = $pdo->prepare("
        SELECT 
            pp.*,
            CASE pp.status
                WHEN 'pending' THEN 'รอตรวจสอบ'
                WHEN 'verified' THEN 'ยืนยันแล้ว'
                WHEN 'rejected' THEN 'ไม่อนุมัติ'
            END as status_display
        FROM pawn_payments pp
        WHERE pp.pawn_id = ?
        ORDER BY pp.created_at DESC
    ");
    $paymentStmt->execute([$pawn_id]);
    $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($payments as &$pay) {
        $pay['amount'] = (float)$pay['amount'];
    }
    
    // Get bank accounts
    $bankStmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC");
    $bankStmt->execute();
    $bankAccounts = $bankStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build interest schedule
    $schedule = [];
    if (in_array($pawn['status'], ['active', 'overdue'])) {
        $nextDue = new DateTime($pawn['next_interest_due']);
        for ($i = 0; $i < 6; $i++) {
            $dueDate = clone $nextDue;
            $dueDate->modify("+{$i} months");
            $schedule[] = [
                'period' => $i + 1,
                'due_date' => $dueDate->format('Y-m-d'),
                'amount' => $pawn['monthly_interest'],
                'is_current' => $i === 0,
                'is_overdue' => $i === 0 && $pawn['is_overdue']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $pawn,
        'payments' => $payments,
        'schedule' => $schedule,
        'bank_accounts' => $bankAccounts
    ]);
}

/**
 * Submit interest payment
 */
function submitInterestPayment($pdo, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $pawn_id = $input['pawn_id'] ?? null;
    $slip_image_url = $input['slip_image_url'] ?? null;
    $amount = isset($input['amount']) ? (float)$input['amount'] : null;
    $months = isset($input['months']) ? max(1, (int)$input['months']) : 1;
    
    if (!$pawn_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุรายการจำนำ']);
        return;
    }
    
    if (!$slip_image_url) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาแนบสลิปการโอน']);
        return;
    }
    
    // Get pawn
    $stmt = $pdo->prepare("SELECT * FROM pawns WHERE id = ? AND user_id = ?");
    $stmt->execute([$pawn_id, $user_id]);
    $pawn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pawn) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการจำนำ']);
        return;
    }
    
    if (!in_array($pawn['status'], ['active', 'overdue'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'สถานะจำนำไม่ถูกต้อง']);
        return;
    }
    
    // Calculate interest
    $monthlyInterest = (float)$pawn['principal_amount'] * ((float)$pawn['interest_rate_percent'] / 100);
    $totalInterest = $monthlyInterest * $months;
    
    // Calculate period dates
    $periodStart = new DateTime($pawn['next_interest_due']);
    $periodEnd = clone $periodStart;
    $periodEnd->modify('+' . $months . ' months');
    $periodEnd->modify('-1 day');
    
    $pdo->beginTransaction();
    try {
        // Create payment record
        $paymentStmt = $pdo->prepare("
            INSERT INTO pawn_payments (
                pawn_id, payment_type, amount, slip_image_url,
                status, period_start, period_end, note, created_at, updated_at
            ) VALUES (
                ?, 'interest', ?, ?, 
                'pending', ?, ?, ?, NOW(), NOW()
            )
        ");
        $paymentStmt->execute([
            $pawn_id,
            $totalInterest,
            $slip_image_url,
            $periodStart->format('Y-m-d'),
            $periodEnd->format('Y-m-d'),
            "ชำระดอกเบี้ย {$months} เดือน"
        ]);
        
        $payment_id = $pdo->lastInsertId();
        
        // Create case for admin verification
        $caseStmt = $pdo->prepare("
            INSERT INTO cases (
                channel_id, external_user_id, user_id, customer_profile_id,
                case_type, status, subject, description, priority
            ) VALUES (
                ?, ?, ?, ?,
                'pawn_interest', 'open', ?, ?, 'high'
            )
        ");
        $caseStmt->execute([
            $pawn['channel_id'],
            $pawn['external_user_id'],
            $user_id,
            $pawn['customer_profile_id'],
            "ตรวจสอบสลิปดอกเบี้ย: {$pawn['pawn_no']}",
            "ลูกค้าส่งสลิปชำระดอกเบี้ย\nรหัส: {$pawn['pawn_no']}\nจำนวน: {$months} เดือน\nยอด: {$totalInterest} บาท"
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'ส่งสลิปเรียบร้อยแล้ว รอเจ้าหน้าที่ตรวจสอบ',
            'data' => [
                'payment_id' => $payment_id,
                'pawn_id' => $pawn_id,
                'pawn_no' => $pawn['pawn_no'],
                'months' => $months,
                'amount' => $totalInterest,
                'period_start' => $periodStart->format('Y-m-d'),
                'period_end' => $periodEnd->format('Y-m-d')
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
