-- Add missing columns to subscriptions table for billing automation

ALTER TABLE subscriptions 
ADD COLUMN next_billing_date DATE AFTER current_period_end,
ADD COLUMN package_id INT AFTER plan_id,
ADD INDEX idx_next_billing (next_billing_date);

-- Add foreign key for package_id (if packages table exists)
-- ALTER TABLE subscriptions ADD FOREIGN KEY (package_id) REFERENCES packages(id);

-- Update existing subscriptions to set next_billing_date
UPDATE subscriptions 
SET next_billing_date = DATE_ADD(current_period_end, INTERVAL 1 DAY)
WHERE next_billing_date IS NULL;
