<?php
/**
 * Customer Payments API
 * GET /api/customer/payments - Get all payments
 * GET /api/customer/payments/{id} - Get specific payment
 * GET /api/customer/payments/{id}/installments - Get installment schedule for a payment's order
 * POST /api/customer/payments/notify - Submit payment notification (upload slip)
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

try {
    $pdo = getDB();
    
    if ($method === 'GET') {
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
                // GET /api/customer/payments/{id}
                $stmt = $pdo->prepare("
                    SELECT 
                        p.*,
                        o.order_no,
                        o.product_name,
                        o.total_amount as order_total,
                        c.platform_user_name,
                        c.metadata as conversation_metadata
                    FROM payments p
                    JOIN orders o ON p.order_id = o.id
                    LEFT JOIN conversations c ON JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.conversation_id')) = c.conversation_id
                    WHERE p.id = ? AND p.customer_id = ?
                ");
                $stmt->execute([$payment_id, $user_id]);
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
                
                echo json_encode(['success' => true, 'data' => $payment]);
            }
            
        } else {
            // GET /api/customer/payments - List all
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
            $offset = ($page - 1) * $limit;
            
            $status = isset($_GET['status']) ? $_GET['status'] : null;
            $payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : null;
            
            // Build query
            $where = ['p.customer_id = ?'];
            $params = [$user_id];
            
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
            
            // Get payments
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
                    o.order_no,
                    o.product_name
                FROM payments p
                JOIN orders o ON p.order_id = o.id
                WHERE $where_clause
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'payments' => $payments,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'total_pages' => ceil($total / $limit)
                    ]
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
