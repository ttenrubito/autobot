<?php
/**
 * Customer Deposits API (มัดจำ)
 * 
 * GET  /api/customer/deposits           - Get all deposits for customer
 * GET  /api/customer/deposits?id=X      - Get specific deposit
 * POST /api/customer/deposits?action=pay - Submit payment for deposit
 * 
 * Database Schema (deposits table):
 * - id, channel_id, external_user_id, user_id, customer_profile_id
 * - deposit_no, product_ref_id, product_name, product_price
 * - deposit_amount, deposit_percentage
 * - status (pending, paid, converted, cancelled, expired)
 * - expires_at, converted_order_id
 * - slip_image_url, verified_by, verified_at
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
$deposit_id = $_GET['id'] ?? null;

try {
    $pdo = getDB();

    // Check if deposits table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'deposits'");
    if ($tableCheck->rowCount() === 0) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'summary' => [
                'total_deposit' => 0,
                'pending_count' => 0,
                'paid_count' => 0
            ],
            'pagination' => [
                'page' => 1,
                'limit' => 20,
                'total' => 0,
                'total_pages' => 0
            ],
            'message' => 'ระบบมัดจำยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ'
        ]);
        exit;
    }

    if ($method === 'GET') {
        if ($action === 'eligible') {
            // ดึงสินค้าที่ลูกค้าเคยซื้อ (สำหรับเลือกมาฝาก)
            getEligibleItems($pdo, $user_id);
        } elseif ($action === 'calculate') {
            // คำนวณดอกเบี้ยก่อนสร้าง
            calculateInterest($pdo);
        } elseif ($action === 'due_soon') {
            // ดึงรายการใกล้ครบกำหนด
            getDueSoonDeposits($pdo, $user_id);
        } elseif ($deposit_id) {
            getDepositDetail($pdo, $deposit_id, $user_id);
        } else {
            getAllDeposits($pdo, $user_id);
        }
    } elseif ($method === 'POST') {
        if ($action === 'pay') {
            submitPayment($pdo, $user_id);
        } elseif ($action === 'create') {
            createDeposit($pdo, $user_id);
        } elseif ($action === 'extend') {
            extendInterest($pdo, $user_id);
        } elseif ($action === 'pickup') {
            markPickedUp($pdo, $user_id);
        } elseif ($action === 'redeem') {
            // ไถ่ถอน (รับคืน + จ่ายเต็ม)
            redeemDeposit($pdo, $user_id);
        } elseif ($action === 'forfeit') {
            // หลุดจำนำ (ยึดของ)
            forfeitDeposit($pdo, $user_id);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("Customer Deposits API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่',
        'error' => $e->getMessage()
    ]);
}

/**
 * Detect schema type for deposits table
 */
function getDepositsSchema($pdo)
{
    static $schema = null;
    if ($schema !== null)
        return $schema;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM deposits LIKE 'item_type'");
        $hasItemType = $stmt->rowCount() > 0;

        if ($hasItemType) {
            // Production schema
            $schema = [
                'type' => 'production',
                'name' => 'item_name',
                'description' => 'item_description',
                'amount' => 'deposit_amount',
                'expires' => 'next_payment_due',
                'notes' => 'notes',
                'status_deposited' => 'deposited',
                'status_picked' => 'picked_up'
            ];
        } else {
            // Localhost schema  
            $schema = [
                'type' => 'localhost',
                'name' => 'product_name',
                'description' => 'COALESCE(NULL, "")',
                'amount' => 'deposit_amount',
                'expires' => 'expires_at',
                'notes' => 'admin_notes',
                'status_deposited' => 'deposited',
                'status_picked' => 'converted'
            ];
        }
    } catch (Exception $e) {
        $schema = ['type' => 'production'];
    }

    return $schema;
}

