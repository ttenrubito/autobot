<?php
/**
 * Migration: Add customer columns to cases table
 * Adds: customer_platform, customer_name, customer_avatar
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$results = [];

try {
    $pdo = getDB();
    
    // Check and add customer_platform
    $stmt = $pdo->query("SHOW COLUMNS FROM cases LIKE 'customer_platform'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN customer_platform VARCHAR(50) DEFAULT NULL AFTER external_user_id");
        $results['customer_platform'] = 'added';
    } else {
        $results['customer_platform'] = 'already exists';
    }
    
    // Check and add customer_name
    $stmt = $pdo->query("SHOW COLUMNS FROM cases LIKE 'customer_name'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN customer_name VARCHAR(255) DEFAULT NULL AFTER customer_platform");
        $results['customer_name'] = 'added';
    } else {
        $results['customer_name'] = 'already exists';
    }
    
    // Check and add customer_avatar
    $stmt = $pdo->query("SHOW COLUMNS FROM cases LIKE 'customer_avatar'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN customer_avatar TEXT DEFAULT NULL AFTER customer_name");
        $results['customer_avatar'] = 'added';
    } else {
        $results['customer_avatar'] = 'already exists';
    }
    
    echo json_encode([
        'success' => true,
        'results' => $results
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
