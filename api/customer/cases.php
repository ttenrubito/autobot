<?php
/**
 * Customer Cases API
 * 
 * GET  /api/customer/cases           - Get all cases for customer
 * GET  /api/customer/cases?id=X      - Get specific case
 * POST /api/customer/cases           - Create new case
 * 
 * @version 1.0
 * @date 2026-01-07
 */

// Ensure output is sent
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

// Debug: Log incoming auth attempt
$debugHeaders = getallheaders();
$authHeader = $debugHeaders['Authorization'] ?? $debugHeaders['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT_FOUND';
error_log("[Customer Cases API] Auth header: " . substr($authHeader, 0, 50) . "...");

// Verify authentication
$auth = verifyToken();
if (!$auth['valid']) {
    error_log("[Customer Cases API] Auth failed: " . json_encode($auth));
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'Unauthorized',
        'debug' => [
            'auth_header_present' => $authHeader !== 'NOT_FOUND',
            'error' => $auth['error'] ?? 'Token invalid or expired'
        ]
    ]);
    ob_end_flush();
    exit;
}

$user_id = $auth['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$case_id = $_GET['id'] ?? null;

try {
    $pdo = getDB();
    
    // ✅ FIX: Get channel_ids owned by this user (not tenant_id)
    // Each user (shop owner) can only see cases from their own channels
    $channelStmt = $pdo->prepare("SELECT id FROM customer_channels WHERE user_id = ? AND is_deleted = 0");
    $channelStmt->execute([$user_id]);
    $userChannels = $channelStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Also get tenant_id for backward compatibility
    $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_id = $user['tenant_id'] ?? 'default';
    
    if ($method === 'GET') {
        if ($case_id) {
            // Get specific case
            getCaseDetail($pdo, $case_id, $user_id, $userChannels);
        } else {
            // Get all cases for this user's channels
            getAllCases($pdo, $user_id, $userChannels);
        }
    } elseif ($method === 'POST') {
        // Create new case
        createCase($pdo, $tenant_id, $user_id);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Customer Cases API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'
    ]);
}

/**
 * Get all cases for user's channels (เจ้าของร้านดู cases ของ channel ตัวเอง)
 * OR cases created by this user directly (customer portal)
 */
