<?php
/**
 * Run Database Migrations
 * 
 * This script runs pending database migrations on production.
 * Access via: /api/admin/run-migrations?run=1&confirm=yes
 * 
 * @date 2026-01-16
 */

header('Content-Type: application/json');

// Security check
$run = $_GET['run'] ?? null;
$confirm = $_GET['confirm'] ?? null;

if ($run !== '1' || $confirm !== 'yes') {
    echo json_encode([
        'success' => false,
        'message' => 'Add ?run=1&confirm=yes to execute migrations',
        'usage' => '/api/admin/run-migrations?run=1&confirm=yes'
    ]);
    exit;
}

require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();
    $results = [];

    // =====================================================
    // Migration 1: Add notification_templates table
    // =====================================================
    $results[] = runMigration($pdo, 'Create notification_templates table', "
        CREATE TABLE IF NOT EXISTS notification_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(100) NOT NULL UNIQUE,
            template_name VARCHAR(255) NOT NULL,
            description TEXT,
            line_template TEXT,
            facebook_template TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // =====================================================
    // Migration 2: Add push_notifications table
    // =====================================================
    $results[] = runMigration($pdo, 'Create push_notifications table', "
        CREATE TABLE IF NOT EXISTS push_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            platform VARCHAR(20) NOT NULL,
            platform_user_id VARCHAR(255) NOT NULL,
            channel_id INT NULL,
            notification_type VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            message_data JSON,
            status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
            retry_count INT DEFAULT 0,
            max_retries INT DEFAULT 3,
            next_retry_at TIMESTAMP NULL,
            sent_at TIMESTAMP NULL,
            error_message TEXT,
            api_response JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_platform_user (platform, platform_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // =====================================================
    // Migration 3: Add payment notification templates
    // =====================================================
    $results[] = runMigration($pdo, 'Insert payment_verified template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'payment_verified', 
            'Payment Verified',
            '✅ การชำระเงินได้รับการอนุมัติแล้ว\n\n📋 เลขที่: {{payment_no}}\n💰 จำนวน: ฿{{amount}}\n📅 วันที่: {{payment_date}}\n\nขอบคุณที่ใช้บริการค่ะ 🙏',
            '✅ การชำระเงินได้รับการอนุมัติแล้ว\n\n📋 เลขที่: {{payment_no}}\n💰 จำนวน: ฿{{amount}}\n\nขอบคุณที่ใช้บริการค่ะ 🙏',
            1
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");

    $results[] = runMigration($pdo, 'Insert payment_rejected template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'payment_rejected', 
            'Payment Rejected',
            '❌ การชำระเงินถูกปฏิเสธ\n\n📋 เลขที่: {{payment_no}}\n💰 จำนวน: ฿{{amount}}\n📅 วันที่: {{payment_date}}\n\n❗ เหตุผล: {{reason}}\n\nกรุณาติดต่อร้านค้าหากมีข้อสงสัยค่ะ',
            '❌ การชำระเงินถูกปฏิเสธ\n\n📋 เลขที่: {{payment_no}}\n💰 จำนวน: ฿{{amount}}\n\n❗ เหตุผล: {{reason}}\n\nกรุณาติดต่อร้านค้าหากมีข้อสงสัยค่ะ',
            1
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");

    // =====================================================
    // Migration 4: Add paid_amount to orders (if not exists)
    // =====================================================
    $results[] = runMigrationSafe(
        $pdo,
        'Add paid_amount column to orders',
        "ALTER TABLE orders ADD COLUMN paid_amount DECIMAL(12,2) DEFAULT 0 AFTER total_amount"
    );

    $results[] = runMigrationSafe(
        $pdo,
        'Add payment_status column to orders',
        "ALTER TABLE orders ADD COLUMN payment_status VARCHAR(20) DEFAULT 'pending' AFTER status"
    );

    // Count results
    $success = count(array_filter($results, fn($r) => $r['success'] && empty($r['skipped'])));
    $failed = count(array_filter($results, fn($r) => !$r['success']));
    $skipped = count(array_filter($results, fn($r) => !empty($r['skipped'])));

    echo json_encode([
        'success' => true,
        'message' => "Migrations completed: {$success} success, {$skipped} skipped, {$failed} failed",
        'summary' => [
            'success' => $success,
            'skipped' => $skipped,
            'failed' => $failed
        ],
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Migration failed: ' . $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}

/**
 * Run a migration (throws on error)
 */
function runMigration(PDO $pdo, string $name, string $sql): array
{
    try {
        $pdo->exec($sql);
        return ['name' => $name, 'success' => true, 'message' => 'OK'];
    } catch (PDOException $e) {
        return ['name' => $name, 'success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Run a migration safely (ignore "already exists" errors)
 */
function runMigrationSafe(PDO $pdo, string $name, string $sql): array
{
    try {
        $pdo->exec($sql);
        return ['name' => $name, 'success' => true, 'message' => 'OK'];
    } catch (PDOException $e) {
        // Ignore "duplicate column" or "already exists" errors
        if (
            strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), 'already exists') !== false
        ) {
            return ['name' => $name, 'success' => true, 'skipped' => true, 'message' => 'Already exists'];
        }
        return ['name' => $name, 'success' => false, 'message' => $e->getMessage()];
    }
}
