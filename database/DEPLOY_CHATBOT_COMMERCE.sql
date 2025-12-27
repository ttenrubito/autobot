-- ============================================================================
-- CHATBOT E-COMMERCE DEPLOYMENT SCRIPT
-- Created: 2025-12-23
-- Description: Complete deployment script combining all necessary tables and data
-- 
-- Contents:
--   1. Schema Creation (Conversations, Addresses, Orders, Payments, Installments)
--   2. Test User Setup (test1@gmail.com with basic data)
--   3. Additional Mock Data (more conversations, orders, payments)
--
-- Usage:
--   mysql -u root -p autobot < DEPLOY_CHATBOT_COMMERCE.sql
-- ============================================================================

-- ============================================================================
-- PART 1: CREATE TABLES
-- Source: 2025_12_23_create_chatbot_commerce_tables.sql
-- ============================================================================

-- ============================================
-- 1. CONVERSATIONS & CHAT HISTORY
-- ============================================

-- Conversation Sessions
CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(100) UNIQUE NOT NULL,
    customer_id INT NULL COMMENT 'FK to users table, NULL if not identified yet',
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Platform Info
    platform ENUM('line', 'facebook', 'web', 'instagram') NOT NULL,
    platform_user_id VARCHAR(255) NOT NULL COMMENT 'LINE User ID or FB PSID',
    
    -- Session Info
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    status ENUM('active', 'ended', 'timeout') DEFAULT 'active',
    
    -- Summary
    message_count INT DEFAULT 0,
    conversation_summary JSON COMMENT 'Summary of conversation outcome',
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_platform_user (platform, platform_user_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_started_at (started_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat Messages
CREATE TABLE IF NOT EXISTS chat_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(100) NOT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Message Info
    message_id VARCHAR(255) UNIQUE COMMENT 'LINE/FB Message ID',
    platform ENUM('line', 'facebook', 'web', 'instagram') NOT NULL,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    
    -- Sender Info
    sender_type ENUM('customer', 'bot', 'agent') NOT NULL,
    sender_id VARCHAR(255),
    
    -- Message Content
    message_type ENUM('text', 'image', 'video', 'audio', 'file', 'sticker', 'location') NOT NULL,
    message_text TEXT COMMENT 'Text message content',
    message_data JSON COMMENT 'Additional data: URLs, metadata, etc.',
    
    -- Processing Info (AI/NLP)
    intent VARCHAR(100) COMMENT 'Detected intent',
    confidence DECIMAL(5,4) COMMENT 'Confidence score 0-1',
    entities JSON COMMENT 'Extracted entities',
    
    -- Response Info
    response_template VARCHAR(100) COMMENT 'Template used for response',
    response_source ENUM('kb', 'ai', 'api', 'scripted') DEFAULT 'scripted',
    
    -- Timestamps
    sent_at TIMESTAMP NOT NULL COMMENT 'Time sent on platform',
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Time received by our system',
    processed_at TIMESTAMP NULL COMMENT 'Time finished processing',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_conversation (conversation_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_platform (platform),
    INDEX idx_sent_at (sent_at),
    INDEX idx_intent (intent),
    INDEX idx_direction (direction),
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat Events (Special Events)
CREATE TABLE IF NOT EXISTS chat_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(100) NOT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    event_type VARCHAR(50) NOT NULL COMMENT 'e.g., order_placed, payment_submitted, handoff_to_agent',
    event_data JSON NOT NULL COMMENT 'Event details',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_conversation (conversation_id),
    INDEX idx_event_type (event_type),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. CUSTOMER ADDRESSES
-- ============================================

CREATE TABLE IF NOT EXISTS customer_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Address Type
    address_type ENUM('shipping', 'billing', 'both') DEFAULT 'shipping',
    
    -- Contact Info
    recipient_name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    
    -- Address Details
    address_line1 VARCHAR(500) NOT NULL,
    address_line2 VARCHAR(500),
    subdistrict VARCHAR(100) COMMENT 'ตำบล/แขวง',
    district VARCHAR(100) NOT NULL COMMENT 'อำเภอ/เขต',
    province VARCHAR(100) NOT NULL COMMENT 'จังหวัด',
    postal_code VARCHAR(10) NOT NULL,
    country VARCHAR(50) DEFAULT 'Thailand',
    
    -- Additional Info (JSON)
    additional_info JSON COMMENT 'Landmark, delivery notes, GPS, etc.',
    
    -- Flags
    is_default TINYINT(1) DEFAULT 0,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_customer (customer_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_province (province),
    INDEX idx_postal (postal_code),
    INDEX idx_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. ORDERS (สำหรับสินค้าแบรนด์เนม)
-- ============================================

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Order Details
    product_name VARCHAR(255) NOT NULL,
    product_code VARCHAR(100),
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    
    -- Payment Info
    payment_type ENUM('full', 'installment') NOT NULL,
    installment_months INT DEFAULT NULL COMMENT 'จำนวนเดือนที่ผ่อน',
    
    -- Shipping Address
    shipping_address_id INT COMMENT 'FK to customer_addresses',
    
    -- Status
    status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    
    -- Order Source
    source VARCHAR(50) DEFAULT 'chatbot' COMMENT 'chatbot, web, app',
    conversation_id VARCHAR(100) COMMENT 'FK to conversations if from chatbot',
    
    -- Notes
    notes TEXT COMMENT 'Order notes',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    shipped_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shipping_address_id) REFERENCES customer_addresses(id) ON DELETE SET NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE SET NULL,
    INDEX idx_order_no (order_no),
    INDEX idx_customer (customer_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. PAYMENTS
-- ============================================

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_no VARCHAR(50) UNIQUE NOT NULL,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Payment Amount
    amount DECIMAL(10,2) NOT NULL,
    payment_type ENUM('full', 'installment') NOT NULL,
    payment_method ENUM('bank_transfer', 'promptpay', 'credit_card', 'cash') NOT NULL,
    
    -- Installment Info
    installment_period INT DEFAULT NULL COMMENT 'จำนวนงวดทั้งหมด',
    current_period INT DEFAULT NULL COMMENT 'งวดที่กำลังชำระ (1-based)',
    
    -- Status
    status ENUM('pending', 'verifying', 'verified', 'rejected') DEFAULT 'pending',
    
    -- Payment Evidence
    slip_image VARCHAR(500) COMMENT 'URL to slip image',
    
    -- Payment Details (JSON)
    payment_details JSON COMMENT 'Bank info, chatbot data, verification notes, etc.',
    
    -- Verification
    verified_by INT DEFAULT NULL COMMENT 'FK to admin user',
    verified_at TIMESTAMP NULL,
    rejection_reason TEXT,
    
    -- Timestamps
    payment_date TIMESTAMP NULL COMMENT 'วันที่ลูกค้าโอนเงิน',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Meta
    source VARCHAR(50) DEFAULT 'chatbot' COMMENT 'chatbot, web, app',
    
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_payment_no (payment_no),
    INDEX idx_order (order_id),
    INDEX idx_customer (customer_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_status (status),
    INDEX idx_payment_date (payment_date),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. INSTALLMENT SCHEDULES
-- ============================================

CREATE TABLE IF NOT EXISTS installment_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Schedule Info
    period_number INT NOT NULL COMMENT 'งวดที่ (1-based)',
    due_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    
    -- Payment Info
    paid_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
    paid_at TIMESTAMP NULL,
    
    -- Link to Payment
    payment_id INT DEFAULT NULL COMMENT 'FK to payments when paid',
    
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    INDEX idx_order (order_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_due_date (due_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. USER MENU CONFIGURATION
-- ============================================

CREATE TABLE IF NOT EXISTS user_menu_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    menu_items JSON NOT NULL COMMENT 'Array of menu items with enabled/disabled flags',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user_email (user_email),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Chat & Commerce tables created successfully!' AS status;

-- ============================================================================
-- PART 2: SETUP TEST USER (test1@gmail.com)
-- Source: setup_test1_user.sql
-- ============================================================================

-- Create user if not exists
INSERT INTO users (email, password_hash, full_name, phone, status)
VALUES (
    'test1@gmail.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'ทดสอบ ระบบ',
    '0812345678',
    'active'
)
ON DUPLICATE KEY UPDATE
    full_name = 'ทดสอบ ระบบ',
    updated_at = NOW();

-- Get user ID
SET @test_user_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');

SELECT CONCAT('Using user: test1@gmail.com (ID: ', @test_user_id, ')') AS status;

-- Cleanup existing data
DELETE FROM conversations WHERE customer_id = @test_user_id;
DELETE FROM installment_schedules WHERE order_id IN (
    SELECT id FROM (SELECT id FROM orders WHERE customer_id = @test_user_id) AS temp_orders
);
DELETE FROM payments WHERE customer_id = @test_user_id;
DELETE FROM orders WHERE customer_id = @test_user_id;
DELETE FROM customer_addresses WHERE customer_id = @test_user_id;

SELECT 'Cleaned up existing test data for test1@gmail.com' AS status;

-- User menu configuration
INSERT INTO user_menu_config (user_email, menu_items, is_active)
VALUES (
    'test1@gmail.com',
    JSON_OBJECT(
        'menus', JSON_ARRAY(
            JSON_OBJECT('id', 'dashboard', 'label', 'Dashboard', 'enabled', true, 'icon', '📊'),
            JSON_OBJECT('id', 'chat_history', 'label', 'ประวัติการสนทนา', 'enabled', true, 'icon', '💬'),
            JSON_OBJECT('id', 'orders', 'label', 'คำสั่งซื้อ', 'enabled', true, 'icon', '📦'),
            JSON_OBJECT('id', 'addresses', 'label', 'ที่อยู่จัดส่ง', 'enabled', true, 'icon', '📍'),
            JSON_OBJECT('id', 'payment_history', 'label', 'ประวัติการชำระ', 'enabled', true, 'icon', '💰'),
            JSON_OBJECT('id', 'profile', 'label', 'โปรไฟล์', 'enabled', true, 'icon', '👤')
        )
    ),
    1
)
ON DUPLICATE KEY UPDATE
    menu_items = JSON_OBJECT(
        'menus', JSON_ARRAY(
            JSON_OBJECT('id', 'dashboard', 'label', 'Dashboard', 'enabled', true, 'icon', '📊'),
            JSON_OBJECT('id', 'chat_history', 'label', 'ประวัติการสนทนา', 'enabled', true, 'icon', '💬'),
            JSON_OBJECT('id', 'orders', 'label', 'คำสั่งซื้อ', 'enabled', true, 'icon', '📦'),
            JSON_OBJECT('id', 'addresses', 'label', 'ที่อยู่จัดส่ง', 'enabled', true, 'icon', '📍'),
            JSON_OBJECT('id', 'payment_history', 'label', 'ประวัติการชำระ', 'enabled', true, 'icon', '💰'),
            JSON_OBJECT('id', 'profile', 'label', 'โปรไฟล์', 'enabled', true, 'icon', '👤')
        )
    );

-- Create addresses
INSERT INTO customer_addresses (
    customer_id, tenant_id, address_type, recipient_name, phone,
    address_line1, address_line2, subdistrict, district, province, postal_code,
    additional_info, is_default
) VALUES
(
    @test_user_id, 'default', 'shipping', 'คุณทดสอบ ระบบ', '0812345678',
    '123/45 หมู่บ้านสุขสันต์', 'ซอยสุขุมวิท 101', 'บางจาก', 'พระโขนง', 'กรุงเทพมหานคร', '10260',
    JSON_OBJECT(
        'landmark', 'ตรงข้าม Big C บางนา',
        'delivery_note', 'ส่งช่วงเย็นหลัง 17:00 น.',
        'collected_via', 'line_chatbot'
    ),
    1
),
(
    @test_user_id, 'default', 'shipping', 'คุณทดสอบ ระบบ', '0812345678',
    '999 อาคารสาธรสแควร์', 'ชั้น 15', 'สีลม', 'บางรัก', 'กรุงเทพมหานคร', '10500',
    JSON_OBJECT(
        'landmark', 'อาคารสาธรสแควร์ ติด BTS สุรศักดิ์',
        'delivery_note', 'ส่งตอนเที่ยงได้ 12:00-13:00',
        'collected_via', 'chatbot'
    ),
    0
);

-- Get default address
SET @default_addr_id = (SELECT id FROM customer_addresses WHERE customer_id = @test_user_id AND is_default = 1 LIMIT 1);

-- Create sample conversations
INSERT INTO conversations (
    conversation_id, customer_id, tenant_id, platform, platform_user_id,
    started_at, last_message_at, status, message_count, conversation_summary
) VALUES
(
    'conv_line_001_test1', @test_user_id, 'default', 'line', 'U1234567890abcdef',
    DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 'ended', 8,
    JSON_OBJECT('outcome', 'product_inquiry', 'product_name', 'Rolex Submariner', 'intent', 'check_price', 'handled_by', 'bot')
),
(
    'conv_fb_002_test1', @test_user_id, 'default', 'facebook', '1234567890',
    DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), 'ended', 12,
    JSON_OBJECT('outcome', 'order_placed', 'order_id', 'ORD-20251221-001', 'product_name', 'Omega Seamaster', 'total_amount', 280000, 'payment_type', 'installment')
),
(
    'conv_line_003_test1', @test_user_id, 'default', 'line', 'U1234567890abcdef',
    DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 'ended', 5,
    JSON_OBJECT('outcome', 'payment_submitted', 'order_id', 'ORD-20251221-001', 'payment_amount', 50000, 'period', 1)
);

-- Create sample orders
INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name, product_code,
    quantity, unit_price, total_amount, payment_type, installment_months,
    shipping_address_id, status, source, conversation_id, created_at
) VALUES
(
    'ORD-20251221-001', @test_user_id, 'default', 'Omega Seamaster Professional 300M', 'OMEGA-SEA-300',
    1, 280000.00, 280000.00, 'installment', 6,
    @default_addr_id, 'processing', 'chatbot', 'conv_fb_002_test1', DATE_SUB(NOW(), INTERVAL 2 DAY)
);

SET @order1_id = LAST_INSERT_ID();

INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name, product_code,
    quantity, unit_price, total_amount, payment_type, installment_months,
    shipping_address_id, status, source, delivered_at, created_at
) VALUES
(
    'ORD-20251215-123', @test_user_id, 'default', 'Rolex Datejust 41', 'ROLEX-DJ-41',
    1, 420000.00, 420000.00, 'full', NULL,
    @default_addr_id, 'delivered', 'chatbot', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY)
);

SET @order2_id = LAST_INSERT_ID();

-- Create installment schedules
INSERT INTO installment_schedules (order_id, tenant_id, period_number, due_date, amount, status, paid_amount, paid_at) VALUES
(@order1_id, 'default', 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 50000.00, 'paid', 50000.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@order1_id, 'default', 2, DATE_ADD(CURDATE(), INTERVAL 29 DAY), 50000.00, 'pending', 0, NULL),
(@order1_id, 'default', 3, DATE_ADD(CURDATE(), INTERVAL 59 DAY), 50000.00, 'pending', 0, NULL),
(@order1_id, 'default', 4, DATE_ADD(CURDATE(), INTERVAL 89 DAY), 50000.00, 'pending', 0, NULL),
(@order1_id, 'default', 5, DATE_ADD(CURDATE(), INTERVAL 119 DAY), 50000.00, 'pending', 0, NULL),
(@order1_id, 'default', 6, DATE_ADD(CURDATE(), INTERVAL 149 DAY), 50000.00, 'pending', 0, NULL);

-- Create payments
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id, amount,
    payment_type, payment_method, status, slip_image,
    payment_details, verified_at, payment_date, source, created_at
) VALUES
(
    'PAY-20251215-001', @order2_id, @test_user_id, 'default', 420000.00,
    'full', 'bank_transfer', 'verified', '/uploads/slips/test1_payment1.jpg',
    JSON_OBJECT('bank_info', JSON_OBJECT('bank_name', 'ธนาคารกสิกรไทย', 'bank_code', 'KBANK', 'transfer_time', '14:30'), 'verification_notes', JSON_OBJECT('verified_by_name', 'Admin', 'notes', 'ตรวจสอบแล้วถูกต้อง')),
    DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), 'chatbot', DATE_SUB(NOW(), INTERVAL 9 DAY)
),
(
    'PAY-20251222-001', @order1_id, @test_user_id, 'default', 50000.00,
    'installment', 'bank_transfer', 'verified', '/uploads/slips/test1_payment2.jpg',
    JSON_OBJECT('bank_info', JSON_OBJECT('bank_name', 'ธนาคารกรุงเทพ', 'bank_code', 'BBL', 'transfer_time', '10:15'), 'chatbot_data', JSON_OBJECT('platform', 'line', 'conversation_id', 'conv_line_003_test1')),
    DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 'chatbot', DATE_SUB(NOW(), INTERVAL 1 DAY)
);

SELECT 'Basic test data created for test1@gmail.com' AS status;

-- ============================================================================
-- PART 3: ADDITIONAL MOCK DATA
-- Source: add_more_mock_data.sql
-- ============================================================================

-- Add more addresses
INSERT INTO customer_addresses (
    customer_id, tenant_id, address_type, recipient_name, phone,
    address_line1, address_line2, subdistrict, district, province, postal_code,
    additional_info, is_default
) VALUES
(
    @test_user_id, 'default', 'shipping', 'คุณทดสอบ ระบบ', '0812345678',
    '456 ถนนพระราม 4', 'แขวงพระโขนง', 'พระโขนง', 'คลองเตย', 'กรุงเทพมหานคร', '10110',
    JSON_OBJECT('landmark', 'ใกล้ BTS พระโขนง', 'delivery_note', 'โทรก่อนส่ง'),
    0
),
(
    @test_user_id, 'default', 'shipping', 'น้องเต้ (ญาติ)', '0898765432',
    '789/12 หมู่บ้านเศรษฐกิจ', 'ซอยรามคำแหง 24', 'หัวหมาก', 'บางกะปิ', 'กรุงเทพมหานคร', '10240',
    JSON_OBJECT('landmark', 'ตรงข้าม The Mall บางกะปิ', 'delivery_note', 'ส่งวันเสาร์-อาทิตย์ได้'),
    0
),
(
    @test_user_id, 'default', 'shipping', 'คุณทดสอบ ระบบ', '0812345678',
    '321 อาคารจัสมิน', 'ชั้น 22 ห้อง 2205', 'ลุมพินี', 'ปทุมวัน', 'กรุงเทพมหานคร', '10330',
    JSON_OBJECT('landmark', 'อาคารจัสมิน ติด BTS ช่องนนทรี', 'delivery_note', 'ฝากยาม'),
    0
);

-- Add more conversations
INSERT INTO conversations (
    conversation_id, customer_id, tenant_id, platform, platform_user_id,
    started_at, last_message_at, status, message_count, conversation_summary
) VALUES
(
    'conv_line_004_test1', @test_user_id, 'default', 'line', 'U1234567890abcdef',
    DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), 'ended', 6,
    JSON_OBJECT('outcome', 'installment_inquiry', 'product_name', 'Cartier Tank', 'intent', 'ask_installment', 'handled_by', 'bot')
),
(
    'conv_fb_005_test1', @test_user_id, 'default', 'facebook', '1234567890',
    DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), 'ended', 8,
    JSON_OBJECT('outcome', 'complaint', 'order_id', 'ORD-20251215-123', 'issue', 'delivery_delay', 'handled_by', 'bot')
);

