-- ============================================================================
-- Production Migration: User Menu Configuration System
-- Date: 2025-12-29
-- Description: Add user_menu_config table for per-user menu visibility control
--
-- Usage:
--   mysql -u root -p autobot < 2025_12_29_add_user_menu_config.sql
-- ============================================================================

-- Check if table already exists
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '⚠️  Table user_menu_config already exists - migration will be skipped'
        ELSE '✓ Table does not exist - creating now'
    END AS pre_check
FROM information_schema.tables 
WHERE table_schema = DATABASE() 
AND table_name = 'user_menu_config';

-- Create table if not exists
CREATE TABLE IF NOT EXISTS user_menu_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    menu_items JSON NOT NULL COMMENT 'Array of menu items with enabled/disabled flags',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user_email (user_email),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify creation
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ SUCCESS: Table user_menu_config created/verified'
        ELSE '❌ ERROR: Table creation failed'
    END AS result
FROM information_schema.tables 
WHERE table_schema = DATABASE() 
AND table_name = 'user_menu_config';

-- Show table structure
DESCRIBE user_menu_config;

-- Show current data count
SELECT COUNT(*) AS total_configs FROM user_menu_config;

SELECT '============================================' AS '';
SELECT '✅ Migration Complete!' AS '';
SELECT '============================================' AS '';
SELECT '' AS '';
SELECT 'Table created: user_menu_config' AS '';
SELECT 'Next steps:' AS '';
SELECT '1. Run setup_userid4_menu_config.sql if needed' AS '';
SELECT '2. Test API endpoints' AS '';
SELECT '3. Verify admin interface' AS '';
SELECT '' AS '';
