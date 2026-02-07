-- Add case_management to bot_profile config for bot_profile_id = 5
-- Run via: ./deploy_sql_to_production.sh update_case_management_config.sql

UPDATE customer_bot_profiles 
SET config = JSON_SET(
    config,
    '$.case_management', JSON_OBJECT(
        'enabled', true,
        'auto_create_case', true,
        'case_types', JSON_ARRAY(
            'product_inquiry',
            'payment_full',
            'payment_installment',
            'deposit',
            'pawn',
            'repair'
        ),
        'admin_handoff_triggers', JSON_ARRAY(
            'ติดต่อเจ้าหน้าที่',
            'คุยกับคน',
            'ขอสาย',
            'แอดมิน',
            'งง',
            'ไม่เข้าใจ',
            'ขอส่วนลด',
            'ลดได้ไหม',
            'ลดราคา',
            'ต่อรอง',
            'นัดดูของ',
            'video call',
            'วิดีโอคอล',
            'ดูผ่านกล้อง',
            'โทรมา',
            'รูดบัตร',
            'บัตรเครดิต',
            'เครดิต'
        )
    )
)
WHERE id = 5;

-- Verify
SELECT id, name, JSON_EXTRACT(config, '$.case_management.enabled') as case_enabled
FROM customer_bot_profiles WHERE id = 5;
