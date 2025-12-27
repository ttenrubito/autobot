<?php
/**
 * Debug environment variables (DELETE AFTER USE!)
 * SECURITY: Remove this file after debugging
 */

header('Content-Type: application/json');

// Only allow from specific IP or add password protection
// $allowed_ip = 'YOUR_IP_HERE';
// if ($_SERVER['REMOTE_ADDR'] !== $allowed_ip) {
//     http_response_code(403);
//     echo json_encode(['error' => 'Forbidden']);
//     exit;
// }

$env_vars = [
    'INSTANCE_CONN_NAME' => getenv('INSTANCE_CONN_NAME') ?: 'NOT SET',
    'DB_NAME' => getenv('DB_NAME') ?: 'NOT SET',
    'DB_USER' => getenv('DB_USER') ?: 'NOT SET',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ? '***SET***' : 'NOT SET',
    'DB_HOST' => getenv('DB_HOST') ?: 'NOT SET',
    'DB_PORT' => getenv('DB_PORT') ?: 'NOT SET',
];

// Test database connection
$db_test = [
    'status' => 'unknown',
    'message' => '',
];

try {
    require_once __DIR__ . '/../includes/Database.php';
    $db = Database::getInstance();
    $result = $db->queryOne('SELECT 1 as test');
    $db_test = [
        'status' => 'success',
        'message' => 'Database connection successful',
        'test_query' => $result
    ];
} catch (Exception $e) {
    $db_test = [
        'status' => 'failed',
        'message' => $e->getMessage(),
        'type' => get_class($e)
    ];
}

echo json_encode([
    'environment_variables' => $env_vars,
    'database_test' => $db_test,
    'php_version' => phpversion(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
], JSON_PRETTY_PRINT);
