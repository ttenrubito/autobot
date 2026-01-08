#!/bin/bash
# Check and Add Column to Production Database

echo "🔍 Checking Production Database Column..."
echo ""

# Connect to Cloud SQL and check/add column
gcloud sql connect autobot-db --user=root --project=autobot-prod-251215-22549 << 'MYSQL_EOF'
USE autobot;

-- Check if column exists
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ Column already exists'
        ELSE '❌ Column NOT found'
    END AS status
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA='autobot' 
  AND TABLE_NAME='chat_sessions' 
  AND COLUMN_NAME='last_admin_message_at';

-- Add column if not exists (safe - won't error if exists)
ALTER TABLE chat_sessions 
ADD COLUMN IF NOT EXISTS last_admin_message_at TIMESTAMP NULL DEFAULT NULL 
COMMENT 'Admin handoff timeout tracking'
AFTER summary;

-- Add index if not exists
CREATE INDEX IF NOT EXISTS idx_admin_timeout ON chat_sessions(last_admin_message_at);

-- Verify
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA='autobot' 
  AND TABLE_NAME='chat_sessions' 
  AND COLUMN_NAME='last_admin_message_at';

SELECT '✅ Production database is ready for admin handoff!' AS result;

MYSQL_EOF

echo ""
echo "✅ Database check complete!"
