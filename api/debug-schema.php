<?php
/**
 * DEBUG endpoint to check table schema
 * REMOVE AFTER DEBUGGING
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Only for internal debugging
$key = $_GET['key'] ?? '';
if ($key !== 'debug-payments-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $pdo = getDB();
    
    $table = $_GET['table'] ?? 'payments';
    $table = preg_replace('/[^a-z_]/', '', $table);
    
    $stmt = $pdo->query("DESCRIBE `{$table}`");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'table' => $table,
        'columns' => $columns
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
