-- Migration: Create store_config table for multi-tenant chatbot
-- Date: 2026-02-08
-- Purpose: Store per-channel feature toggles and business rules

-- Create store_config table
SET @table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'store_config');

SET @sql = IF(@table_exists = 0, '
CREATE TABLE store_config (
    channel_id INT PRIMARY KEY COMMENT ''FK to customer_channels.id'',
    store_type ENUM(''luxury_resale'', ''jewelry'', ''watches'', ''amulets'', ''electronics'', ''general'') DEFAULT ''luxury_resale'' COMMENT ''Store category type'',
    features JSON DEFAULT NULL COMMENT ''Feature toggles: {pawn: true, repair: true, ...}'',
    business_rules JSON DEFAULT NULL COMMENT ''Business rules: {trade_in: {exchange_rate: 0.10, ...}}'',
    category_keywords TEXT DEFAULT NULL COMMENT ''Product category regex pattern for intent matching'',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_store_type (store_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT ''Per-channel store configuration for multi-tenant chatbot''',
'SELECT ''Table store_config already exists'' AS info');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Insert default config for existing channels (if any)
-- Uncomment and modify as needed:
-- INSERT IGNORE INTO store_config (channel_id, store_type, features)
-- SELECT id, 'luxury_resale', '{"pawn":true,"repair":true,"trade_in":true,"savings":true,"installment":true,"deposit":true}'
-- FROM customer_channels;

SELECT 'store_config table migration complete' AS status;
