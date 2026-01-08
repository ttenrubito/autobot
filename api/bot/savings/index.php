<?php
/**
 * Bot Savings API
 * 
 * Endpoints:
 * POST /api/bot/savings                  - Create new savings account
 * GET  /api/bot/savings/{id}             - Get savings account by ID
 * POST /api/bot/savings/{id}/deposit     - Add deposit to savings
 * GET  /api/bot/savings/by-user          - Get savings by external_user_id
 * GET  /api/bot/savings/{id}/status      - Get savings status
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
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));

// Expected: /api/bot/savings/{id?}/{action?}
// Also support router-provided params via $_GET
$savings_id = $_GET['savings_id'] ?? (isset($uri_parts[3]) && is_numeric($uri_parts[3]) ? (int)$uri_parts[3] : null);
$action = $_GET['action'] ?? ($uri_parts[3] ?? $uri_parts[4] ?? null);

try {
    $db = Database::getInstance();
    
    // Route to appropriate handler
    if ($method === 'POST' && !$savings_id && $action !== 'by-user') {
        // POST /api/bot/savings - Create new savings account
        createSavingsAccount($db);
    } elseif ($method === 'GET' && $action === 'by-user') {
        // GET /api/bot/savings/by-user?channel_id=X&external_user_id=Y
        getSavingsByUser($db);
    } elseif ($method === 'GET' && $savings_id && !$action) {
        // GET /api/bot/savings/{id}
        getSavingsAccount($db, $savings_id);
    } elseif ($method === 'GET' && $savings_id && $action === 'status') {
        // GET /api/bot/savings/{id}/status
        getSavingsStatus($db, $savings_id);
    } elseif ($method === 'POST' && $savings_id && $action === 'deposit') {
        // POST /api/bot/savings/{id}/deposit
        addDeposit($db, $savings_id);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    Logger::error('Bot Savings API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Generate unique account number
 */
function generateAccountNo(): string {
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "SAV-{$date}-{$random}";
}

/**
 * Generate unique transaction number
 */
function generateTransactionNo(): string {
    $date = date('Ymd');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return "SAVTX-{$date}-{$random}";
}

/**
 * Create new savings account
 * 
 * Required: channel_id, external_user_id, platform, product_ref_id, product_name, product_price
 */
function createSavingsAccount($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['channel_id', 'external_user_id', 'platform', 'product_ref_id', 'product_name', 'product_price'];
    foreach ($required as $field) {
        if (empty($input[$field]) && $input[$field] !== 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            return;
        }
    }
    
    // Check if there's already an active savings for this product and user
    $existing = $db->queryOne(
        "SELECT id, account_no, status, current_amount FROM savings_accounts 
         WHERE channel_id = ? AND external_user_id = ? AND product_ref_id = ? AND status = 'active'",
        [(int)$input['channel_id'], (string)$input['external_user_id'], $input['product_ref_id']]
    );
    
    if ($existing) {
        echo json_encode([
            'success' => true,
            'message' => 'Active savings account already exists for this product',
            'data' => $existing,
            'is_existing' => true
        ]);
        return;
    }
    
    $accountNo = generateAccountNo();
    $productPrice = (float)$input['product_price'];
    $targetAmount = (float)($input['target_amount'] ?? $productPrice);
    
    $sql = "INSERT INTO savings_accounts (
        account_no, tenant_id, customer_id, channel_id, external_user_id,
        platform, product_ref_id, product_name, product_price,
        target_amount, current_amount, min_deposit_amount,
        target_date, status, case_id, admin_notes,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, 0, ?,
        ?, 'active', ?, ?,
        NOW(), NOW()
    )";
    
    $params = [
        $accountNo,
        $input['tenant_id'] ?? 'default',
        $input['customer_id'] ?? null,
        (int)$input['channel_id'],
        (string)$input['external_user_id'],
        $input['platform'],
        $input['product_ref_id'],
        $input['product_name'],
        $productPrice,
        $targetAmount,
        $input['min_deposit_amount'] ?? null,
        $input['target_date'] ?? null,
        $input['case_id'] ?? null,
        $input['admin_notes'] ?? null
    ];
    
    $db->execute($sql, $params);
    $newId = $db->lastInsertId();
    
    Logger::info('Savings account created', [
        'savings_id' => $newId,
        'account_no' => $accountNo,
        'product_ref_id' => $input['product_ref_id'],
        'target_amount' => $targetAmount
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Savings account created successfully',
        'data' => [
            'id' => $newId,
            'account_no' => $accountNo,
            'product_ref_id' => $input['product_ref_id'],
            'product_name' => $input['product_name'],
            'product_price' => $productPrice,
            'target_amount' => $targetAmount,
            'current_amount' => 0,
            'remaining' => $targetAmount,
            'status' => 'active'
        ],
        'is_existing' => false
    ]);
}

/**
 * Get savings account by ID
 */
function getSavingsAccount($db, int $savingsId) {
    $account = $db->queryOne("SELECT * FROM savings_accounts WHERE id = ?", [$savingsId]);
    
    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Savings account not found']);
        return;
    }
    
    // Get transactions
    $transactions = $db->queryAll(
        "SELECT * FROM savings_transactions WHERE savings_account_id = ? ORDER BY created_at DESC",
        [$savingsId]
    );
    
    foreach ($transactions as &$tx) {
        $tx['slip_ocr_data'] = $tx['slip_ocr_data'] ? json_decode($tx['slip_ocr_data'], true) : null;
    }
    
    $account['transactions'] = $transactions;
    $account['remaining'] = (float)$account['target_amount'] - (float)$account['current_amount'];
    $account['progress_percent'] = $account['target_amount'] > 0 
        ? round(($account['current_amount'] / $account['target_amount']) * 100, 2) 
        : 0;
    
    echo json_encode([
        'success' => true,
        'data' => $account
    ]);
}

