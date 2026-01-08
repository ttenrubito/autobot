-- ============================================
-- UNIFIED PAYMENT SYSTEM MIGRATION
-- Date: 2026-01-07
-- Description: Add unified payment classification for all payment types
-- ============================================

-- 1. Add new columns to payments table for unified classification
ALTER TABLE payments
ADD COLUMN IF NOT EXISTS reference_type ENUM('order', 'installment_contract', 'savings_account', 'unknown') DEFAULT 'unknown' AFTER payment_type,
ADD COLUMN IF NOT EXISTS reference_id BIGINT UNSIGNED NULL AFTER reference_type,
ADD COLUMN IF NOT EXISTS ai_suggested_type ENUM('full', 'installment', 'savings', 'unknown') DEFAULT 'unknown' AFTER reference_id,
ADD COLUMN IF NOT EXISTS ai_confidence DECIMAL(3,2) DEFAULT 0.00 AFTER ai_suggested_type,
ADD COLUMN IF NOT EXISTS ai_suggested_reference_id BIGINT UNSIGNED NULL AFTER ai_confidence,
ADD COLUMN IF NOT EXISTS classification_notes TEXT NULL AFTER ai_suggested_reference_id;

-- 2. Modify payment_type to include 'savings' and 'unknown'
ALTER TABLE payments
MODIFY COLUMN payment_type ENUM('full', 'installment', 'savings', 'unknown') NOT NULL DEFAULT 'unknown';

-- 3. Add indexes for efficient queries
ALTER TABLE payments
ADD INDEX IF NOT EXISTS idx_reference_type (reference_type),
ADD INDEX IF NOT EXISTS idx_reference (reference_type, reference_id),
ADD INDEX IF NOT EXISTS idx_ai_suggested (ai_suggested_type);

-- 4. Add period_number column for installment tracking
ALTER TABLE payments
ADD COLUMN IF NOT EXISTS installment_period_number INT NULL AFTER current_period COMMENT 'Which period this payment is for (when type=installment)';

-- ============================================
-- VIEW: All Payments with Reference Details
-- ============================================

DROP VIEW IF EXISTS v_unified_payments;
CREATE VIEW v_unified_payments AS
SELECT 
    p.*,
    u.full_name as customer_name,
    u.email as customer_email,
    u.phone as customer_phone,
    -- Order details
    CASE WHEN p.reference_type = 'order' THEN o.order_no ELSE NULL END as order_no,
    CASE WHEN p.reference_type = 'order' THEN o.product_name ELSE NULL END as order_product_name,
    -- Installment details
    CASE WHEN p.reference_type = 'installment_contract' THEN ic.contract_no ELSE NULL END as contract_no,
    CASE WHEN p.reference_type = 'installment_contract' THEN ic.product_name ELSE NULL END as installment_product_name,
    -- Savings details
    CASE WHEN p.reference_type = 'savings_account' THEN sa.account_no ELSE NULL END as savings_account_no,
    CASE WHEN p.reference_type = 'savings_account' THEN sa.product_name ELSE NULL END as savings_product_name
FROM payments p
LEFT JOIN users u ON p.customer_id = u.id
LEFT JOIN orders o ON p.reference_type = 'order' AND p.reference_id = o.id
LEFT JOIN installment_contracts ic ON p.reference_type = 'installment_contract' AND p.reference_id = ic.id
LEFT JOIN savings_accounts sa ON p.reference_type = 'savings_account' AND p.reference_id = sa.id;

-- ============================================
-- SAMPLE DATA: Update existing payments to have proper reference_type
-- ============================================

-- Set reference_type for orders (existing payments linked to orders)
UPDATE payments 
SET reference_type = 'order',
    reference_id = order_id
WHERE order_id IS NOT NULL 
  AND payment_type IN ('full', 'installment')
  AND reference_type = 'unknown';

-- ============================================
-- DOCUMENTATION
-- ============================================

/*
UNIFIED PAYMENT FLOW:

1. Chatbot receives slip from customer
   → INSERT INTO payments (
       payment_type = 'unknown' OR AI-detected type,
       reference_type = 'unknown' OR AI-detected,
       ai_suggested_type = AI prediction,
       ai_confidence = AI confidence score,
       status = 'pending'
   )

2. Admin reviews in unified payment panel
   → Shows AI suggestion as default
   → Admin can override with correct type/reference
   → Admin approves/rejects

3. On Approval (API handles sync):
   IF payment_type = 'installment' THEN
       INSERT INTO installment_payments
       UPDATE installment_contracts (paid_amount, paid_periods)
   
   IF payment_type = 'savings' THEN
       INSERT INTO savings_transactions
       UPDATE savings_accounts (current_amount)
   
   IF payment_type = 'full' THEN
       UPDATE orders (paid_amount, status)

4. Customer sees:
   - payment-history.php: ALL slips/payments
   - savings.php: Savings-specific transactions
   - installments.php: Installment-specific payments
*/
