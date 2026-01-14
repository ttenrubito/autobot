<?php
/**
 * Migration: Allow NULL for order_id and customer_id in payments table
 * Run this once after deploying
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$key = $_GET['key'] ?? '';
if ($key !== 'migrate-payments-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $pdo = getDB();
    
    $results = [];
    
    // ALTER order_id to allow NULL
    $pdo->exec("ALTER TABLE payments MODIFY order_id INT NULL");
    $results[] = "✅ order_id now allows NULL";
    
    // ALTER customer_id to allow NULL (or default to 0)
    $pdo->exec("ALTER TABLE payments MODIFY customer_id INT NULL DEFAULT 0");
    $results[] = "✅ customer_id now allows NULL";
    
    echo json_encode([
        'success' => true,
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
