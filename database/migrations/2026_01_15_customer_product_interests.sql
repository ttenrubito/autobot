-- Migration: Create customer_product_interests table
-- Date: 2026-01-15
-- Purpose: Track products that customers are interested in for marketing/push notifications

-- =====================================================
-- 1. Create customer_product_interests table
-- =====================================================
CREATE TABLE IF NOT EXISTS `customer_product_interests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` VARCHAR(50) NOT NULL DEFAULT 'default',
    `customer_profile_id` INT UNSIGNED NOT NULL COMMENT 'FK to customer_profiles.id',
    `channel_id` INT UNSIGNED NULL COMMENT 'FK to customer_channels.id',
    `case_id` INT UNSIGNED NULL COMMENT 'FK to cases.id if from case',
    
    -- Product information
    `product_ref_id` VARCHAR(100) NULL COMMENT 'Product code/SKU',
    `product_name` VARCHAR(255) NULL COMMENT 'Product name from API/chat',
    `product_category` VARCHAR(100) NULL COMMENT 'Category: watch, ring, necklace, etc.',
    `product_price` DECIMAL(15,2) NULL COMMENT 'Price at time of interest',
    `product_image_url` VARCHAR(500) NULL COMMENT 'Product image URL',
    
    -- Interest tracking
    `interest_type` ENUM('viewed', 'inquired', 'price_check', 'saved', 'compared', 'added_to_cart', 'purchased') NOT NULL DEFAULT 'inquired',
    `interest_score` TINYINT UNSIGNED DEFAULT 1 COMMENT 'Interest level 1-10, higher = more interested',
    `source` ENUM('chat', 'image_search', 'web', 'admin_input') DEFAULT 'chat',
    
    -- Context
    `message_text` TEXT NULL COMMENT 'Original message that triggered interest',
    `metadata` JSON NULL COMMENT 'Additional metadata (slots, intent, etc.)',
    
    -- Timestamps
    `first_seen_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `notified_at` DATETIME NULL COMMENT 'When we last sent push notification about this',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX `idx_customer_profile` (`customer_profile_id`),
    INDEX `idx_product_ref` (`product_ref_id`),
    INDEX `idx_product_category` (`product_category`),
    INDEX `idx_interest_type` (`interest_type`),
    INDEX `idx_tenant_customer` (`tenant_id`, `customer_profile_id`),
    INDEX `idx_last_seen` (`last_seen_at`),
    
    -- Unique constraint: one record per customer per product
    UNIQUE KEY `uniq_customer_product` (`customer_profile_id`, `product_ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- 2. Add new columns to customer_profiles if not exist
-- =====================================================

-- Add total_inquiries counter
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'customer_profiles' 
    AND COLUMN_NAME = 'total_inquiries');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `customer_profiles` ADD COLUMN `total_inquiries` INT UNSIGNED DEFAULT 0 COMMENT "Total number of inquiries made"',
    'SELECT "Column total_inquiries already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add total_cases counter
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'customer_profiles' 
    AND COLUMN_NAME = 'total_cases');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `customer_profiles` ADD COLUMN `total_cases` INT UNSIGNED DEFAULT 0 COMMENT "Total number of cases created"',
    'SELECT "Column total_cases already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add last_case_at timestamp
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'customer_profiles' 
    AND COLUMN_NAME = 'last_case_at');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `customer_profiles` ADD COLUMN `last_case_at` DATETIME NULL COMMENT "Last time a case was created"',
    'SELECT "Column last_case_at already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add preferred_categories JSON
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'customer_profiles' 
    AND COLUMN_NAME = 'preferred_categories');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `customer_profiles` ADD COLUMN `preferred_categories` JSON NULL COMMENT "Array of preferred product categories"',
    'SELECT "Column preferred_categories already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- =====================================================
-- 3. Add customer_profile_id to cases table if not exist
-- =====================================================
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'cases' 
    AND COLUMN_NAME = 'customer_profile_id');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `cases` ADD COLUMN `customer_profile_id` INT UNSIGNED NULL COMMENT "FK to customer_profiles.id" AFTER `customer_id`',
    'SELECT "Column customer_profile_id already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for customer_profile_id in cases
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'cases' 
    AND INDEX_NAME = 'idx_customer_profile_id');

SET @sql = IF(@idx_exists = 0, 
    'ALTER TABLE `cases` ADD INDEX `idx_customer_profile_id` (`customer_profile_id`)',
    'SELECT "Index idx_customer_profile_id already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- =====================================================
-- 4. Add chat_summary column to cases for context
-- =====================================================
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'cases' 
    AND COLUMN_NAME = 'chat_summary');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `cases` ADD COLUMN `chat_summary` TEXT NULL COMMENT "Summary of chat history for this case" AFTER `description`',
    'SELECT "Column chat_summary already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add products_interested JSON column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'cases' 
    AND COLUMN_NAME = 'products_interested');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `cases` ADD COLUMN `products_interested` JSON NULL COMMENT "Array of products customer is interested in" AFTER `product_ref_id`',
    'SELECT "Column products_interested already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- =====================================================
-- 5. Verify migration
-- =====================================================
SELECT 'Migration completed successfully!' AS status;

-- Show new table structure
DESCRIBE customer_product_interests;

-- Show added columns in customer_profiles
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_COMMENT 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'customer_profiles'
AND COLUMN_NAME IN ('total_inquiries', 'total_cases', 'last_case_at', 'preferred_categories');

-- Show added columns in cases
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_COMMENT 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'cases'
AND COLUMN_NAME IN ('customer_profile_id', 'chat_summary', 'products_interested');
