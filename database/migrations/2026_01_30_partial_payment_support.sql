-- Migration: Add partial payment support to installment_payments
-- Date: 2026-01-30

-- 1. Add paid_amount column to track partial payments per period
ALTER TABLE installment_payments 
ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 
AFTER amount;

-- 2. Update existing paid records: set paid_amount = amount for completed periods
UPDATE installment_payments 
SET paid_amount = amount 
WHERE status = 'paid' AND paid_amount = 0;

-- 3. Add remaining_amount as computed (virtual) column - MySQL 5.7+
-- Skip virtual column for compatibility, compute in application

-- Show result
SELECT 'Migration completed!' as status;
SELECT 
    (SELECT COUNT(*) FROM installment_payments) as total_periods,
    (SELECT COUNT(*) FROM installment_payments WHERE status = 'paid') as paid_periods,
    (SELECT COUNT(*) FROM installment_payments WHERE status = 'partial') as partial_periods;
