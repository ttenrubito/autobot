-- Add 'reports' menu to existing user_menu_config
-- This adds the reports menu for users who have custom menu configurations

-- First, let's see what we have
SELECT id, user_email, 
       JSON_EXTRACT(menu_items, '$.menus') as current_menus
FROM user_menu_config 
WHERE is_active = 1;

-- Update all active user_menu_config to add reports menu if not exists
-- This uses JSON_ARRAY_APPEND to add the new menu item

UPDATE user_menu_config 
SET menu_items = JSON_SET(
    menu_items,
    '$.menus',
    JSON_ARRAY_APPEND(
        JSON_EXTRACT(menu_items, '$.menus'),
        '$',
        JSON_OBJECT(
            'id', 'reports',
            'label', 'รายงานรายรับ',
            'enabled', true,
            'icon', '📊',
            'url', 'reports/income.php'
        )
    )
)
WHERE is_active = 1
  AND JSON_SEARCH(menu_items, 'one', 'reports', NULL, '$.menus[*].id') IS NULL;

-- Verify the update
SELECT id, user_email, 
       JSON_SEARCH(menu_items, 'one', 'reports', NULL, '$.menus[*].id') as has_reports
FROM user_menu_config 
WHERE is_active = 1;
