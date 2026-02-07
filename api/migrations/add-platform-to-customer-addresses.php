<?php
/**
 * Migration: Add platform columns to customer_addresses
 * 
 * Changes:
 * 1. Add platform + platform_user_id columns
 * 2. Backfill from customer_profiles
 * 3. Add index on (platform, platform_user_id)
 * 4. Drop tenant_id column
 * 
 * Run: GET /api/migrations/add-platform-to-customer-addresses.php?execute=1
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$dryRun = !isset($_GET['execute']) || $_GET['execute'] !== '1';
$results = [];

try {
    $pdo = getDB();

    // Step 1: Check if platform column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM customer_addresses LIKE 'platform'");
    $platformExists = $stmt->rowCount() > 0;

    if (!$platformExists) {
        $sql = "ALTER TABLE customer_addresses 
                ADD COLUMN platform VARCHAR(20) DEFAULT 'line' AFTER customer_id,
                ADD COLUMN platform_user_id VARCHAR(255) AFTER platform";

        if ($dryRun) {
            $results[] = ['step' => 1, 'action' => 'ADD platform + platform_user_id columns', 'sql' => $sql, 'status' => 'DRY_RUN'];
        } else {
            $pdo->exec($sql);
            $results[] = ['step' => 1, 'action' => 'ADD platform + platform_user_id columns', 'status' => 'DONE'];
        }
    } else {
        $results[] = ['step' => 1, 'action' => 'platform column already exists', 'status' => 'SKIP'];
    }

    // Step 2: Backfill platform_user_id from customer_profiles
    $sql = "UPDATE customer_addresses ca
            JOIN customer_profiles cp ON ca.customer_id = cp.id
            SET ca.platform = cp.platform,
                ca.platform_user_id = cp.platform_user_id
            WHERE ca.platform_user_id IS NULL";

    if ($dryRun) {
        // In dry run, if column doesn't exist yet, just report the SQL
        if (!$platformExists) {
            $results[] = ['step' => 2, 'action' => 'Backfill platform_user_id (after step 1)', 'sql' => $sql, 'status' => 'DRY_RUN'];
        } else {
            // Count how many rows need update
            $countSql = "SELECT COUNT(*) as cnt FROM customer_addresses WHERE platform_user_id IS NULL";
            $stmt = $pdo->query($countSql);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
            $results[] = ['step' => 2, 'action' => 'Backfill platform_user_id', 'rows_to_update' => $count, 'sql' => $sql, 'status' => 'DRY_RUN'];
        }
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $affected = $stmt->rowCount();
        $results[] = ['step' => 2, 'action' => 'Backfill platform_user_id', 'rows_updated' => $affected, 'status' => 'DONE'];
    }

    // Step 3: Add index on (platform, platform_user_id)
    $stmt = $pdo->query("SHOW INDEX FROM customer_addresses WHERE Key_name = 'idx_platform_user'");
    $indexExists = $stmt->rowCount() > 0;

    if (!$indexExists) {
        $sql = "ALTER TABLE customer_addresses ADD INDEX idx_platform_user (platform, platform_user_id)";

        if ($dryRun) {
            $results[] = ['step' => 3, 'action' => 'ADD INDEX idx_platform_user', 'sql' => $sql, 'status' => 'DRY_RUN'];
        } else {
            $pdo->exec($sql);
            $results[] = ['step' => 3, 'action' => 'ADD INDEX idx_platform_user', 'status' => 'DONE'];
        }
    } else {
        $results[] = ['step' => 3, 'action' => 'idx_platform_user already exists', 'status' => 'SKIP'];
    }

    // Step 4: Drop tenant_id column
    $stmt = $pdo->query("SHOW COLUMNS FROM customer_addresses LIKE 'tenant_id'");
    $tenantExists = $stmt->rowCount() > 0;

    if ($tenantExists) {
        $sql = "ALTER TABLE customer_addresses DROP COLUMN tenant_id";

        if ($dryRun) {
            $results[] = ['step' => 4, 'action' => 'DROP tenant_id column', 'sql' => $sql, 'status' => 'DRY_RUN'];
        } else {
            $pdo->exec($sql);
            $results[] = ['step' => 4, 'action' => 'DROP tenant_id column', 'status' => 'DONE'];
        }
    } else {
        $results[] = ['step' => 4, 'action' => 'tenant_id already dropped', 'status' => 'SKIP'];
    }

    echo json_encode([
        'success' => true,
        'dry_run' => $dryRun,
        'message' => $dryRun ? 'Add ?execute=1 to run migration' : 'Migration completed',
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'results' => $results
    ], JSON_PRETTY_PRINT);
}
