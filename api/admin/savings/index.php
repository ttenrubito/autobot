<?php
/**
 * Admin Savings API
 * 
 * Endpoints:
 * GET  /api/admin/savings                      - List all savings accounts (with filters)
 * GET  /api/admin/savings/{id}                 - Get savings account details
 * POST /api/admin/savings/{id}/approve-deposit - Approve pending deposit
 * POST /api/admin/savings/{id}/cancel          - Cancel savings account
 * POST /api/admin/savings/{id}/complete        - Mark savings as completed
 * 
 * @version 2.0
 * @date 2026-01-06
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Logger.php';
require_once __DIR__ . '/../../../includes/AdminAuth.php';
require_once __DIR__ . '/../../../includes/services/PushNotificationService.php';

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$savingsId = $_GET['savings_id'] ?? null;
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
    if ($method === 'GET' && !$savingsId) {
        // Check if stats requested
        if (isset($_GET['stats']) && $_GET['stats'] === '1') {
            getSavingsStats($db);
        } else {
            // GET /api/admin/savings - List savings accounts
            listSavingsAccounts($db);
        }
    } elseif ($method === 'GET' && $savingsId) {
        // GET /api/admin/savings/{id}
        getSavingsAccount($db, (int)$savingsId);
    } elseif ($method === 'POST' && $savingsId && $action === 'approve-deposit') {
        // POST /api/admin/savings/{id}/approve-deposit
        approveDeposit($db, (int)$savingsId, $adminId);
    } elseif ($method === 'POST' && $savingsId && $action === 'cancel') {
        // POST /api/admin/savings/{id}/cancel
        cancelSavings($db, (int)$savingsId, $adminId);
    } elseif ($method === 'POST' && $savingsId && $action === 'complete') {
        // POST /api/admin/savings/{id}/complete
        completeSavings($db, (int)$savingsId, $adminId);
    } elseif ($method === 'PUT' && $savingsId) {
        // PUT /api/admin/savings/{id} - Update status
        updateSavingsStatus($db, $savingsId, $adminId);
    } elseif ($method === 'POST' && $savingsId && $action === 'deposit') {
        // POST /api/admin/savings/{id}/deposit - Manual deposit
        manualDeposit($db, $savingsId, $adminId);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    Logger::error('Admin Savings API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * List savings accounts with filters
 * 
 * Query params:
 * - status: active, completed, cancelled, expired
 * - platform: line, facebook  
 * - search: search in account_no, external_user_id
 * - pending_deposits: 1 to show only accounts with pending deposits
 * - limit, offset: pagination
 */
function listSavingsAccounts($db) {
    $status = $_GET['status'] ?? null;
    $platform = $_GET['platform'] ?? null;
    $search = $_GET['search'] ?? null;
    $pendingDeposits = $_GET['pending_deposits'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $where = [];
    $params = [];
    
    if ($status) {
        $where[] = "sa.status = ?";
        $params[] = $status;
    }
    
    if ($platform) {
        $where[] = "sa.platform = ?";
        $params[] = $platform;
    }
    
    if ($search) {
        $where[] = "(sa.account_no LIKE ? OR sa.external_user_id LIKE ? OR sa.product_ref_id LIKE ?)";
        $searchPattern = "%{$search}%";
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // For pending deposits, we need a subquery
    $havingClause = '';
    if ($pendingDeposits) {
        $havingClause = 'HAVING pending_count > 0';
    }
    
    // Count total
    $countSql = "SELECT COUNT(*) as total FROM savings_accounts sa {$whereClause}";
    $total = $db->queryOne($countSql, $params)['total'] ?? 0;
    
    // Get savings accounts with pending deposit counts
    $sql = "SELECT 
                sa.*,
                ch.name as channel_name,
                (SELECT COUNT(*) FROM savings_transactions st 
                 WHERE st.savings_account_id = sa.id AND st.status = 'pending') as pending_count,
                (SELECT SUM(amount) FROM savings_transactions st 
                 WHERE st.savings_account_id = sa.id AND st.status = 'pending') as pending_amount
            FROM savings_accounts sa
            LEFT JOIN customer_channels ch ON sa.channel_id = ch.id
            {$whereClause}
            {$havingClause}
            ORDER BY 
                FIELD(sa.status, 'active', 'completed', 'cancelled', 'expired'),
                sa.updated_at DESC
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $accounts = $db->queryAll($sql, $params);
    
    // Calculate progress for each account
    foreach ($accounts as &$account) {
        $account['progress_percent'] = $account['target_amount'] > 0 
            ? min(100, round(($account['current_amount'] / $account['target_amount']) * 100, 2))
            : 0;
        $account['remaining_amount'] = max(0, $account['target_amount'] - $account['current_amount']);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $accounts,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total
        ]
    ]);
}

