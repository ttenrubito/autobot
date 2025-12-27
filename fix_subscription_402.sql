-- Fix Subscription Expired (HTTP 402) for Facebook Channel
-- Page ID: 548866645142339
-- Channel: ทดสอบ facebook 1

-- 1. ตรวจสอบ subscription ปัจจุบัน
SELECT 
    c.id as customer_id,
    c.name as customer_name,
    c.email,
    s.id as subscription_id,
    s.plan_name,
    s.status as sub_status,
    s.start_date,
    s.end_date,
    CASE 
        WHEN s.end_date IS NULL THEN 'NO_EXPIRY'
        WHEN s.end_date < NOW() THEN 'EXPIRED'
        ELSE 'ACTIVE'
    END as actual_status,
    cc.id as channel_id,
    cc.name as channel_name,
    cc.inbound_api_key
FROM customers c
LEFT JOIN customer_subscriptions s ON c.id = s.customer_id
INNER JOIN customer_channels cc ON c.id = cc.customer_id
WHERE cc.type = 'facebook' 
  AND cc.status = 'active'
  AND cc.is_deleted = 0
  AND JSON_UNQUOTE(JSON_EXTRACT(cc.config, '$.page_id')) = '548866645142339';

-- 2. อัพเดท subscription ให้ active ถ้าหมดอายุหรือไม่มี
-- (แก้ชั่วคราว - production ควรมีระบบ billing จริง)

UPDATE customer_subscriptions s
INNER JOIN customers c ON s.customer_id = c.id
INNER JOIN customer_channels cc ON c.id = cc.customer_id
SET 
    s.status = 'active',
    s.end_date = DATE_ADD(NOW(), INTERVAL 1 YEAR),
    s.updated_at = NOW()
WHERE cc.type = 'facebook'
  AND cc.is_deleted = 0
  AND JSON_UNQUOTE(JSON_EXTRACT(cc.config, '$.page_id')) = '548866645142339'
  AND (s.status != 'active' OR s.end_date < NOW() OR s.end_date IS NULL);

-- 3. ถ้าไม่มี subscription เลย - สร้างใหม่
INSERT INTO customer_subscriptions (customer_id, plan_name, status, start_date, end_date, is_trial, created_at, updated_at)
SELECT 
    c.id,
    'Professional' as plan_name,
    'active' as status,
    NOW() as start_date,
    DATE_ADD(NOW(), INTERVAL 1 YEAR) as end_date,
    0 as is_trial,
    NOW() as created_at,
    NOW() as updated_at
FROM customers c
INNER JOIN customer_channels cc ON c.id = cc.customer_id
LEFT JOIN customer_subscriptions s ON c.id = s.customer_id
WHERE cc.type = 'facebook'
  AND cc.is_deleted = 0
  AND JSON_UNQUOTE(JSON_EXTRACT(cc.config, '$.page_id')) = '548866645142339'
  AND s.id IS NULL;

-- 4. ตรวจสอบผลลัพธ์หลังแก้ไข
SELECT 
    c.id,
    c.name,
    s.plan_name,
    s.status,
    s.end_date,
    'Fixed' as action
FROM customers c
INNER JOIN customer_subscriptions s ON c.id = s.customer_id
INNER JOIN customer_channels cc ON c.id = cc.customer_id
WHERE cc.type = 'facebook'
  AND cc.is_deleted = 0
  AND JSON_UNQUOTE(JSON_EXTRACT(cc.config, '$.page_id')) = '548866645142339';
