<?php
/**
 * Run SQL Migration API
 * POST /api/run-migration.php
 * 
 * SECURITY: Requires admin secret key
 */

header('Content-Type: application/json');

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$secret = $input['secret'] ?? '';
$migrationName = $input['migration'] ?? '';

// Verify secret (very basic - in production use proper auth)
$expectedSecret = getenv('MIGRATION_SECRET') ?: 'autobot_migration_2026_secure';
if ($secret !== $expectedSecret) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid secret']);
    exit;
}

require_once __DIR__ . '/../config.php';

try {
    $pdo = getDB();
    
    // Define allowed migrations
    $migrations = [
        'add_channel_id_to_customer_profiles' => [
            // Step 1: Check and add column
            [
                'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_profiles' AND COLUMN_NAME = 'channel_id'",
                'skip_if' => 1,
                'sql' => "ALTER TABLE customer_profiles ADD COLUMN channel_id INT NULL AFTER tenant_id"
            ],
            
            // Step 2: Add index (may already exist)
            [
                'sql' => "ALTER TABLE customer_profiles ADD INDEX idx_channel_id (channel_id)",
                'ignore_error' => true
            ],
            
            // Step 3: Update from cases
            [
                'sql' => "UPDATE customer_profiles cp SET channel_id = (SELECT c.channel_id FROM cases c WHERE c.external_user_id = cp.platform_user_id AND c.platform = cp.platform LIMIT 1) WHERE cp.channel_id IS NULL"
            ],
            
            // Step 4: Update from chat_sessions
            [
                'sql' => "UPDATE customer_profiles cp SET channel_id = (SELECT cs.channel_id FROM chat_sessions cs WHERE cs.external_user_id = cp.platform_user_id LIMIT 1) WHERE cp.channel_id IS NULL"
            ]
        ]
    ];
    
    if (!isset($migrations[$migrationName])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Unknown migration',
            'available' => array_keys($migrations)
        ]);
        exit;
    }
    
    $results = [];
    foreach ($migrations[$migrationName] as $idx => $step) {
        try {
            // Handle new format with check/skip logic
            if (is_array($step)) {
                // Check condition if specified
                if (isset($step['check']) && isset($step['skip_if'])) {
                    $checkResult = $pdo->query($step['check'])->fetchColumn();
                    if ($checkResult == $step['skip_if']) {
                        $results[] = ['step' => $idx + 1, 'status' => 'SKIP (already done)'];
                        continue;
                    }
                }
                
                $sql = $step['sql'];
                $ignoreError = $step['ignore_error'] ?? false;
                
                try {
                    $pdo->exec($sql);
                    $results[] = ['step' => $idx + 1, 'status' => 'OK'];
                } catch (PDOException $e) {
                    if ($ignoreError) {
                        $results[] = ['step' => $idx + 1, 'status' => 'SKIP (error ignored)', 'note' => substr($e->getMessage(), 0, 50)];
                    } else {
                        throw $e;
                    }
                }
            } else {
                // Old format - just SQL string
                $pdo->exec($step);
                $results[] = ['step' => $idx + 1, 'status' => 'OK'];
            }
        } catch (PDOException $e) {
            // Ignore "column already exists" or "duplicate key" errors
            $errorCode = $e->getCode();
            if (in_array($errorCode, ['42S21', '42000', 1060, 1061])) {
                $results[] = ['step' => $idx + 1, 'status' => 'SKIP (already done)'];
            } else {
                $results[] = ['step' => $idx + 1, 'status' => 'ERROR', 'error' => $e->getMessage()];
            }
        }
    }
    
    // Get counts
    $hasChannel = $pdo->query("SELECT COUNT(*) FROM customer_profiles WHERE channel_id IS NOT NULL")->fetchColumn();
    $noChannel = $pdo->query("SELECT COUNT(*) FROM customer_profiles WHERE channel_id IS NULL")->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'migration' => $migrationName,
        'results' => $results,
        'stats' => [
            'has_channel_id' => (int)$hasChannel,
            'no_channel_id' => (int)$noChannel
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Migration failed',
        'error' => $e->getMessage()
    ]);
}