/**
 * Get savings account details with transactions
 */
function getSavingsAccount($db, int $savingsId) {
    $account = $db->queryOne("SELECT sa.*, ch.name as channel_name 
                              FROM savings_accounts sa
                              LEFT JOIN customer_channels ch ON sa.channel_id = ch.id
                              WHERE sa.id = ?", [$savingsId]);
    
    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Savings account not found']);
        return;
    }
    
    // Calculate progress
    $account['progress_percent'] = $account['target_amount'] > 0 
        ? min(100, round(($account['current_amount'] / $account['target_amount']) * 100, 2))
        : 0;
    $account['remaining_amount'] = max(0, $account['target_amount'] - $account['current_amount']);
    
    // Get all transactions
    $transactions = $db->queryAll(
        "SELECT st.*, p.slip_image_url, p.slip_verified_at 
         FROM savings_transactions st
         LEFT JOIN payments p ON st.payment_id = p.id
         WHERE st.savings_account_id = ?
         ORDER BY st.created_at DESC",
        [$savingsId]
    );
    
    $account['transactions'] = $transactions;
    
    // Get related case if exists
    $case = $db->queryOne(
        "SELECT * FROM cases WHERE savings_account_id = ? ORDER BY created_at DESC LIMIT 1",
        [$savingsId]
    );
    $account['case'] = $case;
    
    // Get related product info from orders if linked
    if ($account['order_id']) {
        $order = $db->queryOne(
            "SELECT * FROM orders WHERE id = ?",
            [$account['order_id']]
        );
        $account['order'] = $order;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $account
    ]);
}

/**
 * Approve a pending deposit transaction
 */
