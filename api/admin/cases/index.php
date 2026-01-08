<?php
/**
 * Admin Cases API
 * 
 * Endpoints:
 * GET  /api/admin/cases                     - List all cases (with filters)
 * GET  /api/admin/cases/{id}                - Get case details
 * PUT  /api/admin/cases/{id}/assign         - Assign case to admin
 * PUT  /api/admin/cases/{id}/resolve        - Resolve case
 * POST /api/admin/cases/{id}/send-message   - Send message to customer
 * POST /api/admin/cases/{id}/note           - Add internal note
 * 
 * @version 2.0
 * @date 2026-01-06
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Logger.php';
require_once __DIR__ . '/../../../includes/AdminAuth.php';

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$case_id = $_GET['case_id'] ?? null;
$action = $_GET['action'] ?? null;

try {
    $db = Database::getInstance();
    
    // Verify admin authentication
    $admin = AdminAuth::verify();
    if (!$admin) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $adminId = $admin['id'] ?? null;
    
    // Route to appropriate handler
    if ($method === 'GET' && !$case_id) {
        // Check if stats requested
        if (isset($_GET['stats']) && $_GET['stats'] === '1') {
            getCaseStats($db);
        } else {
            // GET /api/admin/cases - List cases
            listCases($db);
        }
    } elseif ($method === 'GET' && $case_id) {
        // GET /api/admin/cases/{id}
        getCase($db, (int)$case_id);
    } elseif ($method === 'PUT' && $case_id && $action === 'assign') {
        // PUT /api/admin/cases/{id}/assign
        assignCase($db, (int)$case_id, $adminId);
    } elseif ($method === 'PUT' && $case_id && $action === 'resolve') {
        // PUT /api/admin/cases/{id}/resolve
        resolveCase($db, (int)$case_id, $adminId);
    } elseif ($method === 'POST' && $case_id && $action === 'send-message') {
        // POST /api/admin/cases/{id}/send-message
        sendMessage($db, (int)$case_id, $adminId);
    } elseif ($method === 'POST' && $case_id && $action === 'note') {
        // POST /api/admin/cases/{id}/note
        addNote($db, (int)$case_id, $adminId);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    Logger::error('Admin Cases API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * List cases with filters
 * 
 * Query params:
 * - status: open, pending_admin, in_progress, pending_customer, resolved, cancelled
 * - case_type: product_inquiry, payment_full, payment_installment, payment_savings, etc.
 * - platform: line, facebook
 * - assigned_to: admin_id
 * - priority: low, normal, high, urgent
 * - search: search in case_no, subject, external_user_id
 * - limit, offset: pagination
 */
function listCases($db) {
    $status = $_GET['status'] ?? null;
    $caseType = $_GET['case_type'] ?? null;
    $platform = $_GET['platform'] ?? null;
    $assignedTo = $_GET['assigned_to'] ?? null;
    $priority = $_GET['priority'] ?? null;
    $search = $_GET['search'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $where = [];
    $params = [];
    
    if ($status) {
        $where[] = "c.status = ?";
        $params[] = $status;
    }
    
    if ($caseType) {
        $where[] = "c.case_type = ?";
        $params[] = $caseType;
    }
    
    if ($platform) {
        $where[] = "c.platform = ?";
        $params[] = $platform;
    }
    
    if ($assignedTo) {
        $where[] = "c.assigned_to = ?";
        $params[] = (int)$assignedTo;
    }
    
    if ($priority) {
        $where[] = "c.priority = ?";
        $params[] = $priority;
    }
    
    if ($search) {
        $where[] = "(c.case_no LIKE ? OR c.subject LIKE ? OR c.external_user_id LIKE ?)";
        $searchPattern = "%{$search}%";
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Count total
    $countSql = "SELECT COUNT(*) as total FROM cases c {$whereClause}";
    $total = $db->queryOne($countSql, $params)['total'] ?? 0;
    
    // Get cases
    $sql = "SELECT 
                c.*,
                ch.name as channel_name,
                a.email as assigned_admin_email
            FROM cases c
            LEFT JOIN customer_channels ch ON c.channel_id = ch.id
            LEFT JOIN admin_users a ON c.assigned_to = a.id
            {$whereClause}
            ORDER BY 
                FIELD(c.status, 'pending_admin', 'open', 'in_progress', 'pending_customer', 'resolved', 'cancelled'),
                FIELD(c.priority, 'urgent', 'high', 'normal', 'low'),
                c.updated_at DESC
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $cases = $db->queryAll($sql, $params);
    
    // Decode JSON fields
    foreach ($cases as &$case) {
        $case['slots'] = $case['slots'] ? json_decode($case['slots'], true) : [];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $cases,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total
        ]
    ]);
}

