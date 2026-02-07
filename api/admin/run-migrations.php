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

// Debug: Show table schema
if (isset($_GET['schema'])) {
    require_once __DIR__ . '/../../config.php';
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['schema']);
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SHOW CREATE TABLE {$table}");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'table' => $table, 'schema' => $row['Create Table'] ?? $row]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Debug: Query data directly
if (isset($_GET['query'])) {
    require_once __DIR__ . '/../../config.php';
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT id, order_no, payment_type, total_amount, deposit_amount, deposit_expiry, paid_amount, status FROM orders ORDER BY id DESC LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

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
            '✅ รับชำระงวดที่ {{current_period}}/{{total_periods}} เรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดครั้งนี้: ฿{{amount}}\n📅 วันที่: {{payment_date}}\n\n📊 ชำระแล้ว: ฿{{paid_amount}} / ฿{{total_amount}}\n💵 คงเหลือ: ฿{{remaining_amount}}\n📋 สถานะ: {{paid_periods}}/{{total_periods}} งวด\n{{next_period_info}}\n\nขอบคุณที่ใช้บริการค่ะ 🙏',
            '✅ รับชำระงวดที่ {{current_period}}/{{total_periods}} แล้วค่ะ\n\n💰 ยอด: ฿{{amount}}\n💵 คงเหลือ: ฿{{remaining_amount}}\n📋 {{paid_periods}}/{{total_periods}} งวด\n\nขอบคุณค่ะ 🙏',
            1
        )
        ON DUPLICATE KEY UPDATE 
            line_template = '✅ รับชำระงวดที่ {{current_period}}/{{total_periods}} เรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดครั้งนี้: ฿{{amount}}\n📅 วันที่: {{payment_date}}\n\n📊 ชำระแล้ว: ฿{{paid_amount}} / ฿{{total_amount}}\n💵 คงเหลือ: ฿{{remaining_amount}}\n📋 สถานะ: {{paid_periods}}/{{total_periods}} งวด\n{{next_period_info}}\n\nขอบคุณที่ใช้บริการค่ะ 🙏',
            facebook_template = '✅ รับชำระงวดที่ {{current_period}}/{{total_periods}} แล้วค่ะ\n\n💰 ยอด: ฿{{amount}}\n💵 คงเหลือ: ฿{{remaining_amount}}\n📋 {{paid_periods}}/{{total_periods}} งวด\n\nขอบคุณค่ะ 🙏',
            updated_at = NOW()
    ");

    $results[] = runMigration($pdo, 'Insert installment_completed template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'installment_completed', 
            'Installment Completed',
            '🎉 ยินดีด้วยค่ะ! ผ่อนครบทุกงวดแล้ว\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_paid}}\n✅ ชำระครบ {{total_periods}} งวด\n📅 วันที่ครบ: {{completion_date}}\n\n🎊 ขอบคุณที่ไว้วางใจใช้บริการค่ะ 🙏✨',
            '🎉 ยินดีด้วยค่ะ! ผ่อนครบแล้ว\n\n📦 {{product_name}}\n💰 ยอดรวม: ฿{{total_paid}}\n✅ ครบ {{total_periods}} งวด\n\nขอบคุณค่ะ 🙏✨',
            1
        )
        ON DUPLICATE KEY UPDATE 
            line_template = '🎉 ยินดีด้วยค่ะ! ผ่อนครบทุกงวดแล้ว\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_paid}}\n✅ ชำระครบ {{total_periods}} งวด\n📅 วันที่ครบ: {{completion_date}}\n\n🎊 ขอบคุณที่ไว้วางใจใช้บริการค่ะ 🙏✨',
            facebook_template = '🎉 ยินดีด้วยค่ะ! ผ่อนครบแล้ว\n\n📦 {{product_name}}\n💰 ยอดรวม: ฿{{total_paid}}\n✅ ครบ {{total_periods}} งวด\n\nขอบคุณค่ะ 🙏✨',
            updated_at = NOW()
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

    // =====================================================
    // Migration 9: Update templates with bank_account
    // =====================================================
    $results[] = runMigration($pdo, 'Update order_created_full template with bank account', "
        UPDATE notification_templates 
        SET 
            line_template = '🛒 สร้างคำสั่งซื้อเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_amount}}\n📋 เลขที่: {{order_number}}\n\n💳 กรุณาชำระเงินเต็มจำนวน\n\n🏦 บัญชีโอนเงิน:\n{{bank_account}}\n\nเมื่อชำระแล้ว ส่งสลิปมาได้เลยค่ะ 🙏',
            facebook_template = '🛒 สร้างคำสั่งซื้อเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_amount}}\n📋 เลขที่: {{order_number}}\n\n💳 กรุณาชำระเงินเต็มจำนวน\n\n🏦 บัญชีโอนเงิน:\n{{bank_account}}\n\nส่งสลิปมาได้เลยค่ะ 🙏',
            updated_at = NOW()
        WHERE template_key = 'order_created_full'
    ");

    $results[] = runMigration($pdo, 'Update order_created_installment template with bank account', "
        UPDATE notification_templates 
        SET 
            line_template = '🛒 สร้างคำสั่งซื้อผ่อนชำระเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_amount}}\n📋 เลขที่: {{order_number}}\n\n📅 ผ่อนชำระ {{total_periods}} งวด:\n▫️ งวดที่ 1: ฿{{period_1_amount}} (ครบกำหนด {{period_1_due}})\n▫️ งวดที่ 2: ฿{{period_2_amount}}\n▫️ งวดที่ 3: ฿{{period_3_amount}}\n\n🏦 บัญชีโอนเงิน:\n{{bank_account}}\n\n💳 กรุณาชำระงวดแรกภายในวันที่กำหนดค่ะ\nเมื่อชำระแล้ว ส่งสลิปมาได้เลยค่ะ 🙏',
            facebook_template = '🛒 สร้างคำสั่งซื้อผ่อนชำระเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_amount}}\n\n📅 ผ่อน {{total_periods}} งวด\n▫️ งวดแรก: ฿{{period_1_amount}}\n\n🏦 บัญชีโอนเงิน:\n{{bank_account}}',
            updated_at = NOW()
        WHERE template_key = 'order_created_installment'
    ");

    // =====================================================
    // Migration 9.5: Add deposit order template
    // =====================================================
    $results[] = runMigration($pdo, 'Insert order_created_deposit template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'order_created_deposit', 
            'Order Created - Deposit',
            '🎯 สร้างรายการมัดจำเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ราคาเต็ม: ฿{{total_amount}}\n💎 ยอดมัดจำ: ฿{{deposit_amount}}\n📋 เลขที่: {{order_number}}\n📅 กันสินค้าถึง: {{deposit_expiry}}\n\n🏦 บัญชีโอนเงิน:\n{{bank_account}}\n\n💳 กรุณาโอนยอดมัดจำภายในวันนี้ค่ะ\nเมื่อชำระแล้ว ส่งสลิปมาได้เลยค่ะ 🙏',
            '🎯 สร้างรายการมัดจำเรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ราคาเต็ม: ฿{{total_amount}}\n💎 ยอดมัดจำ: ฿{{deposit_amount}}\n📅 กันสินค้าถึง: {{deposit_expiry}}\n\n🏦 บัญชีโอนเงิน:\n{{bank_account}}\n\nส่งสลิปมาได้เลยค่ะ 🙏',
            1
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");

    // =====================================================
    // Migration 10: Add platform user columns to customer_addresses
    // =====================================================
    $results[] = runMigrationSafe($pdo, 'Add user_id to customer_addresses', 
        "ALTER TABLE customer_addresses ADD COLUMN user_id INT UNSIGNED NULL AFTER customer_id"
    );
    $results[] = runMigrationSafe($pdo, 'Add platform_user_id to customer_addresses', 
        "ALTER TABLE customer_addresses ADD COLUMN platform_user_id VARCHAR(255) NULL AFTER user_id"
    );
    $results[] = runMigrationSafe($pdo, 'Add platform to customer_addresses', 
        "ALTER TABLE customer_addresses ADD COLUMN platform VARCHAR(50) NULL AFTER platform_user_id"
    );
    $results[] = runMigrationSafe($pdo, 'Add index on customer_addresses user_id', 
        "CREATE INDEX idx_customer_addresses_user_id ON customer_addresses(user_id)"
    );
    $results[] = runMigrationSafe($pdo, 'Add index on customer_addresses platform_user', 
        "CREATE INDEX idx_customer_addresses_platform_user ON customer_addresses(platform_user_id, platform)"
    );

    // =====================================================
    // Migration 11: Extend payment_type ENUM to support deposit/savings
    // =====================================================
    $results[] = runMigrationSafe($pdo, 'Extend payment_type ENUM for deposit and savings', 
        "ALTER TABLE orders MODIFY COLUMN payment_type ENUM('full', 'installment', 'deposit', 'savings') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full'"
    );

    // =====================================================
    // Migration 12: Fix ALL order notification templates (UPSERT)
    // =====================================================
    
    // Template: order_created_full
    $results[] = runMigration($pdo, 'UPSERT order_created_full template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'order_created_full', 
            'Order Created - Full Payment',
            '🛒 สร้างคำสั่งซื้อเรียบร้อยแล้วค่ะ

📦 สินค้า: {{product_name}}
💰 ยอดชำระ: ฿{{total_amount}}
📋 เลขที่: {{order_number}}

🏦 บัญชีโอนเงิน:
{{bank_account}}

💳 กรุณาชำระเงินเต็มจำนวน
เมื่อชำระแล้ว ส่งสลิปมาได้เลยค่ะ 🙏',
            '🛒 สร้างคำสั่งซื้อเรียบร้อยแล้วค่ะ

📦 สินค้า: {{product_name}}
💰 ยอดชำระ: ฿{{total_amount}}
📋 เลขที่: {{order_number}}

🏦 บัญชีโอนเงิน:
{{bank_account}}

ส่งสลิปมาได้เลยค่ะ 🙏',
            1
        )
        ON DUPLICATE KEY UPDATE 
            line_template = VALUES(line_template),
            facebook_template = VALUES(facebook_template),
            updated_at = NOW()
    ");

    // Template: order_created_installment
    $results[] = runMigration($pdo, 'UPSERT order_created_installment template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'order_created_installment', 
            'Order Created - Installment',
            '🛒 สร้างคำสั่งซื้อผ่อนชำระเรียบร้อยแล้วค่ะ

📦 สินค้า: {{product_name}}
💰 ยอดรวม: ฿{{total_amount}}
📋 เลขที่: {{order_number}}

📅 ผ่อนชำระ {{total_periods}} งวด:
▫️ งวดที่ 1: ฿{{period_1_amount}} (ครบกำหนด {{period_1_due}})
▫️ งวดที่ 2: ฿{{period_2_amount}} (ครบกำหนด {{period_2_due}})
▫️ งวดที่ 3: ฿{{period_3_amount}} (ครบกำหนด {{period_3_due}})

🏦 บัญชีโอนเงิน:
{{bank_account}}

💳 กรุณาชำระงวดแรกภายในวันที่กำหนดค่ะ
เมื่อชำระแล้ว ส่งสลิปมาได้เลยค่ะ 🙏',
            '🛒 สร้างคำสั่งซื้อผ่อนชำระเรียบร้อยแล้วค่ะ

📦 สินค้า: {{product_name}}
💰 ยอดรวม: ฿{{total_amount}}

📅 ผ่อน {{total_periods}} งวด
▫️ งวดแรก: ฿{{period_1_amount}}

🏦 บัญชีโอนเงิน:
{{bank_account}}

ส่งสลิปมาได้เลยค่ะ 🙏',
            1
        )
        ON DUPLICATE KEY UPDATE 
            line_template = VALUES(line_template),
            facebook_template = VALUES(facebook_template),
            updated_at = NOW()
    ");

    // Template: order_created_deposit
    $results[] = runMigration($pdo, 'UPSERT order_created_deposit template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'order_created_deposit', 
            'Order Created - Deposit',
            '🎯 สร้างรายการมัดจำเรียบร้อยแล้วค่ะ

📦 สินค้า: {{product_name}}
💰 ราคาเต็ม: ฿{{total_amount}}
💎 ยอดมัดจำ: ฿{{deposit_amount}}
📋 เลขที่: {{order_number}}
📅 กันสินค้าถึง: {{deposit_expiry}}

🏦 บัญชีโอนเงิน:
{{bank_account}}

💳 กรุณาโอนยอดมัดจำภายในวันนี้ค่ะ
เมื่อชำระแล้ว ส่งสลิปมาได้เลยค่ะ 🙏',
            '🎯 สร้างรายการมัดจำเรียบร้อยแล้วค่ะ

📦 สินค้า: {{product_name}}
💰 ราคาเต็ม: ฿{{total_amount}}
💎 ยอดมัดจำ: ฿{{deposit_amount}}
📅 กันสินค้าถึง: {{deposit_expiry}}

🏦 บัญชีโอนเงิน:
{{bank_account}}

ส่งสลิปมาได้เลยค่ะ 🙏',
            1
        )
        ON DUPLICATE KEY UPDATE 
            line_template = VALUES(line_template),
            facebook_template = VALUES(facebook_template),
            updated_at = NOW()
    ");

    // Template: order_created_savings
    $results[] = runMigration($pdo, 'UPSERT order_created_savings template', "
        INSERT INTO notification_templates (template_key, template_name, line_template, facebook_template, is_active)
        VALUES (
            'order_created_savings', 
            'Order Created - Savings',
            '🏦 เปิดบัญชีออมสินค้าเรียบร้อยแล้วค่ะ

📦 สินค้า: {{product_name}}
🎯 เป้าหมาย: ฿{{target_amount}}
💰 ยอดปัจจุบัน: ฿{{current_balance}}
📋 เลขที่: {{order_number}}

🏦 บัญชีโอนเงิน:
{{bank_account}}

ออมได้ตามสะดวกค่ะ พอครบเป้าก็รับสินค้าได้เลย 🙏',
            '🏦 เปิดบัญชีออมสินค้าเรียบร้อยแล้วค่ะ

📦 สินค้า: {{product_name}}
🎯 เป้าหมาย: ฿{{target_amount}}

🏦 บัญชีโอนเงิน:
{{bank_account}}

ออมได้ตามสะดวกค่ะ 🙏',
            1
        )
        ON DUPLICATE KEY UPDATE 
            line_template = VALUES(line_template),
            facebook_template = VALUES(facebook_template),
            updated_at = NOW()
    ");

    // =====================================================
    // Migration: Create cronjob_logs table
    // =====================================================
    $results[] = runMigration($pdo, 'Create cronjob_logs table', "
        CREATE TABLE IF NOT EXISTS `cronjob_logs` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `job_id` VARCHAR(100) NOT NULL COMMENT 'Unique identifier for the cronjob',
            `status` ENUM('success', 'error', 'skipped', 'running') NOT NULL DEFAULT 'running',
            `result` JSON DEFAULT NULL COMMENT 'JSON result from execution',
            `error_message` TEXT DEFAULT NULL,
            `duration_ms` INT DEFAULT NULL COMMENT 'Execution time in milliseconds',
            `executed_at` DATETIME NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_job_id` (`job_id`),
            KEY `idx_executed_at` (`executed_at`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migration: Add paid_amount column to installment_payments for partial payment support
    // Check if column exists first
    $checkStmt = $pdo->query("SHOW COLUMNS FROM installment_payments LIKE 'paid_amount'");
    if ($checkStmt->rowCount() == 0) {
        $results[] = runMigration($pdo, 'Add paid_amount to installment_payments', "
            ALTER TABLE installment_payments 
            ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 
            AFTER amount
        ");
    } else {
        $results[] = ['name' => 'Add paid_amount to installment_payments', 'success' => true, 'skipped' => true, 'message' => 'Already exists'];
    }
    
    // Update existing paid records
    $results[] = runMigration($pdo, 'Update existing paid installment_payments', "
        UPDATE installment_payments 
        SET paid_amount = amount 
        WHERE status = 'paid' AND paid_amount = 0
    ");

    // =====================================================
    // Migration: Add 'deposit' to order_type ENUM (if exists)
    // OR payment_type ENUM - depends on schema
    // =====================================================
    
    // Check which column exists
    $hasOrderType = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'order_type'");
        $hasOrderType = $checkCol->rowCount() > 0;
    } catch (Exception $e) {}
    
    if ($hasOrderType) {
        $results[] = runMigrationSafe($pdo, 'Add deposit to order_type ENUM', 
            "ALTER TABLE orders MODIFY COLUMN order_type ENUM('full_payment', 'installment', 'savings_completion', 'deposit') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full_payment'"
        );
    } else {
        // payment_type already handled in Migration 11 above
        $results[] = ['name' => 'Add deposit to order_type ENUM', 'success' => true, 'skipped' => true, 'message' => 'Using payment_type instead'];
    }

    // =====================================================
    // Migration: Add deposit_amount column to orders
    // =====================================================
    $checkStmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'deposit_amount'");
    if ($checkStmt->rowCount() == 0) {
        $results[] = runMigration($pdo, 'Add deposit_amount to orders', "
            ALTER TABLE orders 
            ADD COLUMN deposit_amount DECIMAL(12,2) DEFAULT NULL 
            AFTER paid_amount
        ");
    } else {
        $results[] = ['name' => 'Add deposit_amount to orders', 'success' => true, 'skipped' => true, 'message' => 'Already exists'];
    }

    // =====================================================
    // Migration: Add deposit_expiry column to orders
    // =====================================================
    $checkStmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'deposit_expiry'");
    if ($checkStmt->rowCount() == 0) {
        $results[] = runMigration($pdo, 'Add deposit_expiry to orders', "
            ALTER TABLE orders 
            ADD COLUMN deposit_expiry DATE DEFAULT NULL 
            AFTER deposit_amount
        ");
    } else {
        $results[] = ['name' => 'Add deposit_expiry to orders', 'success' => true, 'skipped' => true, 'message' => 'Already exists'];
    }

    // =====================================================
    // Migration: Add platform columns to customer_addresses
    // =====================================================
    $checkPlatformCol = $pdo->query("SHOW COLUMNS FROM customer_addresses LIKE 'platform'");
    if ($checkPlatformCol->rowCount() === 0) {
        $results[] = runMigrationSafe($pdo, 'Add platform column to customer_addresses', "
            ALTER TABLE customer_addresses 
            ADD COLUMN platform VARCHAR(20) DEFAULT NULL 
            AFTER tenant_id
        ");
    } else {
        $results[] = ['name' => 'Add platform to customer_addresses', 'success' => true, 'skipped' => true, 'message' => 'Already exists'];
    }
    
    $checkPlatformUserCol = $pdo->query("SHOW COLUMNS FROM customer_addresses LIKE 'platform_user_id'");
    if ($checkPlatformUserCol->rowCount() === 0) {
        $results[] = runMigrationSafe($pdo, 'Add platform_user_id column to customer_addresses', "
            ALTER TABLE customer_addresses 
            ADD COLUMN platform_user_id VARCHAR(255) DEFAULT NULL 
            AFTER platform
        ");
    } else {
        $results[] = ['name' => 'Add platform_user_id to customer_addresses', 'success' => true, 'skipped' => true, 'message' => 'Already exists'];
    }
    
    // Add index for platform lookup
    $results[] = runMigrationSafe($pdo, 'Add index on customer_addresses platform_user_id', "
        CREATE INDEX idx_addresses_platform_user ON customer_addresses (platform, platform_user_id)
    ");

    // =====================================================
    // Migration: Fix customer_addresses FK for chatbot users
    // Chatbot users are in customer_profiles, not users table
    // So we need to allow NULL customer_id or remove the FK
    // =====================================================
    
    // 1. Drop the FK constraint if it exists
    $results[] = runMigrationSafe($pdo, 'Drop customer_addresses FK to users', "
        ALTER TABLE customer_addresses DROP FOREIGN KEY customer_addresses_ibfk_1
    ");
    
    // 2. Make customer_id nullable
    $results[] = runMigrationSafe($pdo, 'Make customer_addresses.customer_id nullable', "
        ALTER TABLE customer_addresses MODIFY COLUMN customer_id INT NULL
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
