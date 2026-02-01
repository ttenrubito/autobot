<?php
/**
 * Customer Pawns API (ฝากจำนำ)
 * 
 * Hybrid A+ System: สินค้าที่รับจำนำต้องมาจาก order ที่ลูกค้าซื้อจากร้าน
 * 
 * GET  /api/customer/pawns                    - Get all pawns for customer
 * GET  /api/customer/pawns?id=X               - Get specific pawn detail
 * GET  /api/customer/pawns?action=eligible    - Get items eligible for pawning
 * POST /api/customer/pawns?action=create      - Create new pawn from order
 * POST /api/customer/pawns?action=pay-interest - Submit interest payment
 * POST /api/customer/pawns?action=redeem      - Submit redemption payment
 * 
 * Business Rules:
 * - Loan: 65-70% ของราคาประเมิน
 * - Interest: 2% ต่อเดือน
 * - Term: 30 วัน (ต่ออายุได้สูงสุด 12 ครั้ง)
 * 
 * @version 2.0 - Hybrid A+ with order linkage
 * @date 2026-01-31
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/services/PaymentMatchingService.php';

// Business Constants
define('DEFAULT_LOAN_PERCENTAGE', 65);
define('DEFAULT_INTEREST_RATE', 2.0);
define('DEFAULT_TERM_DAYS', 30);
define('MAX_EXTENSIONS', 12);

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
        if ($action === 'eligible') {
            getEligibleItems($pdo, $user_id);
        } elseif ($action === 'calculate') {
            calculateInterestPreview($pdo);
        } elseif ($pawn_id) {
            getPawnDetail($pdo, $pawn_id, $user_id);
        } else {
            getAllPawns($pdo, $user_id);
        }
    } elseif ($method === 'POST') {
        if ($action === 'create') {
            createPawnFromOrder($pdo, $user_id);
        } elseif ($action === 'pay-interest') {
            submitInterestPayment($pdo, $user_id);
        } elseif ($action === 'redeem') {
            submitRedemption($pdo, $user_id);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action. Use: create, pay-interest, or redeem']);
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
 * Get items eligible for pawning (items purchased from shop that aren't currently pawned)
 * 
 * Uses customer_id from query param to find orders for that customer
 * customer_id = customer_profiles.id (from autocomplete selection)
 */
function getEligibleItems($pdo, $shop_owner_id)
{
    // ดึง customer_id จาก query param (ที่ส่งมาจาก pawns.php autocomplete)
    $customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
    
    if (!$customer_id) {
        echo json_encode([
            'success' => false,
            'message' => 'กรุณาระบุ customer_id'
        ]);
        return;
    }
    
    // ดึง orders ทั้งหมดของลูกค้า (ทุกสถานะ ให้เจ้าหน้าที่พิจารณาเอง)
    // Match by customer_id (FK to customer_profiles) หรือ platform_user_id
    // JOIN กับ products table เพื่อดึง image_url
    $stmt = $pdo->prepare("
        SELECT 
            o.id as order_id, 
            o.order_no, 
            o.product_name,
            o.product_code, 
            o.product_ref_id,
            o.unit_price, 
            o.total_amount, 
            o.paid_amount,
            o.status as order_status,
            o.payment_status,
            o.payment_type,
            o.created_at as purchase_date,
            (o.unit_price * ? / 100) as suggested_loan,
            (o.unit_price * ? / 100 * ? / 100) as monthly_interest,
            cp.display_name as customer_name,
            cp.platform,
            p.image_url as product_image
        FROM orders o
        LEFT JOIN customer_profiles cp ON (
            o.customer_id = cp.id 
            OR (o.platform_user_id = cp.platform_user_id AND o.platform = cp.platform)
        )
        LEFT JOIN products p ON o.product_code = p.product_code
        WHERE (o.customer_id = ? OR cp.id = ?)
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([
        DEFAULT_LOAN_PERCENTAGE, 
        DEFAULT_LOAN_PERCENTAGE, 
        DEFAULT_INTEREST_RATE,
        $customer_id,
        $customer_id
    ]);
    $eligibleItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $eligibleItems,
        'business_rules' => [
            'loan_percentage' => DEFAULT_LOAN_PERCENTAGE,
            'interest_rate_monthly' => DEFAULT_INTEREST_RATE,
            'term_days' => DEFAULT_TERM_DAYS,
            'max_extensions' => MAX_EXTENSIONS
        ],
        'message' => count($eligibleItems) > 0 
            ? 'พบสินค้าที่สามารถนำมาจำนำได้ ' . count($eligibleItems) . ' รายการ'
            : 'ไม่พบสินค้าที่สามารถนำมาจำนำได้ (ต้องเป็นสินค้าที่ซื้อจากร้านและชำระเงินแล้ว)'
    ]);
}

