<?php
/**
 * Order API v2 (คำสั่งซื้อ) - Uses OrderService
 * 
 * REST Endpoints:
 * GET  /api/v2/orders                    - Get all orders for customer
 * GET  /api/v2/orders?id=X               - Get specific order detail
 * GET  /api/v2/orders?no=ORD123          - Get order by order number
 * GET  /api/v2/orders/pending            - Get pending orders only
 * POST /api/v2/orders/pay                - Link payment to order
 * PUT  /api/v2/orders/status             - Update order status (admin)
 * 
 * @version 2.0
 * @date 2026-01-31
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/services/OrderService.php';

use App\Services\OrderService;

// Parse path info
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', $pathInfo));
$action = $pathParts[1] ?? null;

$method = $_SERVER['REQUEST_METHOD'];

Auth::require();
$userId = Auth::id();
$platformUserId = null; // Platform user ID from chatbot context only

try {
    $db = Database::getInstance()->getPdo();
    $orderService = new OrderService($db);
    
    // Route based on method and action
    if ($method === 'GET') {
        switch ($action) {
            case 'pending':
                // Get pending orders only (by user_id for LIFF/portal, platform_user_id for chatbot)
                $result = $orderService->findPendingOrders("user:{$userId}");
                Response::success([
                    'items' => $result,
                    'count' => count($result)
                ]);
                break;
                
            default:
                // Get orders list or detail
                $orderId = $_GET['id'] ?? null;
                $orderNo = $_GET['no'] ?? null;
                
                if ($orderId) {
                    $order = $orderService->getOrderById((int)$orderId);
                    if ($order && $order['user_id'] === $userId) {
                        // Add order items
                        $order['items'] = getOrderItems($db, (int)$orderId);
                        Response::success($order);
                    } else {
                        Response::error('Order not found', 404);
                    }
                } elseif ($orderNo) {
                    $order = $orderService->getOrderByNo($orderNo);
                    if ($order && $order['user_id'] === $userId) {
                        $order['items'] = getOrderItems($db, $order['id']);
                        Response::success($order);
                    } else {
                        Response::error('Order not found', 404);
                    }
                } else {
                    // List all orders for this user
                    $orders = $orderService->getOrdersByCustomer("user:{$userId}");
                    Response::success([
                        'items' => $orders,
                        'count' => count($orders)
                    ]);
                }
        }
        
    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        
        switch ($action) {
            case 'pay':
                // Link payment to order
                $orderId = $body['order_id'] ?? null;
                $paymentId = $body['payment_id'] ?? null;
                
                if (!$orderId) {
                    Response::error('order_id required', 400);
                }
                
                $result = $orderService->linkPaymentToOrder((int)$orderId, $paymentId);
                
                if ($result['success']) {
                    Response::success($result);
                } else {
                    Response::error($result['error'] ?? 'Payment link failed', 400);
                }
                break;
                
            default:
                Response::error('Invalid action. Use: pay', 400);
        }
        
    } elseif ($method === 'PUT') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        
        switch ($action) {
            case 'status':
                // Update order status (check if admin)
                $orderId = $body['order_id'] ?? null;
                $status = $body['status'] ?? null;
                
                if (!$orderId || !$status) {
                    Response::error('order_id and status required', 400);
                }
                
                $result = $orderService->updateStatus((int)$orderId, $status);
                
                if ($result['success']) {
                    Response::success($result);
                } else {
                    Response::error($result['error'] ?? 'Status update failed', 400);
                }
                break;
                
            default:
                Response::error('Invalid action', 400);
        }
        
    } else {
        Response::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    error_log("Order API Error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}

/**
 * Get order items
 */
function getOrderItems(PDO $db, int $orderId): array
{
    $stmt = $db->prepare("
        SELECT oi.*, p.name as product_name, p.sku, p.primary_image_url
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
