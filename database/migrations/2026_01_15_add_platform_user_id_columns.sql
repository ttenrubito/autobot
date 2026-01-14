-- ============================================================================
-- Migration: Add platform_user_id column for proper JOIN with customer_profiles
-- Date: 2026-01-15
-- Description: Add platform_user_id column to payments and orders tables
--              (repairs and pawns already have external_user_id which serves same purpose)
-- ============================================================================

-- ============================================================================
-- 1. PAYMENTS TABLE - Add platform_user_id (currently has customer_platform_id)
-- ============================================================================
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'payments' 
    AND COLUMN_NAME = 'platform_user_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE payments ADD COLUMN platform_user_id VARCHAR(255) NULL AFTER customer_profile_id',
    'SELECT "payments.platform_user_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add platform column if not exists
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'payments' 
    AND COLUMN_NAME = 'platform'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE payments ADD COLUMN platform ENUM("line", "facebook", "web", "instagram") NULL AFTER platform_user_id',
    'SELECT "payments.platform already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 2. ORDERS TABLE - Add platform_user_id (currently has customer_platform_id)
-- ============================================================================
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'orders' 
    AND COLUMN_NAME = 'platform_user_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE orders ADD COLUMN platform_user_id VARCHAR(255) NULL AFTER customer_profile_id',
    'SELECT "orders.platform_user_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add platform column if not exists
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'orders' 
    AND COLUMN_NAME = 'platform'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE orders ADD COLUMN platform ENUM("line", "facebook", "web", "instagram") NULL AFTER platform_user_id',
    'SELECT "orders.platform already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 3. PAWNS TABLE - Add user_id (currently only has customer_id)
-- ============================================================================
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pawns' 
    AND COLUMN_NAME = 'user_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pawns ADD COLUMN user_id INT NULL AFTER id',
    'SELECT "pawns.user_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add platform_user_id to pawns (alias for external_user_id for consistency)
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'pawns' 
    AND COLUMN_NAME = 'platform_user_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE pawns ADD COLUMN platform_user_id VARCHAR(255) NULL AFTER user_id',
    'SELECT "pawns.platform_user_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 4. REPAIRS TABLE - Add user_id and platform_user_id
-- ============================================================================
-- Add user_id if not exists
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'repairs' 
    AND COLUMN_NAME = 'user_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE repairs ADD COLUMN user_id INT NULL AFTER id',
    'SELECT "repairs.user_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add platform_user_id if not exists
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'repairs' 
    AND COLUMN_NAME = 'platform_user_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE repairs ADD COLUMN platform_user_id VARCHAR(255) NULL AFTER customer_profile_id',
    'SELECT "repairs.platform_user_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 5. ADD INDEXES
-- ============================================================================
DROP PROCEDURE IF EXISTS add_index_if_not_exists;

DELIMITER //
CREATE PROCEDURE add_index_if_not_exists(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_column VARCHAR(64)
)
BEGIN
    DECLARE index_count INT;
    
    SELECT COUNT(*) INTO index_count
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = p_table
    AND INDEX_NAME = p_index;
    
    IF index_count = 0 THEN
        SET @sql = CONCAT('CREATE INDEX ', p_index, ' ON ', p_table, '(', p_column, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL add_index_if_not_exists('payments', 'idx_payments_platform_user_id', 'platform_user_id');
CALL add_index_if_not_exists('orders', 'idx_orders_platform_user_id', 'platform_user_id');
CALL add_index_if_not_exists('repairs', 'idx_repairs_platform_user_id', 'platform_user_id');
CALL add_index_if_not_exists('pawns', 'idx_pawns_platform_user_id', 'platform_user_id');
CALL add_index_if_not_exists('pawns', 'idx_pawns_user_id', 'user_id');

DROP PROCEDURE IF EXISTS add_index_if_not_exists;

-- ============================================================================
-- 6. BACKFILL platform_user_id FROM EXISTING DATA
-- ============================================================================

-- 6.1 Backfill payments.platform_user_id from customer_platform_id
UPDATE payments 
SET platform_user_id = customer_platform_id
WHERE platform_user_id IS NULL 
  AND customer_platform_id IS NOT NULL
  AND customer_platform_id != '';

-- 6.2 Backfill payments.platform from customer_platform
UPDATE payments 
SET platform = customer_platform
WHERE platform IS NULL 
  AND customer_platform IS NOT NULL
  AND customer_platform IN ('line', 'facebook', 'web', 'instagram');

-- 6.3 Backfill orders.platform_user_id from customer_platform_id
UPDATE orders 
SET platform_user_id = customer_platform_id
WHERE platform_user_id IS NULL 
  AND customer_platform_id IS NOT NULL
  AND customer_platform_id != '';

-- 6.4 Backfill orders.platform from customer_platform
UPDATE orders 
SET platform = customer_platform
WHERE platform IS NULL 
  AND customer_platform IS NOT NULL
  AND customer_platform IN ('line', 'facebook', 'web', 'instagram');

-- 6.5 Backfill pawns.platform_user_id from external_user_id
UPDATE pawns 
SET platform_user_id = external_user_id
WHERE platform_user_id IS NULL 
  AND external_user_id IS NOT NULL
  AND external_user_id != '';

-- 6.6 Backfill repairs.platform_user_id from external_user_id
UPDATE repairs 
SET platform_user_id = external_user_id
WHERE platform_user_id IS NULL 
  AND external_user_id IS NOT NULL
  AND external_user_id != '';

-- ============================================================================
-- VERIFICATION QUERY
-- ============================================================================
SELECT 'Migration completed successfully' as status;

-- To verify run this query:
-- SELECT 
--     'payments' as tbl, COUNT(*) as total, SUM(IF(platform_user_id IS NOT NULL, 1, 0)) as has_platform_user_id
-- FROM payments
-- UNION ALL
-- SELECT 'orders', COUNT(*), SUM(IF(platform_user_id IS NOT NULL, 1, 0)) FROM orders
-- UNION ALL
-- SELECT 'repairs', COUNT(*), SUM(IF(platform_user_id IS NOT NULL, 1, 0)) FROM repairs
-- UNION ALL  
-- SELECT 'pawns', COUNT(*), SUM(IF(platform_user_id IS NOT NULL, 1, 0)) FROM pawns;
