<?php
/**
 * Customer Repairs API (งานซ่อม)
 * 
 * GET  /api/customer/repairs            - Get all repairs for customer
 * GET  /api/customer/repairs?id=X       - Get specific repair detail
 * POST /api/customer/repairs?action=approve - Approve quote
 * POST /api/customer/repairs?action=pay  - Submit payment
 * 
 * Database Schema (repairs table):
 * - id, channel_id, external_user_id, user_id, customer_profile_id
 * - repair_no, item_description, brand, model, serial_number
 * - issue_description, received_condition
 * - status (pending, received, diagnosing, quoted, approved, repairing, completed, cancelled)
 * - quoted_amount, quoted_at, quote_valid_until, quote_note
 * - approved_at, completed_at, paid_amount, paid_at
 * - warranty_months, warranty_until
 * - delivery_method, tracking_number
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
$repair_id = $_GET['id'] ?? null;

try {
    $pdo = getDB();
    
    // Check if repairs table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'repairs'");
    if ($tableCheck->rowCount() === 0) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'summary' => [
                'pending_count' => 0,
                'in_progress_count' => 0,
                'completed_count' => 0
            ],
            'pagination' => [
                'page' => 1,
                'limit' => 20,
                'total' => 0,
                'total_pages' => 0
            ],
            'message' => 'ระบบงานซ่อมยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ'
        ]);
        exit;
    }
    
    if ($method === 'GET') {
        if ($repair_id) {
            getRepairDetail($pdo, $repair_id, $user_id);
        } else {
            getAllRepairs($pdo, $user_id);
        }
    } elseif ($method === 'POST') {
        if ($action === 'approve') {
            approveQuote($pdo, $user_id);
        } elseif ($action === 'pay') {
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
    error_log("Customer Repairs API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่',
        'error' => $e->getMessage()
    ]);
}

/**
 * Detect schema type for repairs table
 */
function getRepairsSchema($pdo) {
    static $schema = null;
    if ($schema !== null) return $schema;
    
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM repairs LIKE 'item_type'");
        $hasItemType = $stmt->rowCount() > 0;
        
        if ($hasItemType) {
            // Production schema
            $schema = [
                'type' => 'production',
                'name' => 'item_name',
                'brand' => 'item_brand',
                'model' => 'item_model',
                'serial' => 'item_serial',
                'issue' => 'problem_description',
                'quoted' => 'estimated_cost',
                'final' => 'final_cost',
                'completed' => 'actual_completion_date',
                'picked' => 'picked_up_date'
            ];
        } else {
            // Localhost schema
            $schema = [
                'type' => 'localhost',
                'name' => 'product_name',
                'brand' => 'product_brand',
                'model' => 'product_model',
                'serial' => 'product_serial',
                'issue' => 'issue_description',
                'quoted' => 'estimated_cost',
                'final' => 'final_cost',
                'completed' => 'completed_at',
                'picked' => 'delivered_at'
            ];
        }
    } catch (Exception $e) {
        $schema = ['type' => 'production'];
    }
    
    return $schema;
}

/**
 * Get all repairs for customer
 */
