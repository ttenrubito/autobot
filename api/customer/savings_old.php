<?php
/**
 * Customer Savings API
 * 
 * GET  /api/customer/savings           - Get all savings goals for customer
 * POST /api/customer/savings           - Create new savings goal
 * POST /api/customer/savings?action=deposit  - Add deposit
 * GET  /api/customer/savings?id=X      - Get specific savings goal
 * 
 * Database Schema (savings_goals table):
 * - id, user_id, customer_profile_id
 * - product_ref_id, product_name, name
 * - target_amount, saved_amount, pending_amount
 * - status (active, completed, cancelled, paused)
 * - target_date, completed_at
 * - customer_name, customer_phone, note
 * - created_at, updated_at
 * 
 * Database Schema (savings_transactions table):
 * - id, savings_goal_id, customer_profile_id
 * - amount, transaction_type (deposit, withdrawal, adjustment)
 * - status (pending, verified, rejected)
 * - slip_image_url, ocr_data, payment_ref, sender_name, transfer_time
 * - verified_by, verified_at, rejection_reason
 * - note, case_id, created_at, updated_at
 * 
 * @version 2.0
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
$savings_id = $_GET['id'] ?? null;

try {
    $pdo = getDB();
    
    // Check if savings_accounts table exists (we use this instead of savings_goals)
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'savings_accounts'");
    if ($tableCheck->rowCount() === 0) {
        // Table doesn't exist yet - return empty data with message
        echo json_encode([
            'success' => true,
            'data' => [],
            'summary' => [
                'total_saved' => 0,
                'total_goal' => 0,
                'total_pending' => 0,
                'active_count' => 0,
                'completed_count' => 0
            ],
            'pagination' => [
                'page' => 1,
                'limit' => 20,
                'total' => 0,
                'total_pages' => 0
            ],
            'message' => 'ระบบออมเงินยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ'
        ]);
        exit;
    }
    
    if ($method === 'GET') {
        if ($savings_id) {
            // Get specific savings goal
            getSavingsDetail($pdo, $savings_id, $user_id);
        } else {
            // Get all savings for customer
            getAllSavings($pdo, $user_id);
        }
    } elseif ($method === 'POST') {
        if ($action === 'deposit') {
            // Add deposit
            addDeposit($pdo, $user_id);
        } else {
            // Create new savings goal
            createSavings($pdo, $user_id);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Customer Savings API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่',
        'error' => $e->getMessage()
    ]);
}

/**
 * Get all savings goals for customer
 */