function getAllCases($pdo, $user_id, $userChannels) {
    $status = $_GET['status'] ?? null;
    $priority = $_GET['priority'] ?? null;
    
    // ✅ NEW: Date filter - default to today for daily monitoring
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $datePreset = $_GET['date_preset'] ?? 'today'; // today, week, month, all
    
    // Apply date preset if no explicit dates
    if (!$dateFrom && !$dateTo) {
        switch ($datePreset) {
            case 'today':
                $dateFrom = date('Y-m-d');
                $dateTo = date('Y-m-d');
                break;
            case 'week':
                $dateFrom = date('Y-m-d', strtotime('-7 days'));
                $dateTo = date('Y-m-d');
                break;
            case 'month':
                $dateFrom = date('Y-m-d', strtotime('-30 days'));
                $dateTo = date('Y-m-d');
                break;
            case 'all':
                // No date filter
                break;
        }
    }
    
    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    // ✅ FIX: Filter by user's channel_ids OR user_id (for customer portal cases)
    $where = [];
    $params = [];
    
    if (!empty($userChannels)) {
        $channelPlaceholders = implode(',', array_fill(0, count($userChannels), '?'));
        $where[] = "(c.channel_id IN ({$channelPlaceholders}) OR c.user_id = ?)";
        $params = array_merge($userChannels, [$user_id]);
    } else {
        // User has no channels - only show their own cases (customer portal)
        $where[] = "c.user_id = ?";
        $params[] = $user_id;
    }
    
    // ✅ NEW: Apply date filters
    if ($dateFrom) {
        $where[] = 'DATE(c.created_at) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $where[] = 'DATE(c.created_at) <= ?';
        $params[] = $dateTo;
    }
    
    if ($status) {
        $where[] = 'c.status = ?';
        $params[] = $status;
    }
    
    if ($priority) {
        $where[] = 'c.priority = ?';
        $params[] = $priority;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get total count first
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM cases c WHERE {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Get paginated results - JOIN with customer_profiles to get name/avatar
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.case_no,
            c.case_type,
            c.platform,
            c.external_user_id,
            c.subject,
            c.description,
            c.status,
            c.priority,
            c.product_ref_id,
            c.order_id,
            c.payment_id,
            c.savings_account_id,
            c.slots,
            c.resolution_type,
            c.resolution_notes,
            c.created_at,
            c.updated_at,
            c.resolved_at,
            COALESCE(cp.display_name, c.customer_name, CONCAT('ลูกค้า ', RIGHT(c.external_user_id, 6))) as customer_name,
            COALESCE(cp.avatar_url, cp.profile_pic_url, c.customer_avatar) as customer_avatar,
            c.platform as customer_platform,
            o.order_no,
            o.product_name as order_product_name,
            u.full_name as assigned_to_name
        FROM cases c
        LEFT JOIN customer_profiles cp ON c.external_user_id = cp.platform_user_id AND c.platform = cp.platform
        LEFT JOIN orders o ON c.order_id = o.id
        LEFT JOIN users u ON c.assigned_to = u.id
        WHERE {$whereClause}
        ORDER BY c.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ✅ FIX: Calculate summary using same filter logic (channels OR user_id + date filter)
    // Rebuild params without pagination for summary query
    $summaryParams = [];
    $summaryWhereParts = [];
    
    if (!empty($userChannels)) {
        $channelPlaceholders = implode(',', array_fill(0, count($userChannels), '?'));
        $summaryWhereParts[] = "(channel_id IN ({$channelPlaceholders}) OR user_id = ?)";
        $summaryParams = array_merge($userChannels, [$user_id]);
    } else {
        $summaryWhereParts[] = "user_id = ?";
        $summaryParams = [$user_id];
    }
    
    // ✅ Apply same date filter to summary
    if ($dateFrom) {
        $summaryWhereParts[] = 'DATE(created_at) >= ?';
        $summaryParams[] = $dateFrom;
    }
    if ($dateTo) {
        $summaryWhereParts[] = 'DATE(created_at) <= ?';
        $summaryParams[] = $dateTo;
    }
    
    $summaryWhere = implode(' AND ', $summaryWhereParts);
    
    $summaryStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM cases 
        WHERE {$summaryWhere}
        GROUP BY status
    ");
    $summaryStmt->execute($summaryParams);
    $statusCounts = $summaryStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // ✅ Calculate filtered total for summary
    $filteredTotal = array_sum($statusCounts);
    
    $summary = [
        'total' => $filteredTotal,  // ✅ Use filtered total instead of pagination total
        'open' => (int)($statusCounts['open'] ?? 0),
        'in_progress' => (int)($statusCounts['in_progress'] ?? 0),
        'pending_admin' => (int)($statusCounts['pending_admin'] ?? 0),
        'pending_customer' => (int)($statusCounts['pending_customer'] ?? 0),
        'resolved' => (int)($statusCounts['resolved'] ?? 0),
        'cancelled' => (int)($statusCounts['cancelled'] ?? 0),
        'date_range' => [
            'from' => $dateFrom,
            'to' => $dateTo,
            'preset' => $datePreset
        ]
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'cases' => $cases,
            'summary' => $summary,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]
    ]);
}

/**
 * Get specific case detail
 * ✅ FIX: Use channel_id OR user_id to filter cases
 */
