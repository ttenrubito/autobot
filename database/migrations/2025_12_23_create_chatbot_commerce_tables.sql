-- ============================================
-- Chatbot E-Commerce System Tables
-- Created: 2025-12-23
-- Description: Tables for chat history, customer addresses, orders, and payments
-- ============================================

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

-- ============================================
-- COMPLETION MESSAGE
-- ============================================
SELECT 'Chat & Commerce tables created successfully!' AS status;
