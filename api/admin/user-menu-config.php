<?php
/**
 * Admin Menu Configuration API
 * Manage user menu configurations (CRUD)
 * Admin only access
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/AdminAuth.php';

AdminAuth::require();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();

try {
    if ($method === 'GET') {
        // Get menu config(s)
        $userEmail = $_GET['user_email'] ?? null;
        
        if ($userEmail) {
            // Get specific user config
            $config = $db->queryOne(
                'SELECT id, user_email, menu_items, is_active, created_at, updated_at 
                 FROM user_menu_config 
                 WHERE user_email = ?',
                [$userEmail]
            );
            
            if ($config) {
                $config['menu_items'] = json_decode($config['menu_items'], true);
            }
            
            Response::success(['config' => $config]);
        } else {
            // Get all configs
            $configs = $db->query(
                'SELECT id, user_email, menu_items, is_active, created_at, updated_at 
                 FROM user_menu_config 
                 ORDER BY created_at DESC'
            );
            
            foreach ($configs as &$config) {
                $config['menu_items'] = json_decode($config['menu_items'], true);
            }
            
            Response::success(['configs' => $configs]);
        }
        
    } elseif ($method === 'POST') {
        // Create or update menu config
        $input = file_get_contents('php://input');
        $data = $input ? json_decode($input, true) : [];
        
        if (empty($data['user_email'])) {
            Response::error('user_email is required', 400);
        }
        
        if (empty($data['menu_items'])) {
            Response::error('menu_items is required', 400);
        }
        
        $userEmail = trim($data['user_email']);
        $menuItems = $data['menu_items'];
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        
        // Validate menu_items structure
        if (!isset($menuItems['menus']) || !is_array($menuItems['menus'])) {
            Response::error('Invalid menu_items format. Expected {menus: [...]}', 400);
        }
        
        // Check if user exists
        $targetUser = $db->queryOne('SELECT id FROM users WHERE email = ?', [$userEmail]);
        if (!$targetUser) {
            Response::error('User not found', 404);
        }
        
        // Check if config already exists
        $existing = $db->queryOne('SELECT id FROM user_menu_config WHERE user_email = ?', [$userEmail]);
        
        $menuItemsJson = json_encode($menuItems, JSON_UNESCAPED_UNICODE);
        
        if ($existing) {
            // Update existing
            $db->execute(
                'UPDATE user_menu_config SET menu_items = ?, is_active = ?, updated_at = NOW() WHERE user_email = ?',
                [$menuItemsJson, $isActive, $userEmail]
            );
            
            Response::success([
                'message' => 'Menu configuration updated successfully',
                'user_email' => $userEmail
            ]);
        } else {
            // Insert new
            $db->execute(
                'INSERT INTO user_menu_config (user_email, menu_items, is_active) VALUES (?, ?, ?)',
                [$userEmail, $menuItemsJson, $isActive]
            );
            
            Response::success([
                'message' => 'Menu configuration created successfully',
                'user_email' => $userEmail
            ]);
        }
        
    } elseif ($method === 'DELETE') {
        // Delete menu config (reset to default)
        $input = file_get_contents('php://input');
        $data = $input ? json_decode($input, true) : [];
        
        if (empty($data['user_email'])) {
            Response::error('user_email is required', 400);
        }
        
        $userEmail = trim($data['user_email']);
        
        $db->execute('DELETE FROM user_menu_config WHERE user_email = ?', [$userEmail]);
        
        Response::success([
            'message' => 'Menu configuration deleted successfully (user will see default menus)',
            'user_email' => $userEmail
        ]);
        
    } else {
        Response::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log('Admin Menu Config API error: ' . $e->getMessage());
    Response::error('Server error: ' . $e->getMessage(), 500);
}
