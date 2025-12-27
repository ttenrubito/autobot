<?php
/**
 * Admin Payments API
 * GET /api/admin/payments - List all payments with stats
 * GET /api/admin/payments/{id} - Get payment details
 * PUT /api/admin/payments/{id}/approve - Approve payment
 * PUT /api/admin/payments/{id}/reject - Reject payment
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/AdminAuth.php';

// Verify admin authentication
$auth = verifyAdminToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = getDB();
    
    if ($method === 'GET') {
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            // GET /api/admin/payments/{id}
            $payment_id = (int)$_GET['id'];
            
            $stmt = $pdo->prepare("
                SELECT 
                    p.*,
                    o.order_no,
                    o.product_name,
                    u.full_name as customer_name,
                    u.email as customer_email
                FROM payments p
                JOIN orders o ON p.order_id = o.id
                JOIN users u ON p.customer_id = u.id
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
            
            echo json_encode(['success' => true, 'data' => $payment]);
            
        } else {
            // GET /api/admin/payments - List all
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
            $offset = ($page - 1) * $limit;
            
            $status = isset($_GET['status']) ? $_GET['status'] : null;
            $payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : null;
            $search = isset($_GET['search']) ? $_GET['search'] : null;
            
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
            
            if ($search) {
                $where[] = '(p.payment_no LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $where_clause = implode(' AND ', $where);
            
            // Get stats
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN status = 'verifying' THEN 1 END) as verifying,
                    COUNT(CASE WHEN status = 'verified' THEN 1 END) as verified,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected
                FROM payments p
                JOIN users u ON p.customer_id = u.id
                WHERE $where_clause
            ");
            $stmt->execute($params);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get total count
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM payments p
                JOIN users u ON p.customer_id = u.id
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
                    p.payment_date,
                    p.created_at,
                    o.order_no,
                    o.product_name,
                    u.full_name as customer_name,
                    u.email as customer_email
                FROM payments p
                JOIN orders o ON p.order_id = o.id
                JOIN users u ON p.customer_id = u.id
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
                    'stats' => $stats,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'total_pages' => ceil($total / $limit)
                    ]
                ]
            ]);
        }
        
    } elseif ($method === 'PUT' && isset($_GET['id']) && is_numeric($_GET['id'])) {
        $payment_id = (int)$_GET['id'];
        $action = $_GET['action'] ?? null;
        
        if ($action === 'approve') {
            // PUT /api/admin/payments/{id}/approve
            
            // Get payment info
            $stmt = $pdo->prepare("
                SELECT p.*, o.installment_months
                FROM payments p
                JOIN orders o ON p.order_id = o.id
                WHERE p.id = ?
            ");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Payment not found']);
                exit;
            }
            
            // Update payment status
            $stmt = $pdo->prepare("
                UPDATE payments
                SET status = 'verified',
                    verified_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$payment_id]);
            
            // If installment, update schedule
            if ($payment['payment_type'] === 'installment' && $payment['current_period']) {
                $stmt = $pdo->prepare("
                    UPDATE installment_schedules
                    SET status = 'paid',
                        paid_amount = amount,
                        paid_at = NOW(),
                        payment_id = ?
                    WHERE order_id = ? AND period_number = ?
                ");
                $stmt->execute([
                    $payment_id,
                    $payment['order_id'],
                    $payment['current_period']
                ]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Payment approved']);
            
        } elseif ($action === 'reject') {
            // PUT /api/admin/payments/{id}/reject
            
            $input = json_decode(file_get_contents('php://input'), true);
            $reason = $input['reason'] ?? 'ไม่ระบุเหตุผล';
            
            $stmt = $pdo->prepare("
                UPDATE payments
                SET status = 'rejected',
                    rejection_reason = ?,
                    verified_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reason, $payment_id]);
            
            echo json_encode(['success' => true, 'message' => 'Payment rejected']);
            
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Admin Payments API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}
