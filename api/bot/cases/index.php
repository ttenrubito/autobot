<?php
/**
 * Bot Cases API
 * 
 * Endpoints:
 * POST /api/bot/cases                    - Create new case
 * GET  /api/bot/cases/{id}               - Get case by ID
 * POST /api/bot/cases/{id}/update-slot   - Update case slots
 * POST /api/bot/cases/{id}/status        - Update case status
 * 
 * @version 2.0
 * @date 2026-01-06
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

// Expected: /api/bot/cases/{id?}/{action?}
// uri_parts: [0]api, [1]bot, [2]cases, [3]id?, [4]action?
// Also support router-provided params via $_GET
$case_id = $_GET['case_id'] ?? (isset($uri_parts[3]) && is_numeric($uri_parts[3]) ? (int)$uri_parts[3] : null);
$action = $_GET['action'] ?? ($uri_parts[4] ?? null);

try {
    $db = Database::getInstance();
    
    // Route to appropriate handler
    if ($method === 'POST' && !$case_id) {
        // POST /api/bot/cases - Create new case
        createCase($db);
    } elseif ($method === 'GET' && $case_id) {
        // GET /api/bot/cases/{id}
        getCase($db, $case_id);
    } elseif ($method === 'POST' && $case_id && $action === 'update-slot') {
        // POST /api/bot/cases/{id}/update-slot
        updateCaseSlot($db, $case_id);
    } elseif ($method === 'POST' && $case_id && $action === 'status') {
        // POST /api/bot/cases/{id}/status
        updateCaseStatus($db, $case_id);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    Logger::error('Bot Cases API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Generate unique case number
 */
function generateCaseNo(): string {
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "CASE-{$date}-{$random}";
}

/**
 * Create new case
 * 
 * Required: channel_id, external_user_id, platform, case_type
 * Optional: subject, description, slots, product_ref_id, order_id, payment_id
 */