/**
 * Get case details with activities
 */
function getCase($db, int $caseId) {
    $case = $db->queryOne("SELECT c.*, ch.name as channel_name 
                           FROM cases c 
                           LEFT JOIN customer_channels ch ON c.channel_id = ch.id
                           WHERE c.id = ?", [$caseId]);
    
    if (!$case) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        return;
    }
    
    // Decode JSON fields
    $case['slots'] = $case['slots'] ? json_decode($case['slots'], true) : [];
    
    // Get activities
    $activities = $db->queryAll(
        "SELECT * FROM case_activities WHERE case_id = ? ORDER BY created_at DESC LIMIT 50",
        [$caseId]
    );
    
    foreach ($activities as &$activity) {
        $activity['old_value'] = $activity['old_value'] ? json_decode($activity['old_value'], true) : null;
        $activity['new_value'] = $activity['new_value'] ? json_decode($activity['new_value'], true) : null;
    }
    
    $case['activities'] = $activities;
    
    // Get related order if exists
    if ($case['order_id']) {
        $case['order'] = $db->queryOne(
            "SELECT * FROM orders WHERE id = ?",
            [$case['order_id']]
        );
    }
    
    // Get related payment if exists
    if ($case['payment_id']) {
        $case['payment'] = $db->queryOne(
            "SELECT * FROM payments WHERE id = ?",
            [$case['payment_id']]
        );
    }
    
    // Get related savings if exists
    if ($case['savings_account_id']) {
        $case['savings_account'] = $db->queryOne(
            "SELECT * FROM savings_accounts WHERE id = ?",
            [$case['savings_account_id']]
        );
    }
    
    // Get recent chat messages for this session
    if ($case['session_id']) {
        $case['recent_messages'] = $db->queryAll(
            "SELECT * FROM chat_messages WHERE session_id = ? ORDER BY created_at DESC LIMIT 20",
            [$case['session_id']]
        );
    }
    
    echo json_encode([
        'success' => true,
        'data' => $case
    ]);
}

/**
 * Assign case to admin
 */
function assignCase($db, int $caseId, ?int $currentAdminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $assignTo = $input['assign_to'] ?? $currentAdminId;
    
    // Get current case
    $case = $db->queryOne("SELECT id, status, assigned_to FROM cases WHERE id = ?", [$caseId]);
    if (!$case) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        return;
    }
    
    $oldAssigned = $case['assigned_to'];
    
    // Update
    $db->execute(
        "UPDATE cases SET assigned_to = ?, assigned_at = NOW(), status = 'in_progress', updated_at = NOW() WHERE id = ?",
        [$assignTo, $caseId]
    );
    
    // Log activity
    logActivity($db, $caseId, 'assigned', 
        ['assigned_to' => $oldAssigned], 
        ['assigned_to' => $assignTo],
        'admin', $currentAdminId);
    
    echo json_encode([
        'success' => true,
        'message' => 'Case assigned successfully',
        'data' => [
            'case_id' => $caseId,
            'assigned_to' => $assignTo,
            'status' => 'in_progress'
        ]
    ]);
}

/**
 * Resolve case
 */
