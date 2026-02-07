-- =====================================================
-- Fix Installment Contracts: Change DEFAULT from 12 to 3
-- นโยบายร้าน: 3 งวด ภายใน 60 วัน
-- =====================================================

-- 1. Change default total_periods from 12 to 3
ALTER TABLE installment_contracts 
MODIFY COLUMN total_periods INT NOT NULL DEFAULT 3 
COMMENT '3 งวด ภายใน 60 วัน (ตามนโยบายร้าน)';

-- 2. Update any contracts that might have wrong defaults (if any)
-- Only update if they look like they were set by default (12 periods)
-- and have no payments yet
UPDATE installment_contracts 
SET total_periods = 3,
    updated_at = NOW()
WHERE total_periods = 12 
AND paid_periods = 0
AND status IN ('pending', 'active')
AND admin_notes LIKE '%Chatbot%';

-- 3. Verify the change
SELECT 
    'Total contracts' as metric,
    COUNT(*) as value
FROM installment_contracts
UNION ALL
SELECT 
    'Contracts with 3 periods',
    COUNT(*)
FROM installment_contracts
WHERE total_periods = 3
UNION ALL
SELECT 
    'Contracts with 12 periods',
    COUNT(*)
FROM installment_contracts
WHERE total_periods = 12;

-- Show current defaults
SHOW CREATE TABLE installment_contracts;
