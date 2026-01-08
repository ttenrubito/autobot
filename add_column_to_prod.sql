-- Check current columns
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA='autobot' 
  AND TABLE_NAME='chat_sessions'
ORDER BY ORDINAL_POSITION;

-- Add column if not exists
ALTER TABLE chat_sessions 
ADD COLUMN IF NOT EXISTS last_admin_message_at TIMESTAMP NULL DEFAULT NULL 
COMMENT 'Admin handoff timeout - bot pauses when admin is active'
AFTER summary;

-- Add index
CREATE INDEX IF NOT EXISTS idx_admin_timeout ON chat_sessions(last_admin_message_at);

-- Verify
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA='autobot' 
  AND TABLE_NAME='chat_sessions' 
  AND COLUMN_NAME='last_admin_message_at';

SELECT '✅ Admin handoff column ready!' AS status;
