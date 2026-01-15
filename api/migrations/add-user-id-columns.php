<?php
/**
 * Migration: Add user_id and rename customer_id to platform_user_id
 * 
 * Changes:
 * 1. orders: ADD user_id, RENAME customer_id → platform_user_id (VARCHAR)
 * 2. payments: ADD user_id, RENAME customer_id → platform_user_id (VARCHAR)
 * 3. repairs: ADD user_id, RENAME customer_id → platform_user_id (VARCHAR)
 * 4. pawns: ADD user_id, RENAME customer_id → platform_user_id (VARCHAR)
 * 
 * Run: curl "https://autobot.boxdesign.in.th/api/migrations/add-user-id-columns.php?debug_key=autobot2026&execute=1"
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if (($_GET['debug_key'] ?? '') !== 'autobot2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$execute = ($_GET['execute'] ?? '') === '1';
$pdo = getDB();
$results = [];

$tables = ['orders', 'payments', 'repairs', 'pawns'];

foreach ($tables as $table) {
    try {
        // Check current columns
        $stmt = $pdo->query("DESCRIBE `{$table}`");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $hasUserId = in_array('user_id', $columns);
        $hasCustomerId = in_array('customer_id', $columns);
        $hasPlatformUserId = in_array('platform_user_id', $columns);
        
        $tableResults = [
            'table' => $table,
            'current' => [
                'has_user_id' => $hasUserId,
                'has_customer_id' => $hasCustomerId,
                'has_platform_user_id' => $hasPlatformUserId
            ],
            'actions' => []
        ];
        
        // Step 1: Add user_id column if not exists
        if (!$hasUserId) {
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `user_id` INT NULL AFTER `id`, ADD INDEX `idx_{$table}_user_id` (`user_id`)";
            if ($execute) {
                $pdo->exec($sql);
                $tableResults['actions'][] = ['add_user_id' => 'DONE', 'sql' => $sql];
            } else {
                $tableResults['actions'][] = ['add_user_id' => 'DRY_RUN', 'sql' => $sql];
            }
        } else {
            $tableResults['actions'][] = ['add_user_id' => 'SKIP - already exists'];
        }
        
        // Step 2: Add platform_user_id column if not exists
        if (!$hasPlatformUserId) {
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `platform_user_id` VARCHAR(255) NULL AFTER `user_id`, ADD INDEX `idx_{$table}_platform_user_id` (`platform_user_id`)";
            if ($execute) {
                $pdo->exec($sql);
                $tableResults['actions'][] = ['add_platform_user_id' => 'DONE', 'sql' => $sql];
            } else {
                $tableResults['actions'][] = ['add_platform_user_id' => 'DRY_RUN', 'sql' => $sql];
            }
        } else {
            $tableResults['actions'][] = ['add_platform_user_id' => 'SKIP - already exists'];
        }
        
        // Step 3: If customer_id exists (and platform_user_id now exists), migrate data
        if ($hasCustomerId && !$hasPlatformUserId) {
            // Get current customer_id values and lookup platform_user_id from customer_profiles
            $sql = "UPDATE `{$table}` t 
                    LEFT JOIN customer_profiles cp ON t.customer_id = cp.id 
                    SET t.platform_user_id = cp.platform_user_id 
                    WHERE t.customer_id IS NOT NULL AND t.platform_user_id IS NULL";
            if ($execute) {
                $affected = $pdo->exec($sql);
                $tableResults['actions'][] = ['migrate_customer_id_to_platform_user_id' => 'DONE', 'affected_rows' => $affected, 'sql' => $sql];
            } else {
                $tableResults['actions'][] = ['migrate_customer_id_to_platform_user_id' => 'DRY_RUN', 'sql' => $sql];
            }
        }
        
        // Step 4: Keep customer_id for now (don't drop - for backward compatibility)
        // We can drop it later after all code is updated
        
        $results[] = $tableResults;
        
    } catch (Exception $e) {
        $results[] = [
            'table' => $table,
            'error' => $e->getMessage()
        ];
    }
}

// Also update customer_profiles to have proper indexes
try {
    $stmt = $pdo->query("SHOW INDEX FROM customer_profiles WHERE Key_name = 'idx_platform_user_id'");
    $hasIndex = $stmt->fetch();
    
    if (!$hasIndex) {
        $sql = "ALTER TABLE customer_profiles ADD INDEX `idx_platform_user_id` (`platform_user_id`, `platform`)";
        if ($execute) {
            $pdo->exec($sql);
            $results[] = ['customer_profiles' => 'Added index on platform_user_id', 'status' => 'DONE'];
        } else {
            $results[] = ['customer_profiles' => 'Add index on platform_user_id', 'status' => 'DRY_RUN', 'sql' => $sql];
        }
    }
} catch (Exception $e) {
    $results[] = ['customer_profiles_index' => 'error', 'message' => $e->getMessage()];
}

echo json_encode([
    'success' => true,
    'execute_mode' => $execute,
    'results' => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
