-- Migration: Add 'deposit' and 'savings' to payment_type ENUM
-- Date: 2026-01-19
-- Description: UI supports 4 payment types but DB only has 2

-- 1. Update payments.payment_type ENUM
ALTER TABLE payments 
MODIFY COLUMN payment_type ENUM('full','installment','deposit','savings') NOT NULL DEFAULT 'full';

-- 2. Update orders.payment_type ENUM  
ALTER TABLE orders 
MODIFY COLUMN payment_type ENUM('full','installment','deposit','savings') NOT NULL DEFAULT 'full';

-- Verify changes
SELECT 'payments.payment_type' as table_column, COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_type'
UNION ALL
SELECT 'orders.payment_type' as table_column, COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_type';
