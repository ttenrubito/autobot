<?php
/**
 * Admin Menu Configuration API
 * GET /api/admin/menu-config/{user_id} - Get user menu config
 * POST /api/admin/menu-config - Save menu config
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
$uri = $_SERVER['REQUEST_URI'];
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));

try {
    $pdo = getDB();
    
    if ($method === 'GET' && isset($uri_parts[3])) {
        // GET /api/admin/menu-config/{user_id}
        $user_id = (int)$uri_parts[3];
        
        // Get user email
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        
        // Get menu config
        $stmt = $pdo->prepare("
            SELECT menu_items, is_active
            FROM user_menu_config
            WHERE user_email = ?
        ");
        $stmt->execute([$user['email']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($config) {
            $config['menu_items'] = json_decode($config['menu_items'], true);
        }
        
        echo json_encode(['success' => true, 'data' => $config ?: []]);
        
    } elseif ($method === 'POST') {
        // POST /api/admin/menu-config
        $input = json_decode(file_get_contents('php://input'), true);
        
        $user_email = $input['user_email'] ?? null;
        $menu_items = $input['menu_items'] ?? null;
        
        if (!$user_email || !$menu_items) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        // Upsert menu config
        $stmt = $pdo->prepare("
            INSERT INTO user_menu_config (user_email, menu_items, is_active)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE
                menu_items = ?,
                updated_at = NOW()
        ");
        
        $menu_json = json_encode($menu_items);
        $stmt->execute([$user_email, $menu_json, $menu_json]);
        
        echo json_encode(['success' => true, 'message' => 'Menu configuration saved']);
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Admin Menu Config API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}
