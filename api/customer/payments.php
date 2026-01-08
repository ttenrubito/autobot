<?php
/**
 * Customer Payments API
 * GET /api/customer/payments - Get all payments
 * GET /api/customer/payments/{id} - Get specific payment
 * GET /api/customer/payments/{id}/installments - Get installment schedule for a payment's order
 * GET /api/customer/payments/{id}/references - Get customer references for classification
 * POST /api/customer/payments/notify - Submit payment notification (upload slip)
 * POST /api/customer/payments?action=classify - Classify and approve payment (shop owner)
 * POST /api/customer/payments?action=approve - Approve payment only
 * POST /api/customer/payments?action=reject - Reject payment
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

// Check for action parameter
$action = $_GET['action'] ?? null;

try {
    $pdo = getDB();
    
    // Handle POST actions (classify, approve, reject)
    if ($method === 'POST' && $action) {
        $input = json_decode(file_get_contents('php://input'), true);
        $payment_id = $input['payment_id'] ?? $_GET['id'] ?? null;
        
        if (!$payment_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'กรุณาระบุ payment_id']);
            exit;
        }
        
        // Verify payment exists and user owns it (as shop owner)
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลการชำระเงิน']);
            exit;
        }
        
        switch ($action) {
            case 'classify':
                classifyAndApprove($pdo, $payment, $input, $user_id);
                break;
            case 'approve':
                approvePayment($pdo, $payment, $user_id);
                break;
            case 'reject':
                rejectPayment($pdo, $payment, $input, $user_id);
                break;
            case 'references':
                getCustomerReferences($pdo, $payment['customer_id']);
                break;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        exit;
    }
    
    if ($method === 'GET') {
        // Check if requesting references for classification
        if (isset($_GET['references']) && isset($_GET['id'])) {
            $payment_id = (int)$_GET['id'];
            $stmt = $pdo->prepare("SELECT customer_id FROM payments WHERE id = ?");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($payment) {
                getCustomerReferences($pdo, $payment['customer_id']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Payment not found']);
            }
            exit;
        }
        
        // Check if requesting a specific payment (id set by router)
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $payment_id = (int)$_GET['id'];
            
            // Check if requesting installments
            if (isset($_GET['installments'])) {
                // GET /api/customer/payments/{id}/installments
                
                $stmt = $pdo->prepare("
                    SELECT order_id FROM payments
                    WHERE id = ? AND customer_id = ?
                ");
                $stmt->execute([$payment_id, $user_id]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$payment) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Payment not found']);
                    exit;
                }
                
                $stmt = $pdo->prepare("
                    SELECT * FROM installment_schedules
                    WHERE order_id = ?
                    ORDER BY period_number ASC
                ");
                $stmt->execute([$payment['order_id']]);
                $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'installments' => $installments,
                        'count' => count($installments)
                    ]
                ]);
                
            } else {
                // GET /api/customer/payments/{id} - Include extra data for classification
                $stmt = $pdo->prepare("
                    SELECT 
                        p.*,
                        o.order_no,
                        o.product_name,
                        o.total_amount as order_total,
                        c.platform_user_name,
                        c.metadata as conversation_metadata,
                        u.full_name as customer_name,
                        u.email as customer_email,
                        u.phone as customer_phone
                    FROM payments p
                    LEFT JOIN orders o ON p.order_id = o.id
                    LEFT JOIN conversations c ON JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.conversation_id')) = c.conversation_id
                    LEFT JOIN users u ON p.customer_id = u.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$payment_id]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$payment) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Payment not found']);
                    exit;
                }
                
                // Decode JSON
                $payment['payment_details'] = $payment['payment_details'] ? 
                    json_decode($payment['payment_details'], true) : null;
                $payment['conversation_metadata'] = $payment['conversation_metadata'] ? 
                    json_decode($payment['conversation_metadata'], true) : null;
                
                // ✅ Get related chat messages for this payment
                $chatMessages = getPaymentChatMessages($pdo, $payment);
                $payment['chat_messages'] = $chatMessages;
                
                echo json_encode(['success' => true, 'data' => $payment]);
            }
            
        } else {
            // GET /api/customer/payments - List all
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
            $offset = ($page - 1) * $limit;
            
            $status = isset($_GET['status']) ? $_GET['status'] : null;
            $payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : null;
            
            // Build query - Shop owner sees all payments (not filtered by customer_id)
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
            
            $where_clause = implode(' AND ', $where);
            
            // Get total count
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM payments p
                WHERE $where_clause
            ");
            $stmt->execute($params);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get payments with customer info
            $stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    p.payment_no,
                    p.order_id,
                    p.amount,
                    p.payment_type,
                    p.payment_method,
                    p.installment_period,
                    p.current_period,
                    p.status,
                    p.slip_image,
                    p.payment_date,
                    p.verified_at,
                    p.created_at,
                    p.reference_type,
                    p.reference_id,
                    p.customer_platform,
                    p.customer_name,
                    p.customer_avatar,
                    o.order_no,
                    o.product_name,
                    u.full_name as user_full_name
                FROM payments p
                LEFT JOIN orders o ON p.order_id = o.id
                LEFT JOIN users u ON p.customer_id = u.id
                WHERE $where_clause
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process payments to use fallback for customer_name
            foreach ($payments as &$payment) {
                if (empty($payment['customer_name'])) {
                    $payment['customer_name'] = $payment['user_full_name'] ?? 'ไม่ระบุลูกค้า';
                }
                unset($payment['user_full_name']);
            }
            unset($payment);
            
            // Get summary counts
            $stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified_count,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
                FROM payments
            ");
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'payments' => $payments,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'total_pages' => ceil($total / $limit)
                    ],
                    'summary' => $summary
                ]
            ]);
        }
        
    } elseif ($method === 'POST' && end($uri_parts) === 'notify') {
        // POST /api/customer/payments/notify - Submit payment notification
        
        // Handle file upload (slip image)
        $slip_url = null;
        if (isset($_FILES['slip_image']) && $_FILES['slip_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../public/uploads/slips/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_ext = pathinfo($_FILES['slip_image']['name'], PATHINFO_EXTENSION);
            $filename = 'slip_' . $user_id . '_' . time() . '.' . $file_ext;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['slip_image']['tmp_name'], $filepath)) {
                $slip_url = '/public/uploads/slips/' . $filename;
            }
        }
        
        // Get form data
        $order_id = $_POST['order_id'] ?? null;
        $amount = $_POST['amount'] ?? null;
        $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
        $payment_type = $_POST['payment_type'] ?? 'full';
        $current_period = $_POST['current_period'] ?? null;
        $bank_name = $_POST['bank_name'] ?? '';
        $transfer_time = $_POST['transfer_time'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        if (!$order_id || !$amount) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        // Verify order belongs to user
        $stmt = $pdo->prepare("
            SELECT id, installment_months, payment_type 
            FROM orders 
            WHERE id = ? AND customer_id = ?
        ");
        $stmt->execute([$order_id, $user_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }
        
        // Generate payment number
        $payment_no = 'PAY-' . date('Ymd') . '-' . str_pad($user_id, 3, '0', STR_PAD_LEFT) . '-' . time();
        
        // Prepare payment details JSON
        $payment_details = json_encode([
            'bank_info' => [
                'bank_name' => $bank_name,
                'transfer_time' => $transfer_time
            ],
            'notes' => $notes,
            'submitted_via' => 'web'
        ]);
        
        // Insert payment
        $stmt = $pdo->prepare("
            INSERT INTO payments (
                payment_no, order_id, customer_id, tenant_id,
                amount, payment_type, payment_method,
                installment_period, current_period,
                status, slip_image, payment_details,
                payment_date, source
            ) VALUES (?, ?, ?, 'default', ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), 'web')
        ");
        
        $stmt->execute([
            $payment_no,
            $order_id,
            $user_id,
            $amount,
            $payment_type,
            $payment_method,
            $order['installment_months'],
            $current_period,
            $slip_url,
            $payment_details
        ]);
        
        $payment_id = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Payment notification submitted successfully',
            'data' => [
                'payment_id' => $payment_id,
                'payment_no' => $payment_no,
                'status' => 'pending'
            ]
        ]);
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Payments API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Get customer references (savings, installments, orders) for classification
 */
