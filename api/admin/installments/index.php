<?php
/**
 * Admin Installments API
 * 
 * Endpoints:
 * GET  /api/admin/installments                        - List all installment contracts
 * GET  /api/admin/installments/{id}                   - Get contract details
 * POST /api/admin/installments/{id}/approve           - Approve contract
 * POST /api/admin/installments/{id}/verify-payment    - Verify payment
 * POST /api/admin/installments/{id}/reject-payment    - Reject payment
 * POST /api/admin/installments/{id}/manual-payment    - Add manual payment
 * POST /api/admin/installments/{id}/update-due-date   - Update due date
 * POST /api/admin/installments/{id}/cancel            - Cancel contract
 * GET  /api/admin/installments?stats=1                - Get statistics
 * 
 * @version 1.0
 * @date 2026-01-07
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Logger.php';
require_once __DIR__ . '/../../../includes/AdminAuth.php';

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$contractId = $_GET['contract_id'] ?? $_GET['id'] ?? null;
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
    if ($method === 'GET' && !$contractId) {
        if (isset($_GET['stats']) && $_GET['stats'] === '1') {
            getInstallmentStats($db);
        } elseif (isset($_GET['pending_payments']) && $_GET['pending_payments'] === '1') {
            listPendingPayments($db);
        } else {
            listInstallmentContracts($db);
        }
    } elseif ($method === 'GET' && $contractId) {
        getContractDetails($db, (int)$contractId);
    } elseif ($method === 'POST' && $contractId && $action === 'approve') {
        approveContract($db, (int)$contractId, $adminId);
    } elseif ($method === 'POST' && $contractId && $action === 'verify-payment') {
        verifyPayment($db, (int)$contractId, $adminId);
    } elseif ($method === 'POST' && $contractId && $action === 'reject-payment') {
        rejectPayment($db, (int)$contractId, $adminId);
    } elseif ($method === 'POST' && $contractId && $action === 'manual-payment') {
        addManualPayment($db, (int)$contractId, $adminId);
    } elseif ($method === 'POST' && $contractId && $action === 'update-due-date') {
        updateDueDate($db, (int)$contractId, $adminId);
    } elseif ($method === 'POST' && $contractId && $action === 'cancel') {
        cancelContract($db, (int)$contractId, $adminId);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    Logger::error('Admin Installments API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * List installment contracts with filters
 */
function listInstallmentContracts($db) {
    $status = $_GET['status'] ?? null;
    $platform = $_GET['platform'] ?? null;
    $search = $_GET['search'] ?? null;
    $pendingPayments = $_GET['pending_payments'] ?? null;
    $overdue = $_GET['overdue'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $where = [];
    $params = [];
    
    if ($status) {
        $where[] = "c.status = ?";
        $params[] = $status;
    }
    
    if ($platform) {
        $where[] = "c.platform = ?";
        $params[] = $platform;
    }
    
    if ($search) {
        $where[] = "(c.contract_no LIKE ? OR c.external_user_id LIKE ? OR c.customer_name LIKE ? OR c.customer_phone LIKE ? OR c.product_name LIKE ?)";
        $searchTerm = "%{$search}%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    if ($pendingPayments === '1') {
        $where[] = "c.pending_amount > 0";
    }
    
    if ($overdue === '1') {
        $where[] = "(c.status = 'overdue' OR (c.next_due_date < CURDATE() AND c.status = 'active'))";
    }
    
    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM installment_contracts c {$whereClause}";
    $countResult = $db->queryOne($countSql, $params);
    $total = $countResult['total'] ?? 0;
    
    // Get contracts with pending payment count
    $sql = "SELECT c.*,
            (SELECT COUNT(*) FROM installment_payments WHERE contract_id = c.id AND status = 'pending') as pending_payment_count,
            (SELECT SUM(amount) FROM installment_payments WHERE contract_id = c.id AND status = 'pending') as pending_payment_amount
            FROM installment_contracts c
            {$whereClause}
            ORDER BY 
                CASE WHEN c.pending_amount > 0 THEN 0 ELSE 1 END,
                CASE c.status WHEN 'overdue' THEN 0 WHEN 'active' THEN 1 WHEN 'pending_approval' THEN 2 ELSE 3 END,
                c.next_due_date ASC
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $contracts = $db->queryAll($sql, $params);
    
    // Add progress info
    foreach ($contracts as &$contract) {
        $contract['progress'] = calculateProgress($contract);
        $contract['is_overdue'] = ($contract['next_due_date'] && $contract['next_due_date'] < date('Y-m-d') && $contract['status'] === 'active');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $contracts,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total
        ]
    ]);
}

