<?php
/**
 * Bot Repairs API (งานซ่อม/เซอร์วิส)
 * 
 * Endpoints:
 * POST /api/bot/repairs                   - Create new repair request (รับงานซ่อม)
 * GET  /api/bot/repairs/{id}              - Get repair by ID
 * GET  /api/bot/repairs/by-user           - Get repairs by external_user_id  
 * POST /api/bot/repairs/{id}/update       - Update repair status
 * POST /api/bot/repairs/{id}/quote        - Submit repair quote
 * POST /api/bot/repairs/{id}/approve      - Customer approves quote
 * POST /api/bot/repairs/{id}/pay          - Submit payment for repair
 * GET  /api/bot/repairs/{id}/status       - Get repair status
 * 
 * Business Rules:
 * - Customer submits repair request with issue description
 * - Shop assesses and provides quote
 * - Customer approves quote
 * - Shop performs repair
 * - Customer pays and picks up
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

// Expected: /api/bot/repairs/{id?}/{action?}
$repair_id = $_GET['repair_id'] ?? (isset($uri_parts[3]) && is_numeric($uri_parts[3]) ? (int)$uri_parts[3] : null);
$action = $_GET['action'] ?? ($uri_parts[3] ?? $uri_parts[4] ?? null);

// Handle by-user as action
if ($action === 'by-user') {
    $repair_id = null;
}

try {
    $db = Database::getInstance();
    
    // Route to appropriate handler
    if ($method === 'POST' && !$repair_id && $action !== 'by-user') {
        createRepair($db);
    } elseif ($method === 'GET' && $action === 'by-user') {
        getRepairsByUser($db);
    } elseif ($method === 'GET' && $repair_id && !$action) {
        getRepair($db, $repair_id);
    } elseif ($method === 'GET' && $repair_id && $action === 'status') {
        getRepairStatus($db, $repair_id);
    } elseif ($method === 'POST' && $repair_id && $action === 'update') {
        updateRepairStatus($db, $repair_id);
    } elseif ($method === 'POST' && $repair_id && $action === 'quote') {
        submitQuote($db, $repair_id);
    } elseif ($method === 'POST' && $repair_id && $action === 'approve') {
        approveQuote($db, $repair_id);
    } elseif ($method === 'POST' && $repair_id && $action === 'pay') {
        submitPayment($db, $repair_id);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    Logger::error('Bot Repairs API Error: ' . $e->getMessage(), [
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
 * Generate unique repair number
 */
function generateRepairNo(): string {
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "REP-{$date}-{$random}";
}

/**
 * Create new repair request
 * 
 * Required: channel_id, external_user_id, platform, product_name, issue_description
 * Optional: product_brand, product_model, issue_category, customer_name, customer_phone
 */
function createRepair($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['channel_id', 'external_user_id', 'platform', 'product_name', 'issue_description'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
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
    
    // Validate issue category if provided
    $validCategories = ['battery', 'glass', 'band', 'crown', 'movement', 'water_damage', 
                        'polish', 'service', 'resize', 'stone_setting', 'clasp', 'other'];
    $issueCategory = $input['issue_category'] ?? 'other';
    if (!in_array($issueCategory, $validCategories)) {
        $issueCategory = 'other';
    }
    
    $repairNo = generateRepairNo();
    
    $sql = "INSERT INTO repairs (
        repair_no, tenant_id, customer_id, customer_profile_id,
        channel_id, external_user_id, platform,
        customer_name, customer_phone, customer_line_name,
        product_ref_id, product_name, product_brand, product_model, 
        product_serial, product_description, product_images,
        issue_description, issue_category,
        status, received_at,
        case_id, admin_notes,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?,
        'pending_assessment', NOW(),
        ?, ?,
        NOW(), NOW()
    )";
    
    $params = [
        $repairNo,
        $input['tenant_id'] ?? 'default',
        $input['customer_id'] ?? null,
        $input['customer_profile_id'] ?? null,
        (int)$input['channel_id'],
        (string)$input['external_user_id'],
        $input['platform'],
        $input['customer_name'] ?? null,
        $input['customer_phone'] ?? null,
        $input['customer_line_name'] ?? null,
        $input['product_ref_id'] ?? null,
        $input['product_name'],
        $input['product_brand'] ?? null,
        $input['product_model'] ?? null,
        $input['product_serial'] ?? null,
        $input['product_description'] ?? null,
        !empty($input['product_images']) ? json_encode($input['product_images']) : null,
        $input['issue_description'],
        $issueCategory,
        $input['case_id'] ?? null,
        $input['admin_notes'] ?? null
    ];
    
    $db->execute($sql, $params);
    $newId = $db->lastInsertId();
    
    Logger::info('Repair request created', [
        'repair_id' => $newId,
        'repair_no' => $repairNo,
        'product_name' => $input['product_name'],
        'issue_category' => $issueCategory
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'รับเรื่องงานซ่อมเรียบร้อยค่ะ รอเจ้าหน้าที่ประเมินราคา',
        'data' => [
            'id' => $newId,
            'repair_no' => $repairNo,
            'product_name' => $input['product_name'],
            'issue_description' => $input['issue_description'],
            'issue_category' => $issueCategory,
            'status' => 'pending_assessment'
        ]
    ]);
}

