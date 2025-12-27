<?php
/**
 * Simple Admin Login API (No dependencies)
 * POST /api/admin/login
 */

// Load dependencies FIRST (same as customer login)
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/JWT.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username and password are required']);
        exit;
    }
    
    // Use Database singleton (same as customer login)
    $db = Database::getInstance();
    
    // Get admin user (using queryOne method)
    $admin = $db->queryOne(
        "SELECT id, username, password_hash, full_name, email, role, is_active 
         FROM admin_users 
         WHERE username = ? 
         LIMIT 1",
        [$username]
    );
    
    if (!$admin) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        exit;
    }
    
    if (!$admin['is_active']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is disabled']);
        exit;
    }
    
    // Verify password
    if (!password_verify($password, $admin['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        exit;
    }
    
    // Success! Update last login
    $db->execute(
        "UPDATE admin_users SET last_login = NOW() WHERE id = ?",
        [$admin['id']]
    );
    
    // Generate JWT token
    $payload = [
        'admin_id' => (int)$admin['id'],
        'username' => $admin['username'],
        'role' => $admin['role'],
        'type' => 'admin'
    ];
    
    // 7 days expiry
    $token = JWT::generate($payload, 7 * 24 * 60 * 60);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'token' => $token,
            'admin' => [
                'id' => (int)$admin['id'],
                'username' => $admin['username'],
                'full_name' => $admin['full_name'],
                'email' => $admin['email'],
                'role' => $admin['role']
            ]
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Admin Login Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database Connection Error: ' . $e->getMessage()]);
}
