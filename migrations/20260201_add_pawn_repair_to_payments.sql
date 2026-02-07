-- Migration: Add pawn_id and repair_id columns to payments table
-- For linking payments to pawns (interest/redemption) and repairs
-- Date: 2026-02-01

-- Add repair_id column if not exists
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'payments' 
    AND COLUMN_NAME = 'repair_id'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE payments ADD COLUMN repair_id INT NULL AFTER order_id, ADD INDEX idx_payments_repair_id (repair_id)',
    'SELECT "repair_id column already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add pawn_id column if not exists  
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'payments' 
    AND COLUMN_NAME = 'pawn_id'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE payments ADD COLUMN pawn_id INT NULL AFTER repair_id, ADD INDEX idx_payments_pawn_id (pawn_id)',
    'SELECT "pawn_id column already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Success message
SELECT 'Migration complete: pawn_id and repair_id columns added to payments table' as result;
