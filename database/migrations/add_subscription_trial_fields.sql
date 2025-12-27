-- Migration: Add Trial and Billing Fields to Subscriptions Table
-- Run this migration to add trial period and billing date tracking

-- Add columns to subscriptions table
ALTER TABLE subscriptions 
ADD COLUMN trial_end_date DATE NULL AFTER auto_renew,
ADD COLUMN trial_used BOOLEAN DEFAULT FALSE AFTER trial_end_date,
ADD COLUMN next_billing_date DATE NULL AFTER current_period_end,
ADD INDEX idx_next_billing (next_billing_date),
ADD INDEX idx_trial_end (trial_end_date);

-- Add columns to users table for trial tracking
ALTER TABLE users
ADD COLUMN trial_start_date TIMESTAMP NULL AFTER status,
ADD COLUMN trial_days_remaining INT DEFAULT 7 AFTER trial_start_date;

-- Add status for trial
ALTER TABLE subscriptions 
MODIFY COLUMN status ENUM('trial', 'active', 'paused', 'cancelled', 'expired') DEFAULT 'trial';
