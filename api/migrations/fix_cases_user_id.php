<?php
/**
 * Fix cases user_id migration
 * Run this to update cases.user_id from channel owner
 * 
 * Usage: GET /api/migrations/fix_cases_user_id.php?key=autobot_migration_2026
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

// Security check
$key = $_GET['key'] ?? '';
if ($key !== 'autobot_migration_2026') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid key']);
    exit;
}

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    
    // Step 1: Count records to fix
    $beforeCount = $db->queryOne(
        "SELECT COUNT(*) as cnt FROM cases WHERE user_id IS NULL"
    )['cnt'] ?? 0;
    
    // Step 2: Fix user_id in cases
    $db->execute("
        UPDATE cases c
        JOIN customer_channels cc ON c.channel_id = cc.id
        SET c.user_id = cc.user_id
        WHERE c.user_id IS NULL OR c.user_id != cc.user_id
    ");
    
    // Step 3: Verify
    $afterCount = $db->queryOne(
        "SELECT COUNT(*) as cnt FROM cases WHERE user_id IS NULL"
    )['cnt'] ?? 0;
    
    // Get sample of fixed records
    $samples = $db->query(
        "SELECT id, case_no, user_id, channel_id FROM cases ORDER BY id DESC LIMIT 5"
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Migration completed',
        'before_count' => (int)$beforeCount,
        'after_count' => (int)$afterCount,
        'fixed' => (int)$beforeCount - (int)$afterCount,
        'samples' => $samples
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
