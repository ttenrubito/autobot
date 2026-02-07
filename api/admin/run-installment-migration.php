<?php
/**
 * Run Installment Migration via API
 * 
 * This script runs the installment system migration on production database
 * Adds missing columns to installment_contracts and installment_payments
 * 
 * Usage: 
 * - Call via browser: /api/admin/run-installment-migration.php?key=xxx
 * - Or via CLI: php api/admin/run-installment-migration.php
 */

header('Content-Type: application/json');

// Security: require API key or CLI
$isCliMode = php_sapi_name() === 'cli';
$apiKey = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
$validKey = 'migrate-installment-2026-01-18';

if (!$isCliMode && $apiKey !== $validKey) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden. Invalid API key.']);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';

// Helper function to check if column exists
function columnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return $stmt->fetchColumn() > 0;
}

// Helper function to check if index exists  
function indexExists($pdo, $table, $indexName) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $indexName]);
    return $stmt->fetchColumn() > 0;
}

try {
    $pdo = getDB();
    $results = [];
    
    // 1. Add columns to installment_contracts
    $columnsToAdd = [
        ['table' => 'installment_contracts', 'column' => 'platform_user_id', 'definition' => 'VARCHAR(255) NULL'],
        ['table' => 'installment_contracts', 'column' => 'channel_id', 'definition' => 'INT NULL'],
        ['table' => 'installment_contracts', 'column' => 'external_user_id', 'definition' => 'VARCHAR(255) NULL'],
    ];
    
    foreach ($columnsToAdd as $col) {
        if (!columnExists($pdo, $col['table'], $col['column'])) {
            try {
                $pdo->exec("ALTER TABLE {$col['table']} ADD COLUMN {$col['column']} {$col['definition']}");
                $results[] = ['action' => "Add {$col['column']} to {$col['table']}", 'status' => 'success'];
            } catch (Exception $e) {
                $results[] = ['action' => "Add {$col['column']} to {$col['table']}", 'status' => 'error', 'error' => $e->getMessage()];
            }
        } else {
            $results[] = ['action' => "Add {$col['column']} to {$col['table']}", 'status' => 'exists'];
        }
    }
    
    // 2. Add index on platform_user_id
    if (!indexExists($pdo, 'installment_contracts', 'idx_ic_platform_user')) {
        try {
            $pdo->exec("CREATE INDEX idx_ic_platform_user ON installment_contracts(platform_user_id)");
            $results[] = ['action' => 'Create index idx_ic_platform_user', 'status' => 'success'];
        } catch (Exception $e) {
            $results[] = ['action' => 'Create index idx_ic_platform_user', 'status' => 'error', 'error' => $e->getMessage()];
        }
    } else {
        $results[] = ['action' => 'Create index idx_ic_platform_user', 'status' => 'exists'];
    }
    
    // 3. Create installment_reminders table if not exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS installment_reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contract_id INT NOT NULL,
            reminder_type VARCHAR(50) NOT NULL,
            due_date DATE NOT NULL,
            period_number INT NOT NULL,
            message_sent TEXT,
            sent_at DATETIME,
            status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_contract (contract_id),
            INDEX idx_type_date (reminder_type, due_date)
        )");
        $results[] = ['action' => 'Create installment_reminders table', 'status' => 'success'];
    } catch (Exception $e) {
        $results[] = ['action' => 'Create installment_reminders table', 'status' => 'error', 'error' => $e->getMessage()];
    }
    
    // 4. Create push_notifications table if not exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            platform VARCHAR(50) NOT NULL,
            platform_user_id VARCHAR(255) NOT NULL,
            channel_id INT,
            notification_type VARCHAR(100),
            message TEXT,
            sent_at DATETIME,
            delivery_status ENUM('pending', 'sent', 'failed', 'delivered') DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_platform_user (platform_user_id),
            INDEX idx_status (delivery_status)
        )");
        $results[] = ['action' => 'Create push_notifications table', 'status' => 'success'];
    } catch (Exception $e) {
        $results[] = ['action' => 'Create push_notifications table', 'status' => 'error', 'error' => $e->getMessage()];
    }
    
    // 5. Verify - get current columns
    $stmt = $pdo->query("SHOW COLUMNS FROM installment_contracts");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    Logger::info('[MIGRATION] Installment consolidation completed', $results);
    
    echo json_encode([
        'success' => true,
        'message' => 'Installment migration completed',
        'results' => $results,
        'installment_contracts_columns' => $columns
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    Logger::error('[MIGRATION] Installment consolidation failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Migration failed',
        'error' => $e->getMessage()
    ]);
}