/**
 * Get statistics
 */
function getInstallmentStats($db) {
    $stats = $db->queryOne("SELECT 
        COUNT(*) as total_contracts,
        SUM(CASE WHEN status = 'pending' OR status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        COALESCE(SUM(financed_amount), 0) as total_financed,
        COALESCE(SUM(paid_amount), 0) as total_paid,
        COALESCE(SUM(pending_amount), 0) as total_pending,
        SUM(CASE WHEN next_due_date < CURDATE() AND status = 'active' THEN 1 ELSE 0 END) as overdue_payments
        FROM installment_contracts
    ");
    
    $pendingPayments = $db->queryOne("SELECT 
        COUNT(*) as count, 
        COALESCE(SUM(amount), 0) as amount 
        FROM installment_payments 
        WHERE status = 'pending_verification' OR status = 'pending'
    ");
    
    // Return flat format for UI
    echo json_encode([
        'success' => true,
        'data' => [
            'total' => (int)($stats['total_contracts'] ?? 0),
            'pending' => (int)($stats['pending_approval'] ?? 0),
            'active' => (int)($stats['active'] ?? 0),
            'overdue' => (int)(($stats['overdue'] ?? 0) + ($stats['overdue_payments'] ?? 0)),
            'completed' => (int)($stats['completed'] ?? 0),
            'cancelled' => (int)($stats['cancelled'] ?? 0),
            'total_financed' => (float)($stats['total_financed'] ?? 0),
            'total_paid' => (float)($stats['total_paid'] ?? 0),
            'total_pending' => (float)($stats['total_pending'] ?? 0),
            'pending_payments' => (int)($pendingPayments['count'] ?? 0),
            'pending_payments_amount' => (float)($pendingPayments['amount'] ?? 0)
        ]
    ]);
}

/**
 * List pending payments awaiting verification
 */
function listPendingPayments($db) {
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $sql = "SELECT p.*, 
            c.contract_no, c.customer_name, c.customer_phone, c.product_name, c.platform,
            c.total_amount as contract_total, c.amount_per_period
            FROM installment_payments p
            LEFT JOIN installment_contracts c ON p.contract_id = c.id
            WHERE p.status = 'pending_verification' OR p.status = 'pending'
            ORDER BY p.created_at ASC
            LIMIT ? OFFSET ?";
    
    $payments = $db->queryAll($sql, [$limit, $offset]);
    
    // Get total count
    $countResult = $db->queryOne("SELECT COUNT(*) as total FROM installment_payments WHERE status = 'pending_verification' OR status = 'pending'");
    $total = $countResult['total'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'data' => $payments,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]
    ]);
}

/**
 * Get contract details with full payment history
 */
function getContractDetails($db, int $contractId) {
    $contract = $db->queryOne("SELECT * FROM installment_contracts WHERE id = ?", [$contractId]);
    
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contract not found']);
        return;
    }
    
    // Get all payments
    $payments = $db->queryAll(
        "SELECT p.*, 
            (SELECT u.name FROM users u WHERE u.id = p.verified_by) as verified_by_name
         FROM installment_payments p 
         WHERE p.contract_id = ? 
         ORDER BY p.period_number ASC, p.created_at DESC",
        [$contractId]
    );
    
    // Parse JSON fields
    foreach ($payments as &$payment) {
        $payment['slip_ocr_data'] = $payment['slip_ocr_data'] ? json_decode($payment['slip_ocr_data'], true) : null;
    }
    
    // Get channel info
    $channel = null;
    if ($contract['channel_id']) {
        $channel = $db->queryOne("SELECT id, name, platform FROM channels WHERE id = ?", [$contract['channel_id']]);
    }
    
    // Build payment schedule
    $schedule = buildPaymentSchedule($contract, $payments);
    
    // Calculate progress
    $progress = calculateProgress($contract);
    
    // Merge contract with additional data for flat response
    $result = array_merge($contract, [
        'payments' => $payments,
        'schedule' => $schedule,
        'channel' => $channel,
        'progress' => $progress,
        // Calculate remaining if not present
        'remaining_amount' => ($contract['financed_amount'] ?? $contract['total_amount'] ?? 0) - ($contract['paid_amount'] ?? 0)
    ]);
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
}

