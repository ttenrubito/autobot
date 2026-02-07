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
        } elseif ($action === 'cancel') {
            // Cancel installment
            cancelInstallment($pdo, $customer_id);
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
 * Get all installments for customer (shop owner)
 * Pattern: Same as cases.php - JOIN with customer_profiles to get customer name/avatar
 */
function getAllInstallments($pdo, $user_id)
{
    // Pagination
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    // Filters
    $statusFilter = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
    $dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
    $dateTo = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null;
    $search = isset($_GET['search']) && $_GET['search'] !== '' ? trim($_GET['search']) : null;
    
    // Build WHERE clause
    $whereClauses = ['c.customer_id = ?'];
    $params = [$user_id];
    
    if ($statusFilter) {
        $whereClauses[] = 'c.status = ?';
        $params[] = $statusFilter;
    }
    
    if ($dateFrom) {
        $whereClauses[] = 'DATE(c.created_at) >= ?';
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $whereClauses[] = 'DATE(c.created_at) <= ?';
        $params[] = $dateTo;
    }
    
    if ($search) {
        $whereClauses[] = '(c.name LIKE ? OR c.product_name LIKE ? OR c.contract_no LIKE ? OR o.order_no LIKE ? OR c.customer_name LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $whereSQL = implode(' AND ', $whereClauses);

    // Get total count first
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM installment_contracts c
        LEFT JOIN orders o ON c.order_id = o.id
        WHERE $whereSQL
    ");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // JOIN with customer_profiles AND orders for order_no (like cases.php pattern)
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            (c.total_periods - c.paid_periods) as remaining_periods,
            (c.financed_amount - c.paid_amount) as remaining_amount,
            ROUND((c.paid_periods / NULLIF(c.total_periods, 0)) * 100, 1) as progress_percent,
            CASE 
                WHEN c.next_due_date < CURDATE() AND c.status = 'active' THEN 1
                ELSE 0
            END as is_overdue,
            -- ✅ JOIN customer_profiles for name/avatar (same pattern as cases.php)
            COALESCE(cp.display_name, cp.full_name, c.customer_name, CONCAT('ลูกค้า ', RIGHT(c.platform_user_id, 6))) as customer_display_name,
            COALESCE(cp.avatar_url, cp.profile_pic_url, c.customer_avatar) as customer_avatar_url,
            c.platform as customer_platform,
            -- ✅ Order info for display
            o.order_no as order_number
        FROM installment_contracts c
        LEFT JOIN customer_profiles cp ON c.platform_user_id = cp.platform_user_id AND c.platform = cp.platform
        LEFT JOIN orders o ON c.order_id = o.id
        WHERE $whereSQL
        ORDER BY c.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));
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
    $summaryStmt->execute([$user_id]);
    $summaryData = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    $totalPaid = (float) ($summaryData['total_paid'] ?? 0);
    $totalRemaining = (float) ($summaryData['total_remaining'] ?? 0);
    $activeCount = (int) ($summaryData['active_count'] ?? 0);
    $overdueCount = (int) ($summaryData['overdue_count'] ?? 0);

    foreach ($installments as &$i) {
        $i['paid_amount'] = (float) ($i['paid_amount'] ?? 0);
        $i['financed_amount'] = (float) ($i['financed_amount'] ?? 0);
        $i['amount_per_period'] = (float) ($i['amount_per_period'] ?? 0);
        $i['remaining_amount'] = (float) ($i['remaining_amount'] ?? 0);
        $i['progress_percent'] = (float) ($i['progress_percent'] ?? 0);
        $i['is_overdue'] = (bool) $i['is_overdue'];
        
        // ✅ Normalize customer fields for frontend (same as orders.php pattern)
        $i['customer_name'] = $i['customer_display_name'] ?? $i['customer_name'] ?? null;
        $i['customer_avatar'] = $i['customer_avatar_url'] ?? null;
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
 * Pattern: Same as cases.php - JOIN with customer_profiles
 */
function getInstallmentDetail($pdo, $installment_id, $user_id)
{
    // JOIN with customer_profiles AND orders for complete info
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            COALESCE(cp.display_name, cp.full_name, c.customer_name, CONCAT('ลูกค้า ', RIGHT(c.platform_user_id, 6))) as customer_display_name,
            COALESCE(cp.avatar_url, cp.profile_pic_url, c.customer_avatar) as customer_avatar_url,
            c.platform as customer_platform,
            o.order_no as order_number,
            o.product_code as product_code
        FROM installment_contracts c
        LEFT JOIN customer_profiles cp ON c.platform_user_id = cp.platform_user_id AND c.platform = cp.platform
        LEFT JOIN orders o ON c.order_id = o.id
        WHERE c.id = ? AND c.customer_id = ?
    ");
    $stmt->execute([$installment_id, $user_id]);
    $installment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$installment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูล']);
        return;
    }
    
    // Normalize customer fields
    $installment['customer_name'] = $installment['customer_display_name'] ?? $installment['customer_name'] ?? null;
    $installment['customer_avatar'] = $installment['customer_avatar_url'] ?? null;

    // Get product image from product_catalog if available (table may not exist)
    $installment['product_image_url'] = null;
    if (!empty($installment['product_ref_id'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT image_url FROM product_catalog WHERE ref_id = ? LIMIT 1
            ");
            $stmt->execute([$installment['product_ref_id']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            $installment['product_image_url'] = $product['image_url'] ?? null;
        } catch (PDOException $e) {
            // Table doesn't exist or other error - just continue without image
            $installment['product_image_url'] = null;
        }
    }

    // Get payments history from installment_payments table
    // ✅ FIXED: Use contract_id (production schema) and JOIN with payments via payment_id
    $stmt = $pdo->prepare("
        SELECT ip.*, 
               p.payment_no as linked_payment_no
        FROM installment_payments ip
        LEFT JOIN payments p ON ip.payment_id = p.id
        WHERE ip.contract_id = ?
        ORDER BY ip.period_number ASC, ip.created_at DESC
    ");
    $stmt->execute([$installment_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create a map: period_number => payment_no(s) from the payments we just fetched
    $periodPaymentMap = [];
    foreach ($payments as $p) {
        $period = (int)($p['period_number'] ?? 0);
        if ($period > 0 && !empty($p['linked_payment_no'])) {
            if (!isset($periodPaymentMap[$period])) {
                $periodPaymentMap[$period] = [];
            }
            if (!in_array($p['linked_payment_no'], $periodPaymentMap[$period])) {
                $periodPaymentMap[$period][] = $p['linked_payment_no'];
            }
        }
    }

    // Calculate pending amount from payments
    $pendingAmount = 0;
    foreach ($payments as $p) {
        if ($p['status'] === 'pending' || $p['status'] === 'pending_verification') {
            $pendingAmount += (float) $p['amount'];
        }
    }

    // Calculate progress
    $installment['paid_amount'] = (float) ($installment['paid_amount'] ?? 0);
    $installment['financed_amount'] = (float) ($installment['financed_amount'] ?? 0);
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
    $totalPeriods = (int) $installment['total_periods'];
    $productPrice = (float) ($installment['product_price'] ?? $installment['total_amount'] ?? 0);
    $startDate = $installment['first_payment_date'] ?? $installment['started_at'] ?? $installment['created_at'];

    // ✅ FIX: Calculate per-period amounts correctly (งวดแรก + 3% ค่าดำเนินการ)
    // นโยบาย ฮ.เฮง เฮง: ค่าธรรมเนียม 3% รวมในงวดแรก
    $serviceFeeRate = 0.03;
    $serviceFee = round($productPrice * $serviceFeeRate);
    $basePerPeriod = floor($productPrice / $totalPeriods);
    $remainder = $productPrice - ($basePerPeriod * $totalPeriods);
    
    // Period amounts: P1 = base + fee, P2 = base, P3 = base + remainder
    $periodAmounts = [
        1 => $basePerPeriod + $serviceFee,  // งวดแรก: รวมค่าธรรมเนียม 3%
        2 => $basePerPeriod,                 // งวดที่ 2: ยอดฐาน
        3 => $basePerPeriod + $remainder     // งวดที่ 3: ยอดฐาน + เศษ
    ];

    $schedule = [];
    for ($i = 1; $i <= $totalPeriods; $i++) {
        // Calculate due date (นโยบาย ฮ.เฮง เฮง: Day 0, 30, 60)
        $periodDays = [1 => 0, 2 => 30, 3 => 60];
        $daysToAdd = $periodDays[$i] ?? (($i - 1) * 30);
        $dueDate = date('Y-m-d', strtotime($startDate . " +{$daysToAdd} days"));

        // Find payment for this period
        $payment = null;
        foreach ($payments as $p) {
            if ((int) $p['period_number'] === $i) {
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

        // ✅ FIX: Use correct amount for this period (from payment record or calculated)
        $amountDue = $payment['amount'] ?? $periodAmounts[$i] ?? $basePerPeriod;
        
        // ✅ Get payment_no from verified payments map
        $paymentNoForPeriod = isset($periodPaymentMap[$i]) 
            ? implode(', ', $periodPaymentMap[$i]) // Join multiple payment refs if any
            : null;

        $schedule[] = [
            'period_number' => $i,
            'due_date' => $dueDate,
            'amount_due' => $amountDue,
            'amount' => $payment['amount'] ?? null,
            'status' => $status,
            'paid_at' => $payment['created_at'] ?? null,
            'payment_date' => $payment['paid_date'] ?? null,
            'payment_no' => $paymentNoForPeriod,  // ✅ From verified payments
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
            'payment_history' => $payments,
            'all_payment_refs' => $periodPaymentMap  // ✅ All verified payments linked to this order (by period)
        ]
    ]);
}

/**
 * Create new installment plan (customer-initiated)
 */
function createInstallment($pdo, $customer_id)
{
    $input = json_decode(file_get_contents('php://input'), true);

    $name = trim($input['name'] ?? '');
    $totalAmount = (float) ($input['total_amount'] ?? 0);
    $totalTerms = (int) ($input['total_terms'] ?? 0);
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

    // Calculate first due date
    // สำหรับ 3 งวด (default): ใช้นโยบาย 60 วัน (งวด 1=0, งวด 2=+20, งวด 3=+40 วัน)
    // สำหรับงวดอื่นๆ: ใช้ 1 เดือน/งวด
    if ($totalTerms == 3) {
        $firstDueDate = $startDate; // งวดแรก = วันเริ่ม
    } else {
        $firstDueDate = date('Y-m-d', strtotime($startDate . ' +1 month'));
    }

    $pdo->beginTransaction();

    try {
        // Create contract (pending approval)
        // Note: Customer-created installments typically have no service fee (interest_rate=0)
        $stmt = $pdo->prepare("
            INSERT INTO installment_contracts 
            (contract_no, tenant_id, customer_id, channel_id, external_user_id, platform,
             product_name, product_ref_id, product_price, total_amount, down_payment,
             financed_amount, total_periods, amount_per_period, 
             interest_rate, interest_type, total_interest,
             contract_date, start_date, next_due_date, status, admin_notes, created_at)
            VALUES (?, 'default', ?, 1, '', 'web',
                    ?, ?, ?, ?, 0,
                    ?, ?, ?,
                    0, 'none', 0,
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
            // คำนวณ due date ตามนโยบาย
            if ($totalTerms == 3) {
                // 3 งวด: ใช้นโยบาย 60 วัน (งวด 1=0, งวด 2=+20, งวด 3=+40 วัน)
                $periodDays = [1 => 0, 2 => 20, 3 => 40];
                $daysToAdd = $periodDays[$period] ?? (($period - 1) * 20);
                $dueDate = date('Y-m-d', strtotime($startDate . " +{$daysToAdd} days"));
            } else {
                // งวดอื่นๆ: 1 เดือน/งวด
                if ($period > 1) {
                    $dueDate = date('Y-m-d', strtotime($dueDate . ' +1 month'));
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO installment_payments 
                (contract_id, period_number, due_date, amount, paid_amount, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 0, 'pending', NOW(), NOW())
            ");
            $stmt->execute([$contractId, $period, $dueDate, $perPeriod]);
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
function payInstallment($pdo, $customer_id)
{
    $input = json_decode(file_get_contents('php://input'), true);

    $installmentId = (int) ($input['installment_id'] ?? 0);
    $amount = (float) ($input['amount'] ?? 0);
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

/**
 * Cancel installment contract
 * เจ้าของร้านสามารถยกเลิกแผนผ่อนได้ (เฉพาะที่ยังไม่ครบกำหนด)
 */
function cancelInstallment($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);
    $contractId = $input['id'] ?? $_GET['id'] ?? null;
    $reason = $input['reason'] ?? null;

    if (!$contractId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุ ID แผนผ่อนชำระ']);
        return;
    }

    // Verify ownership (use customer_id, not user_id)
    $stmt = $pdo->prepare("
        SELECT c.* FROM installment_contracts c
        WHERE c.id = ? AND c.customer_id = ?
    ");
    $stmt->execute([$contractId, $user_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบแผนผ่อนชำระนี้']);
        return;
    }

    // Check if already completed or cancelled
    if ($contract['status'] === 'completed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ไม่สามารถยกเลิกได้ เนื่องจากชำระครบแล้ว']);
        return;
    }

    if ($contract['status'] === 'cancelled') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'แผนผ่อนนี้ถูกยกเลิกไปแล้ว']);
        return;
    }

    // Update status to cancelled
    $cancelTime = date('Y-m-d H:i');
    $cancelNote = "\n[ยกเลิกเมื่อ: {$cancelTime}]" . ($reason ? " เหตุผล: {$reason}" : '');
    
    $stmt = $pdo->prepare("
        UPDATE installment_contracts 
        SET status = 'cancelled', 
            admin_notes = CONCAT(COALESCE(admin_notes, ''), ?),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $cancelNote,
        $contractId
    ]);

    // Also cancel pending payments
    $stmt = $pdo->prepare("
        UPDATE installment_payments 
        SET status = 'cancelled'
        WHERE contract_id = ? AND status IN ('pending', 'partial', 'overdue')
    ");
    $stmt->execute([$contractId]);

    echo json_encode([
        'success' => true,
        'message' => 'ยกเลิกแผนผ่อนชำระเรียบร้อยแล้ว'
    ]);
}