-- Add more orders
INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name, product_code,
    quantity, unit_price, total_amount, payment_type, installment_months,
    shipping_address_id, status, source, created_at
) VALUES
(
    'ORD-20251210-456', @test_user_id, 'default', 'Cartier Tank Must Large', 'CARTIER-TANK-L',
    1, 150000.00, 150000.00, 'installment', 10,
    @default_addr_id, 'processing', 'chatbot', DATE_SUB(NOW(), INTERVAL 13 DAY)
);

SET @order3_id = LAST_INSERT_ID();

INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name, product_code,
    quantity, unit_price, total_amount, payment_type,
    shipping_address_id, status, source, shipped_at, created_at
) VALUES
(
    'ORD-20251218-789', @test_user_id, 'default', 'TAG Heuer Carrera Calibre 16', 'TAG-CARRERA-16',
    1, 175000.00, 175000.00, 'full',
    @default_addr_id, 'shipped', 'chatbot', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)
);

SET @order4_id = LAST_INSERT_ID();

INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name, product_code,
    quantity, unit_price, total_amount, payment_type,
    shipping_address_id, status, source, created_at
) VALUES
(
    'ORD-20251222-111', @test_user_id, 'default', 'Longines Master Collection', 'LONGINES-MASTER',
    1, 95000.00, 95000.00, 'full',
    @default_addr_id, 'pending', 'web', DATE_SUB(NOW(), INTERVAL 1 DAY)
);

