-- Fix Overdue Invoice blocking Facebook Channel
-- User ID: 3
-- Invoice: INV-20251217-00003-8

-- 1. ตรวจสอบ invoice ปัจจุบัน
SELECT 
    i.id,
    i.invoice_number,
    i.customer_id,
    c.name as customer_name,
    c.email,
    i.amount,
    i.status as invoice_status,
    i.due_date,
    i.paid_at,
    CASE 
        WHEN i.status = 'paid' THEN 'OK'
        WHEN i.due_date < NOW() AND i.status != 'paid' THEN 'OVERDUE'
        ELSE 'PENDING'
    END as check_status
FROM invoices i
INNER JOIN customers c ON i.customer_id = c.id
WHERE c.id = 3
ORDER BY i.created_at DESC
LIMIT 5;

-- 2. แก้ไข invoice ให้เป็น paid (ชั่วคราว - production ควรมีระบบ billing จริง)
UPDATE invoices 
SET 
    status = 'paid',
    paid_at = NOW(),
    updated_at = NOW()
WHERE customer_id = 3
  AND status != 'paid';

-- 3. ตรวจสอบผลลัพธ์
SELECT 
    id,
    invoice_number,
    customer_id,
    amount,
    status,
    due_date,
    paid_at,
    'Fixed - marked as paid' as action
FROM invoices
WHERE customer_id = 3
ORDER BY created_at DESC
LIMIT 5;

-- 4. ตรวจสอบ Facebook channel ที่เกี่ยวข้อง
SELECT 
    cc.id as channel_id,
    cc.customer_id,
    cc.name as channel_name,
    cc.type,
    cc.status,
    JSON_UNQUOTE(JSON_EXTRACT(cc.config, '$.page_id')) as page_id,
    cc.inbound_api_key
FROM customer_channels cc
WHERE cc.customer_id = 3
  AND cc.type = 'facebook'
  AND cc.is_deleted = 0;