/**
 * Approve pending contract
 */
function approveContract($db, int $contractId, $adminId) {
    $contract = $db->queryOne("SELECT * FROM installment_contracts WHERE id = ?", [$contractId]);
    
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contract not found']);
        return;
    }
    
    if ($contract['status'] !== 'pending_approval') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Contract is not pending approval']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $notes = $input['notes'] ?? null;
    
    $db->execute(
        "UPDATE installment_contracts 
         SET status = 'active', 
             approved_by = ?, 
             approved_at = NOW(), 
             approval_notes = ?,
             updated_at = NOW() 
         WHERE id = ?",
        [$adminId, $notes, $contractId]
    );
    
    Logger::info('Installment contract approved', [
        'contract_id' => $contractId,
        'contract_no' => $contract['contract_no'],
        'admin_id' => $adminId
    ]);
    
    // Queue push notification
    queuePushNotification($db, $contract, 'contract_approved', [
        'contract_no' => $contract['contract_no'],
        'product_name' => $contract['product_name'],
        'total_amount' => $contract['total_amount'],
        'amount_per_period' => $contract['amount_per_period'],
        'total_periods' => $contract['total_periods'],
        'first_due_date' => $contract['next_due_date']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Contract approved successfully',
        'data' => ['contract_no' => $contract['contract_no']]
    ]);
}

/**
 * Verify a pending payment
 */
