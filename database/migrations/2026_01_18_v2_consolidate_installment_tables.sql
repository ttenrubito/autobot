-- =====================================================================
-- Migration: Consolidate Installment Tables (MySQL 5.7 compatible)
-- Date: 2026-01-18
-- Purpose: Add missing columns to installment tables
-- =====================================================================

-- Add platform_user_id to installment_contracts (if not exists)
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'installment_contracts' 
    AND COLUMN_NAME = 'platform_user_id');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE installment_contracts ADD COLUMN platform_user_id VARCHAR(255) NULL AFTER platform', 
    'SELECT "Column platform_user_id already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add channel_id to installment_contracts
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'installment_contracts' 
    AND COLUMN_NAME = 'channel_id');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE installment_contracts ADD COLUMN channel_id INT NULL AFTER platform_user_id', 
    'SELECT "Column channel_id already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add external_user_id to installment_contracts
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'installment_contracts' 
    AND COLUMN_NAME = 'external_user_id');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE installment_contracts ADD COLUMN external_user_id VARCHAR(255) NULL AFTER channel_id', 
    'SELECT "Column external_user_id already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create installment_reminders table
CREATE TABLE IF NOT EXISTS installment_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    reminder_type VARCHAR(50) NOT NULL,
    due_date DATE NOT NULL,
    period_number INT NOT NULL,
    message_sent TEXT,
    sent_at DATETIME,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contract (contract_id),
    INDEX idx_type_date (reminder_type, due_date)
);

SELECT 'Migration v2 completed successfully' AS result;
