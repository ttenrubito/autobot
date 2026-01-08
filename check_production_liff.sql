-- Check and Update LIFF ID in Production
-- Run this on Production Database

-- 1. Check current campaigns
SELECT 
    id,
    code,
    name,
    liff_id,
    is_active,
    start_date,
    end_date,
    CASE 
        WHEN liff_id IS NOT NULL AND liff_id != '' THEN '✅ HAS LIFF'
        ELSE '❌ NO LIFF'
    END as liff_status,
    CASE
        WHEN (start_date IS NULL OR start_date <= CURDATE())
         AND (end_date IS NULL OR end_date >= CURDATE())
        THEN '✅ IN DATE RANGE'
        ELSE '❌ OUT OF DATE RANGE'
    END as date_status
FROM campaigns
WHERE is_active = 1
ORDER BY created_at DESC
LIMIT 10;

-- 2. Check what the bot query will return
SELECT 
    'Bot will see these campaigns:' as info;

SELECT 
    id,
    code,
    name,
    liff_id,
    CASE 
        WHEN liff_id IS NOT NULL AND liff_id != '' THEN 'Will show LIFF URL ✅'
        ELSE 'Will NOT show LIFF URL ❌'
    END as url_status
FROM campaigns
WHERE is_active = 1
    AND (start_date IS NULL OR start_date <= CURDATE())
    AND (end_date IS NULL OR end_date >= CURDATE())
ORDER BY created_at DESC
LIMIT 5;

-- 3. UPDATE: Add your LIFF ID here (UNCOMMENT and REPLACE VALUES)
-- UPDATE campaigns 
-- SET liff_id = 'YOUR_LIFF_ID_HERE'  -- ใส่ LIFF ID ที่ได้จาก LINE Developers Console
-- WHERE code = 'DEMO2026';  -- ใส่ Campaign Code ที่ต้องการ

-- 4. Verify update
-- SELECT code, name, liff_id 
-- FROM campaigns 
-- WHERE code = 'DEMO2026';
