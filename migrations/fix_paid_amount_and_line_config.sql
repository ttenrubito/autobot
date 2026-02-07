-- Migration: Add missing columns and fix LINE config key
-- Date: 2026-01-19
-- Issues:
-- 1. paid_amount column missing on production
-- 2. LINE channel uses 'channel_access_token' but code expects 'line_channel_access_token'

-- 1. Add paid_amount to orders if not exists
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'orders' 
                   AND COLUMN_NAME = 'paid_amount');
                   
-- MySQL doesn't support IF NOT EXISTS for ADD COLUMN, so we use prepared statement
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE orders ADD COLUMN paid_amount DECIMAL(12,2) DEFAULT 0.00 AFTER total_amount',
    'SELECT "paid_amount already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Fix LINE channel config key: 'channel_access_token' -> 'line_channel_access_token'
UPDATE customer_channels 
SET config = JSON_SET(
    config, 
    '$.line_channel_access_token', 
    JSON_UNQUOTE(JSON_EXTRACT(config, '$.channel_access_token'))
)
WHERE type = 'line' 
  AND JSON_EXTRACT(config, '$.channel_access_token') IS NOT NULL
  AND JSON_EXTRACT(config, '$.line_channel_access_token') IS NULL;

-- 3. Also add line_channel_secret for consistency
UPDATE customer_channels 
SET config = JSON_SET(
    config, 
    '$.line_channel_secret', 
    JSON_UNQUOTE(JSON_EXTRACT(config, '$.channel_secret'))
)
WHERE type = 'line' 
  AND JSON_EXTRACT(config, '$.channel_secret') IS NOT NULL
  AND JSON_EXTRACT(config, '$.line_channel_secret') IS NULL;

-- Verify
SELECT id, name, type,
       JSON_EXTRACT(config, '$.line_channel_access_token') IS NOT NULL as has_line_token
FROM customer_channels WHERE type = 'line';
