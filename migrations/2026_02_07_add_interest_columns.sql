-- Add interest/fee columns to installment_contracts
-- Safe migration that checks if columns exist first

-- Drop procedure if exists to ensure clean state
DROP PROCEDURE IF EXISTS add_interest_columns;

DELIMITER //

CREATE PROCEDURE add_interest_columns()
BEGIN
    -- Add interest_rate if not exists
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'installment_contracts' 
        AND COLUMN_NAME = 'interest_rate'
    ) THEN
        ALTER TABLE installment_contracts 
        ADD COLUMN interest_rate DECIMAL(5,2) NOT NULL DEFAULT 3.00 
        COMMENT 'Fee/interest rate % at contract creation';
    END IF;
    
    -- Add interest_type if not exists
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'installment_contracts' 
        AND COLUMN_NAME = 'interest_type'
    ) THEN
        ALTER TABLE installment_contracts 
        ADD COLUMN interest_type ENUM('none', 'flat', 'reducing') NOT NULL DEFAULT 'flat'
        COMMENT 'Type: none=no fee, flat=one-time, reducing=decreasing';
    END IF;
    
    -- Add total_interest if not exists
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'installment_contracts' 
        AND COLUMN_NAME = 'total_interest'
    ) THEN
        ALTER TABLE installment_contracts 
        ADD COLUMN total_interest DECIMAL(12,2) NOT NULL DEFAULT 0
        COMMENT 'Total fee/interest amount in baht';
    END IF;
END //

DELIMITER ;

-- Execute the procedure
CALL add_interest_columns();

-- Clean up
DROP PROCEDURE IF EXISTS add_interest_columns;

-- Update existing contracts: calculate total_interest from financed_amount - product_price
UPDATE installment_contracts 
SET total_interest = ROUND(financed_amount - product_price, 2),
    interest_rate = 3.00,
    interest_type = 'flat'
WHERE total_interest = 0 
  AND financed_amount > product_price 
  AND product_price > 0;

SELECT 'Migration completed successfully' as status;
