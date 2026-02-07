<?php
/**
 * Check Database Schema
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

$key = $_GET['key'] ?? '';
if ($key !== 'check-schema-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $pdo = getDB();
    $results = [];
    
    $table = $_GET['table'] ?? 'orders';
    
    // Get table create statement
    $stmt = $pdo->query("SHOW CREATE TABLE {$table}");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $results['create_table'] = $row['Create Table'] ?? null;
    
    // Get columns
    $stmt = $pdo->query("SHOW COLUMNS FROM {$table}");
    $results['columns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
