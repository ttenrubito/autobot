#!/bin/bash
# Auto-apply migration via Cloud Run Job
# This script creates a one-time Cloud Run Job to execute the migration

set -e

PROJECT_ID="autobot-prod-251215-22549"
REGION="asia-southeast1"
SQL_INSTANCE="autobot-db"
DB_NAME="autobot"

echo "🚀 Creating Cloud Run Job to apply migration..."

# Create temporary migration runner script
cat > /tmp/run_migration.sh << 'EOF'
#!/bin/bash
apt-get update && apt-get install -y default-mysql-client

# Get connection via Cloud SQL Proxy socket
export MYSQL_PWD="${DB_PASSWORD}"

mysql -h 127.0.0.1 -u root "${DB_NAME}" << 'SQL'
ALTER TABLE chat_messages 
ADD COLUMN IF NOT EXISTS role ENUM('user','assistant','system','admin') NOT NULL DEFAULT 'user' 
COMMENT 'Message sender role' 
AFTER sender_type;

UPDATE chat_messages 
SET role = CASE 
    WHEN sender_type = 'customer' THEN 'user'
    WHEN sender_type = 'bot' THEN 'assistant'
    WHEN sender_type = 'agent' THEN 'admin'
    ELSE 'system'
END
WHERE role = 'user';

SELECT 'Migration completed successfully!' as status;
SQL

echo "✅ Migration applied!"
EOF

# Deploy as Cloud Run Job
gcloud run jobs create migration-admin-role \
  --region=$REGION \
  --project=$PROJECT_ID \
  --image=gcr.io/cloud-builders/gcloud \
  --set-cloudsql-instances="${PROJECT_ID}:${REGION}:${SQL_INSTANCE}" \
  --set-env-vars="DB_NAME=${DB_NAME}" \
  --execute-now \
  --wait

echo "✅ Migration job completed!"
