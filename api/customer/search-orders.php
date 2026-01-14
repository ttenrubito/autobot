<?php
/**
 * Search Orders API
 * GET /api/customer/search-orders.php?q=search_term
 * 
 * Returns matching orders for autocomplete
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
$query = trim($_GET['q'] ?? '');

if (strlen($query) < 1) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $pdo = getDB();
    
    // Get tenant_id from user (same pattern as cases.php)
    $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_id = $user['tenant_id'] ?? 'default';
    
    // Search orders by tenant_id (same pattern as cases.php)
    // Fallback to user_id if tenant_id column doesn't exist
    $searchTerm = '%' . $query . '%';
    
    // Check if orders table has tenant_id column
    $colCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'tenant_id'");
    $hasTenantId = $colCheck->rowCount() > 0;
    
    if ($hasTenantId) {
        // Use tenant_id (preferred - same as cases.php)
        // Note: orders table uses customer_id to link to customer_profiles
        $stmt = $pdo->prepare("
            SELECT 
                o.id,
                COALESCE(o.order_number, o.order_no) as order_number,
                COALESCE(o.order_number, o.order_no) as order_no,
                o.product_name as customer_name,
                o.total_amount,
                COALESCE(o.total_amount - o.remaining_amount, 0) as paid_amount,
                o.status,
                o.status as payment_status,
                o.created_at
            FROM orders o
            WHERE o.tenant_id = ?
            AND (
                o.order_number LIKE ? 
                OR o.order_no LIKE ?
                OR o.product_name LIKE ?
                OR CAST(o.id AS CHAR) LIKE ?
            )
            ORDER BY o.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$tenant_id, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    } else {
        // Fallback to customer_id if tenant_id column doesn't exist yet
        $stmt = $pdo->prepare("
            SELECT 
                o.id,
                COALESCE(o.order_number, o.order_no) as order_number,
                COALESCE(o.order_number, o.order_no) as order_no,
                o.product_name as customer_name,
                o.total_amount,
                COALESCE(o.total_amount - o.remaining_amount, 0) as paid_amount,
                o.status,
                o.status as payment_status,
                o.created_at
            FROM orders o
            WHERE (
                o.order_number LIKE ? 
                OR o.order_no LIKE ?
                OR o.product_name LIKE ?
                OR CAST(o.id AS CHAR) LIKE ?
            )
            ORDER BY o.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate remaining amount
    foreach ($orders as &$order) {
        $order['remaining_amount'] = $order['total_amount'] - ($order['paid_amount'] ?? 0);
    }
    unset($order);
    
    echo json_encode([
        'success' => true,
        'data' => $orders
    ]);
    
} catch (Exception $e) {
    error_log("Search orders error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการค้นหา'
    ]);
}
