<?php
/**
 * Unified Payment Management API
 * 
 * GET    /api/admin/payments           - List all payments with filters
 * GET    /api/admin/payments/{id}      - Get payment details
 * POST   /api/admin/payments/{id}/classify  - Classify and approve payment
 * POST   /api/admin/payments/{id}/reject    - Reject payment
 * 
 * @version 1.0
 * @date 2026-01-07
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/Database.php';

// Verify admin authentication
$auth = verifyAdminToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$admin_id = $auth['admin_id'];
$method = $_SERVER['REQUEST_METHOD'];

// Parse route
$request_uri = $_SERVER['REQUEST_URI'];
$path_parts = explode('/', trim(parse_url($request_uri, PHP_URL_PATH), '/'));

// Find payment ID and action from path
$payment_id = null;
$action = null;

foreach ($path_parts as $i => $part) {
    if ($part === 'payments' && isset($path_parts[$i + 1])) {
        if (is_numeric($path_parts[$i + 1])) {
            $payment_id = (int)$path_parts[$i + 1];
            if (isset($path_parts[$i + 2])) {
                $action = $path_parts[$i + 2];
            }
        }
    }
}

try {
    $pdo = getDB();
    
    if ($method === 'GET') {
        if ($payment_id) {
            getPaymentDetail($pdo, $payment_id);
        } else {
            listPayments($pdo);
        }
    } elseif ($method === 'POST') {
        if ($payment_id && $action === 'classify') {
            classifyAndApprove($pdo, $payment_id, $admin_id);
        } elseif ($payment_id && $action === 'reject') {
            rejectPayment($pdo, $payment_id, $admin_id);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Unified Payment API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'
    ]);
}

/**
 * List all payments with filtering
 */
function listPayments($pdo) {
    $status = $_GET['status'] ?? null;
    $payment_type = $_GET['payment_type'] ?? null;
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $search = $_GET['search'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    // Build query
    $where = ['1=1'];
    $params = [];
    
    if ($status) {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    
    if ($payment_type) {
        $where[] = 'p.payment_type = ?';
        $params[] = $payment_type;
    }
    
    if ($date_from) {
        $where[] = 'DATE(p.created_at) >= ?';
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $where[] = 'DATE(p.created_at) <= ?';
        $params[] = $date_to;
    }
    
    if ($search) {
        $where[] = '(p.payment_no LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Count total
    $countSql = "
        SELECT COUNT(*) as total
        FROM payments p
        LEFT JOIN users u ON p.customer_id = u.id
        WHERE $whereClause
    ";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get payments
    $sql = "
        SELECT 
            p.*,
            u.full_name as customer_name,
            u.email as customer_email,
            u.phone as customer_phone,
            o.order_no,
            o.product_name as order_product_name,
            ic.contract_no,
            ic.product_name as installment_product_name,
            sa.account_no as savings_account_no,
            sa.product_name as savings_product_name
        FROM payments p
        LEFT JOIN users u ON p.customer_id = u.id
        LEFT JOIN orders o ON p.order_id = o.id
        LEFT JOIN installment_contracts ic ON p.reference_type = 'installment_contract' AND p.reference_id = ic.id
        LEFT JOIN savings_accounts sa ON p.reference_type = 'savings_account' AND p.reference_id = sa.id
        WHERE $whereClause
        ORDER BY p.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get summary counts
    $summarySql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified_count,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN payment_type = 'unknown' AND status = 'pending' THEN 1 ELSE 0 END) as unclassified_count
        FROM payments
    ";
    $stmt = $pdo->query($summarySql);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $payments,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'total_pages' => ceil($total / $limit)
        ],
        'summary' => $summary
    ]);
}

/**
 * Get payment detail with customer references
 */
