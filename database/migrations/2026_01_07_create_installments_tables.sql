-- ============================================
-- Chatbot Commerce v2.0 - Installment System
-- Created: 2026-01-07
-- Description: Tables for installment (ผ่อนชำระ) management
-- ============================================

-- ============================================
-- 1. INSTALLMENT CONTRACTS (สัญญาผ่อนชำระ)
-- ============================================

CREATE TABLE IF NOT EXISTS installment_contracts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Contract identification
    contract_no VARCHAR(50) UNIQUE NOT NULL COMMENT 'Format: INS-YYYYMMDD-XXXXX',
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Customer info
    customer_id INT NULL COMMENT 'FK to users if identified',
    channel_id BIGINT UNSIGNED NOT NULL,
    external_user_id VARCHAR(255) NOT NULL COMMENT 'LINE UID or FB PSID',
    platform ENUM('line', 'facebook', 'web', 'instagram') NOT NULL,
    
    -- Customer details (denormalized for quick access)
    customer_name VARCHAR(255) NULL,
    customer_phone VARCHAR(50) NULL,
    customer_line_name VARCHAR(255) NULL,
    
    -- Product info
    product_ref_id VARCHAR(100) NOT NULL COMMENT 'NPD ref_id',
    product_name VARCHAR(255) NOT NULL,
    product_code VARCHAR(100) NULL COMMENT 'SKU/Item code',
    product_price DECIMAL(12,2) NOT NULL COMMENT 'Price at time of contract',
    
    -- Financial details
    total_amount DECIMAL(12,2) NOT NULL COMMENT 'Total contract value',
    down_payment DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Initial down payment',
    financed_amount DECIMAL(12,2) NOT NULL COMMENT 'Amount to pay in installments (total - down)',
    
    -- Installment plan
    total_periods INT NOT NULL COMMENT 'Number of installment periods (e.g., 3, 6, 12)',
    amount_per_period DECIMAL(12,2) NOT NULL COMMENT 'Amount per period',
    
    -- Interest (optional)
    interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Interest rate % (0 for interest-free)',
    interest_type ENUM('none', 'flat', 'reducing') NOT NULL DEFAULT 'none',
    total_interest DECIMAL(12,2) NOT NULL DEFAULT 0,
    
    -- Progress tracking
    paid_periods INT NOT NULL DEFAULT 0 COMMENT 'Number of periods paid',
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Total amount paid (verified only)',
    pending_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Amount pending verification',
    remaining_amount DECIMAL(12,2) GENERATED ALWAYS AS (financed_amount - paid_amount) STORED,
    remaining_periods INT GENERATED ALWAYS AS (total_periods - paid_periods) STORED,
    
    -- Schedule
    contract_date DATE NOT NULL DEFAULT (CURRENT_DATE),
    start_date DATE NULL COMMENT 'First payment due date',
    next_due_date DATE NULL COMMENT 'Next payment due date',
    last_paid_date DATE NULL,
    end_date DATE NULL COMMENT 'Expected completion date',
    
    -- Status
    status ENUM(
        'pending_approval',   -- Waiting admin approval
        'active',             -- Contract approved and active
        'overdue',            -- Payment overdue
        'completed',          -- All payments made
        'cancelled',          -- Cancelled by customer/admin
        'defaulted'           -- Customer defaulted
    ) NOT NULL DEFAULT 'pending_approval',
    
    -- Approval
    approved_by INT NULL COMMENT 'FK to admin user',
    approved_at TIMESTAMP NULL,
    approval_notes TEXT NULL,
    
    -- Related entities
    case_id BIGINT UNSIGNED NULL COMMENT 'FK to cases',
    order_id INT NULL COMMENT 'FK to orders after completion',
    
    -- Admin notes
    admin_notes TEXT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_contract_no (contract_no),
    INDEX idx_tenant (tenant_id),
    INDEX idx_customer (customer_id),
    INDEX idx_channel (channel_id),
    INDEX idx_external_user (external_user_id),
    INDEX idx_platform (platform),
    INDEX idx_product (product_ref_id),
    INDEX idx_status (status),
    INDEX idx_next_due (next_due_date),
    INDEX idx_created (created_at),
    INDEX idx_phone (customer_phone),
    INDEX idx_status_due (status, next_due_date)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. INSTALLMENT PAYMENTS (การชำระงวด)
-- ============================================

