<?php
/**
 * Search Orders API
 * GET /api/customer/search-orders.php?q=search_term
 * 
 * Returns matching orders for autocomplete
 * Includes customer profile info and filters out closed orders
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
$platformUserIdFilter = trim($_GET['platform_user_id'] ?? '');

if (strlen($query) < 1) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $pdo = getDB();

    // Get tenant_id from user
    $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_id = $user['tenant_id'] ?? 'default';

    $searchTerm = '%' . $query . '%';

    // Build WHERE clause
    $whereConditions = ["o.tenant_id = :tenant_id"];
    $params = [':tenant_id' => $tenant_id];
    
    // ✅ Filter by platform_user_id if provided (for customer-specific order search)
    if (!empty($platformUserIdFilter)) {
        $whereConditions[] = "o.platform_user_id = :platform_user_id";
        $params[':platform_user_id'] = $platformUserIdFilter;
    }
    
    // Search conditions
    $whereConditions[] = "(
        o.order_number LIKE :q1
        OR o.order_no LIKE :q2
        OR o.product_name LIKE :q3
        OR CAST(o.id AS CHAR) LIKE :q4
        OR cp.display_name LIKE :q5
        OR cp.full_name LIKE :q6
        OR cp.phone LIKE :q7
        OR o.platform_user_id LIKE :q8
    )";
    $params[':q1'] = $searchTerm;
    $params[':q2'] = $searchTerm;
    $params[':q3'] = $searchTerm;
    $params[':q4'] = $searchTerm;
    $params[':q5'] = $searchTerm;
    $params[':q6'] = $searchTerm;
    $params[':q7'] = $searchTerm;
    $params[':q8'] = $searchTerm;
    
    // Exclude closed orders
    $whereConditions[] = "o.status NOT IN ('paid', 'completed', 'cancelled', 'refunded', 'delivered')";
    $whereConditions[] = "(o.payment_status IS NULL OR o.payment_status NOT IN ('paid', 'completed', 'refunded'))";

    // ✅ JOIN with customer_profiles to search by customer name
    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            COALESCE(o.order_number, o.order_no) as order_number,
            COALESCE(o.order_number, o.order_no) as order_no,
            o.product_name,
            o.total_amount,
            COALESCE(o.paid_amount, 0) as paid_amount,
            COALESCE(o.remaining_amount, o.total_amount - COALESCE(o.paid_amount, 0)) as remaining_amount,
            o.status,
            COALESCE(o.payment_status, o.status) as payment_status,
            o.payment_type,
            o.platform_user_id,
            o.created_at,
            -- Customer info from customer_profiles
            COALESCE(cp.display_name, cp.full_name, o.product_name) as customer_name,
            cp.avatar_url as customer_avatar,
            cp.platform as customer_platform,
            cp.phone as customer_phone
        FROM orders o
        LEFT JOIN customer_profiles cp ON cp.platform_user_id = o.platform_user_id AND cp.platform = o.platform
        WHERE " . implode(" AND ", $whereConditions) . "
        ORDER BY o.created_at DESC
        LIMIT 15
    ");
    $stmt->execute($params);

    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate remaining amount
    foreach ($orders as &$order) {
        $order['remaining_amount'] = floatval($order['total_amount']) - floatval($order['paid_amount'] ?? 0);
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