function createCase($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['channel_id', 'external_user_id', 'platform', 'case_type'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            return;
        }
    }
    
    // Validate case_type
    $validTypes = ['product_inquiry', 'payment_full', 'payment_installment', 'payment_savings', 'general_inquiry', 'complaint', 'other'];
    if (!in_array($input['case_type'], $validTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid case_type']);
        return;
    }
    
    // Validate platform
    $validPlatforms = ['line', 'facebook', 'web', 'instagram'];
    if (!in_array($input['platform'], $validPlatforms)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid platform']);
        return;
    }
    
    // ✅ SESSION-BASED CASE: Find ANY open case for this customer (ignore case_type)
    // Case expires after 24 hours of inactivity
    $existingCase = $db->queryOne(
        "SELECT * FROM cases 
         WHERE channel_id = ? AND external_user_id = ? 
         AND status NOT IN ('resolved', 'cancelled')
         AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY updated_at DESC LIMIT 1",
        [(int)$input['channel_id'], (string)$input['external_user_id']]
    );
    
    if ($existingCase) {
        // Update existing case with new slots/message
        $existingSlots = json_decode($existingCase['slots'] ?? '{}', true) ?: [];
        $newSlots = $input['slots'] ?? [];
        $mergedSlots = array_merge($existingSlots, $newSlots);
        
        // Append message to description (chat history)
        $newDescription = $existingCase['description'] ?? '';
        if (!empty($input['message'])) {
            $timestamp = date('Y-m-d H:i:s');
            $newDescription = trim($newDescription . "\n[{$timestamp}] ลูกค้า: " . $input['message']);
        }
        
        // Update case_type if new type is more specific (e.g. payment > inquiry)
        $caseTypePriority = [
            'product_inquiry' => 1,
            'general_inquiry' => 1,
            'payment_full' => 2,
            'payment_installment' => 2,
            'payment_savings' => 2,
            'complaint' => 3,
            'other' => 0
        ];
        $existingPriority = $caseTypePriority[$existingCase['case_type']] ?? 0;
        $newPriority = $caseTypePriority[$input['case_type']] ?? 0;
        $finalCaseType = ($newPriority > $existingPriority) ? $input['case_type'] : $existingCase['case_type'];
        
        $db->execute(
            "UPDATE cases SET slots = ?, description = ?, case_type = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($mergedSlots), $newDescription, $finalCaseType, $existingCase['id']]
        );
        
        Logger::info('[CASE_API] Case updated (session-based)', [
            'case_id' => $existingCase['id'],
            'case_no' => $existingCase['case_no'],
            'case_type' => $finalCaseType,
            'message_appended' => !empty($input['message'])
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Case updated',
            'data' => [
                'id' => $existingCase['id'],
                'case_no' => $existingCase['case_no'],
                'case_type' => $finalCaseType,
                'is_new' => false
            ]
        ]);
        return;
    }
    
    $caseNo = generateCaseNo();
    $slots = isset($input['slots']) ? json_encode($input['slots']) : null;
    
    // Include initial message in description
    $description = $input['description'] ?? null;
    if (!empty($input['message'])) {
        $timestamp = date('Y-m-d H:i:s');
        $description = "[{$timestamp}] ลูกค้า: " . $input['message'];
    }
    
    // Determine initial status based on case_type
    $status = 'open';
    if (in_array($input['case_type'], ['payment_full', 'payment_installment', 'payment_savings'])) {
        // Payment cases might need admin review after slip is received
        $status = 'open';
    }
    
    // Auto-generate subject if not provided
    $subject = $input['subject'] ?? null;
    if (!$subject) {
        $typeLabels = [
            'product_inquiry' => 'สอบถามสินค้า',
            'payment_full' => 'ชำระเงินเต็ม',
            'payment_installment' => 'ชำระผ่อน',
            'payment_savings' => 'ออมสินค้า',
            'general_inquiry' => 'สอบถามทั่วไป',
            'complaint' => 'ร้องเรียน',
            'other' => 'อื่นๆ'
        ];
        $subject = $typeLabels[$input['case_type']] ?? 'New Case';
    }
    
    $sql = "INSERT INTO cases (
        case_no, tenant_id, case_type, channel_id, external_user_id, 
        customer_id, platform, session_id, subject, description, 
        slots, product_ref_id, order_id, payment_id, savings_account_id,
        status, priority, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, NOW(), NOW()
    )";
    
    $params = [
        $caseNo,
        $input['tenant_id'] ?? 'default',
        $input['case_type'],
        (int)$input['channel_id'],
        (string)$input['external_user_id'],
        $input['customer_id'] ?? null,
        $input['platform'],
        $input['session_id'] ?? null,
        $subject,
        $input['description'] ?? null,
        $slots,
        $input['product_ref_id'] ?? null,
        $input['order_id'] ?? null,
        $input['payment_id'] ?? null,
        $input['savings_account_id'] ?? null,
        $status,
        $input['priority'] ?? 'normal'
    ];
    
    $db->execute($sql, $params);
    $newId = $db->lastInsertId();
    
    // Log activity
    logCaseActivity($db, $newId, 'created', null, [
        'case_type' => $input['case_type'],
        'status' => $status
    ], 'bot', null);
    
    // Update session's active_case_id if session_id provided
    if (!empty($input['session_id'])) {
        $db->execute(
            "UPDATE chat_sessions SET active_case_id = ?, active_case_type = ?, updated_at = NOW() WHERE id = ?",
            [$newId, $input['case_type'], (int)$input['session_id']]
        );
    }
    
    Logger::info('Case created', [
        'case_id' => $newId,
        'case_no' => $caseNo,
        'case_type' => $input['case_type'],
        'channel_id' => $input['channel_id'],
        'external_user_id' => $input['external_user_id']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Case created successfully',
        'data' => [
            'id' => $newId,
            'case_no' => $caseNo,
            'case_type' => $input['case_type'],
            'status' => $status,
            'subject' => $subject
        ]
    ]);
}

/**
 * Get case by ID
 */
