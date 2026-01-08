<?php
/**
 * Admin Savings Transactions API
 * 
 * Endpoints:
 * POST /api/admin/savings/transactions/{id}/approve - Approve pending transaction
 * POST /api/admin/savings/transactions/{id}/reject  - Reject pending transaction
 * 
 * @version 1.0
 * @date 2026-01-07
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Logger.php';
require_once __DIR__ . '/../../../includes/AdminAuth.php';

// Parse URL to get transaction_id and action
$uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Expected: /api/admin/savings/transactions/{id}/approve or /api/admin/savings/transactions/{id}/reject
preg_match('#/transactions/(\d+)/(approve|reject)#', $uri, $matches);

$transactionId = $matches[1] ?? null;
$action = $matches[2] ?? null;

if (!$transactionId || !$action) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request. Expected /transactions/{id}/approve or /transactions/{id}/reject']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = getDB();
    
    // Verify admin authentication
    $admin = AdminAuth::verify();
    if (!$admin) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $adminId = $admin['id'] ?? null;
    
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    
    if ($action === 'approve') {
        approveTransaction($pdo, (int)$transactionId, $adminId);
    } elseif ($action === 'reject') {
        $input = json_decode(file_get_contents('php://input'), true);
        $reason = $input['reason'] ?? 'ไม่ระบุเหตุผล';
        rejectTransaction($pdo, (int)$transactionId, $adminId, $reason);
    }
    
} catch (Exception $e) {
    Logger::error('Admin Savings Transactions API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Approve a pending transaction
 */
function approveTransaction($pdo, $transactionId, $adminId) {
    // Get transaction
    $stmt = $pdo->prepare("
        SELECT st.*, sa.account_no, sa.current_amount, sa.target_amount, sa.customer_id
        FROM savings_transactions st
        JOIN savings_accounts sa ON st.savings_account_id = sa.id
        WHERE st.id = ?
    ");
    $stmt->execute([$transactionId]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Transaction not found']);
        return;
    }
    
    if ($transaction['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Transaction is not pending. Current status: ' . $transaction['status']]);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        // Update transaction status
        $stmt = $pdo->prepare("
            UPDATE savings_transactions 
            SET status = 'verified', 
                verified_by = ?, 
                verified_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$adminId, $transactionId]);
        
        // Update savings account current_amount
        $newAmount = (float)$transaction['current_amount'] + (float)$transaction['amount'];
        $stmt = $pdo->prepare("
            UPDATE savings_accounts 
            SET current_amount = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newAmount, $transaction['savings_account_id']]);
        
        // Check if goal is reached
        $goalReached = $newAmount >= (float)$transaction['target_amount'];
        
        $pdo->commit();
        
        // Log
        Logger::info("Transaction #{$transactionId} approved. Account {$transaction['account_no']} new balance: {$newAmount}");
        
        echo json_encode([
            'success' => true,
            'message' => 'อนุมัติรายการสำเร็จ',
            'data' => [
                'transaction_id' => $transactionId,
                'amount' => $transaction['amount'],
                'new_balance' => $newAmount,
                'goal_reached' => $goalReached
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reject a pending transaction
 */
function rejectTransaction($pdo, $transactionId, $adminId, $reason) {
    // Get transaction
    $stmt = $pdo->prepare("SELECT * FROM savings_transactions WHERE id = ?");
    $stmt->execute([$transactionId]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
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
    
    // Update transaction status
    $stmt = $pdo->prepare("
        UPDATE savings_transactions 
        SET status = 'rejected', 
            verified_by = ?, 
            verified_at = NOW(),
            rejection_reason = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$adminId, $reason, $transactionId]);
    
    Logger::info("Transaction #{$transactionId} rejected. Reason: {$reason}");
    
    echo json_encode([
        'success' => true,
        'message' => 'ปฏิเสธรายการสำเร็จ',
        'data' => [
            'transaction_id' => $transactionId,
            'reason' => $reason
        ]
    ]);
}