-- Add installment schedules for Order 3
INSERT INTO installment_schedules (order_id, tenant_id, period_number, due_date, amount, status, paid_amount, paid_at) VALUES
(@order3_id, 'default', 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 15000.00, 'paid', 15000.00, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@order3_id, 'default', 2, DATE_ADD(CURDATE(), INTERVAL 27 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 3, DATE_ADD(CURDATE(), INTERVAL 57 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 4, DATE_ADD(CURDATE(), INTERVAL 87 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 5, DATE_ADD(CURDATE(), INTERVAL 117 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 6, DATE_ADD(CURDATE(), INTERVAL 147 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 7, DATE_ADD(CURDATE(), INTERVAL 177 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 8, DATE_ADD(CURDATE(), INTERVAL 207 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 9, DATE_ADD(CURDATE(), INTERVAL 237 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 10, DATE_ADD(CURDATE(), INTERVAL 267 DAY), 15000.00, 'pending', 0, NULL);

-- Add more payments
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id, amount,
    payment_type, payment_method, installment_period, current_period,
    status, slip_image, payment_details, verified_at, payment_date, source, created_at
) VALUES
(
    'PAY-20251210-002', @order3_id, @test_user_id, 'default', 15000.00,
    'installment', 'bank_transfer', 10, 1, 'verified', '/autobot/public/uploads/slips/test1_payment4.png',
    JSON_OBJECT('bank_info', JSON_OBJECT('bank_name', 'ธนาคารไทยพาณิชย์', 'bank_code', 'SCB', 'transfer_time', '16:45')),
    DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 13 DAY), 'chatbot', DATE_SUB(NOW(), INTERVAL 13 DAY)
),
(
    'PAY-20251218-003', @order4_id, @test_user_id, 'default', 175000.00,
    'full', 'bank_transfer', NULL, NULL, 'pending', '/autobot/public/uploads/slips/test1_payment5.png',
    JSON_OBJECT('bank_info', JSON_OBJECT('bank_name', 'ธนาคารกรุงไทย', 'bank_code', 'KTB', 'transfer_time', '11:20')),
    NULL, DATE_SUB(NOW(), INTERVAL 5 DAY), 'chatbot', DATE_SUB(NOW(), INTERVAL 5 DAY)
);

-- ============================================================================
-- DEPLOYMENT COMPLETE
-- ============================================================================

SELECT '========================================' AS '';
SELECT '✅ DEPLOYMENT COMPLETE!' AS status;
SELECT '========================================' AS '';
SELECT '' AS '';
SELECT 'Tables created:' AS '';
SELECT '  ✓ conversations' AS '';
SELECT '  ✓ chat_messages' AS '';
SELECT '  ✓ chat_events' AS '';
SELECT '  ✓ customer_addresses' AS '';
SELECT '  ✓ orders' AS '';
SELECT '  ✓ payments' AS '';
SELECT '  ✓ installment_schedules' AS '';
SELECT '  ✓ user_menu_config' AS '';
SELECT '' AS '';
SELECT 'Test account created:' AS '';
SELECT '  Email: test1@gmail.com' AS '';
SELECT '  Password: password123' AS '';
SELECT '' AS '';
SELECT 'Sample data summary:' AS '';
SELECT CONCAT('  - Addresses: ', COUNT(*)) AS '' FROM customer_addresses WHERE customer_id = @test_user_id;
SELECT CONCAT('  - Conversations: ', COUNT(*)) AS '' FROM conversations WHERE customer_id = @test_user_id;
SELECT CONCAT('  - Orders: ', COUNT(*)) AS '' FROM orders WHERE customer_id = @test_user_id;
SELECT CONCAT('  - Payments: ', COUNT(*)) AS '' FROM payments WHERE customer_id = @test_user_id;
SELECT '' AS '';
SELECT '========================================' AS '';
SELECT 'System ready for testing!' AS '';
SELECT '========================================' AS '';
