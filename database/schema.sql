-- ============================================
-- AI Automation Customer Portal Database Schema
-- ============================================

-- Drop existing tables (in reverse order due to foreign keys)
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS invoice_items;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS payment_methods;
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS subscription_plans;
DROP TABLE IF EXISTS api_usage_logs;
DROP TABLE IF EXISTS bot_chat_logs;
DROP TABLE IF EXISTS customer_services;
DROP TABLE IF EXISTS service_types;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS users;

-- ============================================
-- Users & Authentication
-- ============================================

CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    company_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    status ENUM('active', 'suspended', 'cancelled') DEFAULT 'active',
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password Reset Tokens
CREATE TABLE password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Services & Types
-- ============================================

-- Service Types (Facebook Bot, LINE Bot, Google Vision, Google NL)
CREATE TABLE service_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    base_price DECIMAL(10,2) NOT NULL,
    billing_unit VARCHAR(50) NOT NULL COMMENT 'per month, per request, etc.',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer Services (Active Services)
CREATE TABLE customer_services (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    service_type_id INT NOT NULL,
    service_name VARCHAR(255) NOT NULL COMMENT 'Custom name given by customer',
    platform VARCHAR(50) COMMENT 'facebook, line, etc.',
    api_key VARCHAR(255) UNIQUE,
    webhook_url TEXT,
    config JSON COMMENT 'Service-specific configuration',
    status ENUM('active', 'paused', 'error') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (service_type_id) REFERENCES service_types(id),
    INDEX idx_user_service (user_id, service_type_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Usage Tracking
-- ============================================

-- Bot Chat Logs (Facebook, LINE)
CREATE TABLE bot_chat_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    customer_service_id INT NOT NULL,
    platform_user_id VARCHAR(255) NOT NULL,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    message_type VARCHAR(50) NOT NULL COMMENT 'text, image, video, etc.',
    message_content TEXT,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_service_id) REFERENCES customer_services(id) ON DELETE CASCADE,
    INDEX idx_service_date (customer_service_id, created_at),
    INDEX idx_platform_user (platform_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API Usage Logs (Google Vision, Natural Language)
CREATE TABLE api_usage_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    customer_service_id INT NOT NULL,
    api_type VARCHAR(50) NOT NULL COMMENT 'google_vision, google_nl',
    endpoint VARCHAR(255) NOT NULL,
    request_count INT DEFAULT 1,
    request_size INT COMMENT 'bytes',
    response_time INT COMMENT 'milliseconds',
    status_code INT,
    cost DECIMAL(10,4) COMMENT 'Cost per request',
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_service_id) REFERENCES customer_services(id) ON DELETE CASCADE,
    INDEX idx_service_api_date (customer_service_id, api_type, created_at),
    INDEX idx_date (created_at),
    INDEX idx_api_type (api_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Subscriptions & Billing
-- ============================================

-- Subscription Plans
CREATE TABLE subscription_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    monthly_price DECIMAL(10,2) NOT NULL,
    included_requests INT COMMENT 'Free requests per month',
    overage_rate DECIMAL(10,4) COMMENT 'Price per request over limit',
    features JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer Subscriptions
CREATE TABLE subscriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('active', 'paused', 'cancelled', 'expired') DEFAULT 'active',
    current_period_start DATE NOT NULL,
    current_period_end DATE NOT NULL,
    auto_renew BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cancelled_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id),
    INDEX idx_user_status (user_id, status),
    INDEX idx_period_end (current_period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Payment Methods (Omise)
-- ============================================

CREATE TABLE payment_methods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    omise_customer_id VARCHAR(255),
    omise_card_id VARCHAR(255),
    card_brand VARCHAR(50),
    card_last4 VARCHAR(4),
    card_expiry_month INT,
    card_expiry_year INT,
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_default (user_id, is_default),
    INDEX idx_omise_customer (omise_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Invoices & Transactions
-- ============================================

-- Invoices
CREATE TABLE invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    subscription_id INT,
    amount DECIMAL(10,2) NOT NULL,
    tax DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'THB',
    status ENUM('pending', 'paid', 'failed', 'cancelled') DEFAULT 'pending',
    billing_period_start DATE,
    billing_period_end DATE,
    due_date DATE,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
    INDEX idx_user_status (user_id, status),
    INDEX idx_invoice_number (invoice_number),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoice Items (Breakdown)
CREATE TABLE invoice_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,4) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment Transactions
CREATE TABLE transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    payment_method_id INT,
    omise_charge_id VARCHAR(255),
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'THB',
    status ENUM('pending', 'successful', 'failed') DEFAULT 'pending',
    error_message TEXT,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    INDEX idx_omise_charge (omise_charge_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Activity Logs (Audit Trail)
-- ============================================

CREATE TABLE activity_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50),
    resource_id INT,
    details JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_date (user_id, created_at),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Sample Data for Testing
-- ============================================

-- Insert Service Types
INSERT INTO service_types (name, code, description, base_price, billing_unit, is_active) VALUES
('Facebook Chatbot', 'facebook_bot', 'AI Chatbot สำหรับ Facebook Messenger', 1500.00, 'per month', TRUE),
('LINE Chatbot', 'line_bot', 'AI Chatbot สำหรับ LINE Official Account', 1500.00, 'per month', TRUE),
('Google Vision API', 'google_vision', 'Image Analysis & Recognition', 0.50, 'per request', TRUE),
('Google Natural Language API', 'google_nl', 'Text Analysis & Sentiment Detection', 0.30, 'per request', TRUE);

-- Insert Subscription Plans
INSERT INTO subscription_plans (name, description, monthly_price, included_requests, overage_rate, features, is_active) VALUES
('Starter', 'เหมาะสำหรับธุรกิจขนาดเล็ก', 2500.00, 1000, 0.50, JSON_ARRAY('1 Bot', '1000 API Requests', 'Email Support'), TRUE),
('Professional', 'เหมาะสำหรับธุรกิจขนาดกลาง', 5000.00, 5000, 0.40, JSON_ARRAY('3 Bots', '5000 API Requests', 'Priority Support', 'Analytics Dashboard'), TRUE),
('Enterprise', 'เหมาะสำหรับองค์กรขนาดใหญ่', 10000.00, 20000, 0.30, JSON_ARRAY('Unlimited Bots', '20000 API Requests', '24/7 Support', 'Custom Integration', 'Dedicated Account Manager'), TRUE);

-- Insert Demo User (password: demo1234)
INSERT INTO users (email, password_hash, full_name, phone, company_name, status) VALUES
('demo@aiautomation.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Demo User', '0812345678', 'Demo Company Ltd.', 'active');

-- Get the demo user ID for subsequent inserts
SET @demo_user_id = LAST_INSERT_ID();

-- Insert Demo Subscription
INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end, auto_renew) VALUES
(@demo_user_id, 2, 'active', DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_ADD(CURDATE(), INTERVAL 15 DAY), TRUE);

-- Insert Demo Services
INSERT INTO customer_services (user_id, service_type_id, service_name, platform, api_key, status) VALUES
(@demo_user_id, 1, 'Facebook Bot - สนับสนุนลูกค้า', 'facebook', CONCAT('fb_', MD5(RAND())), 'active'),
(@demo_user_id, 2, 'LINE Bot - แจ้งข่าวสาร', 'line', CONCAT('line_', MD5(RAND())), 'active'),
(@demo_user_id, 3, 'Vision API - ตรวจสอบภาพ', NULL, CONCAT('gv_', MD5(RAND())), 'active'),
(@demo_user_id, 4, 'NL API - วิเคราะห์ความคิดเห็น', NULL, CONCAT('gnl_', MD5(RAND())), 'active');

-- Note: For password hashing in PHP, use: password_hash('demo1234', PASSWORD_DEFAULT)
-- The hash above is just a sample. You should generate a new one when creating actual users.