/**
 * Get repair by ID
 */
function getRepair($db, int $repairId) {
    $repair = $db->queryOne("SELECT * FROM repairs WHERE id = ?", [$repairId]);
    
    if (!$repair) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการซ่อม']);
        return;
    }
    
    // Parse JSON fields
    $repair['product_images'] = $repair['product_images'] ? json_decode($repair['product_images'], true) : [];
    $repair['before_images'] = $repair['before_images'] ? json_decode($repair['before_images'], true) : [];
    $repair['after_images'] = $repair['after_images'] ? json_decode($repair['after_images'], true) : [];
    
    echo json_encode([
        'success' => true,
        'data' => array_merge($repair, [
            'estimated_cost' => $repair['estimated_cost'] ? (float)$repair['estimated_cost'] : null,
            'final_cost' => $repair['final_cost'] ? (float)$repair['final_cost'] : null,
            'parts_cost' => $repair['parts_cost'] ? (float)$repair['parts_cost'] : null,
            'labor_cost' => $repair['labor_cost'] ? (float)$repair['labor_cost'] : null,
            'paid_amount' => (float)$repair['paid_amount']
        ])
    ]);
}

/**
 * Get repairs by user
 */
function getRepairsByUser($db) {
    $channelId = $_GET['channel_id'] ?? null;
    $externalUserId = $_GET['external_user_id'] ?? null;
    $status = $_GET['status'] ?? null;
    
    if (!$channelId || !$externalUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing channel_id or external_user_id']);
        return;
    }
    
    $sql = "SELECT * FROM repairs WHERE channel_id = ? AND external_user_id = ?";
    $params = [(int)$channelId, (string)$externalUserId];
    
    if ($status) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $repairs = $db->queryAll($sql, $params);
    
    // Format numeric fields
    foreach ($repairs as &$repair) {
        $repair['estimated_cost'] = $repair['estimated_cost'] ? (float)$repair['estimated_cost'] : null;
        $repair['final_cost'] = $repair['final_cost'] ? (float)$repair['final_cost'] : null;
        $repair['paid_amount'] = (float)$repair['paid_amount'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $repairs,
        'count' => count($repairs)
    ]);
}

/**
 * Update repair status (Admin action)
 */
function updateRepairStatus($db, int $repairId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $repair = $db->queryOne("SELECT * FROM repairs WHERE id = ?", [$repairId]);
    
    if (!$repair) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการซ่อม']);
        return;
    }
    
    $newStatus = $input['status'] ?? null;
    $validStatuses = ['pending_assessment', 'quoted', 'customer_approved', 'in_progress', 
                      'completed', 'ready_for_pickup', 'delivered', 'cancelled'];
    
    if (!$newStatus || !in_array($newStatus, $validStatuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        return;
    }
    
    // Update timestamp fields based on status
    $timestampField = match($newStatus) {
        'quoted' => 'quoted_at',
        'customer_approved' => 'approved_at',
        'in_progress' => 'started_at',
        'completed', 'ready_for_pickup' => 'completed_at',
        'delivered' => 'delivered_at',
        default => null
    };
    
    $sql = "UPDATE repairs SET status = ?, updated_at = NOW()";
    $params = [$newStatus];
    
    if ($timestampField) {
        $sql .= ", {$timestampField} = NOW()";
    }
    
    if (!empty($input['admin_notes'])) {
        $sql .= ", admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] ', ?)";
        $params[] = $input['admin_notes'];
    }
    
    if (!empty($input['technician_notes'])) {
        $sql .= ", technician_notes = CONCAT(IFNULL(technician_notes, ''), '\n[', NOW(), '] ', ?)";
        $params[] = $input['technician_notes'];
    }
    
    // Handle completed status - set warranty
    if ($newStatus === 'completed' || $newStatus === 'ready_for_pickup') {
        $warrantyDays = (int)($input['warranty_days'] ?? 30);
        $sql .= ", warranty_days = ?, warranty_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY)";
        $params[] = $warrantyDays;
        $params[] = $warrantyDays;
    }
    
    $sql .= " WHERE id = ?";
    $params[] = $repairId;
    
    $db->execute($sql, $params);
    
    Logger::info('Repair status updated', [
        'repair_id' => $repairId,
        'repair_no' => $repair['repair_no'],
        'old_status' => $repair['status'],
        'new_status' => $newStatus
    ]);
    
    $statusMessages = [
        'pending_assessment' => 'รอประเมิน',
        'quoted' => 'เสนอราคาแล้ว',
        'customer_approved' => 'ลูกค้าอนุมัติ',
        'in_progress' => 'กำลังซ่อม',
        'completed' => 'ซ่อมเสร็จ',
        'ready_for_pickup' => 'พร้อมรับ',
        'delivered' => 'ส่งมอบแล้ว',
        'cancelled' => 'ยกเลิก'
    ];
    
    echo json_encode([
        'success' => true,
        'message' => 'อัพเดทสถานะเรียบร้อยค่ะ',
        'data' => [
            'id' => $repairId,
            'repair_no' => $repair['repair_no'],
            'status' => $newStatus,
            'status_text' => $statusMessages[$newStatus] ?? $newStatus
        ]
    ]);
}

