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
        if ($action === 'search') {
            searchRepairs($pdo, $user_id);
        } elseif ($repair_id) {
            getRepairDetail($pdo, $repair_id, $user_id);
        } else {
            getAllRepairs($pdo, $user_id);
        }
    } elseif ($method === 'POST') {
        if ($action === 'approve') {
            approveQuote($pdo, $user_id);
        } elseif ($action === 'pay') {
            submitPayment($pdo, $user_id);
        } elseif ($action === 'create') {
            createRepair($pdo, $user_id);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action. Use action=create, action=approve, or action=pay']);
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
function getRepairsSchema($pdo)
{
    static $schema = null;
    if ($schema !== null)
        return $schema;

    try {
        // Get all columns
        $stmt = $pdo->query("SHOW COLUMNS FROM repairs");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $hasItemType = in_array('item_type', $columns);
        $hasCategory = in_array('category', $columns);
        $hasEstimatedCost = in_array('estimated_cost', $columns);
        $hasQuotedAmount = in_array('quoted_amount', $columns);

        $schema = [
            'type' => $hasItemType ? 'production' : 'localhost',
            'category_col' => $hasItemType ? 'item_type' : ($hasCategory ? 'category' : 'item_type'),
            'cost_col' => $hasEstimatedCost ? 'estimated_cost' : ($hasQuotedAmount ? 'quoted_amount' : 'estimated_cost'),
            'has_item_name' => in_array('item_name', $columns),
            'has_brand' => in_array('brand', $columns) || in_array('item_brand', $columns),
            'has_model' => in_array('model', $columns) || in_array('item_model', $columns),
            'has_serial' => in_array('serial_number', $columns) || in_array('item_serial', $columns),
            'has_condition' => in_array('received_condition', $columns) || in_array('item_condition', $columns),
            // Field mappings
            'name' => $hasItemType ? 'item_name' : 'product_name',
            'brand' => in_array('brand', $columns) ? 'brand' : 'item_brand',
            'model' => in_array('model', $columns) ? 'model' : 'item_model',
            'serial' => in_array('serial_number', $columns) ? 'serial_number' : 'item_serial',
            'issue' => in_array('issue_description', $columns) ? 'issue_description' : 'problem_description',
            'quoted' => $hasEstimatedCost ? 'estimated_cost' : 'quoted_amount',
            'final' => 'final_cost',
            'completed' => $hasItemType ? 'actual_completion_date' : 'completed_at',
            'picked' => $hasItemType ? 'picked_up_date' : 'delivered_at'
        ];
    } catch (Exception $e) {
        $schema = [
            'type' => 'production',
            'category_col' => 'item_type',
            'cost_col' => 'estimated_cost',
            'has_item_name' => false,
            'has_brand' => false,
            'has_model' => false,
            'has_serial' => false,
            'has_condition' => false
        ];
    }

    return $schema;
}

/**
 * Search repairs for autocomplete
 */
function searchRepairs($pdo, $user_id)
{
    $query = trim($_GET['q'] ?? '');
    $limit = min(20, max(1, (int) ($_GET['limit'] ?? 10)));

    // Get tenant_id for user
    $userStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $userStmt->execute([$user_id]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    $tenant_id = $userRow['tenant_id'] ?? 'default';

    $s = getRepairsSchema($pdo);

    // Build search query
    $where = ["r.tenant_id = ?"];
    $params = [$tenant_id];

    if ($query !== '') {
        $where[] = "(r.repair_no LIKE ? OR r.customer_name LIKE ? OR r.{$s['name']} LIKE ?)";
        $params[] = "%$query%";
        $params[] = "%$query%";
        $params[] = "%$query%";
    }

    $whereClause = implode(' AND ', $where);

    $sql = "
        SELECT 
            r.id,
            r.repair_no,
            r.{$s['name']} as item_name,
            r.customer_name,
            r.{$s['final']} as final_cost,
            r.status,
            r.created_at
        FROM repairs r
        WHERE $whereClause
        ORDER BY r.created_at DESC
        LIMIT ?
    ";

    $params[] = $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add display label
    foreach ($repairs as &$r) {
        $r['display_label'] = $r['repair_no'] . ' - ' . ($r['item_name'] ?: 'งานซ่อม');
        $r['final_cost'] = $r['final_cost'] ? (float) $r['final_cost'] : null;
    }

    echo json_encode([
        'success' => true,
        'data' => $repairs
    ]);
}

/**
 * Get all repairs for customer
 */
function getAllRepairs($pdo, $user_id)
{
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;

    $where = ['r.user_id = ?'];
    $params = [$user_id];

    if ($status) {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }

    $where_clause = implode(' AND ', $where);

    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM repairs r WHERE $where_clause");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

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
        $r['quoted_amount'] = $r['quoted_amount'] ? (float) $r['quoted_amount'] : null;
        $r['final_cost'] = isset($r['final_cost']) ? (float) $r['final_cost'] : null;
        $r['progress_percent'] = (int) $r['progress_percent'];
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
function getSummary($pdo, $user_id)
{
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN status NOT IN ('picked_up', 'cancelled', 'unclaimed') THEN 1 END) as active_count,
            COUNT(CASE WHEN status = 'quoted' THEN 1 END) as awaiting_approval_count,
            COUNT(CASE WHEN status = 'picked_up' THEN 1 END) as completed_count,
            SUM(CASE WHEN status = 'picked_up' THEN final_cost ELSE 0 END) as total_paid
        FROM repairs
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'active_count' => (int) ($summary['active_count'] ?? 0),
        'awaiting_approval_count' => (int) ($summary['awaiting_approval_count'] ?? 0),
        'completed_count' => (int) ($summary['completed_count'] ?? 0),
        'total_paid' => (float) ($summary['total_paid'] ?? 0)
    ];
}

/**
 * Get specific repair detail
 */
function getRepairDetail($pdo, $repair_id, $user_id)
{
    $stmt = $pdo->prepare("SELECT * FROM repairs WHERE id = ? AND user_id = ?");
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
    $repair['quoted_amount'] = $repair['quoted_amount'] ? (float) $repair['quoted_amount'] : null;
    $repair['paid_amount'] = $repair['paid_amount'] ? (float) $repair['paid_amount'] : null;

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
function approveQuote($pdo, $user_id)
{
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
            'quoted_amount' => (float) $repair['quoted_amount']
        ]
    ]);
}

