-- ============================================
-- ADD REPAIR REFERENCE TYPE TO PAYMENTS
-- Date: 2026-01-15
-- Description: Add 'repair' to reference_type ENUM and repair_id column
-- ============================================

-- 1. Modify reference_type ENUM to include 'repair'
ALTER TABLE payments
MODIFY COLUMN reference_type ENUM('order', 'installment_contract', 'savings_account', 'repair', 'pawn', 'unknown') DEFAULT 'unknown';

-- 2. Add repair_id column if not exists
ALTER TABLE payments
ADD COLUMN IF NOT EXISTS repair_id INT NULL AFTER order_id,
ADD INDEX IF NOT EXISTS idx_repair_id (repair_id);

-- 3. Add pawn_id column if not exists (for future use)
ALTER TABLE payments
ADD COLUMN IF NOT EXISTS pawn_id INT NULL AFTER repair_id,
ADD INDEX IF NOT EXISTS idx_pawn_id (pawn_id);

-- Log migration
SELECT 'Migration 2026_01_15_add_repair_reference_type completed' as status;
