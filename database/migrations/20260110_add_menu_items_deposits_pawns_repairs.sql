-- ============================================================================
-- Migration: Add deposits, pawns, repairs menu items to user_menu_config
-- Date: 2026-01-10
-- Purpose: Add new menu items for existing user configurations
-- ============================================================================

-- New menu items to add:
-- deposits: มัดจำสินค้า
-- pawns: ฝากจำนำ  
-- repairs: งานซ่อม

-- Update all existing user_menu_config records to include new menu items
UPDATE user_menu_config
SET menu_items = JSON_SET(
    menu_items,
    '$.menus',
    JSON_ARRAY_APPEND(
        JSON_ARRAY_APPEND(
            JSON_ARRAY_APPEND(
                JSON_EXTRACT(menu_items, '$.menus'),
                '$',
                JSON_OBJECT('id', 'deposits', 'label', 'มัดจำสินค้า', 'enabled', false, 'icon', '💎', 'url', 'deposits.php')
            ),
            '$',
            JSON_OBJECT('id', 'pawns', 'label', 'ฝากจำนำ', 'enabled', false, 'icon', '🏆', 'url', 'pawns.php')
        ),
        '$',
        JSON_OBJECT('id', 'repairs', 'label', 'งานซ่อม', 'enabled', false, 'icon', '🔧', 'url', 'repairs.php')
    )
),
updated_at = NOW()
WHERE JSON_SEARCH(menu_items, 'one', 'deposits', NULL, '$.menus[*].id') IS NULL;

-- Verify changes
SELECT 
    id,
    user_email,
    JSON_LENGTH(menu_items, '$.menus') as menu_count,
    updated_at
FROM user_menu_config
ORDER BY updated_at DESC;

-- Show menu details (optional verification)
-- SELECT 
--     user_email,
--     JSON_EXTRACT(menu_items, '$.menus[*].id') as menu_ids
-- FROM user_menu_config;