// =====================================================
// Business Constants for ร้าน ฮ.เฮง เฮง
// =====================================================
define('DEFAULT_LOAN_PERCENTAGE', 70);   // วงเงิน 70% ของราคาประเมิน
define('DEFAULT_INTEREST_RATE', 2.0);    // ดอกเบี้ย 2% ต่อเดือน
define('DEFAULT_TERM_DAYS', 30);         // ชำระทุก 30 วัน
define('MAX_OVERDUE_DAYS', 30);          // เกิน 30 วันถือว่าหลุดจำนำได้

/**
 * Get eligible items for deposit (สินค้าที่ลูกค้าเคยซื้อจากร้าน)
 * 
 * Business Rule: รับฝากเฉพาะสินค้าที่ซื้อจากร้านเท่านั้น
 */
function getEligibleItems($pdo, $user_id)
{
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
    
    if (!$customerId) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'message' => 'กรุณาเลือกลูกค้าก่อน'
        ]);
        return;
    }
    
    try {
        // Get orders from this customer that are completed
        $sql = "
            SELECT 
                o.id as order_id,
                o.order_no,
                o.product_code,
                COALESCE(p.name, o.product_name, 'สินค้า') as product_name,
                o.total_amount as purchase_price,
                o.completed_at as purchase_date,
                -- Check if already deposited
                CASE WHEN d.id IS NOT NULL THEN 1 ELSE 0 END as is_deposited,
                d.deposit_no as existing_deposit_no
            FROM orders o
            LEFT JOIN products p ON o.product_id = p.id
            LEFT JOIN deposits d ON d.order_id = o.id AND d.status IN ('deposited', 'active', 'overdue')
            WHERE o.customer_id = ?
            AND o.status IN ('completed', 'delivered')
            AND o.completed_at IS NOT NULL
            ORDER BY o.completed_at DESC
            LIMIT 50
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$customerId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter out already deposited items and calculate suggested values
        $eligible = [];
        foreach ($items as $item) {
            if ($item['is_deposited']) {
                continue; // Skip already deposited
            }
            
            $purchasePrice = (float)$item['purchase_price'];
            $suggestedAppraisal = $purchasePrice; // Start with purchase price
            $suggestedLoan = round($suggestedAppraisal * (DEFAULT_LOAN_PERCENTAGE / 100));
            $suggestedInterest = round($suggestedLoan * (DEFAULT_INTEREST_RATE / 100));
            
            $eligible[] = [
                'order_id' => (int)$item['order_id'],
                'order_no' => $item['order_no'],
                'product_code' => $item['product_code'],
                'product_name' => $item['product_name'],
                'purchase_price' => $purchasePrice,
                'purchase_date' => $item['purchase_date'],
                'suggested_appraisal' => $suggestedAppraisal,
                'suggested_loan' => $suggestedLoan,
                'suggested_interest' => $suggestedInterest
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $eligible,
            'count' => count($eligible),
            'defaults' => [
                'loan_percentage' => DEFAULT_LOAN_PERCENTAGE,
                'interest_rate' => DEFAULT_INTEREST_RATE,
                'term_days' => DEFAULT_TERM_DAYS
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Get eligible items error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถดึงข้อมูลได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Calculate interest preview before creating deposit
 */
function calculateInterest($pdo)
{
    $appraisedValue = isset($_GET['appraised_value']) ? (float)$_GET['appraised_value'] : 0;
    $loanPercentage = isset($_GET['loan_percentage']) ? (float)$_GET['loan_percentage'] : DEFAULT_LOAN_PERCENTAGE;
    $interestRate = isset($_GET['interest_rate']) ? (float)$_GET['interest_rate'] : DEFAULT_INTEREST_RATE;
    
    if ($appraisedValue <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'กรุณาระบุราคาประเมิน'
        ]);
        return;
    }
    
    $loanAmount = round($appraisedValue * ($loanPercentage / 100));
    $interestAmount = round($loanAmount * ($interestRate / 100));
    $redemptionAmount = $loanAmount + $interestAmount;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'appraised_value' => $appraisedValue,
            'loan_percentage' => $loanPercentage,
            'loan_amount' => $loanAmount,
            'interest_rate' => $interestRate,
            'interest_amount' => $interestAmount,
            'redemption_amount' => $redemptionAmount,
            'next_payment_due' => date('Y-m-d', strtotime('+' . DEFAULT_TERM_DAYS . ' days'))
        ]
    ]);
}

