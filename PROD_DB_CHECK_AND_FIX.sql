-- ========================================
-- Production Database Fix for Admin Handoff
-- Run this in GCP Cloud Console SQL Editor
-- ========================================

-- Step 1: Check if column exists
SELECT 
    COUNT(*) as column_exists,
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ Column EXISTS - No migration needed'
        ELSE '❌ Column NOT FOUND - Need to add it'
    END as status
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'autobot' 
  AND TABLE_NAME = 'chat_sessions' 
  AND COLUMN_NAME = 'last_admin_message_at';

-- Step 2: Show current chat_sessions structure
DESCRIBE chat_sessions;

-- Step 3: Add column (run this ONLY if column doesn't exist)
-- Uncomment the lines below after confirming column doesn't exist:

/*
ALTER TABLE chat_sessions 
ADD COLUMN last_admin_message_at TIMESTAMP NULL DEFAULT NULL 
COMMENT 'Admin handoff - tracks when admin last sent message for 1-hour pause'
AFTER summary;

CREATE INDEX idx_admin_timeout ON chat_sessions(last_admin_message_at);
*/

-- Step 4: Verify after adding (uncomment after running ALTER TABLE above)
/*
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'autobot' 
  AND TABLE_NAME = 'chat_sessions' 
  AND COLUMN_NAME = 'last_admin_message_at';

SELECT '✅ Admin handoff column is ready!' as result;
*/
