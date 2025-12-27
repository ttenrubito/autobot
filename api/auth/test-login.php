<?php
// Simple test login without all the complexity
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

try {
    // Direct DB connection
    $mysqli = new mysqli('localhost', 'root', '', 'autobot');
    
    if ($mysqli->connect_error) {
        die(json_encode(['success' => false, 'message' => 'DB Error: ' . $mysqli->connect_error]));
    }
    
    // Get user
    $stmt = $mysqli->prepare("SELECT id, email, password_hash, full_name FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid password']);
        exit;
    }
    
    // Start session
    session_start();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = [
        'id' => $user['id'],
        'email' => $user['email'],
        'name' => $user['full_name']
    ];
    
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => $_SESSION['user']
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
