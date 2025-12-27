-- ============================================================================
-- Update LINE Profile Data for Production
-- วันที่: 2025-12-24
-- คำอธิบาย: อัปเดต conversations ให้มี LINE profile URL และข้อมูลลูกค้าครบถ้วน
-- ============================================================================

SET @test_user_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');

-- 1. อัปเดต conversations ทั้งหมดของ test1@gmail.com ให้มี LINE profile data
UPDATE conversations 
SET 
    metadata = JSON_SET(
        COALESCE(metadata, '{}'),
        '$.line_profile_url', 'https://profile.line-scdn.net/0h_bHyPEE9OGFrSQzI5zs6cHZYDnUZSzotB15TMBobcDFYBjpxBQ4aYh8bczdcAWtwUwkfMhsacjI',
        '$.user_phone', '0812345678',
        '$.display_name', 'ทดสอบ ระบบ',
        '$.status_message', 'มั่นใจในคุณภาพสินค้า',
        '$.tags', JSON_ARRAY('ลูกค้าประจำ', 'ชำระตรงเวลา')
    ),
    platform_user_name = 'ทดสอบ ระบบ'
WHERE customer_id = @test_user_id AND platform = 'line';

SELECT CONCAT('✅ Updated ', ROW_COUNT(), ' conversations with LINE profile data') AS status;

-- 2. ตรวจสอบผลลัพธ์
SELECT 
    conversation_id,
    platform_user_name,
    JSON_EXTRACT(metadata, '$.line_profile_url') as profile_url,
    JSON_EXTRACT(metadata, '$.user_phone') as phone,
    JSON_EXTRACT(metadata, '$.display_name') as display_name,
    created_at
FROM conversations 
WHERE customer_id = @test_user_id
ORDER BY created_at DESC
LIMIT 5;

-- 3. ตรวจสอบว่า payments มี conversation_id ใน payment_details หรือไม่
SELECT 
    p.payment_no,
    p.status,
    JSON_EXTRACT(p.payment_details, '$.conversation_id') as conv_id,
    JSON_EXTRACT(p.payment_details, '$.line_user') as line_user
FROM payments p
WHERE p.customer_id = @test_user_id
ORDER BY p.created_at DESC
LIMIT 5;

-- 4. สรุปผล
SELECT '========================================' AS '';
SELECT '✅ LINE PROFILE DATA UPDATE COMPLETE!' AS '';
SELECT '========================================' AS '';
SELECT '' AS '';
SELECT 'Next Steps:' AS '';
SELECT '1. Deploy updated app code to Cloud Run' AS '';
SELECT '2. Test payment-history.php to see customer profiles' AS '';
SELECT '3. Verify LINE profile pictures are displayed' AS '';
SELECT '' AS '';
SELECT 'Production URL: https://autobot.boxdesign.in.th/payment-history.php' AS '';
SELECT '========================================' AS '';