function getAllRepairs($pdo, $user_id) {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    
    $where = ['r.customer_id = ?'];
    $params = [$user_id];
    
    if ($status) {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM repairs r WHERE $where_clause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Get schema
    $s = getRepairsSchema($pdo);
    
    // Build dynamic SQL
    $sql = "
        SELECT 
            r.id,
            r.repair_no,
            r.{$s['name']} as item_name,
            r.{$s['brand']} as brand,
            r.{$s['model']} as model,
            r.{$s['issue']} as issue_description,
            r.status,
            r.{$s['quoted']} as quoted_amount,
            r.{$s['final']} as final_cost,
            r.{$s['completed']} as completed_at,
            r.created_at,
            -- Progress calculation
            CASE r.status
                WHEN 'received' THEN 10
                WHEN 'pending_assessment' THEN 20
                WHEN 'diagnosing' THEN 30
                WHEN 'quoted' THEN 50
                WHEN 'customer_approved' THEN 60
                WHEN 'approved' THEN 60
                WHEN 'in_progress' THEN 80
                WHEN 'completed' THEN 90
                WHEN 'ready' THEN 95
                WHEN 'ready_for_pickup' THEN 95
                WHEN 'picked_up' THEN 100
                WHEN 'delivered' THEN 100
                ELSE 0
            END as progress_percent
        FROM repairs r
        WHERE $where_clause
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add status display (support both schemas)
    $statusLabels = [
        'received' => 'รับของแล้ว',
        'pending_assessment' => 'รอประเมิน',
        'diagnosing' => 'กำลังตรวจสอบ',
        'quoted' => 'รอลูกค้าอนุมัติ',
        'customer_approved' => 'อนุมัติแล้ว',
        'approved' => 'อนุมัติแล้ว',
        'in_progress' => 'กำลังซ่อม',
        'completed' => 'ซ่อมเสร็จ',
        'ready' => 'พร้อมรับ',
        'ready_for_pickup' => 'พร้อมรับ',
        'picked_up' => 'รับคืนแล้ว',
        'delivered' => 'รับคืนแล้ว',
        'cancelled' => 'ยกเลิก',
        'unclaimed' => 'ไม่มารับ'
    ];
    
    foreach ($repairs as &$r) {
        $r['status_display'] = $statusLabels[$r['status']] ?? $r['status'];
        $r['quoted_amount'] = $r['quoted_amount'] ? (float)$r['quoted_amount'] : null;
        $r['final_cost'] = isset($r['final_cost']) ? (float)$r['final_cost'] : null;
        $r['progress_percent'] = (int)$r['progress_percent'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $repairs,
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
            COUNT(CASE WHEN status NOT IN ('picked_up', 'cancelled', 'unclaimed') THEN 1 END) as active_count,
            COUNT(CASE WHEN status = 'quoted' THEN 1 END) as awaiting_approval_count,
            COUNT(CASE WHEN status = 'picked_up' THEN 1 END) as completed_count,
            SUM(CASE WHEN status = 'picked_up' THEN final_cost ELSE 0 END) as total_paid
        FROM repairs
        WHERE customer_id = ?
    ");
    $stmt->execute([$user_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'active_count' => (int)($summary['active_count'] ?? 0),
        'awaiting_approval_count' => (int)($summary['awaiting_approval_count'] ?? 0),
        'completed_count' => (int)($summary['completed_count'] ?? 0),
        'total_paid' => (float)($summary['total_paid'] ?? 0)
    ];
}

/**
 * Get specific repair detail
 */
function getRepairDetail($pdo, $repair_id, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM repairs WHERE id = ? AND customer_id = ?");
    $stmt->execute([$repair_id, $user_id]);
    $repair = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$repair) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการซ่อม']);
        return;
    }
    
    $statusLabels = [
        'pending' => 'รอรับของ',
        'received' => 'รับของแล้ว',
        'diagnosing' => 'กำลังตรวจสอบ',
        'quoted' => 'รอลูกค้าอนุมัติ',
        'approved' => 'อนุมัติแล้ว',
        'repairing' => 'กำลังซ่อม',
        'completed' => 'ซ่อมเสร็จ',
        'cancelled' => 'ยกเลิก'
    ];
    
    $repair['status_display'] = $statusLabels[$repair['status']] ?? $repair['status'];
    $repair['quoted_amount'] = $repair['quoted_amount'] ? (float)$repair['quoted_amount'] : null;
    $repair['paid_amount'] = $repair['paid_amount'] ? (float)$repair['paid_amount'] : null;
    
    // Progress calculation
    $progressMap = [
        'pending' => 10,
        'received' => 20,
        'diagnosing' => 40,
        'quoted' => 50,
        'approved' => 60,
        'repairing' => 80,
        'completed' => 100,
        'cancelled' => 0
    ];
    $repair['progress_percent'] = $progressMap[$repair['status']] ?? 0;
    
    // Action flags
    $repair['can_approve'] = $repair['status'] === 'quoted' && 
                             $repair['quote_valid_until'] && 
                             strtotime($repair['quote_valid_until']) >= strtotime('today');
    $repair['needs_payment'] = $repair['status'] === 'completed' && !$repair['paid_at'];
    
    // Timeline
    $timeline = [];
    $timeline[] = ['event' => 'สร้างรายการ', 'date' => $repair['created_at'], 'completed' => true];
    
    if ($repair['status'] !== 'pending') {
        $timeline[] = ['event' => 'รับของแล้ว', 'date' => null, 'completed' => true];
    }
    if (in_array($repair['status'], ['diagnosing', 'quoted', 'approved', 'repairing', 'completed'])) {
        $timeline[] = ['event' => 'ตรวจสอบอาการ', 'date' => null, 'completed' => true];
    }
    if ($repair['quoted_at']) {
        $timeline[] = ['event' => 'ส่งใบเสนอราคา', 'date' => $repair['quoted_at'], 'completed' => true];
    }
    if ($repair['approved_at']) {
        $timeline[] = ['event' => 'ลูกค้าอนุมัติ', 'date' => $repair['approved_at'], 'completed' => true];
    }
    if ($repair['completed_at']) {
        $timeline[] = ['event' => 'ซ่อมเสร็จ', 'date' => $repair['completed_at'], 'completed' => true];
    }
    if ($repair['paid_at']) {
        $timeline[] = ['event' => 'ชำระเงินแล้ว', 'date' => $repair['paid_at'], 'completed' => true];
    }
    
    // Get bank accounts
    $bankStmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC");
    $bankStmt->execute();
    $bankAccounts = $bankStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $repair,
        'timeline' => $timeline,
        'bank_accounts' => $bankAccounts
    ]);
}