function approveDeposit($db, int $savingsId, ?int $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $transactionId = $input['transaction_id'] ?? null;
    
    if (!$transactionId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'transaction_id is required']);
        return;
    }
    
    // Get the transaction
    $transaction = $db->queryOne(
        "SELECT * FROM savings_transactions WHERE id = ? AND savings_account_id = ?",
        [$transactionId, $savingsId]
    );
    
    if (!$transaction) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Transaction not found']);
        return;
    }
    
    if ($transaction['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Transaction is not pending']);
        return;
    }
    
    // Get current savings account
    $account = $db->queryOne("SELECT * FROM savings_accounts WHERE id = ?", [$savingsId]);
    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Savings account not found']);
        return;
    }
    
    // Approve the transaction
    $db->execute(
        "UPDATE savings_transactions SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ?",
        [$adminId, $transactionId]
    );
    
    // Update savings account balance
    $newAmount = $account['current_amount'] + $transaction['amount'];
    $isCompleted = $newAmount >= $account['target_amount'];
    
    $db->execute(
        "UPDATE savings_accounts SET 
            current_amount = ?, 
            status = ?,
            updated_at = NOW()
         WHERE id = ?",
        [
            $newAmount,
            $isCompleted ? 'completed' : 'active',
            $savingsId
        ]
    );
    
    // Also update the related payment if exists
    if ($transaction['payment_id']) {
        $db->execute(
            "UPDATE payments SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ?",
            [$adminId, $transaction['payment_id']]
        );
    }
    
    // Calculate new progress
    $progressPercent = $account['target_amount'] > 0 
        ? min(100, round(($newAmount / $account['target_amount']) * 100, 2))
        : 0;
    
    // Send push notification
    if ($account['platform'] && $account['external_user_id']) {
        try {
            $pushService = new PushNotificationService($db);
            $notificationType = $isCompleted ? 'savings_goal_reached' : 'savings_deposit_verified';
            
            if ($isCompleted) {
                $pushService->sendSavingsGoalReached(
                    $account['platform'],
                    $account['external_user_id'],
                    [
                        'product_name' => $account['product_name'],
                        'saved_amount' => $newAmount,
                        'target_amount' => $account['target_amount']
                    ],
                    $account['channel_id'] ? (int)$account['channel_id'] : null
                );
            } else {
                $pushService->sendSavingsDepositVerified(
                    $account['platform'],
                    $account['external_user_id'],
                    [
                        'amount' => $transaction['amount'],
                        'saved_amount' => $newAmount,
                        'target_amount' => $account['target_amount'],
                        'remaining_amount' => max(0, $account['target_amount'] - $newAmount),
                        'progress' => $progressPercent,
                        'product_name' => $account['product_name']
                    ],
                    $account['channel_id'] ? (int)$account['channel_id'] : null
                );
            }
            
            Logger::info('Savings notification sent', [
                'savings_id' => $savingsId,
                'type' => $notificationType
            ]);
        } catch (Exception $e) {
            Logger::error('Failed to send savings notification: ' . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => $isCompleted ? 'Deposit approved. Savings completed!' : 'Deposit approved successfully',
        'data' => [
            'savings_id' => $savingsId,
            'transaction_id' => $transactionId,
            'new_amount' => $newAmount,
            'target_amount' => $account['target_amount'],
            'progress_percent' => $progressPercent,
            'is_completed' => $isCompleted,
            'status' => $isCompleted ? 'completed' : 'active'
        ]
    ]);
}

/**
 * Cancel savings account
 */
function cancelSavings($db, int $savingsId, ?int $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $reason = $input['reason'] ?? null;
    
    // Get current account
    $account = $db->queryOne("SELECT * FROM savings_accounts WHERE id = ?", [$savingsId]);
    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Savings account not found']);
        return;
    }
    
    if ($account['status'] === 'cancelled') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Account is already cancelled']);
        return;
    }
    
    if ($account['status'] === 'completed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cannot cancel a completed savings']);
        return;
    }
    
    // Cancel the account (use admin_notes to store reason)
    $cancelNote = "Cancelled by admin" . ($reason ? ": {$reason}" : "");
    $db->execute(
        "UPDATE savings_accounts SET 
            status = 'cancelled', 
            admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n', ?),
            updated_at = NOW()
         WHERE id = ?",
        [$cancelNote, $savingsId]
    );
    
    // Cancel any pending transactions
    $db->execute(
        "UPDATE savings_transactions SET status = 'cancelled' WHERE savings_account_id = ? AND status = 'pending'",
        [$savingsId]
    );
    
    // TODO: Release the reserved product back to inventory if applicable
    
    echo json_encode([
        'success' => true,
        'message' => 'Savings account cancelled',
        'data' => [
            'savings_id' => $savingsId,
            'status' => 'cancelled',
            'refund_amount' => $account['current_amount'],
            'note' => 'Any verified deposits may need to be refunded manually'
        ]
    ]);
}

/**
 * Mark savings as completed (manual completion)
 */
