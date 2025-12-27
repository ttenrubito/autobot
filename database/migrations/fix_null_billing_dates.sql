-- SQL Migration: Fix existing subscriptions with NULL next_billing_date
-- Run this ONCE to fix existing data

-- Update all active subscriptions that have NULL next_billing_date
-- Set it to current_period_end + 1 day
UPDATE subscriptions 
SET next_billing_date = DATE_ADD(current_period_end, INTERVAL 1 DAY),
    updated_at = NOW()
WHERE status = 'active' 
AND next_billing_date IS NULL;

-- Verify the update
SELECT id, user_id, status, current_period_end, next_billing_date 
FROM subscriptions 
WHERE status = 'active'
ORDER BY id;