/**
 * Submit repair quote (Admin action)
 */
function submitQuote($db, int $repairId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $repair = $db->queryOne("SELECT * FROM repairs WHERE id = ?", [$repairId]);
    
    if (!$repair) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการซ่อม']);
        return;
    }
    
    if (empty($input['estimated_cost'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุราคาประเมิน']);
        return;
    }
    
    $estimatedCost = (float)$input['estimated_cost'];
    $partsCost = (float)($input['parts_cost'] ?? 0);
    $laborCost = (float)($input['labor_cost'] ?? $estimatedCost - $partsCost);
    $estimatedDays = (int)($input['estimated_days'] ?? 7);
    $estimatedCompletionDate = date('Y-m-d', strtotime("+{$estimatedDays} days"));
    
    $db->execute(
        "UPDATE repairs SET 
            estimated_cost = ?,
            parts_cost = ?,
            labor_cost = ?,
            estimated_days = ?,
            estimated_completion_date = ?,
            status = 'quoted',
            quoted_at = NOW(),
            admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] เสนอราคา ', ?, ' บาท'),
            updated_at = NOW()
         WHERE id = ?",
        [
            $estimatedCost,
            $partsCost,
            $laborCost,
            $estimatedDays,
            $estimatedCompletionDate,
            number_format($estimatedCost, 2),
            $repairId
        ]
    );
    
    Logger::info('Repair quote submitted', [
        'repair_id' => $repairId,
        'repair_no' => $repair['repair_no'],
        'estimated_cost' => $estimatedCost
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'เสนอราคาเรียบร้อยค่ะ',
        'data' => [
            'id' => $repairId,
            'repair_no' => $repair['repair_no'],
            'estimated_cost' => $estimatedCost,
            'parts_cost' => $partsCost,
            'labor_cost' => $laborCost,
            'estimated_days' => $estimatedDays,
            'estimated_completion_date' => $estimatedCompletionDate,
            'status' => 'quoted'
        ]
    ]);
}

/**
 * Customer approves quote
 */
function approveQuote($db, int $repairId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $repair = $db->queryOne("SELECT * FROM repairs WHERE id = ?", [$repairId]);
    
    if (!$repair) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการซ่อม']);
        return;
    }
    
    if ($repair['status'] !== 'quoted') {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'ยังไม่มีใบเสนอราคาให้อนุมัติ'
        ]);
        return;
    }
    
    $db->execute(
        "UPDATE repairs SET 
            status = 'customer_approved',
            approved_at = NOW(),
            admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] ลูกค้าอนุมัติราคา'),
            updated_at = NOW()
         WHERE id = ?",
        [$repairId]
    );
    
    Logger::info('Repair quote approved', [
        'repair_id' => $repairId,
        'repair_no' => $repair['repair_no']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'อนุมัติราคาเรียบร้อยค่ะ ✅ เริ่มดำเนินการซ่อมได้เลย',
        'data' => [
            'id' => $repairId,
            'repair_no' => $repair['repair_no'],
            'estimated_cost' => (float)$repair['estimated_cost'],
            'estimated_completion_date' => $repair['estimated_completion_date'],
            'status' => 'customer_approved'
        ]
    ]);
}

/**
 * Submit payment for repair
 */
