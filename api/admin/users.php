<?php
/**
 * Admin Users API (for dropdowns and listings)
 * GET /api/admin/users - List users
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/AdminAuth.php';

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
        $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 100;
        
        $stmt = $pdo->prepare("
            SELECT id, email, full_name, phone, status, created_at
            FROM users
            WHERE status = 'active'
            ORDER BY full_name ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => ['users' => $users]]);
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Admin Users API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}
