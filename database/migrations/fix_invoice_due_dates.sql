-- SQL Migration: Fix existing invoice due dates
-- Run this ONCE to fix existing pending invoices

-- Update pending invoices to use period_end + 3 days grace period
UPDATE invoices 
SET due_date = DATE_ADD(billing_period_end, INTERVAL 3 DAY),
    updated_at = NOW()
WHERE status IN ('pending', 'failed')
AND billing_period_end IS NOT NULL
AND due_date != DATE_ADD(billing_period_end, INTERVAL 3 DAY);

-- Verify the changes
SELECT 
    invoice_number,
    billing_period_start,
    billing_period_end,
    due_date,
    DATEDIFF(due_date, billing_period_end) as days_grace,
    status
FROM invoices 
WHERE status IN ('pending', 'failed')
ORDER BY created_at DESC
LIMIT 10;