function submitPayment($db, int $repairId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $repair = $db->queryOne("SELECT * FROM repairs WHERE id = ?", [$repairId]);
    
    if (!$repair) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการซ่อม']);
        return;
    }
    
    // Must be completed or ready for pickup to pay
    if (!in_array($repair['status'], ['completed', 'ready_for_pickup'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'งานซ่อมยังไม่เสร็จ ยังไม่สามารถชำระเงินได้'
        ]);
        return;
    }
    
    // Validate slip image
    if (empty($input['slip_image_url'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาส่งรูปสลิปการโอน']);
        return;
    }
    
    $amount = (float)($input['amount'] ?? $repair['final_cost'] ?? $repair['estimated_cost']);
    
    $db->execute(
        "UPDATE repairs SET 
            paid_amount = paid_amount + ?,
            payment_slip_url = ?,
            payment_status = CASE 
                WHEN paid_amount + ? >= COALESCE(final_cost, estimated_cost) THEN 'paid'
                WHEN paid_amount + ? > 0 THEN 'partial'
                ELSE 'unpaid'
            END,
            admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] ลูกค้าส่งสลิป ยอด ', ?),
            updated_at = NOW()
         WHERE id = ?",
        [
            $amount,
            $input['slip_image_url'],
            $amount,
            $amount,
            number_format($amount, 2),
            $repairId
        ]
    );
    
    Logger::info('Repair payment submitted', [
        'repair_id' => $repairId,
        'repair_no' => $repair['repair_no'],
        'amount' => $amount
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'ได้รับสลิปชำระค่าซ่อมแล้วค่ะ ✅ รอเจ้าหน้าที่ตรวจสอบนะคะ',
        'data' => [
            'id' => $repairId,
            'repair_no' => $repair['repair_no'],
            'amount' => $amount,
            'product_name' => $repair['product_name']
        ]
    ]);
}

/**
 * Get repair status with full details
 */
function getRepairStatus($db, int $repairId) {
    $repair = $db->queryOne("SELECT * FROM repairs WHERE id = ?", [$repairId]);
    
    if (!$repair) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการซ่อม']);
        return;
    }
    
    $statusMessages = [
        'pending_assessment' => 'รอประเมินราคา',
        'quoted' => 'เสนอราคาแล้ว รอลูกค้าอนุมัติ',
        'customer_approved' => 'ลูกค้าอนุมัติแล้ว รอดำเนินการ',
        'in_progress' => 'กำลังซ่อม',
        'completed' => 'ซ่อมเสร็จแล้ว',
        'ready_for_pickup' => 'พร้อมรับสินค้า',
        'delivered' => 'ส่งมอบแล้ว',
        'cancelled' => 'ยกเลิก'
    ];
    
    $paymentStatusMessages = [
        'unpaid' => 'ยังไม่ชำระ',
        'partial' => 'ชำระบางส่วน',
        'paid' => 'ชำระแล้ว'
    ];
    
    // Calculate progress
    $statusOrder = ['pending_assessment', 'quoted', 'customer_approved', 'in_progress', 
                    'completed', 'ready_for_pickup', 'delivered'];
    $currentIndex = array_search($repair['status'], $statusOrder);
    $progress = $currentIndex !== false ? round(($currentIndex + 1) / count($statusOrder) * 100) : 0;
    
    // Days remaining
    $daysRemaining = null;
    if ($repair['estimated_completion_date']) {
        $now = new DateTime();
        $completion = new DateTime($repair['estimated_completion_date']);
        $daysRemaining = max(0, (int)$now->diff($completion)->days);
        if ($now > $completion) {
            $daysRemaining = -$daysRemaining; // Negative if overdue
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $repair['id'],
            'repair_no' => $repair['repair_no'],
            'product_name' => $repair['product_name'],
            'product_brand' => $repair['product_brand'],
            'issue_description' => $repair['issue_description'],
            'issue_category' => $repair['issue_category'],
            'status' => $repair['status'],
            'status_text' => $statusMessages[$repair['status']] ?? $repair['status'],
            'progress_percent' => $progress,
            'estimated_cost' => $repair['estimated_cost'] ? (float)$repair['estimated_cost'] : null,
            'final_cost' => $repair['final_cost'] ? (float)$repair['final_cost'] : null,
            'paid_amount' => (float)$repair['paid_amount'],
            'payment_status' => $repair['payment_status'],
            'payment_status_text' => $paymentStatusMessages[$repair['payment_status']] ?? $repair['payment_status'],
            'estimated_days' => $repair['estimated_days'],
            'estimated_completion_date' => $repair['estimated_completion_date'],
            'days_remaining' => $daysRemaining,
            'warranty_days' => $repair['warranty_days'],
            'warranty_expires_at' => $repair['warranty_expires_at'],
            'received_at' => $repair['received_at'],
            'completed_at' => $repair['completed_at'],
            'delivered_at' => $repair['delivered_at'],
            'created_at' => $repair['created_at']
        ]
    ]);
}