/**
 * Get deposits that are due soon (ใกล้ครบกำหนด)
 */
function getDueSoonDeposits($pdo, $user_id)
{
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
    
    try {
        $sql = "
            SELECT 
                d.*,
                DATEDIFF(d.next_payment_due, CURDATE()) as days_until_due,
                CASE 
                    WHEN DATEDIFF(d.next_payment_due, CURDATE()) < 0 THEN 'overdue'
                    WHEN DATEDIFF(d.next_payment_due, CURDATE()) <= 3 THEN 'critical'
                    ELSE 'warning'
                END as urgency
            FROM deposits d
            WHERE d.shop_owner_id = ?
            AND d.status IN ('deposited', 'active', 'overdue')
            AND d.next_payment_due <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY d.next_payment_due ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $days]);
        $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $deposits,
            'count' => count($deposits)
        ]);
        
    } catch (Exception $e) {
        error_log("Get due soon deposits error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถดึงข้อมูลได้'
        ]);
    }
}

/**
 * Get all deposits for customer
 */
function getAllDeposits($pdo, $user_id)
{
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;

    // ✅ FIX: Use shop_owner_id for admin view (who owns the shop)
    $where = ['d.shop_owner_id = ?'];
    $params = [$user_id];

    if ($status) {
        $where[] = 'd.status = ?';
        $params[] = $status;
    }

    $where_clause = implode(' AND ', $where);

    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM deposits d WHERE $where_clause");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Build SQL with new fields
    $sql = "
        SELECT 
            d.id,
            d.deposit_no,
            d.customer_id,
            d.item_name as product_name,
            d.item_type,
            d.deposit_amount,
            d.appraised_value,
            d.interest_rate,
            d.expected_interest,
            d.total_interest_paid,
            d.next_payment_due,
            d.extension_count,
            d.status,
            d.deposit_date,
            d.warranty_no,
            d.storage_location,
            d.created_at,
            -- Calculated fields
            DATEDIFF(d.next_payment_due, CURDATE()) as days_until_due,
            CASE 
                WHEN d.next_payment_due IS NULL THEN 'normal'
                WHEN DATEDIFF(d.next_payment_due, CURDATE()) < 0 THEN 'overdue'
                WHEN DATEDIFF(d.next_payment_due, CURDATE()) <= 3 THEN 'critical'
                WHEN DATEDIFF(d.next_payment_due, CURDATE()) <= 7 THEN 'warning'
                ELSE 'normal'
            END as urgency,
            -- Customer info
            c.name as customer_name,
            c.phone as customer_phone
        FROM deposits d
        LEFT JOIN customers c ON d.customer_id = c.id
        WHERE $where_clause
        ORDER BY 
            CASE WHEN d.status IN ('deposited', 'active', 'overdue') THEN 0 ELSE 1 END,
            d.next_payment_due ASC,
            d.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add status display
    $statusLabels = [
        'deposited' => 'ฝากแล้ว',
        'active' => 'กำลังฝาก',
        'ready' => 'พร้อมรับ',
        'picked_up' => 'รับคืนแล้ว',
        'redeemed' => 'ไถ่ถอนแล้ว',
        'overdue' => 'เกินกำหนด',
        'forfeited' => 'หลุดจำนำ',
        'expired' => 'หมดอายุ',
        'disposed' => 'ขายออกแล้ว',
        'cancelled' => 'ยกเลิก'
    ];

    foreach ($deposits as &$d) {
        $d['status_display'] = $statusLabels[$d['status']] ?? $d['status'];
        $d['deposit_amount'] = (float) ($d['deposit_amount'] ?? 0);
    }

    echo json_encode([
        'success' => true,
        'data' => $deposits,
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
            COUNT(CASE WHEN status IN ('deposited', 'active') THEN 1 END) as active_count,
            COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count,
            COUNT(CASE WHEN status IN ('picked_up', 'redeemed') THEN 1 END) as redeemed_count,
            COUNT(CASE WHEN status = 'forfeited' THEN 1 END) as forfeited_count,
            SUM(CASE WHEN status IN ('deposited', 'active', 'overdue') THEN deposit_amount ELSE 0 END) as total_loan_amount,
            SUM(CASE WHEN status IN ('deposited', 'active', 'overdue') THEN expected_interest ELSE 0 END) as total_expected_interest,
            SUM(total_interest_paid) as total_interest_collected
        FROM deposits
        WHERE shop_owner_id = ?
    ");
    $stmt->execute([$user_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'active_count' => (int) ($summary['active_count'] ?? 0),
        'overdue_count' => (int) ($summary['overdue_count'] ?? 0),
        'redeemed_count' => (int) ($summary['redeemed_count'] ?? 0),
        'forfeited_count' => (int) ($summary['forfeited_count'] ?? 0),
        'total_loan_amount' => (float) ($summary['total_loan_amount'] ?? 0),
        'total_expected_interest' => (float) ($summary['total_expected_interest'] ?? 0),
        'total_interest_collected' => (float) ($summary['total_interest_collected'] ?? 0)
    ];
}

/**
 * Get specific deposit detail
 */
function getDepositDetail($pdo, $deposit_id, $user_id)
{
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            DATEDIFF(d.expected_pickup_date, CURDATE()) as days_until_expiry,
            CASE WHEN d.expected_pickup_date < CURDATE() AND d.status = 'deposited' THEN 1 ELSE 0 END as is_expired
        FROM deposits d
        WHERE d.id = ? AND d.customer_id = ?
    ");
    $stmt->execute([$deposit_id, $user_id]);
    $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$deposit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการฝาก']);
        return;
    }

    $statusLabels = [
        'deposited' => 'ฝากแล้ว',
        'ready' => 'พร้อมรับ',
        'picked_up' => 'รับคืนแล้ว',
        'expired' => 'หมดอายุ',
        'disposed' => 'ถูกกำจัด',
        'cancelled' => 'ยกเลิก'
    ];

    $deposit['status_display'] = $statusLabels[$deposit['status']] ?? $deposit['status'];
    $deposit['deposit_amount'] = (float) ($deposit['deposit_amount'] ?? 0);
    $deposit['total_storage_fee'] = (float) ($deposit['total_storage_fee'] ?? 0);
    $deposit['is_expired'] = (bool) $deposit['is_expired'];

    // Get bank accounts for payment
    $bankStmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC");
    $bankStmt->execute();
    $bankAccounts = $bankStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $deposit,
        'bank_accounts' => $bankAccounts
    ]);
}

