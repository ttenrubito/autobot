-- Migration: Create NEW tables for RouterV4 services
-- Date: 2026-01-23
-- NOTE: These are ONLY new tables that don't exist in production
-- Existing tables: chat_sessions, customer_profiles, orders, payments, 
--                  pawns, repairs, savings_accounts, installment_contracts, bot_chat_logs

-- ==================== CHAT STATE TABLE ====================
-- Used by ChatService for quick state management (e.g., checkout state, conversation flow)
-- This is a NEW table for fast key-value state lookups

CREATE TABLE IF NOT EXISTS chat_state (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_key VARCHAR(100) NOT NULL,
    value TEXT,
    external_user_id VARCHAR(255) NOT NULL COMMENT 'Maps to chat_sessions.external_user_id',
    channel_id INT NOT NULL COMMENT 'Maps to chat_sessions.channel_id',
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_state (state_key, external_user_id, channel_id),
    INDEX idx_expires (expires_at),
    INDEX idx_user (external_user_id, channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== PRODUCT VIEWS TABLE ====================
-- Used by ProductService for tracking recently viewed products
-- NEW table for product view analytics

CREATE TABLE IF NOT EXISTS product_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    platform_user_id VARCHAR(255) NOT NULL COMMENT 'User ID from platform',
    channel_id INT NOT NULL,
    product_code VARCHAR(100) NOT NULL,
    product_data JSON COMMENT 'Cached product info at view time',
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_view (platform_user_id, channel_id, product_code),
    INDEX idx_recent (channel_id, platform_user_id, viewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== API USAGE LOGS TABLE ====================
-- Used by BackendApiService for analytics and debugging
-- NEW table for API call tracking

CREATE TABLE IF NOT EXISTS api_usage_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    tenant_id VARCHAR(50) DEFAULT 'default',
    api_type VARCHAR(50) NOT NULL COMMENT 'products, installment, pawn, repair, savings, etc.',
    endpoint VARCHAR(255),
    request_count INT DEFAULT 1,
    response_time INT COMMENT 'milliseconds',
    status_code INT,
    success TINYINT(1) DEFAULT 1,
    error_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_channel (channel_id),
    INDEX idx_type (api_type),
    INDEX idx_created (created_at),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== PRODUCTS TABLE (OPTIONAL) ====================
-- Only create if shop doesn't have external product API
-- This is for local product catalog management

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    tenant_id VARCHAR(50) DEFAULT 'default',
    product_code VARCHAR(100) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sale_price DECIMAL(12,2) NULL,
    image_url TEXT,
    stock INT DEFAULT 0,
    status ENUM('active', 'inactive', 'out_of_stock') DEFAULT 'active',
    metadata JSON COMMENT 'Additional product attributes',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_product (channel_id, product_code),
    INDEX idx_channel (channel_id),
    INDEX idx_category (category),
    INDEX idx_status (status),
    INDEX idx_name (product_name),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== CLEANUP OLD TABLE (if exists) ====================
-- Remove the old migration file's tables if they were accidentally created

-- DROP TABLE IF EXISTS chat_messages; -- We use bot_chat_logs instead
-- DROP TABLE IF EXISTS installments; -- We use installment_contracts instead  
-- DROP TABLE IF EXISTS pawn_tickets; -- We use pawns instead
-- DROP TABLE IF EXISTS repair_orders; -- We use repairs instead

-- ==================== INDEXES FOR EXISTING TABLES ====================
-- Add useful indexes if they don't exist

-- Add index for faster platform_user_id lookups on orders (if not exists)
-- CREATE INDEX IF NOT EXISTS idx_orders_platform_user ON orders (platform_user_id);

-- Add index for faster external_user_id lookups on savings_accounts (if not exists)
-- CREATE INDEX IF NOT EXISTS idx_savings_external_user ON savings_accounts (external_user_id);
