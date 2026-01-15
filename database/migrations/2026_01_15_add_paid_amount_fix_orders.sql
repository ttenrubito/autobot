-- =====================================================
-- Add paid_amount and payment_status columns to orders table
-- Run this on production first, then run fix script
-- =====================================================

-- Step 1: Add columns (if not exists - use ALTER IGNORE for MySQL 5.7)
ALTER TABLE orders ADD COLUMN paid_amount DECIMAL(12,2) DEFAULT 0 AFTER total_amount;
ALTER TABLE orders ADD COLUMN payment_status VARCHAR(20) DEFAULT 'pending' AFTER status;

-- If columns already exist, ignore the error and continue

-- Step 2: Fix orders by calculating from payments
UPDATE orders o
LEFT JOIN (
    SELECT order_id, SUM(amount) as total_paid 
    FROM payments 
    WHERE status = 'verified' AND order_id IS NOT NULL
    GROUP BY order_id
) p ON o.id = p.order_id
SET 
    o.paid_amount = COALESCE(p.total_paid, 0),
    o.remaining_amount = o.total_amount - COALESCE(p.total_paid, 0),
    o.payment_status = CASE 
        WHEN COALESCE(p.total_paid, 0) >= o.total_amount THEN 'paid'
        WHEN COALESCE(p.total_paid, 0) > 0 THEN 'partial'
        ELSE 'pending'
    END,
    o.status = CASE 
        WHEN COALESCE(p.total_paid, 0) >= o.total_amount THEN 'paid'
        WHEN o.status IN ('pending', 'draft', 'pending_payment') AND COALESCE(p.total_paid, 0) > 0 THEN 'processing'
        ELSE o.status
    END,
    o.updated_at = NOW()
WHERE p.order_id IS NOT NULL;

-- Step 3: Verify results
SELECT id, order_number, total_amount, paid_amount, remaining_amount, status, payment_status
FROM orders 
WHERE id IN (SELECT DISTINCT order_id FROM payments WHERE order_id IS NOT NULL)
ORDER BY id DESC
LIMIT 10;
