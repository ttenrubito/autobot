-- Simple Cases Table Migration (Part 1)
-- Run separately to avoid SQL parsing issues

CREATE TABLE IF NOT EXISTS cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_no VARCHAR(50) UNIQUE NOT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    case_type ENUM('product_inquiry', 'payment_full', 'payment_installment', 'payment_savings', 'general_inquiry', 'complaint', 'other') NOT NULL DEFAULT 'general_inquiry',
    channel_id BIGINT UNSIGNED NOT NULL,
    external_user_id VARCHAR(255) NOT NULL,
    customer_id INT NULL,
    platform ENUM('line', 'facebook', 'web', 'instagram') NOT NULL,
    session_id BIGINT UNSIGNED NULL,
    subject VARCHAR(500) NULL,
    description TEXT NULL,
    slots JSON,
    product_ref_id VARCHAR(100) NULL,
    order_id INT NULL,
    payment_id INT NULL,
    savings_account_id BIGINT UNSIGNED NULL,
    status ENUM('open', 'pending_admin', 'in_progress', 'pending_customer', 'resolved', 'cancelled') NOT NULL DEFAULT 'open',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    assigned_to INT NULL,
    assigned_at TIMESTAMP NULL,
    resolution_type ENUM('completed', 'no_response', 'duplicate', 'invalid', 'other') NULL,
    resolution_notes TEXT NULL,
    resolved_at TIMESTAMP NULL,
    resolved_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_customer_message_at TIMESTAMP NULL,
    last_bot_message_at TIMESTAMP NULL,
    last_admin_message_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
