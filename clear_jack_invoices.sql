-- ============================================================================
-- Clear All Invoices for jack@gmail.com (Development/Testing)
-- ============================================================================
-- WARNING: This will DELETE all invoice data for this user
-- Use with caution in production!
-- ============================================================================

-- 1. ตรวจสอบข้อมูลก่อนลบ
SELECT 
    c.id as customer_id,
    c.name,
    c.email,
    COUNT(DISTINCT i.id) as invoice_count,
    COUNT(DISTINCT ii.id) as invoice_item_count,
    COUNT(DISTINCT ph.id) as payment_history_count,
    SUM(i.total_amount) as total_invoice_amount
FROM customers c
LEFT JOIN invoices i ON c.id = i.customer_id
LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
LEFT JOIN payment_history ph ON i.id = ph.invoice_id
WHERE c.email = 'jack@gmail.com'
GROUP BY c.id, c.name, c.email;

-- 2. ลบข้อมูล payment_history
DELETE ph FROM payment_history ph
INNER JOIN invoices i ON ph.invoice_id = i.id
INNER JOIN customers c ON i.customer_id = c.id
WHERE c.email = 'jack@gmail.com';

-- 3. ลบข้อมูล invoice_items
DELETE ii FROM invoice_items ii
INNER JOIN invoices i ON ii.invoice_id = i.id
INNER JOIN customers c ON i.customer_id = c.id
WHERE c.email = 'jack@gmail.com';

-- 4. ลบข้อมูล invoices
DELETE i FROM invoices i
INNER JOIN customers c ON i.customer_id = c.id
WHERE c.email = 'jack@gmail.com';

-- 5. ตรวจสอบผลลัพธ์หลังลบ
SELECT 
    c.id as customer_id,
    c.name,
    c.email,
    COUNT(DISTINCT i.id) as remaining_invoices,
    '✅ Cleared!' as status
FROM customers c
LEFT JOIN invoices i ON c.id = i.customer_id
WHERE c.email = 'jack@gmail.com'
GROUP BY c.id, c.name, c.email;

-- 6. อัพเดท subscription ให้ active (ถ้ามี)
UPDATE customer_subscriptions s
INNER JOIN customers c ON s.customer_id = c.id
SET 
    s.status = 'active',
    s.end_date = DATE_ADD(NOW(), INTERVAL 1 YEAR),
    s.updated_at = NOW()
WHERE c.email = 'jack@gmail.com';

-- 7. แสดงสถานะสุดท้าย
SELECT 
    c.id,
    c.name,
    c.email,
    s.status as subscription_status,
    s.end_date as subscription_end,
    COUNT(i.id) as invoice_count
FROM customers c
LEFT JOIN customer_subscriptions s ON c.id = s.customer_id
LEFT JOIN invoices i ON c.id = i.customer_id
WHERE c.email = 'jack@gmail.com'
GROUP BY c.id, c.name, c.email, s.status, s.end_date;