/**
 * Submit payment for deposit
 */
function submitPayment($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);

    $deposit_id = $input['deposit_id'] ?? null;
    $slip_image_url = $input['slip_image_url'] ?? null;
    $amount = isset($input['amount']) ? (float) $input['amount'] : null;

    if (!$deposit_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุรายการมัดจำ']);
        return;
    }

    if (!$slip_image_url) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาแนบสลิปการโอน']);
        return;
    }

    // Get deposit
    $stmt = $pdo->prepare("SELECT * FROM deposits WHERE id = ? AND customer_id = ?");
    $stmt->execute([$deposit_id, $user_id]);
    $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$deposit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการมัดจำ']);
        return;
    }

    if ($deposit['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'สถานะมัดจำไม่ถูกต้อง']);
        return;
    }

    // Check if expired
    if (strtotime($deposit['expires_at']) < time()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'รายการมัดจำหมดอายุแล้ว']);
        return;
    }

    // Update deposit with slip
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE deposits 
            SET slip_image_url = ?, 
                status = 'pending',
                note = CONCAT(COALESCE(note, ''), '\nส่งสลิปเมื่อ: ', NOW()),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$slip_image_url, $deposit_id]);

        // Create case for admin verification
        $caseStmt = $pdo->prepare("
            INSERT INTO cases (
                channel_id, external_user_id, user_id, customer_profile_id,
                case_type, status, subject, description, priority
            ) VALUES (
                ?, ?, ?, ?,
                'deposit_payment', 'open', ?, ?, 'high'
            )
        ");
        $caseStmt->execute([
            $deposit['channel_id'],
            $deposit['external_user_id'],
            $user_id,
            $deposit['customer_profile_id'],
            "ตรวจสอบสลิปมัดจำ: {$deposit['deposit_no']}",
            "ลูกค้าส่งสลิปชำระมัดจำ\nรหัส: {$deposit['deposit_no']}\nยอด: {$deposit['deposit_amount']} บาท"
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'ส่งสลิปเรียบร้อยแล้ว รอเจ้าหน้าที่ตรวจสอบ',
            'data' => [
                'deposit_id' => $deposit_id,
                'deposit_no' => $deposit['deposit_no'],
                'amount' => (float) $deposit['deposit_amount']
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Create a new deposit (รับฝากสินค้า)
 * 
 * Business Rules (ร้าน ฮ. เฮง เฮง):
 * - รับฝากเฉพาะลูกค้าที่ซื้อสินค้าจากร้าน
 * - ต้องมีใบรับประกันตัวจริง
 * - วงเงิน 65-70% ของราคาประเมิน
 * - ดอกเบี้ย 2%/เดือน
 * - ต้องต่อดอกทุก 30 วัน
 */
function createDeposit($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    $required = ['customer_id', 'item_type', 'item_name', 'appraised_value', 'deposit_amount'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'กรุณากรอกข้อมูลให้ครบ: ' . implode(', ', $missing)
        ]);
        return;
    }

    try {
        // Generate deposit number with date prefix
        $today = date('Ymd');
        $stmt = $pdo->query("SELECT COUNT(*) + 1 as next_num FROM deposits WHERE deposit_no LIKE 'DEP{$today}%'");
        $nextNum = $stmt->fetch(PDO::FETCH_ASSOC)['next_num'];
        $depositNo = 'DEP' . $today . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        // Set dates
        $depositDate = !empty($input['deposit_date']) ? $input['deposit_date'] : date('Y-m-d');
        $nextPaymentDue = date('Y-m-d', strtotime($depositDate . ' +' . DEFAULT_TERM_DAYS . ' days'));
        
        // Calculate interest
        $appraisedValue = (float)$input['appraised_value'];
        $loanPercentage = (float)($input['loan_percentage'] ?? DEFAULT_LOAN_PERCENTAGE);
        $depositAmount = (float)$input['deposit_amount'];
        $interestRate = (float)($input['interest_rate'] ?? DEFAULT_INTEREST_RATE);
        $expectedInterest = round($depositAmount * ($interestRate / 100));

        // Prepare insert with new columns
        $sql = "
            INSERT INTO deposits (
                deposit_no,
                customer_id,
                order_id,
                shop_owner_id,
                tenant_id,
                item_type,
                item_name,
                item_description,
                warranty_no,
                appraised_value,
                loan_percentage,
                deposit_amount,
                interest_rate,
                expected_interest,
                deposit_date,
                next_payment_due,
                storage_location,
                notes,
                status,
                created_at
            ) VALUES (
                :deposit_no,
                :customer_id,
                :order_id,
                :shop_owner_id,
                :tenant_id,
                :item_type,
                :item_name,
                :item_description,
                :warranty_no,
                :appraised_value,
                :loan_percentage,
                :deposit_amount,
                :interest_rate,
                :expected_interest,
                :deposit_date,
                :next_payment_due,
                :storage_location,
                :notes,
                'deposited',
                NOW()
            )
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':deposit_no' => $depositNo,
            ':customer_id' => (int)$input['customer_id'],
            ':order_id' => !empty($input['order_id']) ? (int)$input['order_id'] : null,
            ':shop_owner_id' => $user_id,
            ':tenant_id' => 'default',
            ':item_type' => $input['item_type'],
            ':item_name' => $input['item_name'],
            ':item_description' => $input['item_description'] ?? null,
            ':warranty_no' => $input['warranty_no'] ?? null,
            ':appraised_value' => $appraisedValue,
            ':loan_percentage' => $loanPercentage,
            ':deposit_amount' => $depositAmount,
            ':interest_rate' => $interestRate,
            ':expected_interest' => $expectedInterest,
            ':deposit_date' => $depositDate,
            ':next_payment_due' => $nextPaymentDue,
            ':storage_location' => $input['storage_location'] ?? null,
            ':notes' => $input['notes'] ?? null
        ]);

        $depositId = $pdo->lastInsertId();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกรายการฝากสำเร็จ',
            'data' => [
                'id' => $depositId,
                'deposit_no' => $depositNo,
                'deposit_date' => $depositDate,
                'next_payment_due' => $nextPaymentDue,
                'appraised_value' => $appraisedValue,
                'deposit_amount' => $depositAmount,
                'interest_rate' => $interestRate,
                'expected_interest' => $expectedInterest
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Create deposit error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถบันทึกรายการได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Extend deposit interest (ต่อดอกฝาก)
 * 
 * Business Rules:
 * - ลูกค้าต้องชำระดอกเบี้ย 2% ของวงเงินฝาก
 * - ระบบจะขยาย next_payment_due ออกไป 30 วัน
 * - บันทึกประวัติใน deposit_payments และเพิ่ม extension_count
 * 
 * @param PDO $pdo
 * @param int $user_id
 */
function extendInterest($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (empty($input['deposit_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'กรุณาระบุรหัสฝาก'
        ]);
        return;
    }

    try {
        $pdo->beginTransaction();
        
        // Get deposit
        $stmt = $pdo->prepare("
            SELECT * FROM deposits 
            WHERE id = ? AND shop_owner_id = ?
            FOR UPDATE
        ");
        $stmt->execute([(int)$input['deposit_id'], $user_id]);
        $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$deposit) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'ไม่พบรายการฝาก'
            ]);
            return;
        }

        if (!in_array($deposit['status'], ['deposited', 'active', 'overdue'])) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ไม่สามารถต่อดอกได้ - สถานะ: ' . $deposit['status']
            ]);
            return;
        }

        // Calculate interest amount
        $depositAmount = (float)$deposit['deposit_amount'];
        $interestRate = (float)($deposit['interest_rate'] ?? DEFAULT_INTEREST_RATE);
        $interestAmount = round($depositAmount * ($interestRate / 100));
        
        // Calculate new due date
        $currentDueDate = $deposit['next_payment_due'] ?? date('Y-m-d');
        $baseDate = (strtotime($currentDueDate) < time()) ? date('Y-m-d') : $currentDueDate;
        $newDueDate = date('Y-m-d', strtotime($baseDate . ' +' . DEFAULT_TERM_DAYS . ' days'));

        // Create deposit_payment record
        $paymentNo = 'DPI' . date('Ymd') . rand(1000, 9999);
        
        $paymentStmt = $pdo->prepare("
            INSERT INTO deposit_payments (
                deposit_id, payment_no, payment_date, 
                interest_amount, principal_amount, total_amount,
                payment_method, is_redemption, created_at
            ) VALUES (?, ?, CURDATE(), ?, 0, ?, 'transfer', 0, NOW())
        ");
        $paymentStmt->execute([
            (int)$input['deposit_id'],
            $paymentNo,
            $interestAmount,
            $interestAmount
        ]);

        // Update deposit
        $currentExtension = (int)($deposit['extension_count'] ?? 0);
        $currentTotalPaid = (float)($deposit['total_interest_paid'] ?? 0);

        $updateStmt = $pdo->prepare("
            UPDATE deposits SET 
                next_payment_due = ?,
                last_payment_date = CURDATE(),
                extension_count = ?,
                total_interest_paid = ?,
                expected_interest = ?,
                status = 'active',
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([
            $newDueDate,
            $currentExtension + 1,
            $currentTotalPaid + $interestAmount,
            $interestAmount, // Reset expected for next period
            (int)$input['deposit_id']
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'ต่อดอกเรียบร้อยแล้ว',
            'data' => [
                'deposit_id' => (int)$input['deposit_id'],
                'deposit_no' => $deposit['deposit_no'],
                'interest_paid' => $interestAmount,
                'previous_due_date' => $currentDueDate,
                'new_due_date' => $newDueDate,
                'extension_count' => $currentExtension + 1,
                'total_interest_paid' => $currentTotalPaid + $interestAmount
            ]
        ]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Extend interest error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถต่อดอกได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Mark deposit as picked up (รับคืนสินค้า)
 * 
 * @param PDO $pdo
 * @param int $user_id
 */
function markPickedUp($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['deposit_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'กรุณาระบุรหัสฝาก'
        ]);
        return;
    }

    try {
        // Get deposit
        $stmt = $pdo->prepare("
            SELECT * FROM deposits 
            WHERE id = ? AND (customer_id = ? OR shop_owner_id = ?)
        ");
        $stmt->execute([(int)$input['deposit_id'], $user_id, $user_id]);
        $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$deposit) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'ไม่พบรายการฝาก'
            ]);
            return;
        }

        if ($deposit['status'] !== 'deposited') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ไม่สามารถเปลี่ยนสถานะได้ - สถานะปัจจุบัน: ' . $deposit['status']
            ]);
            return;
        }

        // Update status to picked_up
        $updateStmt = $pdo->prepare("
            UPDATE deposits SET 
                status = 'picked_up',
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([(int)$input['deposit_id']]);

        echo json_encode([
            'success' => true,
            'message' => 'บันทึกการรับคืนสินค้าเรียบร้อย',
            'data' => [
                'deposit_id' => (int)$input['deposit_id'],
                'deposit_no' => $deposit['deposit_no'],
                'status' => 'picked_up',
                'picked_up_at' => date('Y-m-d H:i:s')
            ]
        ]);

    } catch (PDOException $e) {
        error_log("Mark picked up error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถบันทึกได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Redeem deposit (ไถ่ถอน - จ่ายเงินต้น + ดอกเบี้ยแล้วรับของคืน)
 * 
 * Business Rule:
 * - ลูกค้าต้องจ่ายวงเงินฝาก + ดอกเบี้ยค้างทั้งหมด
 * - เปลี่ยนสถานะเป็น redeemed
 */
function redeemDeposit($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['deposit_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุรหัสฝาก']);
        return;
    }

    try {
        $pdo->beginTransaction();
        
        // Get deposit
        $stmt = $pdo->prepare("
            SELECT * FROM deposits 
            WHERE id = ? AND shop_owner_id = ?
            FOR UPDATE
        ");
        $stmt->execute([(int)$input['deposit_id'], $user_id]);
        $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$deposit) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'ไม่พบรายการฝาก']);
            return;
        }

        if (!in_array($deposit['status'], ['deposited', 'active', 'overdue'])) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถไถ่ถอนได้ - สถานะ: ' . $deposit['status']]);
            return;
        }

        $loanAmount = (float)$deposit['deposit_amount'];
        $interestRate = (float)($deposit['interest_rate'] ?? DEFAULT_INTEREST_RATE);
        $expectedInterest = (float)($deposit['expected_interest'] ?? round($loanAmount * ($interestRate / 100)));
        $redemptionAmount = $loanAmount + $expectedInterest;

        // Create deposit_payment record for redemption
        $paymentNo = 'DPR' . date('Ymd') . rand(1000, 9999);
        
        $paymentStmt = $pdo->prepare("
            INSERT INTO deposit_payments (
                deposit_id, payment_no, payment_date, 
                interest_amount, principal_amount, total_amount,
                payment_method, is_redemption, created_at
            ) VALUES (?, ?, CURDATE(), ?, ?, ?, 'transfer', 1, NOW())
        ");
        $paymentStmt->execute([
            (int)$input['deposit_id'],
            $paymentNo,
            $expectedInterest,
            $loanAmount,
            $redemptionAmount
        ]);

        // Update deposit status
        $updateStmt = $pdo->prepare("
            UPDATE deposits SET 
                status = 'redeemed',
                redeemed_at = NOW(),
                actual_pickup_date = CURDATE(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([(int)$input['deposit_id']]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'ไถ่ถอนสำเร็จ ลูกค้าสามารถรับสินค้าคืนได้',
            'data' => [
                'deposit_id' => (int)$input['deposit_id'],
                'deposit_no' => $deposit['deposit_no'],
                'loan_amount' => $loanAmount,
                'interest_paid' => $expectedInterest,
                'total_paid' => $redemptionAmount,
                'status' => 'redeemed',
                'redeemed_at' => date('Y-m-d H:i:s')
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Redeem deposit error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'ไม่สามารถไถ่ถอนได้: ' . $e->getMessage()]);
    }
}

/**
 * Forfeit deposit (หลุดจำนำ - ยึดของเนื่องจากผิดนัดชำระ)
 * 
 * Business Rule:
 * - ใช้เมื่อลูกค้าไม่มาชำระดอกเบี้ยตามกำหนด
 * - ร้านมีสิทธิ์ยึดสินค้าทันที
 */
function forfeitDeposit($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['deposit_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุรหัสฝาก']);
        return;
    }

    try {
        // Get deposit
        $stmt = $pdo->prepare("
            SELECT * FROM deposits 
            WHERE id = ? AND shop_owner_id = ?
        ");
        $stmt->execute([(int)$input['deposit_id'], $user_id]);
        $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$deposit) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'ไม่พบรายการฝาก']);
            return;
        }

        if (!in_array($deposit['status'], ['deposited', 'active', 'overdue'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถยึดของได้ - สถานะ: ' . $deposit['status']]);
            return;
        }

        $reason = $input['reason'] ?? 'ผิดนัดชำระดอกเบี้ยเกินกำหนด';

        // Update deposit status to forfeited
        $updateStmt = $pdo->prepare("
            UPDATE deposits SET 
                status = 'forfeited',
                forfeited_at = NOW(),
                forfeit_reason = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$reason, (int)$input['deposit_id']]);

        echo json_encode([
            'success' => true,
            'message' => 'บันทึกหลุดจำนำเรียบร้อย - สินค้าตกเป็นของร้าน',
            'data' => [
                'deposit_id' => (int)$input['deposit_id'],
                'deposit_no' => $deposit['deposit_no'],
                'item_name' => $deposit['item_name'],
                'loan_amount' => (float)$deposit['deposit_amount'],
                'status' => 'forfeited',
                'forfeited_at' => date('Y-m-d H:i:s'),
                'reason' => $reason
            ]
        ]);

    } catch (Exception $e) {
        error_log("Forfeit deposit error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'ไม่สามารถบันทึกได้: ' . $e->getMessage()]);
    }
}
