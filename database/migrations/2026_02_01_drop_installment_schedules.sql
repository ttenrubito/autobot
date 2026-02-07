-- ============================================
-- Migration: Drop installment_schedules table
-- Date: 2026-02-01
-- Reason: Table is not used in any PHP code
--         It duplicates functionality of installment_payments
--         Uses different FK (order_id) vs payments uses (contract_id)
--         Only has 3 records for order_id=66 which has no contract
-- ============================================

-- 1. Backup table before dropping (optional - for safety)
-- CREATE TABLE installment_schedules_backup AS SELECT * FROM installment_schedules;

-- 2. Drop the unused table
DROP TABLE IF EXISTS installment_schedules;

-- 3. Verify the correct table is being used
-- The system uses:
--   - installment_contracts: stores installment agreements (8 records)
--   - installment_payments: stores each period's payment details (18 records)
--   - Both tables are actively used in 100+ PHP code locations

-- ============================================
-- IMPACT ANALYSIS (verified 2026-02-01):
-- ============================================
-- PHP files using installment_schedules: 0
-- PHP files using installment_payments: 96
-- PHP files using installment_contracts: 20+
--
-- installment_schedules data:
--   - order_id=66, period 1: 24706 THB
--   - order_id=66, period 2: 22666 THB  
--   - order_id=66, period 3: 22668 THB
--   - NOTE: order_id=66 has no corresponding installment_contract!
--
-- After this migration:
--   - No code changes required
--   - No data loss for active contracts (all are in installment_payments)
-- ============================================
