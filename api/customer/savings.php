<?php
/**
 * Customer Savings API
 * 
 * GET  /api/customer/savings           - Get all savings accounts for customer
 * POST /api/customer/savings           - Create new savings account
 * POST /api/customer/savings?action=deposit  - Add deposit
 * GET  /api/customer/savings?id=X      - Get specific savings account
 * 
 * Database Schema (savings_accounts table):
 * - id, account_no, tenant_id, customer_id, channel_id, external_user_id, platform
 * - product_ref_id, product_name, product_price
 * - target_amount, current_amount, min_deposit_amount
 * - started_at, target_date, completed_at
 * - status (active, completed, converted, cancelled, expired, refunded)
 * - order_id, case_id, admin_notes
 * - created_at, updated_at
 * 
 * Database Schema (savings_transactions table):
 * - id, transaction_no, savings_account_id, tenant_id
 * - transaction_type, amount, balance_after, payment_method
 * - slip_image_url, slip_ocr_data, payment_amount, payment_time, sender_name
 * - status, verified_by, verified_at, rejection_reason, notes
 * - case_id, created_at, updated_at
 * 
 * @version 3.0
 * @date 2026-01-11
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
    
    // Check if savings_accounts table exists
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
            getSavingsDetail($pdo, $savings_id, $user_id);
        } else {
            getAllSavings($pdo, $user_id);
        }
    } elseif ($method === 'POST') {
        if ($action === 'deposit') {
            addDeposit($pdo, $user_id);
        } else {
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
 * Get all savings accounts for customer
 */
