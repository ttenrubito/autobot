-- Quick SQL to check and setup LIFF ID
-- Run this in Cloud SQL Console or mysql client

USE autobot_prod;

-- 1. Check current campaigns
SELECT 
    id,
    code,
    name,
    liff_id,
    is_active,
    CASE 
        WHEN liff_id IS NULL OR liff_id = '' THEN '❌ ต้อง Setup'
        ELSE '✅ มีแล้ว'
    END as status
FROM campaigns
WHERE is_active = 1
ORDER BY created_at DESC;

-- 2. If need to setup (uncomment and replace YOUR_LIFF_ID):
-- UPDATE campaigns 
-- SET liff_id = 'YOUR_LIFF_ID_HERE'
-- WHERE code = 'TEST2026';

-- 3. Verify after update:
-- SELECT code, name, liff_id FROM campaigns WHERE code = 'TEST2026';
