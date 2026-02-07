-- Migration: Create products table for V5 Product Management
-- Date: 2026-01-29
-- Purpose: Create products table with shop_owner_id, brand, tags support

-- ==================== PRODUCTS TABLE ====================
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_owner_id INT UNSIGNED DEFAULT NULL COMMENT 'FK to users.id - shop owner',
    channel_id INT NOT NULL DEFAULT 1,
    tenant_id VARCHAR(50) DEFAULT 'default',
    product_code VARCHAR(100) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    brand VARCHAR(100) DEFAULT NULL COMMENT 'Product brand for filtering',
    description TEXT,
    category VARCHAR(100),
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sale_price DECIMAL(12,2) NULL,
    image_url TEXT,
    stock INT DEFAULT 0,
    status ENUM('active', 'inactive', 'out_of_stock') DEFAULT 'active',
    metadata JSON COMMENT 'Additional product attributes',
    tags JSON DEFAULT NULL COMMENT 'Array of searchable tags',
    vector_synced_at DATETIME DEFAULT NULL COMMENT 'Last synced to vector search DB',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_product_shop (shop_owner_id, product_code),
    INDEX idx_shop_owner (shop_owner_id),
    INDEX idx_channel (channel_id),
    INDEX idx_category (category),
    INDEX idx_brand (brand),
    INDEX idx_status (status),
    INDEX idx_name (product_name),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
