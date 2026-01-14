-- ============================================
-- Migration: Allow NULL order_id in payments table
-- Date: 2026-01-14
-- Purpose: Chatbot can receive payment slips before knowing which order it's for
-- ============================================

-- Allow order_id to be NULL for chatbot payments (slip sent before order matching)
ALTER TABLE payments MODIFY COLUMN order_id INT NULL;

-- Verify the change
SELECT 
    COLUMN_NAME, 
    IS_NULLABLE, 
    DATA_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'payments' 
AND COLUMN_NAME = 'order_id';
