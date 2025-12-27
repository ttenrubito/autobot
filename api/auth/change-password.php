<?php
/**
 * Change Password API Endpoint
 * POST /api/auth/change-password
 * 
 * Request Body:
 * {
 *   "current_password": "old_pass",
 *   "new_password": "new_pass",
 *   "confirm_password": "new_pass"
 * }
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Validator.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

// Require authenticated user
Auth::require();

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$currentPassword = $input['current_password'] ?? '';
$newPassword      = $input['new_password'] ?? '';

$validator = new Validator();
$validator->required('current_password', $currentPassword, 'Current password');
$validator->required('new_password', $newPassword, 'New password');

if ($validator->fails()) {
    Response::validationError($validator->getErrors());
}

if (strlen($newPassword) < 8) {
    Response::error('New password must be at least 8 characters long', 400);
}

try {
    $db = Database::getInstance();
    $userId = Auth::id();

    if (!$userId) {
        Response::unauthorized('Authentication required');
    }

    // Get current user with password hash
    $user = $db->queryOne(
        'SELECT id, password_hash FROM users WHERE id = ? LIMIT 1',
        [$userId]
    );

    if (!$user || empty($user['password_hash'])) {
        Response::error('User not found', 404);
    }

    // Verify current password
    if (!Auth::verifyPassword($currentPassword, $user['password_hash'])) {
        Response::error('รหัสผ่านปัจจุบันไม่ถูกต้อง', 400);
    }

    // Hash and update new password
    $newHash = Auth::hashPassword($newPassword);
    $db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$newHash, $userId]);

    // Optionally log activity
    $db->execute(
        'INSERT INTO activity_logs (user_id, action, ip_address, user_agent, created_at)
         VALUES (?, "change_password", ?, ?, NOW())',
        [$userId, Auth::getIpAddress(), Auth::getUserAgent()]
    );

    Response::success(null, 'เปลี่ยนรหัสผ่านสำเร็จ');

} catch (Exception $e) {
    error_log('Change Password Error: ' . $e->getMessage());
    Response::error('ไม่สามารถเปลี่ยนรหัสผ่านได้ กรุณาลองใหม่อีกครั้ง', 500);
}
