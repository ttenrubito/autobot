<?php
/**
 * Customer Profiles API
 * 
 * สำหรับค้นหาลูกค้าแชท (LINE/Facebook) ที่อยู่ในตาราง customer_profiles
 * ใช้โดย pawns.php และหน้า admin อื่นๆ ที่ต้องการ reference ถึง end customer
 * 
 * Endpoints:
 * GET /api/customer-profiles.php?search=xxx       - ค้นหาลูกค้า
 * GET /api/customer-profiles.php?id=xxx           - ดูข้อมูลลูกค้า
 * GET /api/customer-profiles.php?platform_user_id=xxx - ค้นหาด้วย platform user id
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/Database.php';
    require_once __DIR__ . '/../includes/Response.php';

    // Simple auth check - ต้องมี Bearer token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $db = Database::getInstance();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Get by ID
    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $customer = $db->queryOne(
            "SELECT 
                id, 
                tenant_id,
                platform, 
                platform_user_id,
                display_name, 
                avatar_url,
                profile_pic_url,
                phone, 
                email, 
                full_name,
                notes,
                first_seen_at,
                last_active_at,
                total_inquiries,
                total_cases,
                last_case_at,
                created_at
             FROM customer_profiles 
             WHERE id = ?",
            [$id]
        );

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            exit;
        }

        echo json_encode(['success' => true, 'data' => $customer]);
        exit;
    }

    // Get by platform_user_id
    if (isset($_GET['platform_user_id'])) {
        $platformUserId = $_GET['platform_user_id'];
        $platform = $_GET['platform'] ?? null;

        $sql = "SELECT 
                id, 
                tenant_id,
                platform, 
                platform_user_id,
                display_name, 
                avatar_url,
                profile_pic_url,
                phone, 
                email, 
                full_name,
                notes,
                first_seen_at,
                last_active_at,
                total_inquiries,
                total_cases,
                last_case_at,
                created_at
             FROM customer_profiles 
             WHERE platform_user_id = ?";
        $params = [$platformUserId];

        if ($platform) {
            $sql .= " AND platform = ?";
            $params[] = $platform;
        }

        $customer = $db->queryOne($sql, $params);

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            exit;
        }

        echo json_encode(['success' => true, 'data' => $customer]);
        exit;
    }

    // Search customers
    $search = $_GET['search'] ?? '';
    $limit = min((int) ($_GET['limit'] ?? 10), 50);
    $platform = $_GET['platform'] ?? null;
    $tenantId = $_GET['tenant_id'] ?? 'default';

    $whereClauses = ["tenant_id = ?"];
    $params = [$tenantId];

    if ($search) {
        // ค้นหาจาก display_name, phone, email, full_name, platform_user_id
        $whereClauses[] = "(
            display_name LIKE ? OR 
            phone LIKE ? OR 
            email LIKE ? OR 
            full_name LIKE ? OR
            platform_user_id LIKE ?
        )";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($platform) {
        $whereClauses[] = "platform = ?";
        $params[] = $platform;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);
    $params[] = $limit;

    $customers = $db->query(
        "SELECT 
            id, 
            tenant_id,
            platform, 
            platform_user_id,
            display_name, 
            avatar_url,
            profile_pic_url,
            phone, 
            email, 
            full_name,
            notes,
            first_seen_at,
            last_active_at,
            total_inquiries,
            total_cases,
            last_case_at,
            created_at
         FROM customer_profiles 
         $whereSQL
         ORDER BY last_active_at DESC, display_name ASC
         LIMIT ?",
        $params
    );

    echo json_encode([
        'success' => true,
        'data' => $customers ?: []
    ]);

} catch (Exception $e) {
    error_log("Customer Profiles API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
