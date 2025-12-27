<?php
/**
 * Customer Orders API  
 * GET /api/customer/orders - Get all orders
 * GET /api/customer/orders/{id} - Get specific order
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
$uri = $_SERVER['REQUEST_URI'];

// Parse URI
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));

try {
    $pdo = getDB();
    
    if ($method === 'GET') {
        if (isset($uri_parts[3]) && is_numeric($uri_parts[3])) {
            // GET /api/customer/orders/{id}
            $order_id = (int)$uri_parts[3];
            
            $stmt = $pdo->prepare("
                SELECT 
                    o.*,
                    a.recipient_name,
                    a.phone,
                    a.address_line1,
                    a.address_line2,
                    a.subdistrict,
                    a.district,
                    a.province,
                    a.postal_code,
                    a.additional_info as address_additional_info
                FROM orders o
                LEFT JOIN customer_addresses a ON o.shipping_address_id = a.id
                WHERE o.id = ? AND o.customer_id = ?
            ");
            $stmt->execute([$order_id, $user_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Order not found']);
                exit;
            }
            
            // Get installment schedule if applicable
            $order['installment_schedule'] = null;
            if ($order['payment_type'] === 'installment') {
                $stmt = $pdo->prepare("
                    SELECT 
                        id,
                        period_number,
                        due_date,
                        amount,
                        paid_amount,
                        status,
                        paid_at
                    FROM installment_schedules
                    WHERE order_id = ?
                    ORDER BY period_number ASC
                ");
                $stmt->execute([$order_id]);
                $order['installment_schedule'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Get payments for this order
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    payment_no,
                    amount,
                    payment_method,
                    current_period,
                    status,
                    payment_date,
                    verified_at
                FROM payments
                WHERE order_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$order_id]);
            $order['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON
            $order['address_additional_info'] = $order['address_additional_info'] ? 
                json_decode($order['address_additional_info'], true) : null;
            
            echo json_encode(['success' => true, 'data' => $order]);
            
        } else {
            // GET /api/customer/orders - List all
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
            $offset = ($page - 1) * $limit;
            
            $status = isset($_GET['status']) ? $_GET['status'] : null;
            $payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : null;
            
            // Build query
            $where = ['o.customer_id = ?'];
            $params = [$user_id];

            if ($status) {
                $where[] = 'o.status = ?';
                $params[] = $status;
            }

            if ($payment_type) {
                $where[] = 'o.payment_type = ?';
                $params[] = $payment_type;
            }

            $where_clause = implode(' AND ', $where);

            // Get total count
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM orders o
                WHERE $where_clause
            ");
            $stmt->execute($params);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get orders
            $stmt = $pdo->prepare("
                SELECT 
                    o.id,
                    o.order_no,
                    o.product_name,
                    o.product_code,
                    o.quantity,
                    o.total_amount,
                    o.payment_type,
                    o.installment_months,
                    o.status,
                    o.source,
                    o.created_at,
                    o.shipped_at,
                    o.delivered_at,
                    a.province,
                    a.district
                FROM orders o
                LEFT JOIN customer_addresses a ON o.shipping_address_id = a.id
                WHERE $where_clause
                ORDER BY o.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add payment summary for each order
            foreach ($orders as &$order) {
                if ($order['payment_type'] === 'installment') {
                    $stmt = $pdo->prepare("
                        SELECT 
                            COUNT(*) as total_periods,
                            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_periods,
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_periods,
                            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_periods
                        FROM installment_schedules
                        WHERE order_id = ?
                    ");
                    $stmt->execute([$order['id']]);
                    $order['installment_summary'] = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $order['installment_summary'] = null;
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'orders' => $orders,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'total_pages' => ceil($total / $limit)
                    ]
                ]
            ]);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Orders API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}