function getPaymentDetail($pdo, $payment_id) {
    // Get payment
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            u.full_name as customer_name,
            u.email as customer_email,
            u.phone as customer_phone
        FROM payments p
        LEFT JOIN users u ON p.customer_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลการชำระเงิน']);
        return;
    }
    
    $customer_id = $payment['customer_id'];
    
    // Get customer's active orders
    $stmt = $pdo->prepare("
        SELECT id, order_no, product_name, total_amount, 
               COALESCE(paid_amount, 0) as paid_amount,
               status, payment_type
        FROM orders 
        WHERE customer_id = ? AND status NOT IN ('cancelled', 'delivered')
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$customer_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get customer's active installment contracts
    $stmt = $pdo->prepare("
        SELECT id, contract_no, product_name, financed_amount, 
               COALESCE(paid_amount, 0) as paid_amount,
               amount_per_period, paid_periods, total_periods, status,
               next_due_date
        FROM installment_contracts 
        WHERE customer_id = ? AND status IN ('active', 'overdue')
        ORDER BY next_due_date ASC
        LIMIT 10
    ");
    $stmt->execute([$customer_id]);
    $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get customer's active savings accounts
    $stmt = $pdo->prepare("
        SELECT id, account_no, product_name, target_amount, 
               COALESCE(current_amount, 0) as current_amount,
               status
        FROM savings_accounts 
        WHERE customer_id = ? AND status = 'active'
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$customer_id]);
    $savings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'payment' => $payment,
            'customer_references' => [
                'orders' => $orders,
                'installments' => $installments,
                'savings' => $savings
            ]
        ]
    ]);
}

/**
 * Classify and approve payment
 */
function classifyAndApprove($pdo, $payment_id, $admin_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $payment_type = $input['payment_type'] ?? null;
    $reference_type = $input['reference_type'] ?? null;
    $reference_id = $input['reference_id'] ?? null;
    $period_number = $input['period_number'] ?? null; // For installments
    $notes = $input['notes'] ?? '';
    
    if (!$payment_type || !in_array($payment_type, ['full', 'installment', 'savings'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาเลือกประเภทการชำระเงิน']);
        return;
    }
    
    // Validate reference based on type
    if ($payment_type === 'full' && $reference_type !== 'order') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ประเภทชำระเต็มต้องเลือกคำสั่งซื้อ']);
        return;
    }
    
    if ($payment_type === 'installment' && $reference_type !== 'installment_contract') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ประเภทผ่อนชำระต้องเลือกสัญญาผ่อน']);
        return;
    }
    
    if ($payment_type === 'savings' && $reference_type !== 'savings_account') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ประเภทออมเงินต้องเลือกบัญชีออม']);
        return;
    }
    
    // Get payment
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลการชำระเงิน']);
        return;
    }
    
    if ($payment['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'การชำระเงินนี้ได้รับการตรวจสอบแล้ว']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        $amount = (float)$payment['amount'];
        $customer_id = $payment['customer_id'];
        
        // Update payment record
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET payment_type = ?,
                reference_type = ?,
                reference_id = ?,
                installment_period_number = ?,
                classification_notes = ?,
                status = 'verified',
                verified_by = ?,
                verified_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $payment_type,
            $reference_type,
            $reference_id,
            $period_number,
            $notes,
            $admin_id,
            $payment_id
        ]);
        
        // Sync to appropriate table
        if ($payment_type === 'installment' && $reference_id) {
            syncToInstallment($pdo, $payment, $reference_id, $period_number, $admin_id);
        } elseif ($payment_type === 'savings' && $reference_id) {
            syncToSavings($pdo, $payment, $reference_id, $admin_id);
        } elseif ($payment_type === 'full' && $reference_id) {
            syncToOrder($pdo, $payment, $reference_id);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'อนุมัติและบันทึกการชำระเงินเรียบร้อยแล้ว',
            'data' => [
                'payment_id' => $payment_id,
                'payment_type' => $payment_type,
                'reference_type' => $reference_type,
                'reference_id' => $reference_id
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Sync payment to installment_payments table
 */
function syncToInstallment($pdo, $payment, $contract_id, $period_number, $admin_id) {
    $amount = (float)$payment['amount'];
    $payment_id = $payment['id'];
    
    // Generate payment_no
    $payment_no = 'INSPAY-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    
    // Get contract details
    $stmt = $pdo->prepare("SELECT * FROM installment_contracts WHERE id = ?");
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        throw new Exception("Contract not found");
    }
    
    // Determine period number if not provided
    if (!$period_number) {
        $period_number = ($contract['paid_periods'] ?? 0) + 1;
    }
    
    // Insert into installment_payments
    $stmt = $pdo->prepare("
        INSERT INTO installment_payments 
        (contract_id, payment_no, period_number, amount, payment_method, 
         paid_date, status, verified_by, verified_at, 
         slip_image_url, payment_ref, notes, created_at)
        VALUES (?, ?, ?, ?, ?, NOW(), 'verified', ?, NOW(), ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $contract_id,
        $payment_no,
        $period_number,
        $amount,
        $payment['payment_method'] ?? 'bank_transfer',
        $admin_id,
        $payment['slip_image'] ?? null,
        'PAY-' . $payment_id,
        'Synced from unified payment #' . $payment_id
    ]);
    
    // Update contract
    $stmt = $pdo->prepare("
        UPDATE installment_contracts 
        SET paid_amount = COALESCE(paid_amount, 0) + ?,
            paid_periods = COALESCE(paid_periods, 0) + 1,
            last_payment_date = NOW(),
            status = CASE 
                WHEN COALESCE(paid_periods, 0) + 1 >= total_periods THEN 'completed'
                ELSE status 
            END,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$amount, $contract_id]);
}