CREATE TABLE IF NOT EXISTS installment_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Parent contract
    contract_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to installment_contracts',
    
    -- Payment identification
    payment_no VARCHAR(50) UNIQUE NOT NULL COMMENT 'Format: INSPAY-YYYYMMDD-XXXXX',
    period_number INT NOT NULL COMMENT 'Which period (1, 2, 3, ...)',
    
    -- Payment details
    amount DECIMAL(12,2) NOT NULL COMMENT 'Amount paid',
    payment_type ENUM(
        'regular',          -- Regular period payment
        'down_payment',     -- Initial down payment
        'extra',            -- Extra payment
        'extension_fee',    -- Extension fee/interest
        'late_fee'          -- Late payment penalty
    ) NOT NULL DEFAULT 'regular',
    
    -- Payment method
    payment_method ENUM('bank_transfer', 'promptpay', 'credit_card', 'cash', 'other') DEFAULT 'bank_transfer',
    
    -- Due date info
    due_date DATE NULL,
    paid_date DATE NULL COMMENT 'Actual payment date',
    days_late INT GENERATED ALWAYS AS (
        CASE WHEN due_date IS NOT NULL AND paid_date IS NOT NULL 
             THEN DATEDIFF(paid_date, due_date) 
             ELSE 0 END
    ) STORED,
    
    -- Verification
    status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    verified_by INT NULL COMMENT 'FK to admin user',
    verified_at TIMESTAMP NULL,
    rejection_reason VARCHAR(500) NULL,
    
    -- Payment proof
    slip_image_url TEXT NULL,
    slip_ocr_data JSON NULL COMMENT 'OCR extracted data',
    payment_ref VARCHAR(100) NULL COMMENT 'Transaction reference from slip',
    sender_name VARCHAR(255) NULL COMMENT 'Sender name from slip',
    transfer_time DATETIME NULL COMMENT 'Transfer time from slip',
    
    -- Extension details (if applicable)
    is_extension BOOLEAN NOT NULL DEFAULT FALSE,
    extension_months INT NULL,
    extension_reason TEXT NULL,
    
    -- Push notification
    notification_sent BOOLEAN NOT NULL DEFAULT FALSE,
    notification_sent_at TIMESTAMP NULL,
    
    -- Metadata
    case_id BIGINT UNSIGNED NULL COMMENT 'FK to cases',
    notes TEXT NULL,
    admin_notes TEXT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_contract (contract_id),
    INDEX idx_payment_no (payment_no),
    INDEX idx_period (period_number),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    INDEX idx_paid_date (paid_date),
    INDEX idx_created (created_at),
    
    CONSTRAINT fk_installment_payments_contract 
        FOREIGN KEY (contract_id) REFERENCES installment_contracts(id) ON DELETE CASCADE
        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. INSTALLMENT REMINDERS (การแจ้งเตือน)
-- ============================================

