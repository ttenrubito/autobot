-- =====================================================================
-- Migration: Consolidate Installment Tables
-- Date: 2026-01-18
-- Purpose: 
-- 1. Add missing columns to installment_contracts and installment_payments
-- 2. Drop old installments table (will be handled after data migration)
-- =====================================================================

-- Add missing columns to installment_contracts
ALTER TABLE installment_contracts 
    ADD COLUMN IF NOT EXISTS platform_user_id VARCHAR(255) NULL AFTER platform,
    ADD COLUMN IF NOT EXISTS channel_id INT NULL AFTER platform_user_id,
    ADD COLUMN IF NOT EXISTS external_user_id VARCHAR(255) NULL AFTER channel_id;

-- Add index for platform_user_id if not exists
CREATE INDEX IF NOT EXISTS idx_platform_user ON installment_contracts(platform_user_id);

-- Add missing columns to installment_payments
ALTER TABLE installment_payments 
    ADD COLUMN IF NOT EXISTS payment_no VARCHAR(50) NULL AFTER contract_id,
    ADD COLUMN IF NOT EXISTS payment_type ENUM('period', 'partial', 'extra') DEFAULT 'period' AFTER amount,
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) NULL AFTER payment_type,
    ADD COLUMN IF NOT EXISTS slip_image_url VARCHAR(500) NULL AFTER slip_image,
    ADD COLUMN IF NOT EXISTS sender_name VARCHAR(255) NULL AFTER slip_image_url,
    ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(12,2) DEFAULT 0 AFTER amount,
    ADD COLUMN IF NOT EXISTS payment_ref VARCHAR(100) NULL AFTER payment_method;

-- Add unique index for payment_no if not exists
CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_no ON installment_payments(payment_no);

-- =====================================================================
-- DO NOT DROP installments table yet - run this only after confirming
-- that no data exists in it or data has been migrated
-- =====================================================================

-- Check if installments table has any data
-- SELECT COUNT(*) as count FROM installments;

-- If count is 0, you can safely drop:
-- DROP TABLE IF EXISTS installment_payments_old;  -- if exists from previous migration
-- DROP TABLE IF EXISTS installments;

SELECT 'Migration 2026_01_18_consolidate_installment_tables completed' AS result;