function getCaseDetail($pdo, $case_id, $user_id, $userChannels) {
    // Build security filter: case must belong to user's channels OR be created by user
    if (!empty($userChannels)) {
        $channelPlaceholders = implode(',', array_fill(0, count($userChannels), '?'));
        $securityWhere = "(c.channel_id IN ({$channelPlaceholders}) OR c.user_id = ?)";
        $params = array_merge([$case_id], $userChannels, [$user_id]);
    } else {
        $securityWhere = "c.user_id = ?";
        $params = [$case_id, $user_id];
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            COALESCE(cp.display_name, cp.full_name, c.customer_name, CONCAT('ลูกค้า ', RIGHT(c.external_user_id, 6))) as customer_name,
            COALESCE(cp.avatar_url, cp.profile_pic_url, c.customer_avatar) as customer_avatar,
            c.platform as customer_platform,
            o.order_no,
            o.product_name as order_product_name,
            o.total_amount as order_amount,
            p.payment_no,
            p.amount as payment_amount,
            sa.account_no as savings_account_no,
            sa.product_name as savings_product_name,
            u.full_name as assigned_to_name
        FROM cases c
        LEFT JOIN customer_profiles cp ON c.external_user_id = cp.platform_user_id AND c.platform = cp.platform
        LEFT JOIN orders o ON c.order_id = o.id
        LEFT JOIN payments p ON c.payment_id = p.id
        LEFT JOIN savings_accounts sa ON c.savings_account_id = sa.id
        LEFT JOIN users u ON c.assigned_to = u.id
        WHERE c.id = ? AND {$securityWhere}
    ");
    $stmt->execute($params);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$case) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        return;
    }
    
    // Get case history/messages if available
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                message_type,
                message_content,
                sender_type,
                created_at
            FROM case_messages
            WHERE case_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$case_id]);
        $case['messages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // case_messages table might not exist
        $case['messages'] = [];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $case
    ]);
}

/**
 * Create new case
 */
function createCase($pdo, $tenant_id, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        return;
    }
    
    $subject = trim($input['subject'] ?? '');
    $description = trim($input['description'] ?? '');
    $priority = $input['priority'] ?? 'normal';
    $case_type = $input['case_type'] ?? 'general_inquiry';
    $order_id = $input['order_id'] ?? null;
    $payment_id = $input['payment_id'] ?? null;
    
    if (empty($subject)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Subject is required']);
        return;
    }
    
    // Validate priority
    $valid_priorities = ['low', 'normal', 'high', 'urgent'];
    if (!in_array($priority, $valid_priorities)) {
        $priority = 'normal';
    }
    
    // Validate case_type
    $valid_types = ['product_inquiry', 'payment_full', 'payment_installment', 'payment_savings', 'general_inquiry', 'complaint', 'other'];
    if (!in_array($case_type, $valid_types)) {
        $case_type = 'general_inquiry';
    }
    
    // Generate case number
    $case_no = 'CASE-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Get channel_id for this customer from customer_channels (not customer_services)
    $channel_id = null;
    try {
        $stmt = $pdo->prepare("SELECT id FROM customer_channels WHERE user_id = ? AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$user_id]);
        $channel = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($channel) {
            $channel_id = $channel['id'];
        }
    } catch (PDOException $e) {
        // Ignore if table doesn't exist
    }
    
    // Default channel_id to 1 if not found (required field)
    if (!$channel_id) {
        $channel_id = 1;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO cases (
            case_no, tenant_id, channel_id, user_id, external_user_id, platform,
            case_type, subject, description, priority, status,
            order_id, payment_id, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, 'web',
            ?, ?, ?, ?, 'open',
            ?, ?, NOW()
        )
    ");
    
    $stmt->execute([
        $case_no,
        $tenant_id,
        $channel_id,
        $user_id,  // ✅ NEW: Store user_id for filtering
        'web_user_' . $user_id,
        $case_type,
        $subject,
        $description,
        $priority,
        $order_id,
        $payment_id
    ]);
    
    $new_case_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'สร้าง case สำเร็จ',
        'data' => [
            'id' => $new_case_id,
            'case_no' => $case_no
        ]
    ]);
}