CREATE TABLE IF NOT EXISTS installment_reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    contract_id BIGINT UNSIGNED NOT NULL,
    
    -- Reminder type (flexible VARCHAR for different reminder patterns)
    reminder_type VARCHAR(50) NOT NULL COMMENT 'e.g., before_3_days, before_1_day, overdue_1_days',
    
    -- Target due date for this reminder
    due_date DATE NOT NULL COMMENT 'The due date this reminder is for',
    period_number INT NULL COMMENT 'Which payment period',
    
    -- Status
    status ENUM('pending', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    
    -- Message
    message_template VARCHAR(100) NULL,
    message_sent TEXT NULL,
    
    -- Result
    error_message TEXT NULL,
    retry_count INT NOT NULL DEFAULT 0,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_contract (contract_id),
    INDEX idx_due_date (due_date),
    INDEX idx_status (status),
    INDEX idx_contract_type_due (contract_id, reminder_type, due_date),
    
    CONSTRAINT fk_reminders_contract 
        FOREIGN KEY (contract_id) REFERENCES installment_contracts(id) ON DELETE CASCADE
        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. PUSH NOTIFICATIONS LOG (if not exists)
-- ============================================

CREATE TABLE IF NOT EXISTS push_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Target
    platform ENUM('facebook', 'line') NOT NULL,
    platform_user_id VARCHAR(255) NOT NULL,
    channel_id BIGINT UNSIGNED NULL,
    
    -- Notification type
    notification_type ENUM(
        'payment_received',
        'payment_verified',
        'payment_rejected',
        'installment_reminder',
        'installment_payment_verified',
        'installment_completed',
        'installment_overdue',
        'savings_deposit_verified',
        'savings_goal_reached',
        'order_confirmed',
        'order_shipped',
        'order_delivered',
        'custom'
    ) NOT NULL,
    
    -- Content
    title VARCHAR(255) NULL,
    message TEXT NOT NULL,
    message_data JSON NULL COMMENT 'Template variables',
    
    -- Related entities
    payment_id BIGINT UNSIGNED NULL,
    order_id INT NULL,
    contract_id BIGINT UNSIGNED NULL COMMENT 'Installment contract',
    savings_id BIGINT UNSIGNED NULL,
    
    -- Status
    status ENUM('pending', 'sent', 'failed', 'delivered', 'read') NOT NULL DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    
    -- Error handling
    error_message TEXT NULL,
    retry_count INT NOT NULL DEFAULT 0,
    max_retries INT NOT NULL DEFAULT 3,
    next_retry_at TIMESTAMP NULL,
    
    -- API response
    api_response JSON NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_platform_user (platform, platform_user_id),
    INDEX idx_channel (channel_id),
    INDEX idx_status (status),
    INDEX idx_type (notification_type),
    INDEX idx_created (created_at)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. NOTIFICATION TEMPLATES
-- ============================================

CREATE TABLE IF NOT EXISTS notification_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    template_key VARCHAR(100) UNIQUE NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    
    -- Templates
    title_th VARCHAR(255) NULL,
    facebook_template TEXT NULL,
    line_template TEXT NULL,
    
    -- Variables
    available_variables JSON NULL,
    
    -- Status
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_key (template_key),
    INDEX idx_type (notification_type)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INSERT DEFAULT TEMPLATES
-- ============================================

INSERT INTO notification_templates (template_key, notification_type, title_th, facebook_template, line_template, available_variables) VALUES

('payment_received', 'payment_received', 
'ได้รับข้อมูลการชำระเงิน',
'ได้รับข้อมูลการชำระเงินแล้วค่ะ 💳\nยอด: {{amount}} บาท\nรอเจ้าหน้าที่ตรวจสอบนะคะ',
'ได้รับข้อมูลการชำระเงินแล้วค่ะ 💳\nยอด: {{amount}} บาท\nรอเจ้าหน้าที่ตรวจสอบนะคะ',
'["amount", "payment_ref", "created_at"]'),

('payment_verified', 'payment_verified',
'ยืนยันการชำระเงินแล้ว',
'✅ ยืนยันการชำระเงินเรียบร้อยค่ะ\n💰 ยอด: {{amount}} บาท\n📅 วันที่: {{verified_date}}\n🙏 ขอบคุณที่ใช้บริการค่ะ',
'✅ ยืนยันการชำระเงินเรียบร้อยค่ะ\n💰 ยอด: {{amount}} บาท\n📅 วันที่: {{verified_date}}\n🙏 ขอบคุณที่ใช้บริการค่ะ',
'["amount", "verified_date", "payment_ref", "order_number"]'),

('payment_rejected', 'payment_rejected',
'ไม่สามารถยืนยันการชำระเงินได้',
'❌ ไม่สามารถยืนยันการชำระเงินได้ค่ะ\n💰 ยอด: {{amount}} บาท\n📝 เหตุผล: {{reason}}\nกรุณาติดต่อเจ้าหน้าที่ค่ะ',
'❌ ไม่สามารถยืนยันการชำระเงินได้ค่ะ\n💰 ยอด: {{amount}} บาท\n📝 เหตุผล: {{reason}}\nกรุณาติดต่อเจ้าหน้าที่ค่ะ',
'["amount", "reason", "payment_ref"]'),

('installment_payment_verified', 'installment_payment_verified',
'ยืนยันชำระงวดแล้ว',
'✅ ยืนยันชำระงวดที่ {{period_number}} แล้วค่ะ\n💰 ยอดชำระ: {{amount}} บาท\n📊 ชำระแล้ว: {{paid_amount}}/{{total_amount}} บาท\n📅 งวดถัดไป: {{next_due_date}}\n🙏 ขอบคุณค่ะ',
'✅ ยืนยันชำระงวดที่ {{period_number}} แล้วค่ะ\n💰 ยอดชำระ: {{amount}} บาท\n📊 ชำระแล้ว: {{paid_amount}}/{{total_amount}} บาท\n📅 งวดถัดไป: {{next_due_date}}\n🙏 ขอบคุณค่ะ',
'["period_number", "amount", "paid_amount", "total_amount", "remaining_amount", "next_due_date", "paid_periods", "total_periods"]'),

('installment_completed', 'installment_completed',
'ผ่อนชำระครบแล้ว',
'🎉 ผ่อนชำระครบแล้วค่ะ!\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: {{total_amount}} บาท\n✅ ชำระครบ {{total_periods}} งวด\n🙏 ขอบคุณที่ใช้บริการค่ะ',
'🎉 ผ่อนชำระครบแล้วค่ะ!\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: {{total_amount}} บาท\n✅ ชำระครบ {{total_periods}} งวด\n🙏 ขอบคุณที่ใช้บริการค่ะ',
'["product_name", "total_amount", "total_periods"]'),

('installment_reminder', 'installment_reminder',
'แจ้งเตือนค่างวด',
'⏰ แจ้งเตือนค่างวดค่ะ\n📅 ครบกำหนด: {{due_date}}\n💰 ยอด: {{amount}} บาท\n📋 งวดที่: {{period_number}}/{{total_periods}}\nอย่าลืมชำระนะคะ 😊',
'⏰ แจ้งเตือนค่างวดค่ะ\n📅 ครบกำหนด: {{due_date}}\n💰 ยอด: {{amount}} บาท\n📋 งวดที่: {{period_number}}/{{total_periods}}\nอย่าลืมชำระนะคะ 😊',
'["due_date", "amount", "period_number", "total_periods", "product_name"]'),

('installment_due_tomorrow', 'installment_due_tomorrow',
'แจ้งเตือนค่างวดพรุ่งนี้',
'⚠️ พรุ่งนี้ครบกำหนดค่างวดค่ะ\n📅 วันที่: {{due_date}}\n💰 ยอด: {{amount}} บาท\n📋 งวดที่: {{period_number}}/{{total_periods}}\n📦 สินค้า: {{product_name}}\nกรุณาชำระก่อนกำหนดนะคะ 🙏',
'⚠️ พรุ่งนี้ครบกำหนดค่างวดค่ะ\n📅 วันที่: {{due_date}}\n💰 ยอด: {{amount}} บาท\n📋 งวดที่: {{period_number}}/{{total_periods}}\n📦 สินค้า: {{product_name}}\nกรุณาชำระก่อนกำหนดนะคะ 🙏',
'["due_date", "amount", "period_number", "total_periods", "product_name"]'),

('installment_overdue', 'installment_overdue',
'ค่างวดเกินกำหนด',
'⚠️ ค่างวดเกินกำหนดแล้วค่ะ\n📅 ครบกำหนด: {{due_date}}\n💰 ยอด: {{amount}} บาท\n📋 งวดที่: {{period_number}}\nกรุณาชำระโดยเร็วนะคะ',
'⚠️ ค่างวดเกินกำหนดแล้วค่ะ\n📅 ครบกำหนด: {{due_date}}\n💰 ยอด: {{amount}} บาท\n📋 งวดที่: {{period_number}}\nกรุณาชำระโดยเร็วนะคะ',
'["due_date", "amount", "period_number", "days_overdue"]'),

('savings_deposit_verified', 'savings_deposit_verified',
'ยืนยันยอดฝากออม',
'✅ ยืนยันยอดฝากออมแล้วค่ะ\n💰 ฝาก: {{amount}} บาท\n📊 ยอดสะสม: {{saved_amount}}/{{target_amount}} บาท\n📈 ความคืบหน้า: {{progress}}%\n🙏 ขอบคุณค่ะ',
'✅ ยืนยันยอดฝากออมแล้วค่ะ\n💰 ฝาก: {{amount}} บาท\n📊 ยอดสะสม: {{saved_amount}}/{{target_amount}} บาท\n📈 ความคืบหน้า: {{progress}}%\n🙏 ขอบคุณค่ะ',
'["amount", "saved_amount", "target_amount", "remaining_amount", "progress", "product_name"]'),

('savings_goal_reached', 'savings_goal_reached',
'ออมครบเป้าหมาย',
'🎉 ออมครบเป้าหมายแล้วค่ะ!\n📦 สินค้า: {{product_name}}\n💰 ยอดออม: {{saved_amount}} บาท\n✅ พร้อมรับสินค้าแล้วค่ะ\nติดต่อเจ้าหน้าที่เพื่อนัดรับสินค้านะคะ 🙏',
'🎉 ออมครบเป้าหมายแล้วค่ะ!\n📦 สินค้า: {{product_name}}\n💰 ยอดออม: {{saved_amount}} บาท\n✅ พร้อมรับสินค้าแล้วค่ะ\nติดต่อเจ้าหน้าที่เพื่อนัดรับสินค้านะคะ 🙏',
'["product_name", "saved_amount", "target_amount"]'),

('order_confirmed', 'order_confirmed',
'ยืนยันคำสั่งซื้อ',
'✅ ยืนยันคำสั่งซื้อแล้วค่ะ\n📦 Order: #{{order_number}}\n💰 ยอดรวม: {{total_amount}} บาท\nรอจัดส่งนะคะ 📦',
'✅ ยืนยันคำสั่งซื้อแล้วค่ะ\n📦 Order: #{{order_number}}\n💰 ยอดรวม: {{total_amount}} บาท\nรอจัดส่งนะคะ 📦',
'["order_number", "total_amount", "product_names"]'),

('order_shipped', 'order_shipped',
'จัดส่งสินค้าแล้ว',
'📦 จัดส่งสินค้าแล้วค่ะ!\n🚚 เลขพัสดุ: {{tracking_number}}\n📦 Order: #{{order_number}}\nติดตามสถานะได้เลยค่ะ',
'📦 จัดส่งสินค้าแล้วค่ะ!\n🚚 เลขพัสดุ: {{tracking_number}}\n📦 Order: #{{order_number}}\nติดตามสถานะได้เลยค่ะ',
'["tracking_number", "order_number", "shipping_company"]')

ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ============================================
-- End of Migration
-- ============================================
