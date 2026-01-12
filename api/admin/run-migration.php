<?php
/**
 * Run SQL Migration
 * 
 * SECURITY: This endpoint should be protected in production
 * It requires a secret key to run migrations
 */

header('Content-Type: application/json');

// Security check - require secret key
$secretKey = getenv('MIGRATION_SECRET') ?: 'autobot-migrate-2026';
$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';

if ($providedKey !== $secretKey) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get migration file name
$migrationFile = $_GET['file'] ?? $_POST['file'] ?? '';

if (empty($migrationFile)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Migration file name required']);
    exit;
}

// Security: Only allow files from migrations directory
$migrationFile = basename($migrationFile); // Prevent directory traversal
$migrationsDir = __DIR__ . '/../../database/migrations/';
$filePath = $migrationsDir . $migrationFile;

if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode([
        'success' => false, 
        'error' => 'Migration file not found',
        'file' => $migrationFile
    ]);
    exit;
}

// Connect to database - include global config first
require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();
    
    // Read SQL file
    $sql = file_get_contents($filePath);
    
    // Remove comments first
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Split by semicolons and filter empty statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            $clean = trim($stmt);
            return !empty($clean);
        }
    );
    
    $executed = 0;
    $errors = [];
    
    foreach ($statements as $statement) {
        try {
            // Clean up but keep the statement
            $cleanStatement = trim($statement);
            
            if (empty($cleanStatement)) continue;
            
            $pdo->exec($cleanStatement);
            $executed++;
        } catch (PDOException $e) {
            // Continue on "table already exists" or "column already exists" errors
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $errors[] = [
                    'statement' => substr($statement, 0, 100) . '...',
                    'error' => $e->getMessage(),
                    'skipped' => true
                ];
                continue;
            }
            $errors[] = [
                'statement' => substr($statement, 0, 100) . '...',
                'error' => $e->getMessage()
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Migration completed',
        'file' => $migrationFile,
        'statements_executed' => $executed,
        'total_statements' => count($statements),
        'errors' => $errors
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
