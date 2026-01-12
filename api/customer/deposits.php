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
        if ($deposit_id) {
            getDepositDetail($pdo, $deposit_id, $user_id);
        } else {
            getAllDeposits($pdo, $user_id);
        }
    } elseif ($method === 'POST') {
        if ($action === 'pay') {
            submitPayment($pdo, $user_id);
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
function getDepositsSchema($pdo) {
    static $schema = null;
    if ($schema !== null) return $schema;
    
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
                'expires' => 'expected_pickup_date',
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

/**
 * Get all deposits for customer
 */
function getAllDeposits($pdo, $user_id) {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    
    $where = ['d.customer_id = ?'];
    $params = [$user_id];
    
    if ($status) {
        $where[] = 'd.status = ?';
        $params[] = $status;
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM deposits d WHERE $where_clause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Get schema
    $s = getDepositsSchema($pdo);
    
    // Build dynamic SQL
    $sql = "
        SELECT 
            d.id,
            d.deposit_no,
            d.{$s['name']} as product_name,
            d.deposit_amount,
            d.status,
            d.{$s['expires']} as expires_at,
            d.{$s['notes']} as note,
            d.created_at,
            -- Days until expiry
            DATEDIFF(d.{$s['expires']}, CURDATE()) as days_until_expiry
        FROM deposits d
        WHERE $where_clause
        ORDER BY d.created_at DESC
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
        'ready' => 'พร้อมรับ',
        'picked_up' => 'รับคืนแล้ว',
        'pending_payment' => 'รอชำระ',
        'converted' => 'แปลงเป็นออเดอร์แล้ว',
        'expired' => 'หมดอายุ',
        'disposed' => 'ถูกกำจัด',
        'cancelled' => 'ยกเลิก',
        'refunded' => 'คืนเงินแล้ว'
    ];
    
    foreach ($deposits as &$d) {
        $d['status_display'] = $statusLabels[$d['status']] ?? $d['status'];
        $d['deposit_amount'] = (float)($d['deposit_amount'] ?? 0);
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
function getSummary($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'deposited' THEN 1 END) as deposited_count,
            COUNT(CASE WHEN status = 'picked_up' THEN 1 END) as picked_up_count,
            SUM(CASE WHEN status = 'deposited' THEN deposit_amount ELSE 0 END) as deposited_amount,
            SUM(total_storage_fee) as total_storage_fee
        FROM deposits
        WHERE customer_id = ?
    ");
    $stmt->execute([$user_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'deposited_count' => (int)($summary['deposited_count'] ?? 0),
        'picked_up_count' => (int)($summary['picked_up_count'] ?? 0),
        'deposited_amount' => (float)($summary['deposited_amount'] ?? 0),
        'total_storage_fee' => (float)($summary['total_storage_fee'] ?? 0)
    ];
}

/**
 * Get specific deposit detail
 */
function getDepositDetail($pdo, $deposit_id, $user_id) {
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
    $deposit['deposit_amount'] = (float)($deposit['deposit_amount'] ?? 0);
    $deposit['total_storage_fee'] = (float)($deposit['total_storage_fee'] ?? 0);
    $deposit['is_expired'] = (bool)$deposit['is_expired'];
    
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
function submitPayment($pdo, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $deposit_id = $input['deposit_id'] ?? null;
    $slip_image_url = $input['slip_image_url'] ?? null;
    $amount = isset($input['amount']) ? (float)$input['amount'] : null;
    
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
                'amount' => (float)$deposit['deposit_amount']
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
