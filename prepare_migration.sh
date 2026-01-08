#!/bin/bash
# Quick deploy migration to production

set -e

echo "=================================================="
echo "🚀 Deploying Admin Handoff Migration"
echo "=================================================="
echo ""

PROJECT="autobot-prod-251215-22549"
INSTANCE="autobot-db"
DATABASE="autobot"

echo "This will add 'last_admin_message_at' column to chat_sessions table"
echo ""
echo "Project: $PROJECT"
echo "Instance: $INSTANCE"
echo "Database: $DATABASE"
echo ""

# Create SQL file
cat > /tmp/admin_handoff_migration.sql << 'SQL'
-- Add admin handoff timeout tracking
ALTER TABLE chat_sessions 
ADD COLUMN IF NOT EXISTS last_admin_message_at TIMESTAMP NULL DEFAULT NULL 
COMMENT 'Admin message timestamp for 1-hour pause';

-- Add index
CREATE INDEX IF NOT EXISTS idx_admin_timeout 
ON chat_sessions(last_admin_message_at);

-- Verify
SELECT 'Migration completed successfully' AS status;
SHOW COLUMNS FROM chat_sessions LIKE 'last_admin_message_at';
SQL

echo "SQL migration file created at /tmp/admin_handoff_migration.sql"
echo ""
echo "To deploy, run:"
echo ""
echo "  gcloud sql connect $INSTANCE --project=$PROJECT --database=$DATABASE < /tmp/admin_handoff_migration.sql"
echo ""