function verifyPayment($db, int $contractId, $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $paymentId = $input['payment_id'] ?? null;
    
    if (!$paymentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'payment_id is required']);
        return;
    }
    
    // Get payment
    $payment = $db->queryOne(
        "SELECT p.*, c.contract_no, c.product_name, c.financed_amount, c.paid_amount, c.paid_periods, 
                c.total_periods, c.amount_per_period, c.platform, c.external_user_id, c.channel_id
         FROM installment_payments p
         JOIN installment_contracts c ON c.id = p.contract_id
         WHERE p.id = ? AND p.contract_id = ?",
        [$paymentId, $contractId]
    );
    
    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        return;
    }
    
    if ($payment['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Payment is not pending']);
        return;
    }
    
    $amount = (float)$payment['amount'];
    $periodNumber = (int)$payment['period_number'];
    $notes = $input['notes'] ?? null;
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Update payment status
        $db->execute(
            "UPDATE installment_payments 
             SET status = 'verified', 
                 verified_by = ?, 
                 verified_at = NOW(),
                 admin_notes = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [$adminId, $notes, $paymentId]
        );
        
        // Update contract: paid_amount, paid_periods, pending_amount
        $newPaidAmount = (float)$payment['paid_amount'] + $amount;
        $newPaidPeriods = $periodNumber > 0 ? max((int)$payment['paid_periods'], $periodNumber) : (int)$payment['paid_periods'];
        
        // Calculate next due date
        $nextDueDate = null;
        $newStatus = 'active';
        
        if ($newPaidPeriods >= (int)$payment['total_periods'] || $newPaidAmount >= (float)$payment['financed_amount']) {
            // Completed
            $newStatus = 'completed';
            $nextDueDate = null;
        } else {
            // Calculate next period due date
            $contract = $db->queryOne("SELECT start_date FROM installment_contracts WHERE id = ?", [$contractId]);
            $nextPeriod = $newPaidPeriods + 1;
            $nextDueDate = date('Y-m-d', strtotime($contract['start_date'] . " +" . ($nextPeriod - 1) . " months"));
        }
        
        $db->execute(
            "UPDATE installment_contracts 
             SET paid_amount = ?,
                 paid_periods = ?,
                 pending_amount = pending_amount - ?,
                 next_due_date = ?,
                 last_paid_date = CURDATE(),
                 status = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [$newPaidAmount, $newPaidPeriods, $amount, $nextDueDate, $newStatus, $contractId]
        );
        
        $db->commit();
        
        Logger::info('Installment payment verified', [
            'payment_id' => $paymentId,
            'contract_id' => $contractId,
            'period_number' => $periodNumber,
            'amount' => $amount,
            'new_status' => $newStatus
        ]);
        
        // Queue push notification
        $notificationType = $newStatus === 'completed' ? 'installment_completed' : 'installment_payment_verified';
        queuePushNotification($db, [
            'platform' => $payment['platform'],
            'external_user_id' => $payment['external_user_id'],
            'channel_id' => $payment['channel_id']
        ], $notificationType, [
            'contract_no' => $payment['contract_no'],
            'product_name' => $payment['product_name'],
            'period_number' => $periodNumber,
            'amount' => $amount,
            'paid_amount' => $newPaidAmount,
            'total_amount' => $payment['financed_amount'],
            'paid_periods' => $newPaidPeriods,
            'total_periods' => $payment['total_periods'],
            'next_due_date' => $nextDueDate,
            'remaining_amount' => max(0, (float)$payment['financed_amount'] - $newPaidAmount)
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => $newStatus === 'completed' ? 'Payment verified - Contract completed!' : 'Payment verified successfully',
            'data' => [
                'payment_id' => $paymentId,
                'contract_status' => $newStatus,
                'paid_amount' => $newPaidAmount,
                'paid_periods' => $newPaidPeriods,
                'next_due_date' => $nextDueDate
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Reject a pending payment
 */
function rejectPayment($db, int $contractId, $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $paymentId = $input['payment_id'] ?? null;
    $reason = $input['reason'] ?? 'ไม่สามารถยืนยันการชำระเงินได้';
    
    if (!$paymentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'payment_id is required']);
        return;
    }
    
    // Get payment
    $payment = $db->queryOne(
        "SELECT p.*, c.contract_no, c.platform, c.external_user_id, c.channel_id
         FROM installment_payments p
         JOIN installment_contracts c ON c.id = p.contract_id
         WHERE p.id = ? AND p.contract_id = ?",
        [$paymentId, $contractId]
    );
    
    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        return;
    }
    
    if ($payment['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Payment is not pending']);
        return;
    }
    
    $amount = (float)$payment['amount'];
    
    // Update payment status
    $db->execute(
        "UPDATE installment_payments 
         SET status = 'rejected', 
             verified_by = ?, 
             verified_at = NOW(),
             rejection_reason = ?,
             updated_at = NOW()
         WHERE id = ?",
        [$adminId, $reason, $paymentId]
    );
    
    // Update contract pending amount
    $db->execute(
        "UPDATE installment_contracts 
         SET pending_amount = pending_amount - ?,
             updated_at = NOW()
         WHERE id = ?",
        [$amount, $contractId]
    );
    
    Logger::info('Installment payment rejected', [
        'payment_id' => $paymentId,
        'contract_id' => $contractId,
        'reason' => $reason
    ]);
    
    // Queue push notification
    queuePushNotification($db, [
        'platform' => $payment['platform'],
        'external_user_id' => $payment['external_user_id'],
        'channel_id' => $payment['channel_id']
    ], 'payment_rejected', [
        'contract_no' => $payment['contract_no'],
        'amount' => $amount,
        'reason' => $reason
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment rejected',
        'data' => ['payment_id' => $paymentId]
    ]);
}

/**
 * Add manual payment (admin enters payment without slip)
 */