/**
 * Sync payment to savings_transactions table
 */
function syncToSavings($pdo, $payment, $savings_id, $admin_id) {
    $amount = (float)$payment['amount'];
    $payment_id = $payment['id'];
    
    // Generate transaction_no
    $tx_no = 'SAVTX-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    
    // Get current balance
    $stmt = $pdo->prepare("SELECT current_amount FROM savings_accounts WHERE id = ?");
    $stmt->execute([$savings_id]);
    $current = (float)($stmt->fetchColumn() ?? 0);
    $balance_after = $current + $amount;
    
    // Insert into savings_transactions
    $stmt = $pdo->prepare("
        INSERT INTO savings_transactions 
        (transaction_no, savings_account_id, transaction_type, amount, balance_after,
         payment_method, slip_image_url, status, verified_by, verified_at, 
         notes, created_at)
        VALUES (?, ?, 'deposit', ?, ?, ?, ?, 'verified', ?, NOW(), ?, NOW())
    ");
    $stmt->execute([
        $tx_no,
        $savings_id,
        $amount,
        $balance_after,
        $payment['payment_method'] ?? 'bank_transfer',
        $payment['slip_image'] ?? null,
        $admin_id,
        'Synced from unified payment #' . $payment_id
    ]);
    
    // Update savings account
    $stmt = $pdo->prepare("
        UPDATE savings_accounts 
        SET current_amount = ?,
            status = CASE 
                WHEN ? >= target_amount THEN 'completed'
                ELSE status 
            END,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$balance_after, $balance_after, $savings_id]);
}

/**
 * Sync payment to orders table
 */
function syncToOrder($pdo, $payment, $order_id) {
    $amount = (float)$payment['amount'];
    
    // Get order
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception("Order not found");
    }
    
    $new_paid = ($order['paid_amount'] ?? 0) + $amount;
    $total = (float)$order['total_amount'];
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET paid_amount = ?,
            status = CASE 
                WHEN ? >= total_amount THEN 'processing'
                ELSE status 
            END,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$new_paid, $new_paid, $order_id]);
}

/**
 * Reject payment
 */
function rejectPayment($pdo, $payment_id, $admin_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    $reason = $input['reason'] ?? '';
    
    if (empty($reason)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุเหตุผลในการปฏิเสธ']);
        return;
    }
    
    $stmt = $pdo->prepare("
        UPDATE payments 
        SET status = 'rejected',
            rejection_reason = ?,
            verified_by = ?,
            verified_at = NOW()
        WHERE id = ? AND status = 'pending'
    ");
    $stmt->execute([$reason, $admin_id, $payment_id]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ไม่สามารถปฏิเสธได้ หรือรายการนี้ได้รับการตรวจสอบแล้ว']);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'ปฏิเสธการชำระเงินเรียบร้อยแล้ว'
    ]);
}

/**
 * Helper: Verify admin token
 */
function verifyAdminToken() {
    // Try to get token from header or session
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    
    if ($token && strpos($token, 'Bearer ') === 0) {
        $token = substr($token, 7);
    }
    
    // If no token in header, check session
    if (!$token && session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['admin_id'])) {
        return ['valid' => true, 'admin_id' => $_SESSION['admin_id']];
    }
    
    // Verify JWT token if provided
    if ($token) {
        try {
            $auth = verifyToken($token);
            if ($auth['valid'] && isset($auth['is_admin']) && $auth['is_admin']) {
                return ['valid' => true, 'admin_id' => $auth['user_id']];
            }
        } catch (Exception $e) {
            // Token invalid
        }
    }
    
    return ['valid' => false];
}
