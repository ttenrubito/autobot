-- Pawns Schema Updates for ร้าน ฮ. เฮง เฮง Business Requirements
-- Date: 2026-01-31

-- =====================================================
-- 1. Add product/warranty tracking columns to pawns
-- =====================================================

-- Link to original order (for products purchased from shop)
ALTER TABLE pawns ADD COLUMN IF NOT EXISTS original_order_id INT DEFAULT NULL COMMENT 'Order ID where customer purchased the item';

-- Product reference for shop verification
ALTER TABLE pawns ADD COLUMN IF NOT EXISTS product_ref_id VARCHAR(100) DEFAULT NULL COMMENT 'Product reference ID from shop inventory';

-- Warranty/Guarantee document number
ALTER TABLE pawns ADD COLUMN IF NOT EXISTS warranty_no VARCHAR(100) DEFAULT NULL COMMENT 'Warranty document number';

-- Extension counter
ALTER TABLE pawns ADD COLUMN IF NOT EXISTS extension_count INT NOT NULL DEFAULT 0 COMMENT 'Number of times interest has been extended';

-- =====================================================
-- 2. Change default interest rate from 3% to 2%
-- =====================================================
ALTER TABLE pawns MODIFY COLUMN interest_rate DECIMAL(5,2) NOT NULL DEFAULT 2.00 COMMENT 'Monthly interest rate (default 2%)';

-- =====================================================
-- 3. Add period tracking to pawn_payments
-- =====================================================

-- Period number for tracking payment rounds
ALTER TABLE pawn_payments ADD COLUMN IF NOT EXISTS period_number INT DEFAULT 1 COMMENT 'Payment period/round number';

-- Next due date after payment
ALTER TABLE pawn_payments ADD COLUMN IF NOT EXISTS next_due_date DATE DEFAULT NULL COMMENT 'Next payment due date after this payment';

-- =====================================================
-- 4. Add index for better query performance
-- =====================================================
CREATE INDEX IF NOT EXISTS idx_pawns_original_order ON pawns(original_order_id);
CREATE INDEX IF NOT EXISTS idx_pawns_product_ref ON pawns(product_ref_id);
CREATE INDEX IF NOT EXISTS idx_pawns_warranty ON pawns(warranty_no);

-- =====================================================
-- Verification query
-- =====================================================
-- Run this to verify columns were added:
-- SHOW COLUMNS FROM pawns WHERE Field IN ('original_order_id', 'product_ref_id', 'warranty_no', 'extension_count');
-- SHOW COLUMNS FROM pawn_payments WHERE Field IN ('period_number', 'next_due_date');
