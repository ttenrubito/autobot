<?php
/**
 * Database Migration: Fix customer relationships
 * 
 * Changes:
 * 1. Add tenant_id to customer_profiles
 * 2. Drop FK payments.customer_id -> users.id
 * 3. Drop FK orders.customer_id -> users.id
 * 4. Create new FK to customer_profiles
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$key = $_GET['key'] ?? '';
if ($key !== 'migrate-schema-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$dryRun = ($_GET['execute'] ?? '') !== 'yes';

try {
    $pdo = getDB();
    $results = [];
    
    // Step 1: Add tenant_id to customer_profiles if not exists
    $stmt = $pdo->query("SHOW COLUMNS FROM customer_profiles LIKE 'tenant_id'");
    if ($stmt->rowCount() === 0) {
        $sql = "ALTER TABLE customer_profiles ADD COLUMN tenant_id VARCHAR(50) NOT NULL DEFAULT 'default' AFTER id";
        if ($dryRun) {
            $results[] = ['step' => 1, 'action' => 'ADD tenant_id to customer_profiles', 'sql' => $sql, 'status' => 'DRY_RUN'];
        } else {
            $pdo->exec($sql);
            $results[] = ['step' => 1, 'action' => 'ADD tenant_id to customer_profiles', 'status' => 'DONE'];
        }
    } else {
        $results[] = ['step' => 1, 'action' => 'tenant_id already exists in customer_profiles', 'status' => 'SKIP'];
    }
    
    // Step 2: Add index on tenant_id
    $stmt = $pdo->query("SHOW INDEX FROM customer_profiles WHERE Key_name = 'idx_tenant_id'");
    if ($stmt->rowCount() === 0) {
        $sql = "ALTER TABLE customer_profiles ADD INDEX idx_tenant_id (tenant_id)";
        if ($dryRun) {
            $results[] = ['step' => 2, 'action' => 'ADD INDEX idx_tenant_id', 'sql' => $sql, 'status' => 'DRY_RUN'];
        } else {
            $pdo->exec($sql);
            $results[] = ['step' => 2, 'action' => 'ADD INDEX idx_tenant_id', 'status' => 'DONE'];
        }
    } else {
        $results[] = ['step' => 2, 'action' => 'idx_tenant_id already exists', 'status' => 'SKIP'];
    }
    
    // Step 3: Drop FK payments_ibfk_2 (payments.customer_id -> users.id)
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'payments' 
        AND COLUMN_NAME = 'customer_id'
        AND REFERENCED_TABLE_NAME = 'users'
    ");
    $fk = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fk) {
        $sql = "ALTER TABLE payments DROP FOREIGN KEY " . $fk['CONSTRAINT_NAME'];
        if ($dryRun) {
            $results[] = ['step' => 3, 'action' => 'DROP FK ' . $fk['CONSTRAINT_NAME'], 'sql' => $sql, 'status' => 'DRY_RUN'];
        } else {
            $pdo->exec($sql);
            $results[] = ['step' => 3, 'action' => 'DROP FK ' . $fk['CONSTRAINT_NAME'], 'status' => 'DONE'];
        }
    } else {
        $results[] = ['step' => 3, 'action' => 'payments.customer_id FK to users already dropped', 'status' => 'SKIP'];
    }
    
    // Step 4: Drop FK orders_ibfk_1 (orders.customer_id -> users.id)
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'orders' 
        AND COLUMN_NAME = 'customer_id'
        AND REFERENCED_TABLE_NAME = 'users'
    ");
    $fk = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fk) {
        $sql = "ALTER TABLE orders DROP FOREIGN KEY " . $fk['CONSTRAINT_NAME'];
        if ($dryRun) {
            $results[] = ['step' => 4, 'action' => 'DROP FK ' . $fk['CONSTRAINT_NAME'], 'sql' => $sql, 'status' => 'DRY_RUN'];
        } else {
            $pdo->exec($sql);
            $results[] = ['step' => 4, 'action' => 'DROP FK ' . $fk['CONSTRAINT_NAME'], 'status' => 'DONE'];
        }
    } else {
        $results[] = ['step' => 4, 'action' => 'orders.customer_id FK to users already dropped', 'status' => 'SKIP'];
    }
    
    // Step 5: Add new FK payments.customer_id -> customer_profiles.id (nullable)
    // First, ensure customer_id allows NULL and has no conflicting values
    $sql1 = "ALTER TABLE payments MODIFY COLUMN customer_id INT NULL";
    $sql2 = "UPDATE payments SET customer_id = NULL WHERE customer_id NOT IN (SELECT id FROM customer_profiles)";
    
    if ($dryRun) {
        $results[] = ['step' => 5, 'action' => 'MODIFY payments.customer_id to nullable', 'sql' => $sql1, 'status' => 'DRY_RUN'];
        $results[] = ['step' => '5b', 'action' => 'Clean invalid customer_id values', 'sql' => $sql2, 'status' => 'DRY_RUN'];
    } else {
        $pdo->exec($sql1);
        $pdo->exec($sql2);
        $results[] = ['step' => 5, 'action' => 'MODIFY payments.customer_id + clean data', 'status' => 'DONE'];
    }
    
    // Step 6: Add new FK for payments.customer_id -> customer_profiles.id
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'payments' 
        AND COLUMN_NAME = 'customer_id'
        AND REFERENCED_TABLE_NAME = 'customer_profiles'
    ");
    if ($stmt->rowCount() === 0) {
        $sql = "ALTER TABLE payments ADD CONSTRAINT fk_payments_customer_profile 
                FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE SET NULL";
        if ($dryRun) {
            $results[] = ['step' => 6, 'action' => 'ADD FK payments -> customer_profiles', 'sql' => $sql, 'status' => 'DRY_RUN'];
        } else {
            $pdo->exec($sql);
            $results[] = ['step' => 6, 'action' => 'ADD FK payments -> customer_profiles', 'status' => 'DONE'];
        }
    } else {
        $results[] = ['step' => 6, 'action' => 'FK payments -> customer_profiles already exists', 'status' => 'SKIP'];
    }
    
    // Step 7: Same for orders
    $sql1 = "ALTER TABLE orders MODIFY COLUMN customer_id INT NULL";
    $sql2 = "UPDATE orders SET customer_id = NULL WHERE customer_id NOT IN (SELECT id FROM customer_profiles)";
    
    if ($dryRun) {
        $results[] = ['step' => 7, 'action' => 'MODIFY orders.customer_id to nullable', 'sql' => $sql1, 'status' => 'DRY_RUN'];
        $results[] = ['step' => '7b', 'action' => 'Clean invalid customer_id values', 'sql' => $sql2, 'status' => 'DRY_RUN'];
    } else {
        $pdo->exec($sql1);
        $pdo->exec($sql2);
        $results[] = ['step' => 7, 'action' => 'MODIFY orders.customer_id + clean data', 'status' => 'DONE'];
    }
    
    // Step 8: Add new FK for orders.customer_id -> customer_profiles.id
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'orders' 
        AND COLUMN_NAME = 'customer_id'
        AND REFERENCED_TABLE_NAME = 'customer_profiles'
    ");
    if ($stmt->rowCount() === 0) {
        $sql = "ALTER TABLE orders ADD CONSTRAINT fk_orders_customer_profile 
                FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE SET NULL";
        if ($dryRun) {
            $results[] = ['step' => 8, 'action' => 'ADD FK orders -> customer_profiles', 'sql' => $sql, 'status' => 'DRY_RUN'];
        } else {
            $pdo->exec($sql);
            $results[] = ['step' => 8, 'action' => 'ADD FK orders -> customer_profiles', 'status' => 'DONE'];
        }
    } else {
        $results[] = ['step' => 8, 'action' => 'FK orders -> customer_profiles already exists', 'status' => 'SKIP'];
    }
    
    echo json_encode([
        'success' => true,
        'dry_run' => $dryRun,
        'message' => $dryRun ? 'This is a DRY RUN. Add ?execute=yes to apply changes.' : 'Migration completed!',
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