function addManualPayment($db, int $contractId, $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $contract = $db->queryOne("SELECT * FROM installment_contracts WHERE id = ?", [$contractId]);
    
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contract not found']);
        return;
    }
    
    if (!in_array($contract['status'], ['active', 'overdue', 'pending_approval'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Contract cannot accept payments']);
        return;
    }
    
    $amount = (float)($input['amount'] ?? 0);
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
        return;
    }
    
    $periodNumber = (int)($input['period_number'] ?? ($contract['paid_periods'] + 1));
    $paymentType = $input['payment_type'] ?? 'regular';
    $paymentMethod = $input['payment_method'] ?? 'cash';
    $notes = $input['notes'] ?? 'Manual entry by admin';
    $paidDate = $input['paid_date'] ?? date('Y-m-d');
    
    // Generate payment number
    $paymentNo = 'INSPAY-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    
    // Calculate due date
    $dueDate = date('Y-m-d', strtotime($contract['start_date'] . " +" . ($periodNumber - 1) . " months"));
    
    $db->beginTransaction();
    
    try {
        // Insert payment as verified
        $db->execute(
            "INSERT INTO installment_payments (
                contract_id, payment_no, period_number,
                amount, payment_type, payment_method,
                due_date, paid_date,
                status, verified_by, verified_at,
                notes, admin_notes,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                'verified', ?, NOW(),
                ?, ?,
                NOW(), NOW()
            )",
            [
                $contractId, $paymentNo, $periodNumber,
                $amount, $paymentType, $paymentMethod,
                $dueDate, $paidDate,
                $adminId,
                $notes, "Manual entry by admin ID: {$adminId}"
            ]
        );
        
        $paymentId = $db->lastInsertId();
        
        // Update contract
        $newPaidAmount = (float)$contract['paid_amount'] + $amount;
        $newPaidPeriods = max((int)$contract['paid_periods'], $periodNumber);
        
        // Determine new status
        $newStatus = 'active';
        $nextDueDate = null;
        
        if ($newPaidPeriods >= (int)$contract['total_periods'] || $newPaidAmount >= (float)$contract['financed_amount']) {
            $newStatus = 'completed';
        } else {
            $nextPeriod = $newPaidPeriods + 1;
            $nextDueDate = date('Y-m-d', strtotime($contract['start_date'] . " +" . ($nextPeriod - 1) . " months"));
        }
        
        $db->execute(
            "UPDATE installment_contracts 
             SET paid_amount = ?,
                 paid_periods = ?,
                 next_due_date = ?,
                 last_paid_date = ?,
                 status = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [$newPaidAmount, $newPaidPeriods, $nextDueDate, $paidDate, $newStatus, $contractId]
        );
        
        $db->commit();
        
        Logger::info('Manual installment payment added', [
            'payment_id' => $paymentId,
            'contract_id' => $contractId,
            'amount' => $amount,
            'admin_id' => $adminId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Manual payment added successfully',
            'data' => [
                'payment_id' => $paymentId,
                'payment_no' => $paymentNo,
                'contract_status' => $newStatus
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Update due date (extension approval)
 */
function updateDueDate($db, int $contractId, $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $newDueDate = $input['new_due_date'] ?? null;
    
    if (!$newDueDate) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'new_due_date is required']);
        return;
    }
    
    $contract = $db->queryOne("SELECT * FROM installment_contracts WHERE id = ?", [$contractId]);
    
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contract not found']);
        return;
    }
    
    $oldDueDate = $contract['next_due_date'];
    $notes = $input['notes'] ?? "Due date changed from {$oldDueDate} to {$newDueDate}";
    
    // Update next_due_date and status (remove overdue if was overdue)
    $newStatus = $contract['status'] === 'overdue' ? 'active' : $contract['status'];
    
    $db->execute(
        "UPDATE installment_contracts 
         SET next_due_date = ?,
             status = ?,
             admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] ', ?),
             updated_at = NOW()
         WHERE id = ?",
        [$newDueDate, $newStatus, $notes, $contractId]
    );
    
    Logger::info('Installment due date updated', [
        'contract_id' => $contractId,
        'old_due_date' => $oldDueDate,
        'new_due_date' => $newDueDate,
        'admin_id' => $adminId
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Due date updated successfully',
        'data' => [
            'old_due_date' => $oldDueDate,
            'new_due_date' => $newDueDate
        ]
    ]);
}

/**
 * Cancel contract
 */