function getCase($db, int $caseId) {
    $case = $db->queryOne("SELECT * FROM cases WHERE id = ?", [$caseId]);
    
    if (!$case) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        return;
    }
    
    // Decode JSON fields
    $case['slots'] = $case['slots'] ? json_decode($case['slots'], true) : [];
    
    // Get recent activities
    $activities = $db->queryAll(
        "SELECT * FROM case_activities WHERE case_id = ? ORDER BY created_at DESC LIMIT 20",
        [$caseId]
    );
    
    foreach ($activities as &$activity) {
        $activity['old_value'] = $activity['old_value'] ? json_decode($activity['old_value'], true) : null;
        $activity['new_value'] = $activity['new_value'] ? json_decode($activity['new_value'], true) : null;
    }
    
    $case['activities'] = $activities;
    
    echo json_encode([
        'success' => true,
        'data' => $case
    ]);
}

/**
 * Update case slots (add or update slot data)
 */
function updateCaseSlot($db, int $caseId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['slots']) || !is_array($input['slots'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing or invalid slots data']);
        return;
    }
    
    // Get current case
    $case = $db->queryOne("SELECT id, slots FROM cases WHERE id = ?", [$caseId]);
    if (!$case) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        return;
    }
    
    // Merge slots
    $currentSlots = $case['slots'] ? json_decode($case['slots'], true) : [];
    $newSlots = array_merge($currentSlots, $input['slots']);
    
    // Update
    $db->execute(
        "UPDATE cases SET slots = ?, updated_at = NOW() WHERE id = ?",
        [json_encode($newSlots), $caseId]
    );
    
    // Log activity
    logCaseActivity($db, $caseId, 'slot_updated', $currentSlots, $newSlots, 'bot', null);
    
    Logger::info('Case slots updated', [
        'case_id' => $caseId,
        'new_slots' => $input['slots']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Slots updated successfully',
        'data' => [
            'slots' => $newSlots
        ]
    ]);
}

/**
 * Update case status
 */
function updateCaseStatus($db, int $caseId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['status'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing status']);
        return;
    }
    
    $validStatuses = ['open', 'pending_admin', 'in_progress', 'pending_customer', 'resolved', 'cancelled'];
    if (!in_array($input['status'], $validStatuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        return;
    }
    
    // Get current case
    $case = $db->queryOne("SELECT id, status FROM cases WHERE id = ?", [$caseId]);
    if (!$case) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        return;
    }
    
    $oldStatus = $case['status'];
    $newStatus = $input['status'];
    
    // Update
    $updates = ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')];
    
    if ($newStatus === 'resolved') {
        $updates['resolved_at'] = date('Y-m-d H:i:s');
        $updates['resolution_type'] = $input['resolution_type'] ?? 'completed';
        $updates['resolution_notes'] = $input['resolution_notes'] ?? null;
    }
    
    $setClauses = [];
    $params = [];
    foreach ($updates as $key => $value) {
        $setClauses[] = "{$key} = ?";
        $params[] = $value;
    }
    $params[] = $caseId;
    
    $db->execute(
        "UPDATE cases SET " . implode(', ', $setClauses) . " WHERE id = ?",
        $params
    );
    
    // Log activity
    logCaseActivity($db, $caseId, 'status_changed', ['status' => $oldStatus], ['status' => $newStatus], 'bot', null);
    
    Logger::info('Case status updated', [
        'case_id' => $caseId,
        'old_status' => $oldStatus,
        'new_status' => $newStatus
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully',
        'data' => [
            'id' => $caseId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]
    ]);
}

/**
 * Log case activity
 */
function logCaseActivity($db, int $caseId, string $activityType, $oldValue, $newValue, string $actorType, ?string $actorId) {
    $sql = "INSERT INTO case_activities (case_id, activity_type, old_value, new_value, actor_type, actor_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $db->execute($sql, [
        $caseId,
        $activityType,
        $oldValue ? json_encode($oldValue) : null,
        $newValue ? json_encode($newValue) : null,
        $actorType,
        $actorId
    ]);
}