function resolveCase($db, int $caseId, ?int $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $resolutionType = $input['resolution_type'] ?? 'completed';
    $resolutionNotes = $input['resolution_notes'] ?? null;
    
    // Validate resolution_type
    $validTypes = ['completed', 'no_response', 'duplicate', 'invalid', 'other'];
    if (!in_array($resolutionType, $validTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid resolution_type']);
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
    
    // Update
    $db->execute(
        "UPDATE cases SET 
            status = 'resolved', 
            resolution_type = ?, 
            resolution_notes = ?,
            resolved_at = NOW(),
            resolved_by = ?,
            updated_at = NOW()
         WHERE id = ?",
        [$resolutionType, $resolutionNotes, $adminId, $caseId]
    );
    
    // Log activity
    logActivity($db, $caseId, 'resolved',
        ['status' => $oldStatus],
        ['status' => 'resolved', 'resolution_type' => $resolutionType],
        'admin', $adminId);
    
    echo json_encode([
        'success' => true,
        'message' => 'Case resolved successfully',
        'data' => [
            'case_id' => $caseId,
            'status' => 'resolved',
            'resolution_type' => $resolutionType
        ]
    ]);
}

/**
 * Send message to customer (update last_admin_message_at to trigger handoff)
 */
function sendMessage($db, int $caseId, ?int $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['message'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Message is required']);
        return;
    }
    
    $message = $input['message'];
    
    // Get case with session info
    $case = $db->queryOne("SELECT * FROM cases WHERE id = ?", [$caseId]);
    if (!$case) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        return;
    }
    
    // Update case
    $db->execute(
        "UPDATE cases SET last_admin_message_at = NOW(), status = 'pending_customer', updated_at = NOW() WHERE id = ?",
        [$caseId]
    );
    
    // Update session's last_admin_message_at to pause bot
    if ($case['session_id']) {
        $db->execute(
            "UPDATE chat_sessions SET last_admin_message_at = NOW(), updated_at = NOW() WHERE id = ?",
            [$case['session_id']]
        );
    }
    
    // Log activity
    logActivity($db, $caseId, 'admin_message',
        null,
        ['message' => $message],
        'admin', $adminId);
    
    // TODO: Actually send message via LINE/Facebook API
    // This would require calling the respective platform APIs
    
    echo json_encode([
        'success' => true,
        'message' => 'Message sent and bot paused for 1 hour',
        'data' => [
            'case_id' => $caseId,
            'status' => 'pending_customer'
        ]
    ]);
}

/**
 * Add internal note
 */
function addNote($db, int $caseId, ?int $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['note'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Note is required']);
        return;
    }
    
    // Log activity
    logActivity($db, $caseId, 'note_added',
        null,
        ['note' => $input['note']],
        'admin', $adminId);
    
    echo json_encode([
        'success' => true,
        'message' => 'Note added successfully'
    ]);
}

/**
 * Log case activity
 */
function logActivity($db, int $caseId, string $activityType, $oldValue, $newValue, string $actorType, $actorId) {
    $db->execute(
        "INSERT INTO case_activities (case_id, activity_type, old_value, new_value, actor_type, actor_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [
            $caseId,
            $activityType,
            $oldValue ? json_encode($oldValue) : null,
            $newValue ? json_encode($newValue) : null,
            $actorType,
            $actorId
        ]
    );
}

/**
 * Get case statistics
 */
function getCaseStats($db) {
    // Count pending admin cases
    $pendingAdmin = $db->queryOne(
        "SELECT COUNT(*) as cnt FROM cases WHERE status = 'pending_admin'"
    )['cnt'] ?? 0;
    
    // Count pending payment cases
    $pendingPayment = $db->queryOne(
        "SELECT COUNT(*) as cnt FROM cases WHERE status = 'pending_payment'"
    )['cnt'] ?? 0;
    
    // Count open cases
    $open = $db->queryOne(
        "SELECT COUNT(*) as cnt FROM cases WHERE status IN ('open', 'in_progress', 'pending_customer')"
    )['cnt'] ?? 0;
    
    // Count closed today
    $closedToday = $db->queryOne(
        "SELECT COUNT(*) as cnt FROM cases WHERE status IN ('resolved', 'closed') AND DATE(updated_at) = CURDATE()"
    )['cnt'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'pending_admin' => (int)$pendingAdmin,
            'pending_payment' => (int)$pendingPayment,
            'open' => (int)$open,
            'closed_today' => (int)$closedToday
        ]
    ]);
}