function getAllSavings($pdo, $user_id) {
    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    // Status filter
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    
    // Build WHERE clause - use customer_id column
    $where = ['s.customer_id = ?'];
    $params = [$user_id];
    
    if ($status) {
        $where[] = 's.status = ?';
        $params[] = $status;
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Get total count first
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM savings_accounts s WHERE $where_clause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Get savings accounts with progress
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.account_no,
            s.customer_id,
            s.product_ref_id,
            s.product_name,
            s.product_price,
            s.target_amount,
            s.current_amount,
            s.current_amount as saved_amount,
            s.min_deposit_amount,
            s.status,
            s.target_date,
            s.completed_at,
            s.started_at,
            s.admin_notes as note,
            s.created_at,
            s.updated_at,
            -- Progress percentage
            ROUND((s.current_amount / NULLIF(s.target_amount, 0)) * 100, 1) as progress_percent
        FROM savings_accounts s
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
            COALESCE(SUM(current_amount), 0) as total_saved,
            COALESCE(SUM(target_amount), 0) as total_goal,
            0 as total_pending,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
        FROM savings_accounts
        WHERE customer_id = ?
    ");
    $summaryStmt->execute([$user_id]);
    $summaryData = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    $totalSaved = (float)($summaryData['total_saved'] ?? 0);
    $totalGoal = (float)($summaryData['total_goal'] ?? 0);
    $totalPending = (float)($summaryData['total_pending'] ?? 0);
    $activeCount = (int)($summaryData['active_count'] ?? 0);
    $completedCount = (int)($summaryData['completed_count'] ?? 0);
    
    // Process each savings account
    foreach ($savings as &$s) {
        $s['saved_amount'] = (float)($s['saved_amount'] ?? 0);
        $s['current_amount'] = (float)($s['current_amount'] ?? 0);
        $s['target_amount'] = (float)($s['target_amount'] ?? 0);
        $s['pending_amount'] = 0;
        $s['remaining'] = max(0, $s['target_amount'] - $s['current_amount']);
        $s['progress_percent'] = (float)($s['progress_percent'] ?? 0);
        $s['display_name'] = $s['product_name'] ?: ('ออมเงิน #' . $s['account_no']);
        $s['name'] = $s['display_name'];
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
 * Get specific savings account detail
 */
function getSavingsDetail($pdo, $savings_id, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            s.current_amount as saved_amount,
            ROUND((s.current_amount / NULLIF(s.target_amount, 0)) * 100, 1) as progress_percent
        FROM savings_accounts s
        WHERE (s.id = ? OR s.account_no = ?) AND s.customer_id = ?
    ");
    $stmt->execute([$savings_id, $savings_id, $user_id]);
    $savings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$savings) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูล']);
        return;
    }
    
    // Get transactions/deposits
    $transactions = [];
    $txTableCheck = $pdo->query("SHOW TABLES LIKE 'savings_transactions'");
    if ($txTableCheck->rowCount() > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                t.id,
                t.transaction_no,
                t.savings_account_id,
                t.amount,
                t.balance_after,
                t.transaction_type,
                t.status,
                t.slip_image_url,
                t.slip_image_url as slip_image,
                t.payment_method,
                t.sender_name,
                t.payment_time as transfer_time,
                t.verified_by,
                t.verified_at,
                t.rejection_reason,
                t.notes as note,
                t.created_at,
                t.updated_at
            FROM savings_transactions t
            WHERE t.savings_account_id = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$savings['id']]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
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
    $savings['remaining'] = max(0, $savings['target_amount'] - $savings['current_amount']);
    $savings['progress_percent'] = (float)($savings['progress_percent'] ?? 0);
    $savings['display_name'] = $savings['product_name'] ?: ('ออมเงิน #' . $savings['account_no']);
    $savings['name'] = $savings['display_name'];
    
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
 * Create new savings account
 */
function createSavings($pdo, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $productName = trim($input['product_name'] ?? $input['name'] ?? '');
    $targetAmount = (float)($input['target_amount'] ?? 0);
    $productPrice = (float)($input['product_price'] ?? $targetAmount);
    $targetDate = $input['target_date'] ?? null;
    $note = trim($input['note'] ?? '');
    
    if (empty($productName) || $targetAmount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    // Generate account number
    $accountNo = 'SAV-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $productRefId = 'PROD-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    
    $stmt = $pdo->prepare("
        INSERT INTO savings_accounts 
        (account_no, customer_id, channel_id, external_user_id, platform, product_ref_id, 
         product_name, product_price, target_amount, current_amount, status, 
         target_date, admin_notes, created_at, updated_at)
        VALUES (?, ?, 0, ?, 'web', ?, ?, ?, ?, 0, 'active', ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $accountNo,
        $user_id,
        'user_' . $user_id,
        $productRefId,
        $productName,
        $productPrice,
        $targetAmount,
        $targetDate ?: null,
        $note ?: null
    ]);
    
    $id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'สร้างบัญชีออมสินค้าสำเร็จ',
        'data' => [
            'id' => $id,
            'account_no' => $accountNo,
            'product_ref_id' => $productRefId
        ]
    ]);
}

/**
 * Add deposit to savings account
 */
function addDeposit($pdo, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $savingsId = (int)($input['savings_id'] ?? $input['savings_account_id'] ?? 0);
    $amount = (float)($input['amount'] ?? 0);
    $note = trim($input['note'] ?? '');
    
    if ($savingsId <= 0 || $amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    // Verify ownership
    $stmt = $pdo->prepare("
        SELECT id, account_no, status, current_amount 
        FROM savings_accounts 
        WHERE id = ? AND customer_id = ?
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
    
    // Check if savings_transactions table exists
    $txTableCheck = $pdo->query("SHOW TABLES LIKE 'savings_transactions'");
    if ($txTableCheck->rowCount() === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ระบบยังไม่พร้อม กรุณาติดต่อผู้ดูแล']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Generate transaction number
        $txNo = 'SAVTX-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $balanceAfter = (float)$savings['current_amount'] + $amount;
        
        // Insert transaction
        $stmt = $pdo->prepare("
            INSERT INTO savings_transactions 
            (transaction_no, savings_account_id, transaction_type, amount, balance_after, 
             status, notes, created_at, updated_at)
            VALUES (?, ?, 'deposit', ?, ?, 'pending', ?, NOW(), NOW())
        ");
        $stmt->execute([$txNo, $savingsId, $amount, $balanceAfter, $note ?: null]);
        $txId = $pdo->lastInsertId();
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกยอดฝากเรียบร้อย รอตรวจสอบ',
            'data' => [
                'transaction_id' => $txId,
                'transaction_no' => $txNo,
                'amount' => $amount,
                'status' => 'pending'
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
