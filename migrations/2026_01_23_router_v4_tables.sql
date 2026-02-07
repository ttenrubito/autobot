-- Migration: Create tables for RouterV4 services
-- Date: 2026-01-23
-- Description: สร้างตารางใหม่สำหรับ RouterV4Handler ที่ไม่มีใน production

-- ==================== CHAT STATE TABLE ====================
-- Used by ChatService for quick state management (checkout flow, etc.)

CREATE TABLE IF NOT EXISTS chat_state (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_key VARCHAR(100) NOT NULL COMMENT 'e.g. checkout_state, pending_payment',
    value TEXT COMMENT 'JSON or text value',
    external_user_id VARCHAR(255) NOT NULL COMMENT 'LINE userId or FB PSID',
    channel_id INT NOT NULL COMMENT 'FK to customer_channels.id',
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_state (state_key, external_user_id, channel_id),
    INDEX idx_expires (expires_at),
    INDEX idx_user_channel (external_user_id, channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== PRODUCT VIEWS TABLE ====================
-- Used by ProductService for tracking recently viewed products (optional)

CREATE TABLE IF NOT EXISTS product_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    platform_user_id VARCHAR(255) NOT NULL,
    channel_id INT NOT NULL,
    product_code VARCHAR(100) NOT NULL,
    product_data JSON COMMENT 'Cached product info',
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_view (platform_user_id, channel_id, product_code),
    INDEX idx_recent (channel_id, platform_user_id, viewed_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== API USAGE LOGS TABLE ====================
-- Used by BackendApiService for analytics

CREATE TABLE IF NOT EXISTS api_usage_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL COMMENT 'FK to customer_channels.id',
    tenant_id VARCHAR(50) DEFAULT 'default',
    api_type VARCHAR(50) NOT NULL COMMENT 'backend, vision, nlp',
    endpoint VARCHAR(255),
    request_count INT DEFAULT 1,
    response_time INT COMMENT 'Response time in milliseconds',
    status_code INT,
    success TINYINT(1) DEFAULT 1,
    error_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_channel (channel_id),
    INDEX idx_type (api_type),
    INDEX idx_created (created_at),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== CLEANUP JOB ====================
-- Optional: Event to clean up expired chat_state

-- CREATE EVENT IF NOT EXISTS cleanup_expired_chat_state
-- ON SCHEDULE EVERY 1 HOUR
-- DO DELETE FROM chat_state WHERE expires_at < NOW();