function getCustomerReferences($pdo, $customer_id) {
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
            'orders' => $orders,
            'installments' => $installments,
            'savings' => $savings
        ]
    ]);
}

/**
 * Classify and approve payment (sync to appropriate table)
 * Supports both simple mode (just type) and full mode (with reference)
 */
function classifyAndApprove($pdo, $payment, $input, $user_id) {
    $payment_type = $input['payment_type'] ?? null;
    $reference_type = $input['reference_type'] ?? null;
    $reference_id = $input['reference_id'] ?? null;
    $period_number = $input['period_number'] ?? $input['current_period'] ?? null;
    $installment_period = $input['installment_period'] ?? null;
    $notes = $input['notes'] ?? $input['classification_notes'] ?? '';
    
    // Map frontend payment_type to database enum
    $typeMapping = [
        'full' => 'full',
        'installment' => 'installment', 
        'savings' => 'savings_deposit',
        'savings_deposit' => 'savings_deposit'
    ];
    
    $dbPaymentType = $typeMapping[$payment_type] ?? $payment_type;
    
    if (!$payment_type || !in_array($payment_type, ['full', 'installment', 'savings', 'savings_deposit'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาเลือกประเภทการชำระเงิน (full/installment/savings)']);
        return;
    }
    
    // ✅ Simple mode: No reference required, just classify and approve
    $simpleMode = empty($reference_type) && empty($reference_id);
    
    // If NOT simple mode, validate references
    if (!$simpleMode) {
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
        
        if (($payment_type === 'savings' || $payment_type === 'savings_deposit') && $reference_type !== 'savings_account') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ประเภทออมเงินต้องเลือกบัญชีออม']);
            return;
        }
    }
    
    if ($payment['status'] !== 'pending' && $payment['status'] !== 'verifying') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'การชำระเงินนี้ได้รับการตรวจสอบแล้ว']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        $payment_id = $payment['id'];
        $amount = (float)$payment['amount'];
        
        // Build dynamic update query
        $updateFields = [
            'payment_type = ?',
            'classification_notes = ?',
            'status = ?',
            'verified_by = ?',
            'verified_at = NOW()'
        ];
        $updateValues = [
            $dbPaymentType,
            $notes,
            'verified',
            $user_id
        ];
        
        // Add reference fields if provided
        if (!$simpleMode) {
            $updateFields[] = 'reference_type = ?';
            $updateFields[] = 'reference_id = ?';
            $updateValues[] = $reference_type;
            $updateValues[] = $reference_id;
        }
        
        // Add installment period if provided
        if ($payment_type === 'installment' && $period_number) {
            $updateFields[] = 'current_period = ?';
            $updateValues[] = $period_number;
        }
        if ($payment_type === 'installment' && $installment_period) {
            $updateFields[] = 'installment_period = ?';
            $updateValues[] = $installment_period;
        }
        
        $updateValues[] = $payment_id;
        
        $sql = "UPDATE payments SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($updateValues);
        
        // Sync to appropriate table (only if reference provided)
        $sync_result = null;
        if (!$simpleMode) {
            if ($payment_type === 'installment' && $reference_id) {
                $sync_result = syncToInstallment($pdo, $payment, $reference_id, $period_number, $user_id);
            } elseif (($payment_type === 'savings' || $payment_type === 'savings_deposit') && $reference_id) {
                $sync_result = syncToSavings($pdo, $payment, $reference_id, $user_id);
            } elseif ($payment_type === 'full' && $reference_id) {
                $sync_result = syncToOrder($pdo, $payment, $reference_id);
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'อนุมัติและบันทึกการชำระเงินเรียบร้อยแล้ว',
            'data' => [
                'payment_id' => $payment_id,
                'payment_type' => $payment_type,
                'reference_type' => $reference_type,
                'reference_id' => $reference_id,
                'sync_result' => $sync_result
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Simple approve payment (without classification)
 */
function approvePayment($pdo, $payment, $user_id) {
    if ($payment['status'] === 'verified') {
        echo json_encode(['success' => true, 'message' => 'การชำระเงินนี้อนุมัติแล้ว']);
        return;
    }
    
    $stmt = $pdo->prepare("
        UPDATE payments 
        SET status = 'verified',
            verified_by = ?,
            verified_at = NOW()
        WHERE id = ? AND status = 'pending'
    ");
    $stmt->execute([$user_id, $payment['id']]);
    
    // If payment has order_id, update order's paid amount
    if ($payment['order_id']) {
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET paid_amount = COALESCE(paid_amount, 0) + ?,
                status = CASE 
                    WHEN COALESCE(paid_amount, 0) + ? >= total_amount THEN 'processing'
                    ELSE status 
                END,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$payment['amount'], $payment['amount'], $payment['order_id']]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'อนุมัติการชำระเงินเรียบร้อยแล้ว'
    ]);
}

/**
 * Reject payment
 */
function rejectPayment($pdo, $payment, $input, $user_id) {
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
    $stmt->execute([$reason, $user_id, $payment['id']]);
    
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
 * Sync payment to installment_payments table
 */
function syncToInstallment($pdo, $payment, $contract_id, $period_number, $user_id) {
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
        $user_id,
        $payment['slip_image'] ?? null,
        'PAY-' . $payment_id,
        'Synced from payment #' . $payment_id
    ]);
    
    $new_payment_id = $pdo->lastInsertId();
    
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
    
    return [
        'type' => 'installment',
        'installment_payment_id' => $new_payment_id,
        'payment_no' => $payment_no,
        'period_number' => $period_number
    ];
}

/**
 * Sync payment to savings_transactions table
 */
function syncToSavings($pdo, $payment, $savings_id, $user_id) {
    $amount = (float)$payment['amount'];
    $payment_id = $payment['id'];
    
    // Generate transaction_no
    $tx_no = 'SAVTX-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    
    // Get current balance
    $stmt = $pdo->prepare("SELECT current_amount, target_amount FROM savings_accounts WHERE id = ?");
    $stmt->execute([$savings_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        throw new Exception("Savings account not found");
    }
    
    $current = (float)($account['current_amount'] ?? 0);
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
        $user_id,
        'Synced from payment #' . $payment_id
    ]);
    
    $tx_id = $pdo->lastInsertId();
    
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
    
    return [
        'type' => 'savings',
        'transaction_id' => $tx_id,
        'transaction_no' => $tx_no,
        'balance_after' => $balance_after
    ];
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
    
    // Update payment's order_id reference
    $stmt = $pdo->prepare("UPDATE payments SET order_id = ? WHERE id = ?");
    $stmt->execute([$order_id, $payment['id']]);
    
    return [
        'type' => 'order',
        'order_id' => $order_id,
        'new_paid_amount' => $new_paid,
        'fully_paid' => $new_paid >= $total
    ];
}

/**
 * ✅ Get chat messages related to a payment
 * Looks for messages around the payment time from the same customer
 */
function getPaymentChatMessages($pdo, $payment) {
    $messages = [];
    
    try {
        // Try to get conversation_id from payment_details
        $conversationId = null;
        $customerId = $payment['customer_id'] ?? null;
        $customerPlatform = $payment['customer_platform'] ?? 'line';
        
        if (!empty($payment['payment_details']['conversation_id'])) {
            $conversationId = $payment['payment_details']['conversation_id'];
        }
        
        // If no direct conversation_id, try to find by customer platform user ID
        if (!$conversationId && !empty($payment['payment_details']['platform_user_id'])) {
            $platformUserId = $payment['payment_details']['platform_user_id'];
            $conversationId = $customerPlatform . '_%_' . $platformUserId;
        }
        
        // Get payment date for time-based filtering
        $paymentDate = $payment['payment_date'] ?? $payment['created_at'];
        
        // Query chat_messages table
        if ($conversationId) {
            // Get messages from 1 hour before to 30 minutes after payment
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    message_id,
                    direction,
                    sender_type,
                    sender_id,
                    message_type,
                    message_text,
                    message_data,
                    sent_at,
                    created_at
                FROM chat_messages
                WHERE conversation_id LIKE ?
                AND sent_at BETWEEN DATE_SUB(?, INTERVAL 1 HOUR) AND DATE_ADD(?, INTERVAL 30 MINUTE)
                ORDER BY sent_at ASC
                LIMIT 50
            ");
            $stmt->execute([$conversationId, $paymentDate, $paymentDate]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse message_data JSON
            foreach ($messages as &$msg) {
                if (!empty($msg['message_data'])) {
                    $msg['message_data'] = json_decode($msg['message_data'], true);
                }
            }
            unset($msg);
        }
        
        // If no messages found, try broader search by customer
        if (empty($messages) && $customerId) {
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    message_id,
                    direction,
                    sender_type,
                    sender_id,
                    message_type,
                    message_text,
                    message_data,
                    sent_at,
                    created_at
                FROM chat_messages
                WHERE sender_id = ?
                AND sent_at BETWEEN DATE_SUB(?, INTERVAL 2 HOUR) AND DATE_ADD(?, INTERVAL 1 HOUR)
                ORDER BY sent_at ASC
                LIMIT 30
            ");
            $stmt->execute([$customerId, $paymentDate, $paymentDate]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse message_data JSON
            foreach ($messages as &$msg) {
                if (!empty($msg['message_data'])) {
                    $msg['message_data'] = json_decode($msg['message_data'], true);
                }
            }
            unset($msg);
        }
        
    } catch (Exception $e) {
        // Log error but don't fail the request
        error_log("Error getting payment chat messages: " . $e->getMessage());
    }
    
    return $messages;
}
