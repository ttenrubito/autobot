-- =====================================================
-- Fix Order Status Based on Existing Payments
-- =====================================================
-- ปัญหา: Payments ถูกสร้างก่อนที่โค้ดจะ update order status
-- Script นี้จะ recalculate paid_amount และ update status ของทุก order
-- =====================================================

-- 1. ดูสถานะปัจจุบันก่อน
SELECT 
    o.id,
    o.order_number,
    o.total_amount,
    o.paid_amount as current_paid,
    o.remaining_amount as current_remaining,
    o.status as current_status,
    COALESCE(SUM(CASE WHEN p.status = 'verified' THEN p.amount ELSE 0 END), 0) as actual_paid,
    o.total_amount - COALESCE(SUM(CASE WHEN p.status = 'verified' THEN p.amount ELSE 0 END), 0) as actual_remaining,
    COUNT(p.id) as payment_count
FROM orders o
LEFT JOIN payments p ON p.order_id = o.id
GROUP BY o.id
HAVING actual_paid > 0
ORDER BY o.id DESC
LIMIT 20;

-- 2. ✅ UPDATE orders: คำนวณ paid_amount จาก payments ที่ verified
UPDATE orders o
SET 
    paid_amount = COALESCE((
        SELECT SUM(p.amount) 
        FROM payments p 
        WHERE p.order_id = o.id AND p.status = 'verified'
    ), 0),
    remaining_amount = o.total_amount - COALESCE((
        SELECT SUM(p.amount) 
        FROM payments p 
        WHERE p.order_id = o.id AND p.status = 'verified'
    ), 0),
    payment_status = CASE 
        WHEN COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id = o.id AND p.status = 'verified'), 0) >= o.total_amount 
        THEN 'paid'
        WHEN COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id = o.id AND p.status = 'verified'), 0) > 0 
        THEN 'partial'
        ELSE 'pending'
    END,
    status = CASE 
        WHEN COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id = o.id AND p.status = 'verified'), 0) >= o.total_amount 
        THEN 'paid'
        WHEN o.status IN ('pending', 'draft', 'pending_payment') 
            AND COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id = o.id AND p.status = 'verified'), 0) > 0 
        THEN 'processing'
        ELSE o.status
    END,
    updated_at = NOW()
WHERE o.id IN (
    SELECT DISTINCT order_id FROM payments WHERE order_id IS NOT NULL
);

-- 3. ✅ ตรวจสอบผลลัพธ์หลัง update
SELECT 
    o.id,
    o.order_number,
    o.total_amount,
    o.paid_amount,
    o.remaining_amount,
    o.status,
    o.payment_status
FROM orders o
WHERE o.id IN (SELECT DISTINCT order_id FROM payments WHERE order_id IS NOT NULL)
ORDER BY o.id DESC
LIMIT 20;
