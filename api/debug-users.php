<?php
/**
 * DEBUG: Query users
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$key = $_GET['key'] ?? '';
if ($key !== 'debug-payments-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $pdo = getDB();
    
    $stmt = $pdo->query("SELECT id, tenant_id, email, full_name, status FROM users ORDER BY id DESC LIMIT 10");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'users' => $users
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
