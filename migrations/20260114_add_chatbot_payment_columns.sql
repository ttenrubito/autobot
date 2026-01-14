-- Migration: Add columns for chatbot payment slip processing
-- Date: 2026-01-14
-- Purpose: Support storing OCR data from payment slips sent via chatbot

-- Allow order_id to be NULL (payment before order matching)
ALTER TABLE payments MODIFY order_id INT NULL;

-- Allow customer_id to be NULL (anonymous users)
ALTER TABLE payments MODIFY customer_id INT NULL;

-- Add index on payment_details JSON for duplicate checking via payment_ref
-- payment_ref is stored inside payment_details JSON
ALTER TABLE payments ADD INDEX idx_payment_date_recent (payment_date, created_at);

-- No need to add new columns - use existing payment_details JSON to store:
-- - payment_ref
-- - sender_name  
-- - bank_name
-- - ocr_result
-- - etc.

-- slip_image already exists and is varchar(500)