/**
 * Calculate interest preview for potential pawn
 */
function calculateInterestPreview($pdo)
{
    $amount = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;
    $percentage = isset($_GET['percentage']) ? (float)$_GET['percentage'] : DEFAULT_LOAN_PERCENTAGE;
    $rate = isset($_GET['rate']) ? (float)$_GET['rate'] : DEFAULT_INTEREST_RATE;
    $days = isset($_GET['days']) ? (int)$_GET['days'] : DEFAULT_TERM_DAYS;
    
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุราคาประเมิน']);
        return;
    }
    
    $loanAmount = $amount * ($percentage / 100);
    $monthlyInterest = $loanAmount * ($rate / 100);
    $totalMonths = ceil($days / 30);
    $totalInterest = $monthlyInterest * $totalMonths;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'appraised_value' => $amount,
            'loan_percentage' => $percentage,
            'loan_amount' => round($loanAmount, 2),
            'interest_rate' => $rate,
            'term_days' => $days,
            'monthly_interest' => round($monthlyInterest, 2),
            'total_interest' => round($totalInterest, 2),
            'total_redemption' => round($loanAmount + $totalInterest, 2),
            'due_date' => date('Y-m-d', strtotime("+{$days} days"))
        ]
    ]);
}

/**
 * Create new pawn from customer's purchased order
 */
