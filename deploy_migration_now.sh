#!/bin/bash
set -e

echo "🚀 Deploying admin handoff migration to production DB..."
echo ""

gcloud sql connect autobot-db \
  --project=autobot-prod-251215-22549 \
  --database=autobot << 'SQL'
-- Add admin handoff timeout column
ALTER TABLE chat_sessions 
ADD COLUMN IF NOT EXISTS last_admin_message_at TIMESTAMP NULL DEFAULT NULL 
COMMENT 'Timestamp of last admin message - used for 1-hour timeout pause';

-- Add index for performance
ALTER TABLE chat_sessions
ADD INDEX IF NOT EXISTS idx_admin_timeout (last_admin_message_at);

-- Verify
SELECT 'Migration completed - last_admin_message_at column added' AS status;
SELECT COUNT(*) as sessions_count FROM chat_sessions;
SQL

echo ""
echo "✅ Migration deployed successfully!"
