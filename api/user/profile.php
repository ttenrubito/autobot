<?php
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Auth.php';

Auth::require();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();
$userId = Auth::getUserId();

try {
    if ($method === 'GET') {
        // Get user profile
        $user = $db->queryOne('SELECT id, email, full_name, phone, company_name, status, created_at, last_login FROM users WHERE id = ?', [$userId]);
        
        if (!$user) {
            Response::error('User not found', 404);
        }
        
        Response::success(['user' => $user]);
        
    } elseif ($method === 'PUT') {
        // Update user profile
        $input = file_get_contents('php://input');
        $data = $input ? json_decode($input, true) : [];
        
        $fields = [];
        $params = [];
        
        if (isset($data['full_name'])) {
            $fields[] = 'full_name = ?';
            $params[] = trim($data['full_name']);
        }
        
        if (isset($data['phone'])) {
            $fields[] = 'phone = ?';
            $params[] = trim($data['phone']);
        }
        
        if (isset($data['company_name'])) {
            $fields[] = 'company_name = ?';
            $params[] = trim($data['company_name']);
        }
        
        if (empty($fields)) {
            Response::error('No fields to update', 400);
        }
        
        $params[] = $userId;
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        
        $db->execute($sql, $params);
        
        Response::success(['message' => 'Profile updated successfully']);
        
    } else {
        Response::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log('Profile API error: ' . $e->getMessage());
    Response::error('Server error', 500);
}