function createPawnFromOrder($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    $order_id = $input['order_id'] ?? null;
    $customer_id = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
    $appraised_value = isset($input['appraised_value']) ? (float)$input['appraised_value'] : null;
    $loan_percentage = isset($input['loan_percentage']) ? (float)$input['loan_percentage'] : DEFAULT_LOAN_PERCENTAGE;
    $interest_rate = isset($input['interest_rate']) ? (float)$input['interest_rate'] : DEFAULT_INTEREST_RATE;
    $item_description = $input['item_description'] ?? null;
    $item_type = $input['item_type'] ?? 'สินค้าจำนำ';
    
    if (!$order_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุ order_id ของสินค้าที่ต้องการจำนำ']);
        return;
    }
    
    if (!$customer_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุ customer_id']);
        return;
    }
    
    // Verify order exists and belongs to this shop owner (user_id)
    $orderStmt = $pdo->prepare("
        SELECT o.*, cp.display_name as customer_display_name, cp.platform as customer_platform,
               cp.avatar_url as customer_avatar, cp.phone as customer_phone
        FROM orders o
        LEFT JOIN customer_profiles cp ON o.customer_id = cp.id
        WHERE o.id = ? AND o.user_id = ?
    ");
    $orderStmt->execute([$order_id, $user_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการสั่งซื้อ']);
        return;
    }
    
    // Check if already pawned (check by item_name + customer_id to avoid duplicates)
    $existingStmt = $pdo->prepare("
        SELECT id, pawn_no FROM pawns 
        WHERE customer_id = ? 
        AND item_name = ?
        AND status NOT IN ('redeemed', 'forfeited', 'cancelled')
    ");
    $existingStmt->execute([$customer_id, $order['product_name'] ?? $order['product_code']]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => "สินค้านี้ถูกจำนำอยู่แล้ว (รหัส: {$existing['pawn_no']})"
        ]);
        return;
    }
    
    // Use order price if appraised value not provided
    if (!$appraised_value) {
        $appraised_value = (float)($order['unit_price'] ?? $order['total_amount']);
    }
    
    // Calculate loan details
    $loan_amount = $appraised_value * ($loan_percentage / 100);
    $pawn_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime('+' . DEFAULT_TERM_DAYS . ' days'));
    $pawn_no = 'PWN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    $pdo->beginTransaction();
    try {
        // Insert pawn record matching schema columns
        $insertStmt = $pdo->prepare("
            INSERT INTO pawns (
                pawn_no, user_id, customer_id, tenant_id,
                customer_name, customer_phone, customer_avatar, platform,
                item_type, item_name, item_description,
                appraised_value, loan_amount, interest_rate, interest_type,
                pawn_date, due_date,
                status, notes, created_at
            ) VALUES (
                ?, ?, ?, 'default',
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, 'monthly',
                ?, ?,
                'active', ?, NOW()
            )
        ");
        $insertStmt->execute([
            $pawn_no,
            $user_id,
            $customer_id,
            $order['customer_display_name'] ?? $order['customer_name'] ?? null,
            $order['customer_phone'] ?? null,
            $order['customer_avatar'] ?? null,
            $order['customer_platform'] ?? $order['platform'] ?? null,
            $item_type,
            $order['product_name'] ?? $order['product_code'],
            $item_description ?? "จาก Order #{$order['order_no']}",
            $appraised_value,
            $loan_amount,
            $interest_rate,
            $pawn_date,
            $due_date,
            "สร้างจาก Order ID: {$order_id}"
        ]);
        
        $pawn_id = $pdo->lastInsertId();
        
        // Create case for admin to process
        $caseStmt = $pdo->prepare("
            INSERT INTO cases (
                user_id, customer_profile_id,
                case_type, status, subject, description, priority
            ) VALUES (
                ?, ?,
                'pawn_new', 'open', ?, ?, 'high'
            )
        ");
        $caseStmt->execute([
            $user_id,
            $customer_id,
            "จำนำใหม่: {$pawn_no}",
            "รายการจำนำใหม่\nรหัส: {$pawn_no}\nสินค้า: " . ($order['product_name'] ?? $order['product_code']) . "\nราคาประเมิน: " . number_format($appraised_value, 2) . " บาท\nยอดกู้: " . number_format($loan_amount, 2) . " บาท"
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'สร้างรายการจำนำเรียบร้อยแล้ว',
            'data' => [
                'pawn_id' => $pawn_id,
                'pawn_no' => $pawn_no,
                'order_id' => $order_id,
                'item_name' => $order['product_name'] ?? $order['product_code'],
                'appraised_value' => $appraised_value,
                'loan_amount' => round($loan_amount, 2),
                'interest_rate' => $interest_rate,
                'pawn_date' => $pawn_date,
                'due_date' => $due_date
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Detect schema type (production vs localhost)
 * Returns column mappings based on actual table structure
 */
function getPawnsSchema($pdo)
{
    static $schema = null;
    if ($schema !== null)
        return $schema;

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
function getAllPawns($pdo, $user_id)
{
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 20;
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
    $total = (int) $countStmt->fetchColumn();

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
        $p['principal_amount'] = (float) $p['principal_amount'];
        $p['appraisal_value'] = (float) $p['appraisal_value'];
        $p['monthly_interest'] = (float) $p['monthly_interest'];
        $p['total_interest_paid'] = (float) $p['total_interest_paid'];
        $p['days_until_due'] = (int) $p['days_until_due'];
        $p['is_overdue'] = $p['days_until_due'] < 0 && in_array($p['status'], ['active', 'overdue']);
        $p['interest_rate_percent'] = (float) $p['interest_rate_percent'];

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
function getSummary($pdo, $user_id)
{
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
        'active_count' => (int) ($summary['active_count'] ?? 0),
        'overdue_count' => (int) ($summary['overdue_count'] ?? 0),
        'total_principal' => (float) ($summary['total_principal'] ?? 0),
        'total_redeemed' => (float) ($summary['total_redeemed'] ?? 0)
    ];
}

/**
 * Get specific pawn detail
 */
function getPawnDetail($pdo, $pawn_id, $user_id)
{
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
    $pawn['principal_amount'] = (float) $pawn['principal_amount'];
    $pawn['appraisal_value'] = (float) $pawn['appraisal_value'];
    $pawn['monthly_interest'] = (float) $pawn['monthly_interest'];
    $pawn['days_until_due'] = (int) $pawn['days_until_due'];
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
        $pay['amount'] = (float) $pay['amount'];
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
function submitInterestPayment($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);

    $pawn_id = $input['pawn_id'] ?? null;
    $slip_image_url = $input['slip_image_url'] ?? null;
    $amount = isset($input['amount']) ? (float) $input['amount'] : null;
    $months = isset($input['months']) ? max(1, (int) $input['months']) : 1;

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
    $monthlyInterest = (float) $pawn['principal_amount'] * ((float) $pawn['interest_rate_percent'] / 100);
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

/**
 * Submit redemption payment (ไถ่ถอน)
 */
function submitRedemption($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);

    $pawn_id = $input['pawn_id'] ?? null;
    $slip_image_url = $input['slip_image_url'] ?? null;

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

    if (!in_array($pawn['status'], ['active', 'overdue', 'extended'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'สถานะจำนำไม่สามารถไถ่ถอนได้']);
        return;
    }

    // Calculate redemption amount (principal + outstanding interest)
    $loanAmount = (float) ($pawn['loan_amount'] ?? $pawn['principal_amount'] ?? 0);
    $interestRate = (float) ($pawn['interest_rate'] ?? $pawn['interest_rate_percent'] ?? 2);
    $monthlyInterest = $loanAmount * ($interestRate / 100);

    $dueDate = new DateTime($pawn['due_date'] ?? $pawn['next_interest_due']);
    $today = new DateTime();
    $daysOverdue = max(0, (int) $today->diff($dueDate)->format('%r%a'));
    $outstandingMonths = ($daysOverdue > 0) ? ceil($daysOverdue / 30) : 0;

    $principalAmount = $loanAmount;
    $interestAmount = $outstandingMonths * $monthlyInterest;
    $totalAmount = $principalAmount + $interestAmount;

    $pdo->beginTransaction();
    try {
        // Create redemption payment record
        $paymentStmt = $pdo->prepare("
            INSERT INTO pawn_payments (
                pawn_id, payment_type, amount, interest_amount, principal_amount,
                slip_image, payment_date, notes, created_at
            ) VALUES (
                ?, 'redemption', ?, ?, ?,
                ?, CURDATE(), ?, NOW()
            )
        ");
        $paymentStmt->execute([
            $pawn_id,
            $totalAmount,
            $interestAmount,
            $principalAmount,
            $slip_image_url,
            "ไถ่ถอน เงินต้น: {$principalAmount} + ดอกเบี้ย: {$interestAmount}"
        ]);

        $payment_id = $pdo->lastInsertId();

        // Create case for admin verification
        $caseStmt = $pdo->prepare("
            INSERT INTO cases (
                channel_id, external_user_id, user_id, customer_profile_id,
                case_type, status, subject, description, priority
            ) VALUES (
                ?, ?, ?, ?,
                'pawn_redemption', 'open', ?, ?, 'high'
            )
        ");
        $caseStmt->execute([
            $pawn['channel_id'] ?? 0,
            $pawn['external_user_id'] ?? '',
            $user_id,
            $pawn['customer_profile_id'] ?? null,
            "ตรวจสอบสลิปไถ่ถอน: {$pawn['pawn_no']}",
            "ลูกค้าส่งสลิปไถ่ถอน\nรหัส: {$pawn['pawn_no']}\nเงินต้น: {$principalAmount} บาท\nดอกเบี้ย: {$interestAmount} บาท\nรวม: {$totalAmount} บาท"
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'ส่งสลิปไถ่ถอนเรียบร้อยแล้ว รอเจ้าหน้าที่ตรวจสอบ',
            'data' => [
                'payment_id' => $payment_id,
                'pawn_id' => $pawn_id,
                'pawn_no' => $pawn['pawn_no'],
                'principal' => $principalAmount,
                'interest' => $interestAmount,
                'total' => $totalAmount
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