function cancelContract($db, int $contractId, $adminId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $reason = $input['reason'] ?? 'Cancelled by admin';
    
    $contract = $db->queryOne("SELECT * FROM installment_contracts WHERE id = ?", [$contractId]);
    
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contract not found']);
        return;
    }
    
    if ($contract['status'] === 'completed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cannot cancel completed contract']);
        return;
    }
    
    $db->execute(
        "UPDATE installment_contracts 
         SET status = 'cancelled',
             admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] Cancelled: ', ?),
             updated_at = NOW()
         WHERE id = ?",
        [$reason, $contractId]
    );
    
    Logger::info('Installment contract cancelled', [
        'contract_id' => $contractId,
        'contract_no' => $contract['contract_no'],
        'reason' => $reason,
        'admin_id' => $adminId
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Contract cancelled successfully'
    ]);
}

/**
 * Calculate progress
 */
function calculateProgress($contract): array {
    $financedAmount = (float)($contract['financed_amount'] ?? 0);
    $paidAmount = (float)($contract['paid_amount'] ?? 0);
    $pendingAmount = (float)($contract['pending_amount'] ?? 0);
    $percentage = $financedAmount > 0 ? round(($paidAmount / $financedAmount) * 100, 1) : 0;
    
    return [
        'paid_amount' => $paidAmount,
        'pending_amount' => $pendingAmount,
        'remaining_amount' => max(0, $financedAmount - $paidAmount),
        'percentage' => $percentage,
        'paid_periods' => (int)($contract['paid_periods'] ?? 0),
        'total_periods' => (int)($contract['total_periods'] ?? 0)
    ];
}

/**
 * Build payment schedule
 */
function buildPaymentSchedule($contract, $payments): array {
    $schedule = [];
    $totalPeriods = (int)$contract['total_periods'];
    $startDate = $contract['start_date'];
    $amountPerPeriod = (float)$contract['amount_per_period'];
    
    // Index payments by period
    $paymentsByPeriod = [];
    foreach ($payments as $payment) {
        $period = (int)$payment['period_number'];
        if (!isset($paymentsByPeriod[$period])) {
            $paymentsByPeriod[$period] = [];
        }
        $paymentsByPeriod[$period][] = $payment;
    }
    
    for ($i = 1; $i <= $totalPeriods; $i++) {
        $dueDate = date('Y-m-d', strtotime($startDate . " +" . ($i - 1) . " months"));
        $periodPayments = $paymentsByPeriod[$i] ?? [];
        
        $status = 'upcoming';
        $paidAmount = 0;
        
        foreach ($periodPayments as $p) {
            if ($p['status'] === 'verified') {
                $status = 'paid';
                $paidAmount += (float)$p['amount'];
            } elseif ($p['status'] === 'pending' && $status !== 'paid') {
                $status = 'pending';
            }
        }
        
        if ($status === 'upcoming' && $dueDate < date('Y-m-d')) {
            $status = 'overdue';
        }
        
        $schedule[] = [
            'period' => $i,
            'due_date' => $dueDate,
            'amount_due' => $amountPerPeriod,
            'paid_amount' => $paidAmount,
            'status' => $status,
            'payments' => $periodPayments
        ];
    }
    
    return $schedule;
}

/**
 * Queue push notification
 */
function queuePushNotification($db, $target, string $type, array $data) {
    try {
        $platform = $target['platform'] ?? null;
        $externalUserId = $target['external_user_id'] ?? null;
        $channelId = $target['channel_id'] ?? null;
        
        if (!$platform || !$externalUserId) {
            return;
        }
        
        // Get template
        $template = $db->queryOne(
            "SELECT * FROM notification_templates WHERE template_key = ? AND is_active = 1",
            [$type]
        );
        
        $message = '';
        if ($template) {
            $templateText = $platform === 'line' ? $template['line_template'] : $template['facebook_template'];
            $message = $templateText;
            
            // Replace variables
            foreach ($data as $key => $value) {
                if (is_numeric($value)) {
                    $value = number_format($value, 2);
                }
                $message = str_replace("{{{$key}}}", $value, $message);
            }
        }
        
        // Insert to push_notifications queue
        $db->execute(
            "INSERT INTO push_notifications (
                platform, platform_user_id, channel_id,
                notification_type, title, message, message_data,
                status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
            [
                $platform,
                $externalUserId,
                $channelId,
                $type,
                $template['title_th'] ?? null,
                $message,
                json_encode($data)
            ]
        );
        
    } catch (Exception $e) {
        Logger::error('Failed to queue push notification: ' . $e->getMessage());
    }
}