/**
 * Get savings by user
 */
function getSavingsByUser($db) {
    $channelId = $_GET['channel_id'] ?? null;
    $externalUserId = $_GET['external_user_id'] ?? null;
    $status = $_GET['status'] ?? null;
    
    if (!$channelId || !$externalUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing channel_id or external_user_id']);
        return;
    }
    
    $sql = "SELECT * FROM savings_accounts WHERE channel_id = ? AND external_user_id = ?";
    $params = [(int)$channelId, (string)$externalUserId];
    
    if ($status) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $accounts = $db->queryAll($sql, $params);
    
    foreach ($accounts as &$account) {
        $account['remaining'] = (float)$account['target_amount'] - (float)$account['current_amount'];
        $account['progress_percent'] = $account['target_amount'] > 0 
            ? round(($account['current_amount'] / $account['target_amount']) * 100, 2) 
            : 0;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $accounts,
        'count' => count($accounts)
    ]);
}

/**
 * Get savings status (quick status check)
 */
function getSavingsStatus($db, int $savingsId) {
    $account = $db->queryOne(
        "SELECT id, account_no, product_name, target_amount, current_amount, status FROM savings_accounts WHERE id = ?",
        [$savingsId]
    );
    
    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Savings account not found']);
        return;
    }
    
    $remaining = (float)$account['target_amount'] - (float)$account['current_amount'];
    $progressPercent = $account['target_amount'] > 0 
        ? round(($account['current_amount'] / $account['target_amount']) * 100, 2) 
        : 0;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $account['id'],
            'account_no' => $account['account_no'],
            'product_name' => $account['product_name'],
            'target_amount' => (float)$account['target_amount'],
            'current_amount' => (float)$account['current_amount'],
            'remaining' => $remaining,
            'progress_percent' => $progressPercent,
            'status' => $account['status'],
            'is_completed' => $remaining <= 0
        ]
    ]);
}

/**
 * Add deposit to savings account
 * 
 * Required: amount
 * Optional: slip_image_url, payment_method, payment_time, sender_name, case_id
 */
function addDeposit($db, int $savingsId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['amount']) || (float)$input['amount'] <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid amount']);
        return;
    }
    
    // Get savings account
    $account = $db->queryOne("SELECT * FROM savings_accounts WHERE id = ?", [$savingsId]);
    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Savings account not found']);
        return;
    }
    
    if ($account['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Savings account is not active', 'status' => $account['status']]);
        return;
    }
    
    $amount = (float)$input['amount'];
    $currentAmount = (float)$account['current_amount'];
    $newBalance = $currentAmount + $amount;
    $targetAmount = (float)$account['target_amount'];
    
    // Create transaction
    $transactionNo = generateTransactionNo();
    $slipOcrData = isset($input['slip_ocr_data']) ? json_encode($input['slip_ocr_data']) : null;
    
    $txSql = "INSERT INTO savings_transactions (
        transaction_no, savings_account_id, tenant_id, transaction_type,
        amount, balance_after, payment_method, slip_image_url, slip_ocr_data,
        payment_amount, payment_time, sender_name, status, case_id,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?, 'deposit',
        ?, ?, ?, ?, ?,
        ?, ?, ?, 'pending', ?,
        NOW(), NOW()
    )";
    
    $txParams = [
        $transactionNo,
        $savingsId,
        $account['tenant_id'],
        $amount,
        $newBalance,
        $input['payment_method'] ?? 'bank_transfer',
        $input['slip_image_url'] ?? null,
        $slipOcrData,
        $input['payment_amount'] ?? $amount,
        $input['payment_time'] ?? null,
        $input['sender_name'] ?? null,
        $input['case_id'] ?? null
    ];
    
    $db->execute($txSql, $txParams);
    $transactionId = $db->lastInsertId();
    
    // Note: We don't update current_amount yet - that happens after admin verification
    // But if auto_verify is enabled, we can update immediately
    $autoVerify = $input['auto_verify'] ?? false;
    $newStatus = $account['status'];
    
    if ($autoVerify) {
        // Update account balance
        $db->execute(
            "UPDATE savings_accounts SET current_amount = ?, updated_at = NOW() WHERE id = ?",
            [$newBalance, $savingsId]
        );
        
        // Update transaction status
        $db->execute(
            "UPDATE savings_transactions SET status = 'verified', verified_at = NOW() WHERE id = ?",
            [$transactionId]
        );
        
        // Check if target reached
        if ($newBalance >= $targetAmount) {
            $db->execute(
                "UPDATE savings_accounts SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$savingsId]
            );
            $newStatus = 'completed';
        }
    }
    
    $remaining = $targetAmount - ($autoVerify ? $newBalance : $currentAmount);
    $progressPercent = $targetAmount > 0 
        ? round((($autoVerify ? $newBalance : $currentAmount) / $targetAmount) * 100, 2) 
        : 0;
    
    Logger::info('Savings deposit added', [
        'savings_id' => $savingsId,
        'transaction_id' => $transactionId,
        'amount' => $amount,
        'auto_verify' => $autoVerify
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => $autoVerify ? 'Deposit verified and added' : 'Deposit submitted, pending verification',
        'data' => [
            'transaction_id' => $transactionId,
            'transaction_no' => $transactionNo,
            'amount' => $amount,
            'balance_after' => $autoVerify ? $newBalance : $currentAmount,
            'remaining' => $remaining,
            'progress_percent' => $progressPercent,
            'status' => $autoVerify ? 'verified' : 'pending',
            'account_status' => $newStatus,
            'is_completed' => $newStatus === 'completed'
        ]
    ]);
}
