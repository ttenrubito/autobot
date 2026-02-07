-- Migration: Add deposit_interest to payments.payment_type enum
-- For pawn interest payments (ต่อดอกฝาก)
-- Date: 2026-02-01

ALTER TABLE payments 
MODIFY COLUMN payment_type ENUM('full', 'installment', 'deposit', 'savings', 'deposit_interest', 'deposit_savings') 
NOT NULL DEFAULT 'full';

SELECT 'Migration complete: deposit_interest added to payment_type enum' as result;
