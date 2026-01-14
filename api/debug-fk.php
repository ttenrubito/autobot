<?php
/**
 * DEBUG: Check Foreign Key constraints
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
    
    $table = $_GET['table'] ?? null;
    
    if ($table) {
        $stmt = $pdo->prepare("
            SELECT 
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([$table]);
        $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'table' => $table,
            'foreign_keys' => $fks
        ], JSON_PRETTY_PRINT);
    } else {
        // Get all FKs
        $stmt = $pdo->query("
            SELECT 
                TABLE_NAME,
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME
        ");
        $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'all_foreign_keys' => $fks
        ], JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
