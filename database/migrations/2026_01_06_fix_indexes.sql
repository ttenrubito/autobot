-- Fix indexes for MySQL 5.7+ compatibility
-- These use "safe" index creation without IF NOT EXISTS

-- Check and create indexes one by one using stored procedure

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS create_index_if_not_exists(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_index_columns VARCHAR(255)
)
BEGIN
    DECLARE index_exists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO index_exists
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = p_table_name
    AND INDEX_NAME = p_index_name;
    
    IF index_exists = 0 THEN
        SET @sql = CONCAT('CREATE INDEX ', p_index_name, ' ON ', p_table_name, '(', p_index_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //

DELIMITER ;

-- Create indexes safely
CALL create_index_if_not_exists('orders', 'idx_orders_product_ref', 'product_ref_id');
CALL create_index_if_not_exists('orders', 'idx_orders_reservation', 'reservation_expires_at');
CALL create_index_if_not_exists('orders', 'idx_orders_savings', 'savings_account_id');
CALL create_index_if_not_exists('payments', 'idx_payments_savings_tx', 'savings_transaction_id');
CALL create_index_if_not_exists('chat_sessions', 'idx_sessions_active_case', 'active_case_id');

-- Verify tables exist
SELECT 
    TABLE_NAME,
    ENGINE,
    TABLE_ROWS
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('cases', 'case_activities', 'savings_accounts', 'savings_transactions');

-- Clean up procedure
DROP PROCEDURE IF EXISTS create_index_if_not_exists;
