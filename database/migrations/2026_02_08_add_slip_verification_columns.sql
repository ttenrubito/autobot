-- Add slip verification columns to subscription_payments table
-- Run this migration on your database
-- Date: 2026-02-08

-- Add verified_amount column (ignore if exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'subscription_payments' 
                   AND COLUMN_NAME = 'verified_amount');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE subscription_payments ADD COLUMN verified_amount DECIMAL(12,2) DEFAULT NULL COMMENT ''Amount extracted by slip verification API''',
    'SELECT ''Column verified_amount already exists'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add verification_ref column (ignore if exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'subscription_payments' 
                   AND COLUMN_NAME = 'verification_ref');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE subscription_payments ADD COLUMN verification_ref VARCHAR(100) DEFAULT NULL COMMENT ''Transaction reference from slip''',
    'SELECT ''Column verification_ref already exists'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add verification_data column (ignore if exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'subscription_payments' 
                   AND COLUMN_NAME = 'verification_data');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE subscription_payments ADD COLUMN verification_data JSON DEFAULT NULL COMMENT ''Raw verification API response''',
    'SELECT ''Column verification_data already exists'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for verification reference lookups (ignore if exists)
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'subscription_payments' 
                   AND INDEX_NAME = 'idx_subscription_payments_verification_ref');
SET @sql = IF(@idx_exists = 0, 
    'CREATE INDEX idx_subscription_payments_verification_ref ON subscription_payments(verification_ref)',
    'SELECT ''Index already exists'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Migration complete' AS status;
