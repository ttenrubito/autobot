-- Migration: Add missing columns to pawns table for chatbot integration
-- Date: 2026-01-31
-- Purpose: Enable pawn slip matching, case tracking, and order linking

-- Safe column additions using stored procedure
DELIMITER $$

DROP PROCEDURE IF EXISTS add_column_if_not_exists$$
CREATE PROCEDURE add_column_if_not_exists(
    IN table_name VARCHAR(64),
    IN column_name VARCHAR(64),
    IN column_def VARCHAR(512)
)
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO col_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = table_name
      AND COLUMN_NAME = column_name;
    
    IF col_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', table_name, '` ADD COLUMN `', column_name, '` ', column_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS add_index_if_not_exists$$
CREATE PROCEDURE add_index_if_not_exists(
    IN table_name VARCHAR(64),
    IN index_name VARCHAR(64),
    IN index_cols VARCHAR(512)
)
BEGIN
    DECLARE idx_exists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO idx_exists
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = table_name
      AND INDEX_NAME = index_name;
    
    IF idx_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', table_name, '` ADD INDEX `', index_name, '` (', index_cols, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- =====================================================
-- pawns table columns
-- =====================================================
CALL add_column_if_not_exists('pawns', 'channel_id', 'INT NULL');
CALL add_column_if_not_exists('pawns', 'order_id', 'INT NULL');
CALL add_column_if_not_exists('pawns', 'case_id', 'INT NULL');
CALL add_column_if_not_exists('pawns', 'external_user_id', 'VARCHAR(255) NULL');
CALL add_column_if_not_exists('pawns', 'expected_interest_amount', 'DECIMAL(12,2) NULL');
CALL add_column_if_not_exists('pawns', 'next_payment_due', 'DATE NULL');
CALL add_column_if_not_exists('pawns', 'bank_account_id', 'INT NULL');

CALL add_index_if_not_exists('pawns', 'idx_pawns_channel_id', 'channel_id');
CALL add_index_if_not_exists('pawns', 'idx_pawns_order_id', 'order_id');
CALL add_index_if_not_exists('pawns', 'idx_pawns_next_payment_due', 'next_payment_due');

-- =====================================================
-- pawn_payments table columns
-- =====================================================
CALL add_column_if_not_exists('pawn_payments', 'source_payment_id', 'INT NULL');
CALL add_index_if_not_exists('pawn_payments', 'idx_pawn_payments_source', 'source_payment_id');

-- =====================================================
-- payments table columns for Hybrid A+ auto-matching
-- =====================================================
CALL add_column_if_not_exists('payments', 'classified_as', "ENUM('order', 'pawn', 'installment', 'unclassified') DEFAULT 'unclassified'");
CALL add_column_if_not_exists('payments', 'linked_pawn_payment_id', 'INT NULL');
CALL add_column_if_not_exists('payments', 'linked_installment_payment_id', 'INT NULL');
CALL add_column_if_not_exists('payments', 'match_status', "ENUM('auto_matched', 'manual_matched', 'pending') DEFAULT 'pending'");
CALL add_column_if_not_exists('payments', 'matched_at', 'TIMESTAMP NULL');

CALL add_index_if_not_exists('payments', 'idx_payments_classified', 'classified_as');
CALL add_index_if_not_exists('payments', 'idx_payments_match_status', 'match_status');

-- =====================================================
-- Cleanup procedures
-- =====================================================
DROP PROCEDURE IF EXISTS add_column_if_not_exists;
DROP PROCEDURE IF EXISTS add_index_if_not_exists;

SELECT 'Migration 20260131_add_pawn_columns completed!' as status;
