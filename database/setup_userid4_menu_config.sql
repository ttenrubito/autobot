-- ============================================================================
-- Setup Menu Configuration for User ID = 4
-- Sets custom menu for specific user (conversations, addresses, orders, payment_history)
-- ============================================================================

-- Get user email for user_id = 4
SET @target_user_email = (SELECT email FROM users WHERE id = 4 LIMIT 1);

-- Check if user exists
SELECT CASE 
    WHEN @target_user_email IS NULL THEN 'ERROR: User ID 4 not found!'
    ELSE CONCAT('Setting up menu config for: ', @target_user_email)
END AS status;

-- Only proceed if user exists
INSERT INTO user_menu_config (user_email, menu_items, is_active)
SELECT 
    @target_user_email,
    JSON_OBJECT(
        'menus', JSON_ARRAY(
            JSON_OBJECT('id', 'dashboard', 'label', 'Dashboard', 'enabled', true, 'icon', '📊', 'url', 'dashboard.php'),
            JSON_OBJECT('id', 'services', 'label', 'บริการของฉัน', 'enabled', false, 'icon', '🤖', 'url', 'services.php'),
            JSON_OBJECT('id', 'usage', 'label', 'การใช้งาน', 'enabled', false, 'icon', '📈', 'url', 'usage.php'),
            JSON_OBJECT('id', 'payment', 'label', 'ชำระเงิน', 'enabled', false, 'icon', '💳', 'url', 'payment.php'),
            JSON_OBJECT('id', 'billing', 'label', 'ใบแจ้งหนี้', 'enabled', false, 'icon', '📄', 'url', 'billing.php'),
            JSON_OBJECT('id', 'chat_history', 'label', 'ประวัติการสนทนา', 'enabled', false, 'icon', '💬', 'url', 'chat-history.php'),
            JSON_OBJECT('id', 'conversations', 'label', 'แชทกับลูกค้า', 'enabled', true, 'icon', '💭', 'url', 'conversations.php'),
            JSON_OBJECT('id', 'addresses', 'label', 'ที่อยู่จัดส่ง', 'enabled', true, 'icon', '📍', 'url', 'addresses.php'),
            JSON_OBJECT('id', 'orders', 'label', 'คำสั่งซื้อ', 'enabled', true, 'icon', '📦', 'url', 'orders.php'),
            JSON_OBJECT('id', 'payment_history', 'label', 'ประวัติการชำระ / ตรวจสลิป', 'enabled', true, 'icon', '💰', 'url', 'payment-history.php'),
            JSON_OBJECT('id', 'profile', 'label', 'โปรไฟล์', 'enabled', true, 'icon', '👤', 'url', 'profile.php')
        )
    ),
    1
FROM DUAL
WHERE @target_user_email IS NOT NULL
ON DUPLICATE KEY UPDATE
    menu_items = JSON_OBJECT(
        'menus', JSON_ARRAY(
            JSON_OBJECT('id', 'dashboard', 'label', 'Dashboard', 'enabled', true, 'icon', '📊', 'url', 'dashboard.php'),
            JSON_OBJECT('id', 'services', 'label', 'บริการของฉัน', 'enabled', false, 'icon', '🤖', 'url', 'services.php'),
            JSON_OBJECT('id', 'usage', 'label', 'การใช้งาน', 'enabled', false, 'icon', '📈', 'url', 'usage.php'),
            JSON_OBJECT('id', 'payment', 'label', 'ชำระเงิน', 'enabled', false, 'icon', '💳', 'url', 'payment.php'),
            JSON_OBJECT('id', 'billing', 'label', 'ใบแจ้งหนี้', 'enabled', false, 'icon', '📄', 'url', 'billing.php'),
            JSON_OBJECT('id', 'chat_history', 'label', 'ประวัติการสนทนา', 'enabled', false, 'icon', '💬', 'url', 'chat-history.php'),
            JSON_OBJECT('id', 'conversations', 'label', 'แชทกับลูกค้า', 'enabled', true, 'icon', '💭', 'url', 'conversations.php'),
            JSON_OBJECT('id', 'addresses', 'label', 'ที่อยู่จัดส่ง', 'enabled', true, 'icon', '📍', 'url', 'addresses.php'),
            JSON_OBJECT('id', 'orders', 'label', 'คำสั่งซื้อ', 'enabled', true, 'icon', '📦', 'url', 'orders.php'),
            JSON_OBJECT('id', 'payment_history', 'label', 'ประวัติการชำระ / ตรวจสลิป', 'enabled', true, 'icon', '💰', 'url', 'payment-history.php'),
            JSON_OBJECT('id', 'profile', 'label', 'โปรไฟล์', 'enabled', true, 'icon', '👤', 'url', 'profile.php')
        )
    ),
    is_active = 1,
    updated_at = NOW();

-- Verify setup
SELECT 
    CASE 
        WHEN @target_user_email IS NULL THEN 'FAILED: User ID 4 not found'
        ELSE 'SUCCESS: Menu config set for user ID 4'
    END AS result;

-- Show config details
SELECT 
    user_email,
    is_active,
    JSON_LENGTH(menu_items, '$.menus') AS total_menus,
    created_at,
    updated_at
FROM user_menu_config
WHERE user_email = @target_user_email;

-- Show all menus with enabled status
SELECT 
    JSON_UNQUOTE(JSON_EXTRACT(menu_items, CONCAT('$.menus[', idx, '].id'))) AS menu_id,
    JSON_UNQUOTE(JSON_EXTRACT(menu_items, CONCAT('$.menus[', idx, '].label'))) AS menu_label,
    JSON_EXTRACT(menu_items, CONCAT('$.menus[', idx, '].enabled')) AS enabled
FROM user_menu_config
JOIN (
    SELECT 0 AS idx UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
    UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
) AS numbers
WHERE user_email = @target_user_email
    AND JSON_EXTRACT(menu_items, CONCAT('$.menus[', idx, '].id')) IS NOT NULL
ORDER BY idx;

