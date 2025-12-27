-- Migration: Add Safeguards to Prevent Duplicate Billing
-- This adds constraints and indexes to prevent duplicate charges

-- 1. Make invoice_number UNIQUE (ป้องกันสร้าง invoice ซ้ำ)
ALTER TABLE invoices 
ADD UNIQUE KEY unique_invoice_number (invoice_number);

-- 2. Add composite unique index on user + billing period
-- ป้องกันสร้าง invoice ซ้ำสำหรับ user + period เดียวกัน
ALTER TABLE invoices
ADD UNIQUE KEY unique_user_billing_period (user_id, billing_period_start, billing_period_end, subscription_id);

-- 3. Add index for faster lookup
ALTER TABLE invoices
ADD INDEX idx_user_subscription_date (user_id, subscription_id, billing_period_start);
