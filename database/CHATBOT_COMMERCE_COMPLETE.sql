-- ============================================================================
-- CHATBOT COMMERCE COMPLETE SCHEMA
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Complete database schema for Chatbot Commerce System
--              Supporting: Product Search, Full Payment, Installment, Savings
-- ============================================================================

-- ============================================================================
-- 1. CUSTOMER PROFILES (เก็บข้อมูลลูกค้าจาก Chat Platform)
-- ============================================================================
CREATE TABLE IF NOT EXISTS customer_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    platform ENUM('facebook', 'line', 'web', 'manual') NOT NULL DEFAULT 'manual',
    platform_user_id VARCHAR(255) NULL COMMENT 'FB PSID or LINE userId',
    display_name VARCHAR(255) NULL,
    profile_pic_url TEXT NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(255) NULL,
    full_name VARCHAR(255) NULL,
    notes TEXT NULL,
    first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    metadata JSON NULL COMMENT 'Additional platform-specific data',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_platform_user (platform, platform_user_id),
    INDEX idx_phone (phone),
    INDEX idx_platform (platform),
    INDEX idx_last_active (last_active_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. CASES (Conversation/Ticket Tracking)
-- ============================================================================
CREATE TABLE IF NOT EXISTS cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT 'FK to users table (if registered user)',
    customer_profile_id INT NULL COMMENT 'FK to customer_profiles',
    
    -- Platform Info (for direct lookup without join)
    platform ENUM('facebook', 'line', 'web') NULL,
    platform_user_id VARCHAR(255) NULL,
    
    -- Case Details
    case_type ENUM('product_inquiry', 'payment_full', 'payment_installment', 'payment_savings', 'support', 'other') DEFAULT 'other',
    subject VARCHAR(500) NULL,
    description TEXT NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('open', 'pending', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
    
    -- Related References
    product_ref_id VARCHAR(100) NULL,
    order_id INT NULL,
    installment_id INT NULL,
    savings_id INT NULL,
    
    -- Slots collected from conversation
    slots JSON NULL COMMENT 'Collected slots from chatbot conversation',
    
    -- Assignment
    assigned_to INT NULL COMMENT 'Admin user ID',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    
    INDEX idx_customer_profile (customer_profile_id),
    INDEX idx_platform_user (platform, platform_user_id),
    INDEX idx_status (status),
    INDEX idx_case_type (case_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. SAVINGS GOALS (เป้าหมายออมเงิน)
-- ============================================================================
CREATE TABLE IF NOT EXISTS savings_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT 'FK to users (if registered)',
    customer_profile_id INT NULL COMMENT 'FK to customer_profiles',
    
    -- Product Reference
    product_ref_id VARCHAR(100) NULL COMMENT 'Product code being saved for',
    product_name VARCHAR(255) NULL,
    
    -- Goal Details
    name VARCHAR(255) NOT NULL COMMENT 'Goal name/description',
    target_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    saved_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Verified deposits only',
    pending_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Pending verification',
    
    -- Status
    status ENUM('active', 'completed', 'cancelled', 'paused') DEFAULT 'active',
    target_date DATE NULL,
    completed_at TIMESTAMP NULL,
    
    -- Customer Info (denormalized for quick access)
    customer_name VARCHAR(255) NULL,
    customer_phone VARCHAR(20) NULL,
    
    -- Metadata
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_customer_profile (customer_profile_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_phone (customer_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. SAVINGS TRANSACTIONS (รายการฝากออม)
-- ============================================================================
CREATE TABLE IF NOT EXISTS savings_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    savings_goal_id INT NOT NULL,
    customer_profile_id INT NULL,
    
    -- Transaction Details
    amount DECIMAL(12,2) NOT NULL,
    transaction_type ENUM('deposit', 'withdrawal', 'adjustment') DEFAULT 'deposit',
    
    -- Verification
    status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    verified_by INT NULL COMMENT 'Admin user ID who verified',
    verified_at TIMESTAMP NULL,
    rejection_reason VARCHAR(500) NULL,
    
    -- Payment Proof
    slip_image_url TEXT NULL,
    ocr_data JSON NULL COMMENT 'OCR extracted data from slip',
    payment_ref VARCHAR(100) NULL,
    sender_name VARCHAR(255) NULL,
    transfer_time DATETIME NULL,
    
    -- Metadata
    note TEXT NULL,
    case_id INT NULL COMMENT 'Related case',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (savings_goal_id) REFERENCES savings_goals(id) ON DELETE CASCADE,
    INDEX idx_savings_goal (savings_goal_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. INSTALLMENTS (สัญญาผ่อนชำระ)
-- ============================================================================
CREATE TABLE IF NOT EXISTS installments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    customer_profile_id INT NULL,
    
    -- Contract Details
    contract_number VARCHAR(50) NULL COMMENT 'Auto-generated contract number',
    name VARCHAR(255) NOT NULL COMMENT 'Description of installment',
    
    -- Product Reference
    product_ref_id VARCHAR(100) NULL,
    product_name VARCHAR(255) NULL,
    
    -- Financial Details
    total_amount DECIMAL(12,2) NOT NULL COMMENT 'Total price',
    down_payment DECIMAL(12,2) DEFAULT 0,
    financed_amount DECIMAL(12,2) NOT NULL COMMENT 'Amount to be paid in installments',
    total_terms INT NOT NULL COMMENT 'Number of installment periods',
    amount_per_term DECIMAL(12,2) NOT NULL COMMENT 'Amount per period',
    
    -- Progress
    paid_terms INT DEFAULT 0,
    paid_amount DECIMAL(12,2) DEFAULT 0 COMMENT 'Verified payments only',
    pending_amount DECIMAL(12,2) DEFAULT 0 COMMENT 'Pending verification',
    
    -- Interest (optional)
    interest_rate DECIMAL(5,2) DEFAULT 0,
    interest_type ENUM('none', 'flat', 'reducing') DEFAULT 'none',
    
    -- Status
    status ENUM('pending_approval', 'active', 'completed', 'overdue', 'cancelled', 'defaulted') DEFAULT 'pending_approval',
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    
    -- Schedule
    start_date DATE NULL,
    next_due_date DATE NULL,
    
    -- Customer Info (denormalized)
    customer_name VARCHAR(255) NULL,
    customer_phone VARCHAR(20) NULL,
    
    -- Metadata
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_customer_profile (customer_profile_id),
    INDEX idx_contract (contract_number),
    INDEX idx_status (status),
    INDEX idx_phone (customer_phone),
    INDEX idx_next_due (next_due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. INSTALLMENT PAYMENTS (การชำระงวดผ่อน)
-- ============================================================================
CREATE TABLE IF NOT EXISTS installment_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    installment_id INT NOT NULL,
    customer_profile_id INT NULL,
    
    -- Payment Details
    period_number INT NOT NULL COMMENT 'Which period this payment is for',
    amount DECIMAL(12,2) NOT NULL,
    payment_type ENUM('regular', 'extra', 'extension_interest') DEFAULT 'regular',
    
    -- Verification
    status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    verified_by INT NULL,
    verified_at TIMESTAMP NULL,
    rejection_reason VARCHAR(500) NULL,
    
    -- Payment Proof
    slip_image_url TEXT NULL,
    ocr_data JSON NULL,
    payment_ref VARCHAR(100) NULL,
    sender_name VARCHAR(255) NULL,
    transfer_time DATETIME NULL,
    
    -- Extension Details (if applicable)
    is_extension BOOLEAN DEFAULT FALSE,
    extension_months INT NULL,
    
    -- Metadata
    note TEXT NULL,
    case_id INT NULL,
    due_date DATE NULL,
    paid_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (installment_id) REFERENCES installments(id) ON DELETE CASCADE,
    INDEX idx_installment (installment_id),
    INDEX idx_status (status),
    INDEX idx_period (period_number),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. ORDERS (คำสั่งซื้อ)
-- ============================================================================
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    customer_profile_id INT NULL,
    
    -- Order Details
    order_number VARCHAR(50) NULL,
    order_type ENUM('full_payment', 'installment', 'savings_completion') DEFAULT 'full_payment',
    
    -- Related References
    installment_id INT NULL,
    savings_goal_id INT NULL,
    
    -- Financial
    subtotal DECIMAL(12,2) DEFAULT 0,
    discount DECIMAL(12,2) DEFAULT 0,
    shipping_fee DECIMAL(12,2) DEFAULT 0,
    total_amount DECIMAL(12,2) DEFAULT 0,
    paid_amount DECIMAL(12,2) DEFAULT 0,
    
    -- Status
    status ENUM('draft', 'pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded') DEFAULT 'draft',
    payment_status ENUM('unpaid', 'partial', 'paid', 'refunded') DEFAULT 'unpaid',
    
    -- Shipping
    shipping_address_id INT NULL,
    shipping_name VARCHAR(255) NULL,
    shipping_phone VARCHAR(20) NULL,
    shipping_address TEXT NULL,
    tracking_number VARCHAR(100) NULL,
    shipped_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    
    -- Customer Info (denormalized)
    customer_name VARCHAR(255) NULL,
    customer_phone VARCHAR(20) NULL,
    customer_email VARCHAR(255) NULL,
    
    -- Metadata
    note TEXT NULL,
    admin_note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_customer_profile (customer_profile_id),
    INDEX idx_order_number (order_number),
    INDEX idx_status (status),
    INDEX idx_phone (customer_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. ORDER ITEMS
-- ============================================================================
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    
    -- Product Details
    product_ref_id VARCHAR(100) NULL,
    product_name VARCHAR(255) NOT NULL,
    product_code VARCHAR(100) NULL,
    
    -- Pricing
    quantity INT DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL,
    discount DECIMAL(12,2) DEFAULT 0,
    total_price DECIMAL(12,2) NOT NULL,
    
    -- Metadata
    product_metadata JSON NULL COMMENT 'Snapshot of product data at time of order',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_order (order_id),
    INDEX idx_product (product_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. PAYMENTS (การชำระเงินทั่วไป)
-- ============================================================================
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    customer_profile_id INT NULL,
    
    -- Payment Type
    payment_type ENUM('order', 'installment', 'savings', 'other') NOT NULL,
    
    -- Related References
    order_id INT NULL,
    installment_id INT NULL,
    installment_payment_id INT NULL,
    savings_goal_id INT NULL,
    savings_transaction_id INT NULL,
    case_id INT NULL,
    
    -- Payment Details
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('bank_transfer', 'promptpay', 'credit_card', 'cash', 'other') DEFAULT 'bank_transfer',
    
    -- Verification
    status ENUM('pending', 'verified', 'rejected', 'refunded') DEFAULT 'pending',
    verified_by INT NULL,
    verified_at TIMESTAMP NULL,
    rejection_reason VARCHAR(500) NULL,
    
    -- Payment Proof
    slip_image_url TEXT NULL,
    ocr_data JSON NULL,
    payment_ref VARCHAR(100) NULL COMMENT 'Reference from OCR or manual input',
    sender_name VARCHAR(255) NULL,
    receiver_account VARCHAR(100) NULL,
    transfer_time DATETIME NULL,
    
    -- Push Notification
    notification_sent BOOLEAN DEFAULT FALSE,
    notification_sent_at TIMESTAMP NULL,
    
    -- Customer Info (denormalized)
    customer_name VARCHAR(255) NULL,
    customer_phone VARCHAR(20) NULL,
    
    -- Metadata
    note TEXT NULL,
    admin_note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_customer_profile (customer_profile_id),
    INDEX idx_payment_type (payment_type),
    INDEX idx_status (status),
    INDEX idx_order (order_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. PUSH NOTIFICATIONS LOG
-- ============================================================================
CREATE TABLE IF NOT EXISTS push_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_profile_id INT NULL,
    
    -- Target
    platform ENUM('facebook', 'line') NOT NULL,
    platform_user_id VARCHAR(255) NOT NULL,
    
    -- Notification Details
    notification_type ENUM(
        'payment_received', 
        'payment_verified', 
        'payment_rejected',
        'installment_reminder',
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
    message_data JSON NULL COMMENT 'Template variables used',
    
    -- Related References
    payment_id INT NULL,
    order_id INT NULL,
    installment_id INT NULL,
    savings_goal_id INT NULL,
    
    -- Status
    status ENUM('pending', 'sent', 'failed', 'delivered', 'read') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    
    -- Error Handling
    error_message TEXT NULL,
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    next_retry_at TIMESTAMP NULL,
    
    -- API Response
    api_response JSON NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_customer_profile (customer_profile_id),
    INDEX idx_platform_user (platform, platform_user_id),
    INDEX idx_status (status),
    INDEX idx_type (notification_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. NOTIFICATION TEMPLATES
-- ============================================================================
CREATE TABLE IF NOT EXISTS notification_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(100) NOT NULL UNIQUE,
    notification_type VARCHAR(50) NOT NULL,
    
    -- Templates per platform
    facebook_template TEXT NULL,
    line_template TEXT NULL,
    
    -- Template Variables
    available_variables JSON NULL COMMENT 'List of variables that can be used',
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_template_key (template_key),
    INDEX idx_type (notification_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INSERT DEFAULT NOTIFICATION TEMPLATES
-- ============================================================================
INSERT INTO notification_templates (template_key, notification_type, facebook_template, line_template, available_variables) VALUES

('payment_received', 'payment_received', 
'ได้รับข้อมูลการชำระเงินแล้วค่ะ 💳\nยอด: {{amount}} บาท\nรอเจ้าหน้าที่ตรวจสอบนะคะ',
'ได้รับข้อมูลการชำระเงินแล้วค่ะ 💳\nยอด: {{amount}} บาท\nรอเจ้าหน้าที่ตรวจสอบนะคะ',
'["amount", "payment_ref", "created_at"]'),

('payment_verified', 'payment_verified',
'✅ ยืนยันการชำระเงินเรียบร้อยค่ะ\n💰 ยอด: {{amount}} บาท\n📅 วันที่: {{verified_date}}\n🙏 ขอบคุณที่ใช้บริการค่ะ',
'✅ ยืนยันการชำระเงินเรียบร้อยค่ะ\n💰 ยอด: {{amount}} บาท\n📅 วันที่: {{verified_date}}\n🙏 ขอบคุณที่ใช้บริการค่ะ',
'["amount", "verified_date", "payment_ref", "order_number"]'),

('payment_rejected', 'payment_rejected',
'❌ ไม่สามารถยืนยันการชำระเงินได้ค่ะ\n💰 ยอด: {{amount}} บาท\n📝 เหตุผล: {{reason}}\nกรุณาติดต่อเจ้าหน้าที่ค่ะ',
'❌ ไม่สามารถยืนยันการชำระเงินได้ค่ะ\n💰 ยอด: {{amount}} บาท\n📝 เหตุผล: {{reason}}\nกรุณาติดต่อเจ้าหน้าที่ค่ะ',
'["amount", "reason", "payment_ref"]'),

('installment_payment_verified', 'payment_verified',
'✅ ยืนยันชำระงวดที่ {{period_number}} แล้วค่ะ\n💰 ยอดชำระ: {{amount}} บาท\n📊 ชำระแล้ว: {{paid_amount}} / {{total_amount}} บาท\n📅 งวดถัดไป: {{next_due_date}}\n🙏 ขอบคุณค่ะ',
'✅ ยืนยันชำระงวดที่ {{period_number}} แล้วค่ะ\n💰 ยอดชำระ: {{amount}} บาท\n📊 ชำระแล้ว: {{paid_amount}} / {{total_amount}} บาท\n📅 งวดถัดไป: {{next_due_date}}\n🙏 ขอบคุณค่ะ',
'["period_number", "amount", "paid_amount", "total_amount", "remaining_amount", "next_due_date", "paid_terms", "total_terms"]'),

('installment_completed', 'payment_verified',
'🎉 ผ่อนชำระครบแล้วค่ะ!\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: {{total_amount}} บาท\n✅ ชำระครบ {{total_terms}} งวด\n🙏 ขอบคุณที่ใช้บริการค่ะ',
'🎉 ผ่อนชำระครบแล้วค่ะ!\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: {{total_amount}} บาท\n✅ ชำระครบ {{total_terms}} งวด\n🙏 ขอบคุณที่ใช้บริการค่ะ',
'["product_name", "total_amount", "total_terms"]'),

('installment_reminder', 'installment_reminder',
'⏰ แจ้งเตือนค่างวดค่ะ\n📅 ครบกำหนด: {{due_date}}\n💰 ยอด: {{amount}} บาท\n📋 งวดที่: {{period_number}}/{{total_terms}}\nอย่าลืมชำระนะคะ 😊',
'⏰ แจ้งเตือนค่างวดค่ะ\n📅 ครบกำหนด: {{due_date}}\n💰 ยอด: {{amount}} บาท\n📋 งวดที่: {{period_number}}/{{total_terms}}\nอย่าลืมชำระนะคะ 😊',
'["due_date", "amount", "period_number", "total_terms", "product_name"]'),

('savings_deposit_verified', 'savings_deposit_verified',
'✅ ยืนยันยอดฝากออมแล้วค่ะ\n💰 ฝาก: {{amount}} บาท\n📊 ยอดสะสม: {{saved_amount}} / {{target_amount}} บาท\n📈 ความคืบหน้า: {{progress}}%\n🙏 ขอบคุณค่ะ',
'✅ ยืนยันยอดฝากออมแล้วค่ะ\n💰 ฝาก: {{amount}} บาท\n📊 ยอดสะสม: {{saved_amount}} / {{target_amount}} บาท\n📈 ความคืบหน้า: {{progress}}%\n🙏 ขอบคุณค่ะ',
'["amount", "saved_amount", "target_amount", "remaining_amount", "progress", "product_name"]'),

('savings_goal_reached', 'savings_goal_reached',
'🎉 ออมครบเป้าหมายแล้วค่ะ!\n📦 สินค้า: {{product_name}}\n💰 ยอดออม: {{saved_amount}} บาท\n✅ พร้อมรับสินค้าแล้วค่ะ\nติดต่อเจ้าหน้าที่เพื่อนัดรับสินค้านะคะ 🙏',
'🎉 ออมครบเป้าหมายแล้วค่ะ!\n📦 สินค้า: {{product_name}}\n💰 ยอดออม: {{saved_amount}} บาท\n✅ พร้อมรับสินค้าแล้วค่ะ\nติดต่อเจ้าหน้าที่เพื่อนัดรับสินค้านะคะ 🙏',
'["product_name", "saved_amount", "target_amount"]'),

('order_confirmed', 'order_confirmed',
'✅ ยืนยันคำสั่งซื้อแล้วค่ะ\n📦 Order: #{{order_number}}\n💰 ยอดรวม: {{total_amount}} บาท\nรอจัดส่งนะคะ 📦',
'✅ ยืนยันคำสั่งซื้อแล้วค่ะ\n📦 Order: #{{order_number}}\n💰 ยอดรวม: {{total_amount}} บาท\nรอจัดส่งนะคะ 📦',
'["order_number", "total_amount", "product_names"]'),

('order_shipped', 'order_shipped',
'📦 จัดส่งสินค้าแล้วค่ะ!\n🚚 เลขพัสดุ: {{tracking_number}}\n📦 Order: #{{order_number}}\nติดตามสถานะได้เลยค่ะ',
'📦 จัดส่งสินค้าแล้วค่ะ!\n🚚 เลขพัสดุ: {{tracking_number}}\n📦 Order: #{{order_number}}\nติดตามสถานะได้เลยค่ะ',
'["tracking_number", "order_number", "shipping_company"]')

ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ============================================================================
-- 12. CONVERSATION MESSAGES (Chat History for Context)
-- ============================================================================
CREATE TABLE IF NOT EXISTS conversation_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_profile_id INT NULL,
    case_id INT NULL,
    
    -- Platform
    platform ENUM('facebook', 'line') NOT NULL,
    platform_user_id VARCHAR(255) NOT NULL,
    
    -- Message Details
    direction ENUM('incoming', 'outgoing') NOT NULL,
    message_type ENUM('text', 'image', 'sticker', 'file', 'location', 'template') DEFAULT 'text',
    message_text TEXT NULL,
    message_data JSON NULL COMMENT 'Full message payload',
    
    -- Image Analysis (if applicable)
    image_url TEXT NULL,
    image_type ENUM('product', 'payment_slip', 'other') NULL,
    vision_analysis JSON NULL,
    
    -- Bot Processing
    intent_detected VARCHAR(100) NULL,
    slots_extracted JSON NULL,
    bot_response TEXT NULL,
    processing_time_ms INT NULL,
    
    -- Metadata
    platform_message_id VARCHAR(255) NULL,
    platform_timestamp TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_customer_profile (customer_profile_id),
    INDEX idx_platform_user (platform, platform_user_id),
    INDEX idx_case (case_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- END OF SCHEMA
-- ============================================================================