/**
 * Submit payment
 */
function submitPayment($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);

    $repair_id = $input['repair_id'] ?? null;
    $slip_image_url = $input['slip_image_url'] ?? null;
    $amount = isset($input['amount']) ? (float) $input['amount'] : null;

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
    $stmt = $pdo->prepare("SELECT * FROM repairs WHERE id = ? AND user_id = ?");
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
                'amount' => (float) $repair['quoted_amount']
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Create new repair (เจ้าของร้านสร้างรายการซ่อมใหม่)
 */
function createRepair($pdo, $user_id)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Required fields
    $required = ['customer_id', 'item_type', 'item_name', 'issue'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "กรุณากรอก: {$field}"]);
            return;
        }
    }
    
    // Detect schema
    $schema = getRepairsSchema($pdo);
    
    // Generate repair number
    $stmt = $pdo->query("SELECT repair_no FROM repairs ORDER BY id DESC LIMIT 1");
    $lastRepair = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextNum = 1;
    if ($lastRepair && preg_match('/REP(\d+)/', $lastRepair['repair_no'], $matches)) {
        $nextNum = (int) $matches[1] + 1;
    }
    $repairNo = 'REP' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    
    // Get customer profile info
    $customerStmt = $pdo->prepare("
        SELECT id, platform, platform_user_id, display_name, full_name
        FROM customer_profiles 
        WHERE id = ?
    ");
    $customerStmt->execute([$input['customer_id']]);
    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get channel_id from customer_channels
    $channelId = null;
    if ($customer && $customer['platform']) {
        $channelStmt = $pdo->prepare("
            SELECT id FROM customer_channels 
            WHERE user_id = ? AND platform = ? AND status = 'active'
            LIMIT 1
        ");
        $channelStmt->execute([$user_id, $customer['platform']]);
        $channel = $channelStmt->fetch(PDO::FETCH_ASSOC);
        $channelId = $channel ? $channel['id'] : null;
    }
    
    // Build insert based on schema
    $categoryCol = $schema['category_col'];
    $costCol = $schema['cost_col'];
    
    $insertCols = [
        'repair_no', 'user_id', 'customer_profile_id', 'channel_id', 
        'external_user_id', $categoryCol, 'item_description', 'issue_description', 
        $costCol, 'status', 'estimated_completion_date', 'note', 'created_at'
    ];
    $insertValues = [
        $repairNo,
        $user_id,
        $input['customer_id'],
        $channelId,
        $customer ? $customer['platform_user_id'] : null,
        $input['item_type'],
        $input['item_name'] . (!empty($input['item_description']) ? ' - ' . $input['item_description'] : ''),
        $input['issue'],
        $input['estimated_cost'] ?? 0,
        'pending',
        !empty($input['estimated_completion_date']) ? $input['estimated_completion_date'] : null,
        $input['notes'] ?? null,
    ];
    
    // Add optional columns if schema supports
    if ($schema['has_item_name']) {
        $insertCols[] = 'item_name';
        $insertValues[] = $input['item_name'];
    }
    if ($schema['has_brand'] && !empty($input['item_brand'])) {
        $insertCols[] = 'brand';
        $insertValues[] = $input['item_brand'];
    }
    if ($schema['has_model'] && !empty($input['item_model'])) {
        $insertCols[] = 'model';
        $insertValues[] = $input['item_model'];
    }
    if ($schema['has_serial'] && !empty($input['item_serial'])) {
        $insertCols[] = 'serial_number';
        $insertValues[] = $input['item_serial'];
    }
    if ($schema['has_condition'] && !empty($input['item_condition'])) {
        $insertCols[] = 'received_condition';
        $insertValues[] = $input['item_condition'];
    }
    
    $placeholders = array_fill(0, count($insertCols), '?');
    // Fix NOW() for created_at
    $placeholders[count($placeholders) - 1] = 'NOW()';
    array_pop($insertValues); // Remove the created_at value
    
    $sql = "INSERT INTO repairs (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($insertValues);
    
    $repairId = $pdo->lastInsertId();
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'บันทึกรายการซ่อมสำเร็จ',
        'data' => [
            'id' => $repairId,
            'repair_no' => $repairNo
        ]
    ]);
}
