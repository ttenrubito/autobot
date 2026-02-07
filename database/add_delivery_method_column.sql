-- ============================================================================
-- Migration: Add delivery_method column to orders table
-- Created: 2026-01-24
-- Purpose: Fix "Unknown column 'delivery_method'" error in checkout flow
-- ============================================================================

-- Add delivery_method column if it doesn't exist
ALTER TABLE orders 
ADD COLUMN IF NOT EXISTS delivery_method VARCHAR(50) DEFAULT 'pickup' 
COMMENT 'Delivery method: pickup, delivery'
AFTER payment_type;

-- Verify column was added
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'autobot' 
AND TABLE_NAME = 'orders' 
AND COLUMN_NAME = 'delivery_method';