function completeSavings($db, int $savingsId, ?int $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $notes = $input['notes'] ?? null;
    
    // Get current account
    $account = $db->queryOne("SELECT * FROM savings_accounts WHERE id = ?", [$savingsId]);
    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Savings account not found']);
        return;
    }
    
    if ($account['status'] === 'completed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Account is already completed']);
        return;
    }
    
    if ($account['status'] === 'cancelled') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cannot complete a cancelled savings']);
        return;
    }
    
    // Complete the account
    $db->execute(
        "UPDATE savings_accounts SET 
            status = 'completed', 
            completed_at = NOW(),
            admin_notes = ?,
            updated_at = NOW()
         WHERE id = ?",
        [$notes, $savingsId]
    );
    
    // TODO: Create order for delivery if not already exists
    
    echo json_encode([
        'success' => true,
        'message' => 'Savings account marked as completed',
        'data' => [
            'savings_id' => $savingsId,
            'status' => 'completed',
            'current_amount' => $account['current_amount'],
            'target_amount' => $account['target_amount']
        ]
    ]);
}

/**
 * Get savings statistics
 */
function getSavingsStats($db) {
    // Total accounts
    $total = $db->queryOne(
        "SELECT COUNT(*) as cnt FROM savings_accounts"
    )['cnt'] ?? 0;
    
    // Active accounts
    $active = $db->queryOne(
        "SELECT COUNT(*) as cnt FROM savings_accounts WHERE status = 'active'"
    )['cnt'] ?? 0;
    
    // Total amount saved
    $totalAmount = $db->queryOne(
        "SELECT SUM(current_amount) as total FROM savings_accounts WHERE status = 'active'"
    )['total'] ?? 0;
    
    // Near due (target_date within 30 days)
    $nearDue = $db->queryOne(
        "SELECT COUNT(*) as cnt FROM savings_accounts 
         WHERE status = 'active' 
         AND target_date IS NOT NULL 
         AND target_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
    )['cnt'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total' => (int)$total,
            'active' => (int)$active,
            'total_amount' => (float)$totalAmount,
            'near_due' => (int)$nearDue
        ]
    ]);
}

/**
 * Update savings status (PUT)
 */
function updateSavingsStatus($db, $savingsId, $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $newStatus = $input['status'] ?? null;
    
    if (!$newStatus || !in_array($newStatus, ['active', 'completed', 'cancelled', 'expired'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        return;
    }
    
    // Find by account_no (string) or id (int)
    $account = $db->queryOne(
        "SELECT * FROM savings_accounts WHERE account_no = ? OR id = ?",
        [$savingsId, $savingsId]
    );
    
    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Savings account not found']);
        return;
    }
    
    $updateFields = ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')];
    if ($newStatus === 'completed') {
        $updateFields['completed_at'] = date('Y-m-d H:i:s');
    }
    
    $db->execute(
        "UPDATE savings_accounts SET status = ?, updated_at = NOW() " . 
        ($newStatus === 'completed' ? ", completed_at = NOW()" : "") .
        " WHERE id = ?",
        [$newStatus, $account['id']]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Status updated',
        'data' => ['status' => $newStatus]
    ]);
}

/**
 * Manual deposit from admin
 */
function manualDeposit($db, $savingsId, $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $amount = (float)($input['amount'] ?? 0);
    $notes = $input['notes'] ?? 'Manual deposit by admin';
    
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid amount']);
        return;
    }
    
    // Find account
    $account = $db->queryOne(
        "SELECT * FROM savings_accounts WHERE account_no = ? OR id = ?",
        [$savingsId, $savingsId]
    );
    
    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Savings account not found']);
        return;
    }
    
    if ($account['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Account is not active']);
        return;
    }
    
    // Create transaction
    $txnId = 'TXN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    $newBalance = $account['current_amount'] + $amount;
    $db->execute(
        "INSERT INTO savings_transactions 
            (transaction_no, savings_account_id, transaction_type, amount, balance_after, notes, status, created_at)
         VALUES (?, ?, 'deposit', ?, ?, ?, 'verified', NOW())",
        [$txnId, $account['id'], $amount, $newBalance, $notes]
    );
    
    // Update account balance
    $db->execute(
        "UPDATE savings_accounts SET current_amount = ?, updated_at = NOW() WHERE id = ?",
        [$newBalance, $account['id']]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Deposit recorded',
        'data' => [
            'transaction_id' => $txnId,
            'amount' => $amount,
            'new_balance' => $newBalance
        ]
    ]);
}
