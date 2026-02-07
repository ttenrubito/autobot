-- Migration: Add 'deposit' and 'savings' to orders.payment_type ENUM
-- Run this on production database to support deposit/savings orders
-- Date: 2026-01-18

-- =====================================================
-- Step 1: Alter payment_type ENUM to include new values
-- =====================================================
ALTER TABLE orders 
MODIFY COLUMN payment_type ENUM('full', 'installment', 'deposit', 'savings') 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full';

-- =====================================================
-- Verify the change
-- =====================================================
-- Run this to verify:
-- SHOW COLUMNS FROM orders WHERE Field = 'payment_type';
