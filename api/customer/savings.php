<?php
/**
 * Customer Savings API
 * 
 * GET  /api/customer/savings           - Get all savings for customer
 * POST /api/customer/savings           - Create new savings account
 * POST /api/customer/savings?action=deposit  - Add deposit
 * GET  /api/customer/savings?id=X      - Get specific savings account
 * 
 * @version 1.0
 * @date 2026-01-07
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Database.php';

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
    $db = Database::getInstance();
    $pdo = getDB();
    
    // Get customer_id from user_id (fallback to user_id if no customers table)
    $customer_id = $user_id;
    try {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            $customer_id = $customer['id'];
        }
    } catch (PDOException $e) {
        // customers table doesn't exist, use user_id directly
        $customer_id = $user_id;
    }
    
    if ($method === 'GET') {
        if ($savings_id) {
            // Get specific savings account
            getSavingsDetail($pdo, $savings_id, $customer_id);
        } else {
            // Get all savings for customer
            getAllSavings($pdo, $customer_id);
        }
    } elseif ($method === 'POST') {
        if ($action === 'deposit') {
            // Add deposit
            addDeposit($pdo, $customer_id);
        } else {
            // Create new savings account
            createSavings($pdo, $customer_id);
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
        'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'
    ]);
}

/**
 * Get all savings accounts for customer
 */
function getAllSavings($pdo, $customer_id) {
    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    // Get total count first
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM savings_accounts WHERE customer_id = ?");
    $countStmt->execute([$customer_id]);
    $total = (int)$countStmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            s.customer_platform,
            s.customer_name,
            s.customer_avatar,
            COALESCE(
                (SELECT SUM(amount) FROM savings_transactions WHERE savings_account_id = s.id AND status = 'verified'),
                0
            ) as verified_amount,
            COALESCE(
                (SELECT SUM(amount) FROM savings_transactions WHERE savings_account_id = s.id AND status = 'pending'),
                0
            ) as pending_amount,
            ROUND((COALESCE(s.current_amount, 0) / NULLIF(s.target_amount, 0)) * 100, 1) as progress_percent
        FROM savings_accounts s
        WHERE s.customer_id = ?
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$customer_id, $limit, $offset]);
    $savings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary from all records
    $summaryStmt = $pdo->prepare("
        SELECT 
            SUM(current_amount) as total_saved,
            SUM(target_amount) as total_goal,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count
        FROM savings_accounts
        WHERE customer_id = ?
    ");
    $summaryStmt->execute([$customer_id]);
    $summaryData = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    $totalSaved = (float)($summaryData['total_saved'] ?? 0);
    $totalGoal = (float)($summaryData['total_goal'] ?? 0);
    $activeCount = (int)($summaryData['active_count'] ?? 0);
    
    foreach ($savings as &$s) {
        $s['current_amount'] = (float)($s['current_amount'] ?? 0);
        $s['target_amount'] = (float)($s['target_amount'] ?? 0);
        $s['remaining'] = max(0, $s['target_amount'] - $s['current_amount']);
        $s['progress_percent'] = (float)($s['progress_percent'] ?? 0);
        // Use product_name as display name
        $s['name'] = $s['product_name'] ?? 'ออมเงิน #' . $s['id'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $savings,
        'summary' => [
            'total_saved' => $totalSaved,
            'total_goal' => $totalGoal,
            'overall_progress' => $totalGoal > 0 ? round(($totalSaved / $totalGoal) * 100, 1) : 0,
            'active_count' => $activeCount
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
function getSavingsDetail($pdo, $savings_id, $customer_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM savings_accounts 
        WHERE id = ? AND customer_id = ?
    ");
    $stmt->execute([$savings_id, $customer_id]);
    $savings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$savings) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูล']);
        return;
    }
    
    // Get transactions/deposits
    $stmt = $pdo->prepare("
        SELECT * FROM savings_transactions 
        WHERE savings_account_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$savings_id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate pending amount
    $pendingAmount = 0;
    foreach ($transactions as $t) {
        if ($t['status'] === 'pending') {
            $pendingAmount += (float)$t['amount'];
        }
    }
    
    // Calculate progress
    $savings['current_amount'] = (float)($savings['current_amount'] ?? 0);
    $savings['target_amount'] = (float)($savings['target_amount'] ?? 0);
    $savings['pending_amount'] = $pendingAmount;
    $savings['remaining'] = max(0, $savings['target_amount'] - $savings['current_amount']);
    $savings['progress_percent'] = $savings['target_amount'] > 0 
        ? round(($savings['current_amount'] / $savings['target_amount']) * 100, 1) 
        : 0;
    $savings['name'] = $savings['product_name'] ?? 'ออมเงิน #' . $savings['id'];
    
    echo json_encode([
        'success' => true, 
        'data' => [
            'account' => $savings,
            'transactions' => $transactions
        ]
    ]);
}

/**
 * Create new savings account
 */
function createSavings($pdo, $customer_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $productName = trim($input['name'] ?? $input['product_name'] ?? '');
    $targetAmount = (float)($input['target_amount'] ?? 0);
    $targetDate = $input['target_date'] ?? null;
    $note = trim($input['note'] ?? '');
    
    if (empty($productName) || $targetAmount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    // Generate account number
    $accountNo = 'SAV-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    
    $stmt = $pdo->prepare("
        INSERT INTO savings_accounts 
        (account_no, tenant_id, customer_id, channel_id, external_user_id, platform,
         product_ref_id, product_name, product_price, target_amount, target_date, 
         status, current_amount, admin_notes, created_at)
        VALUES (?, 'default', ?, 1, '', 'web',
                ?, ?, ?, ?, ?, 
                'active', 0, ?, NOW())
    ");
    
    $stmt->execute([
        $accountNo,
        $customer_id,
        $accountNo, // product_ref_id
        $productName,
        $targetAmount,
        $targetAmount,
        $targetDate ?: null,
        $note
    ]);
    
    $id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'สร้างเป้าหมายออมเงินสำเร็จ',
        'data' => [
            'id' => $id,
            'account_no' => $accountNo
        ]
    ]);
}

/**
 * Add deposit to savings account
 */
function addDeposit($pdo, $customer_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $savingsId = (int)($input['savings_id'] ?? 0);
    $amount = (float)($input['amount'] ?? 0);
    $note = trim($input['note'] ?? '');
    
    if ($savingsId <= 0 || $amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    // Verify ownership
    $stmt = $pdo->prepare("SELECT id, status, current_amount FROM savings_accounts WHERE id = ? AND customer_id = ?");
    $stmt->execute([$savingsId, $customer_id]);
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
    
    // Generate transaction number
    $txNo = 'SAVTX-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $balanceAfter = (float)$savings['current_amount'] + $amount;
    
    $stmt = $pdo->prepare("
        INSERT INTO savings_transactions 
        (transaction_no, savings_account_id, tenant_id, transaction_type, amount, balance_after, notes, status, created_at)
        VALUES (?, ?, 'default', 'deposit', ?, ?, ?, 'pending', NOW())
    ");
    
    $stmt->execute([$txNo, $savingsId, $amount, $balanceAfter, $note]);
    
    echo json_encode([
        'success' => true,
        'message' => 'บันทึกยอดฝากเรียบร้อย รอตรวจสอบ',
        'data' => [
            'transaction_no' => $txNo,
            'amount' => $amount,
            'status' => 'pending'
        ]
    ]);
}
