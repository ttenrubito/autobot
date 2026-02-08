<?php
/**
 * Migration: Create subscription_payments table
 * 
 * Run via: curl https://autobot.boxdesign.in.th/api/admin/migrations/create_subscription_payments.php?key=MIGRATION_KEY
 */

define('INCLUDE_CHECK', true);

// Allow running from CLI or web with migration key
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    
    // Check migration key for web access (simple security)
    $migrationKey = getenv('MIGRATION_KEY') ?: 'autobot-migrate-2026';
    $providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
    
    if ($providedKey !== $migrationKey) {
        // Fall back to admin session check
        session_start();
        if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin access or migration key required']);
            exit;
        }
    }
}

require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Logger.php';

function output($message, $success = true) {
    global $isCli;
    if ($isCli) {
        echo ($success ? "✅ " : "❌ ") . $message . "\n";
    } else {
        echo json_encode(['success' => $success, 'message' => $message]);
    }
}

try {
    $db = Database::getInstance();
    
    // Check if table already exists
    $tableExists = $db->queryOne(
        "SELECT COUNT(*) as cnt FROM information_schema.tables 
         WHERE table_schema = DATABASE() AND table_name = 'subscription_payments'"
    );
    
    if ($tableExists && $tableExists['cnt'] > 0) {
        output('Table subscription_payments already exists - skipping creation');
        exit;
    }
    
    // Create table
    $sql = "
    CREATE TABLE subscription_payments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL COMMENT 'Shop owner user ID',
        amount DECIMAL(10, 2) NOT NULL COMMENT 'Amount paid in THB',
        slip_url VARCHAR(500) NULL COMMENT 'Public URL of the slip image',
        gcs_path VARCHAR(500) NULL COMMENT 'GCS storage path for the slip',
        status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending' COMMENT 'Payment verification status',
        days_added INT DEFAULT 0 COMMENT 'Number of days added to subscription',
        verified_by INT NULL COMMENT 'Admin user who verified the payment',
        verified_at TIMESTAMP NULL COMMENT 'When the payment was verified',
        rejection_reason VARCHAR(500) NULL COMMENT 'Reason for rejection if status=rejected',
        notes TEXT NULL COMMENT 'Additional notes',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_created_at (created_at),
        
        CONSTRAINT fk_subscription_payments_user 
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_subscription_payments_verifier 
            FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Stores subscription renewal payments with slip images for verification'
    ";
    
    $db->execute($sql);
    
    Logger::info('[Migration] Created subscription_payments table');
    output('Created subscription_payments table successfully');
    
} catch (Exception $e) {
    Logger::error('[Migration] Failed to create subscription_payments', ['error' => $e->getMessage()]);
    output('Migration failed: ' . $e->getMessage(), false);
    if (!$isCli) {
        http_response_code(500);
    }
    exit(1);
}