/**
 * Approve quote
 */
function approveQuote($pdo, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $repair_id = $input['repair_id'] ?? null;
    
    if (!$repair_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุรายการซ่อม']);
        return;
    }
    
    // Get repair
    $stmt = $pdo->prepare("SELECT * FROM repairs WHERE id = ? AND user_id = ?");
    $stmt->execute([$repair_id, $user_id]);
    $repair = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$repair) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการซ่อม']);
        return;
    }
    
    if ($repair['status'] !== 'quoted') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'สถานะไม่ถูกต้อง']);
        return;
    }
    
    // Check quote validity
    if ($repair['quote_valid_until'] && strtotime($repair['quote_valid_until']) < strtotime('today')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ใบเสนอราคาหมดอายุแล้ว กรุณาติดต่อเจ้าหน้าที่']);
        return;
    }
    
    // Update status
    $updateStmt = $pdo->prepare("
        UPDATE repairs 
        SET status = 'approved', approved_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$repair_id]);
    
    // Create notification case
    $caseStmt = $pdo->prepare("
        INSERT INTO cases (
            channel_id, external_user_id, user_id, customer_profile_id,
            case_type, status, subject, description, priority
        ) VALUES (
            ?, ?, ?, ?,
            'repair_approved', 'open', ?, ?, 'medium'
        )
    ");
    $caseStmt->execute([
        $repair['channel_id'],
        $repair['external_user_id'],
        $user_id,
        $repair['customer_profile_id'],
        "ลูกค้าอนุมัติงานซ่อม: {$repair['repair_no']}",
        "ลูกค้าอนุมัติใบเสนอราคาแล้ว สามารถเริ่มดำเนินการซ่อมได้"
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'อนุมัติใบเสนอราคาเรียบร้อยแล้ว',
        'data' => [
            'repair_id' => $repair_id,
            'repair_no' => $repair['repair_no'],
            'quoted_amount' => (float)$repair['quoted_amount']
        ]
    ]);
}

/**
 * Submit payment
 */
function submitPayment($pdo, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $repair_id = $input['repair_id'] ?? null;
    $slip_image_url = $input['slip_image_url'] ?? null;
    $amount = isset($input['amount']) ? (float)$input['amount'] : null;
    
    if (!$repair_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุรายการซ่อม']);
        return;
    }
    
    if (!$slip_image_url) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาแนบสลิปการโอน']);
        return;
    }
    
    // Get repair
    $stmt = $pdo->prepare("SELECT * FROM repairs WHERE id = ? AND customer_id = ?");
    $stmt->execute([$repair_id, $user_id]);
    $repair = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$repair) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการซ่อม']);
        return;
    }
    
    if ($repair['status'] !== 'completed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'งานซ่อมยังไม่เสร็จสมบูรณ์']);
        return;
    }
    
    if ($repair['paid_at']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ชำระเงินไปแล้ว']);
        return;
    }
    
    $pdo->beginTransaction();
    try {
        // Update repair with payment info
        $updateStmt = $pdo->prepare("
            UPDATE repairs 
            SET slip_image_url = ?,
                note = CONCAT(COALESCE(note, ''), '\nส่งสลิปชำระเงินเมื่อ: ', NOW()),
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$slip_image_url, $repair_id]);
        
        // Create case for admin verification
        $caseStmt = $pdo->prepare("
            INSERT INTO cases (
                channel_id, external_user_id, user_id, customer_profile_id,
                case_type, status, subject, description, priority
            ) VALUES (
                ?, ?, ?, ?,
                'repair_payment', 'open', ?, ?, 'high'
            )
        ");
        $caseStmt->execute([
            $repair['channel_id'],
            $repair['external_user_id'],
            $user_id,
            $repair['customer_profile_id'],
            "ตรวจสอบสลิปค่าซ่อม: {$repair['repair_no']}",
            "ลูกค้าส่งสลิปชำระค่าซ่อม\nรหัส: {$repair['repair_no']}\nยอด: {$repair['quoted_amount']} บาท"
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'ส่งสลิปเรียบร้อยแล้ว รอเจ้าหน้าที่ตรวจสอบ',
            'data' => [
                'repair_id' => $repair_id,
                'repair_no' => $repair['repair_no'],
                'amount' => (float)$repair['quoted_amount']
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
