<?php
/**
 * Debug: Check customer_profiles data
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();
    
    // Count profiles
    $stmt = $pdo->query("SELECT COUNT(*) FROM customer_profiles");
    $count = $stmt->fetchColumn();
    
    // Get sample profiles
    $stmt = $pdo->query("SELECT * FROM customer_profiles ORDER BY created_at DESC LIMIT 10");
    $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total_profiles' => $count,
        'profiles' => $profiles
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
