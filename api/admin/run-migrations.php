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

    // =====================================================
    // Migration 5: Order Created Templates
    // =====================================================
    $results[] = runMigration($pdo, 'Insert order_created_full template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'order_created_full', 
            'Order Created - Full Payment',
            '🛒 สร้างคำสั่งซื้อเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_amount}}\n📋 เลขที่: {{order_number}}\n\n💳 กรุณาชำระเงินเต็มจำนวน\nเมื่อชำระแล้ว ส่งสลิปมาได้เลยค่ะ 🙏',
            '🛒 สร้างคำสั่งซื้อเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_amount}}\n📋 เลขที่: {{order_number}}\n\n💳 กรุณาชำระเงินเต็มจำนวน',
            1
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");

    $results[] = runMigration($pdo, 'Insert order_created_installment template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'order_created_installment', 
            'Order Created - Installment',
            '🛒 สร้างคำสั่งซื้อผ่อนชำระเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_amount}}\n📋 เลขที่: {{order_number}}\n\n📅 ผ่อนชำระ {{total_periods}} งวด:\n▫️ งวดที่ 1: ฿{{period_1_amount}} (ครบกำหนด {{period_1_due}})\n▫️ งวดที่ 2: ฿{{period_2_amount}}\n▫️ งวดที่ 3: ฿{{period_3_amount}}\n\n💳 กรุณาชำระงวดแรกภายในวันที่กำหนดค่ะ\nเมื่อชำระแล้ว ส่งสลิปมาได้เลยค่ะ 🙏',
            '🛒 สร้างคำสั่งซื้อผ่อนชำระเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_amount}}\n\n📅 ผ่อน {{total_periods}} งวด\n▫️ งวดแรก: ฿{{period_1_amount}}',
            1
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");

    $results[] = runMigration($pdo, 'Insert order_created_savings template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'order_created_savings', 
            'Order Created - Savings',
            '🏦 เปิดบัญชีออมสินค้าเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n🎯 เป้าหมาย: ฿{{target_amount}}\n💰 ยอดปัจจุบัน: ฿{{current_balance}}\n📋 เลขที่: {{order_number}}\n\nออมได้ตามสะดวกค่ะ พอครบเป้าก็รับสินค้าได้เลย 🙏',
            '🏦 เปิดบัญชีออมสินค้าเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n🎯 เป้าหมาย: ฿{{target_amount}}\n\nออมได้ตามสะดวกค่ะ 🙏',
            1
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");

    // =====================================================
    // Migration 6: Installment Payment Templates
    // =====================================================
    $results[] = runMigration($pdo, 'Insert installment_payment_verified template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'installment_payment_verified', 
            'Installment Payment Verified',
            '✅ รับชำระงวดที่ {{current_period}}/{{total_periods}} เรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 จำนวน: ฿{{amount}}\n📅 วันที่: {{payment_date}}\n\n📋 สถานะ: ชำระแล้ว {{paid_periods}}/{{total_periods}} งวด\n{{next_period_info}}\n\nขอบคุณที่ใช้บริการค่ะ 🙏',
            '✅ รับชำระงวดที่ {{current_period}}/{{total_periods}} เรียบร้อยแล้วค่ะ\n\n💰 จำนวน: ฿{{amount}}\n📋 ชำระแล้ว {{paid_periods}}/{{total_periods}} งวด\n\nขอบคุณค่ะ 🙏',
            1
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");

    $results[] = runMigration($pdo, 'Insert installment_completed template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'installment_completed', 
            'Installment Completed',
            '🎉 ยินดีด้วยค่ะ! ผ่อนครบทุกงวดแล้ว\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวมที่ชำระ: ฿{{total_paid}}\n📅 วันที่ครบ: {{completion_date}}\n\nขอบคุณที่ไว้วางใจใช้บริการค่ะ 🙏✨',
            '🎉 ยินดีด้วยค่ะ! ผ่อนครบทุกงวดแล้ว\n\n📦 {{product_name}}\n💰 ยอดรวม: ฿{{total_paid}}\n\nขอบคุณค่ะ 🙏✨',
            1
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");

    $results[] = runMigration($pdo, 'Insert savings_deposit_verified template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'savings_deposit_verified', 
            'Savings Deposit Verified',
            '✅ รับฝากออมเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ฝากครั้งนี้: ฿{{amount}}\n💵 ยอดสะสม: ฿{{new_balance}}\n🎯 เป้าหมาย: ฿{{target_amount}}\n📊 คงเหลือ: ฿{{remaining}}\n\nสู้ๆค่ะ เกือบถึงเป้าแล้ว! 💪',
            '✅ รับฝากออมเรียบร้อยแล้วค่ะ\n\n💰 ฝาก: ฿{{amount}}\n💵 ยอดสะสม: ฿{{new_balance}}\n🎯 เป้าหมาย: ฿{{target_amount}}\n\nสู้ๆค่ะ 💪',
            1
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");

    $results[] = runMigration($pdo, 'Insert savings_goal_reached template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'savings_goal_reached', 
            'Savings Goal Reached',
            '🎉 ยินดีด้วยค่ะ! ออมครบเป้าหมายแล้ว!\n\n📦 สินค้า: {{product_name}}\n💰 ยอดออมรวม: ฿{{total_saved}}\n📅 วันที่ครบ: {{completion_date}}\n\n📞 รอติดต่อจากทางร้านเพื่อนัดรับสินค้านะคะ\nขอบคุณที่ไว้วางใจค่ะ 🙏✨',
            '🎉 ยินดีด้วยค่ะ! ออมครบเป้าหมายแล้ว!\n\n📦 {{product_name}}\n💰 ยอดรวม: ฿{{total_saved}}\n\nรอติดต่อจากทางร้านนะคะ 🙏✨',
            1
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");

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