function getAllSavings($pdo, $user_id) {
    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    // Status filter
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    
    // Build WHERE clause
    $where = ['s.user_id = ?'];
    $params = [$user_id];
    
    if ($status) {
        $where[] = 's.status = ?';
        $params[] = $status;
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Get total count first
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM savings_goals s WHERE $where_clause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Get savings goals with transaction summary
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.user_id,
            s.customer_profile_id,
            s.product_ref_id,
            s.product_name,
            s.name,
            s.target_amount,
            s.saved_amount,
            s.saved_amount as current_amount,
            s.pending_amount,
            s.status,
            s.target_date,
            s.completed_at,
            s.customer_name,
            s.customer_phone,
            s.note,
            s.created_at,
            s.updated_at,
            -- Calculate verified amount from transactions
            COALESCE(
                (SELECT SUM(amount) FROM savings_transactions 
                 WHERE savings_goal_id = s.id AND status = 'verified' AND transaction_type = 'deposit'),
                0
            ) as verified_amount,
            -- Calculate pending amount from transactions
            COALESCE(
                (SELECT SUM(amount) FROM savings_transactions 
                 WHERE savings_goal_id = s.id AND status = 'pending' AND transaction_type = 'deposit'),
                0
            ) as pending_tx_amount,
            -- Progress percentage
            ROUND((s.saved_amount / NULLIF(s.target_amount, 0)) * 100, 1) as progress_percent
        FROM savings_goals s
        WHERE $where_clause
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $savings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary from all records for this user
    $summaryStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(saved_amount), 0) as total_saved,
            COALESCE(SUM(target_amount), 0) as total_goal,
            COALESCE(SUM(pending_amount), 0) as total_pending,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
        FROM savings_goals
        WHERE user_id = ?
    ");
    $summaryStmt->execute([$user_id]);
    $summaryData = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    $totalSaved = (float)($summaryData['total_saved'] ?? 0);
    $totalGoal = (float)($summaryData['total_goal'] ?? 0);
    $totalPending = (float)($summaryData['total_pending'] ?? 0);
    $activeCount = (int)($summaryData['active_count'] ?? 0);
    $completedCount = (int)($summaryData['completed_count'] ?? 0);
    
    // Process each savings goal
    foreach ($savings as &$s) {
        $s['saved_amount'] = (float)($s['saved_amount'] ?? 0);
        $s['current_amount'] = (float)($s['current_amount'] ?? 0);
        $s['target_amount'] = (float)($s['target_amount'] ?? 0);
        $s['pending_amount'] = (float)($s['pending_amount'] ?? 0);
        $s['remaining'] = max(0, $s['target_amount'] - $s['saved_amount']);
        $s['progress_percent'] = (float)($s['progress_percent'] ?? 0);
        // Use product_name or name as display name
        $s['display_name'] = $s['product_name'] ?: ($s['name'] ?: 'ออมเงิน #' . $s['id']);
    }
    unset($s);
    
    echo json_encode([
        'success' => true,
        'data' => $savings,
        'summary' => [
            'total_saved' => $totalSaved,
            'total_goal' => $totalGoal,
            'total_pending' => $totalPending,
            'overall_progress' => $totalGoal > 0 ? round(($totalSaved / $totalGoal) * 100, 1) : 0,
            'active_count' => $activeCount,
            'completed_count' => $completedCount
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
 * Get specific savings goal detail
 */
function getSavingsDetail($pdo, $savings_id, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            s.saved_amount as current_amount
        FROM savings_goals s
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$savings_id, $user_id]);
    $savings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$savings) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูล']);
        return;
    }
    
    // Get transactions/deposits
    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.savings_goal_id,
            t.amount,
            t.transaction_type,
            t.status,
            t.slip_image_url,
            t.slip_image_url as slip_image,
            t.payment_ref,
            t.sender_name,
            t.transfer_time,
            t.verified_by,
            t.verified_at,
            t.rejection_reason,
            t.note,
            t.created_at,
            t.updated_at
        FROM savings_transactions t
        WHERE t.savings_goal_id = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$savings_id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate pending amount from transactions
    $pendingAmount = 0;
    foreach ($transactions as $t) {
        if ($t['status'] === 'pending' && $t['transaction_type'] === 'deposit') {
            $pendingAmount += (float)$t['amount'];
        }
    }
    
    // Calculate progress
    $savings['saved_amount'] = (float)($savings['saved_amount'] ?? 0);
    $savings['current_amount'] = (float)($savings['current_amount'] ?? 0);
    $savings['target_amount'] = (float)($savings['target_amount'] ?? 0);
    $savings['pending_amount'] = $pendingAmount;
    $savings['remaining'] = max(0, $savings['target_amount'] - $savings['saved_amount']);
    $savings['progress_percent'] = $savings['target_amount'] > 0 
        ? round(($savings['saved_amount'] / $savings['target_amount']) * 100, 1) 
        : 0;
    $savings['display_name'] = $savings['product_name'] ?: ($savings['name'] ?: 'ออมเงิน #' . $savings['id']);
    
    echo json_encode([
        'success' => true, 
        'data' => [
            'account' => $savings,
            'goal' => $savings,
            'transactions' => $transactions
        ]
    ]);
}

/**
 * Create new savings goal
 */
function createSavings($pdo, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($input['name'] ?? '');
    $productName = trim($input['product_name'] ?? $name);
    $targetAmount = (float)($input['target_amount'] ?? 0);
    $targetDate = $input['target_date'] ?? null;
    $note = trim($input['note'] ?? '');
    
    if (empty($name) || $targetAmount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    // Generate reference ID
    $refId = 'SAV-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    
    $stmt = $pdo->prepare("
        INSERT INTO savings_goals 
        (user_id, product_ref_id, product_name, name, target_amount, saved_amount, 
         pending_amount, status, target_date, note, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, 0, 'active', ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $user_id,
        $refId,
        $productName,
        $name,
        $targetAmount,
        $targetDate ?: null,
        $note ?: null
    ]);
    
    $id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'สร้างเป้าหมายออมเงินสำเร็จ',
        'data' => [
            'id' => $id,
            'product_ref_id' => $refId
        ]
    ]);
}

/**
 * Add deposit to savings goal
 */
function addDeposit($pdo, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $savingsId = (int)($input['savings_id'] ?? $input['savings_goal_id'] ?? 0);
    $amount = (float)($input['amount'] ?? 0);
    $note = trim($input['note'] ?? '');
    
    if ($savingsId <= 0 || $amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    // Verify ownership
    $stmt = $pdo->prepare("
        SELECT id, status, saved_amount, pending_amount 
        FROM savings_goals 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$savingsId, $user_id]);
    $savings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$savings) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลบัญชีออม']);
        return;
    }
    
    if ($savings['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'บัญชีออมนี้ไม่สามารถฝากเพิ่มได้']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Insert transaction
        $stmt = $pdo->prepare("
            INSERT INTO savings_transactions 
            (savings_goal_id, amount, transaction_type, status, note, created_at, updated_at)
            VALUES (?, ?, 'deposit', 'pending', ?, NOW(), NOW())
        ");
        $stmt->execute([$savingsId, $amount, $note ?: null]);
        $txId = $pdo->lastInsertId();
        
        // Update pending amount in savings_goals
        $stmt = $pdo->prepare("
            UPDATE savings_goals 
            SET pending_amount = pending_amount + ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$amount, $savingsId]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกยอดฝากเรียบร้อย รอตรวจสอบ',
            'data' => [
                'transaction_id' => $txId,
                'amount' => $amount,
                'status' => 'pending'
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
