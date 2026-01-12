<?php
/**
 * User Menu Configuration API
 * Returns menu items that the current user is allowed to see
 */

// Suppress errors and handle them gracefully
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Auth.php';

// Validate session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Auth::require();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();
$userId = Auth::id();

try {
    if ($method === 'GET') {
        // Get user's email
        $user = $db->queryOne('SELECT email FROM users WHERE id = ?', [$userId]);
        
        if (!$user) {
            Response::error('User not found', 404);
        }
        
        $userEmail = $user['email'];
        
        // Try to get custom menu config
        try {
            $menuConfig = $db->queryOne(
                'SELECT menu_items FROM user_menu_config WHERE user_email = ? AND is_active = 1',
                [$userEmail]
            );
        } catch (Exception $e) {
            // Table might not exist yet - that's okay, use defaults
            error_log('Menu config table query failed (using defaults): ' . $e->getMessage());
            $menuConfig = null;
        }
        
        if ($menuConfig && !empty($menuConfig['menu_items'])) {
            // User has custom config
            $menuData = json_decode($menuConfig['menu_items'], true);
            
            if ($menuData && isset($menuData['menus'])) {
                // Filter only enabled menus
                $enabledMenus = array_filter($menuData['menus'], function($menu) {
                    return isset($menu['enabled']) && $menu['enabled'] === true;
                });
                
                Response::success([
                    'menus' => array_values($enabledMenus),
                    'custom_config' => true
                ]);
            }
        }
        
        // No custom config found - return default menus (all enabled)
        $defaultMenus = [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'enabled' => true, 'icon' => '📊', 'url' => 'dashboard.php'],
            ['id' => 'services', 'label' => 'บริการของฉัน', 'enabled' => true, 'icon' => '🤖', 'url' => 'services.php'],
            ['id' => 'usage', 'label' => 'การใช้งาน', 'enabled' => true, 'icon' => '📈', 'url' => 'usage.php'],
            ['id' => 'payment', 'label' => 'ชำระเงิน', 'enabled' => true, 'icon' => '💳', 'url' => 'payment.php'],
            ['id' => 'billing', 'label' => 'ใบแจ้งหนี้', 'enabled' => true, 'icon' => '📄', 'url' => 'billing.php'],
            ['id' => 'chat_history', 'label' => 'ประวัติการสนทนา', 'enabled' => true, 'icon' => '💬', 'url' => 'chat-history.php'],
            ['id' => 'conversations', 'label' => 'แชทกับลูกค้า', 'enabled' => true, 'icon' => '💭', 'url' => 'conversations.php'],
            ['id' => 'addresses', 'label' => 'ที่อยู่จัดส่ง', 'enabled' => true, 'icon' => '📍', 'url' => 'addresses.php'],
            ['id' => 'orders', 'label' => 'คำสั่งซื้อ', 'enabled' => true, 'icon' => '📦', 'url' => 'orders.php'],
            ['id' => 'payment_history', 'label' => 'ประวัติการชำระ(ตรวจ)', 'enabled' => true, 'icon' => '💰', 'url' => 'payment-history.php'],
            ['id' => 'campaigns', 'label' => 'จัดการแคมเปญ', 'enabled' => true, 'icon' => '🎯', 'url' => 'campaigns.php'],
            ['id' => 'line_applications', 'label' => 'ใบสมัคร LINE', 'enabled' => true, 'icon' => '📋', 'url' => 'line-applications.php'],
            ['id' => 'cases', 'label' => 'Case Inbox', 'enabled' => true, 'icon' => '📥', 'url' => 'cases.php'],
            ['id' => 'savings', 'label' => 'ออมเงิน', 'enabled' => true, 'icon' => '🐷', 'url' => 'savings.php'],
            ['id' => 'installments', 'label' => 'ผ่อนชำระ', 'enabled' => true, 'icon' => '📅', 'url' => 'installments.php'],
            ['id' => 'deposits', 'label' => 'มัดจำสินค้า', 'enabled' => true, 'icon' => '💎', 'url' => 'deposits.php'],
            ['id' => 'pawns', 'label' => 'ฝากจำนำ', 'enabled' => true, 'icon' => '🏆', 'url' => 'pawns.php'],
            ['id' => 'repairs', 'label' => 'งานซ่อม', 'enabled' => true, 'icon' => '🔧', 'url' => 'repairs.php'],
            ['id' => 'profile', 'label' => 'โปรไฟล์', 'enabled' => true, 'icon' => '👤', 'url' => 'profile.php'],
        ];
        
        Response::success([
            'menus' => $defaultMenus,
            'custom_config' => false
        ]);
        
    } else {
        Response::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log('Menu Config API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    Response::error('Server error: ' . $e->getMessage(), 500);
}
