#!/bin/bash
# Deploy Admin Handoff Migration to Production

set -e

echo "=================================================="
echo "🚀 Deploying Admin Handoff Migration to Production"
echo "=================================================="
echo ""

PROJECT_ID="autobot-prod-251215-22549"
INSTANCE="autobot-prod-251215-22549:asia-southeast1:autobot-db"
DATABASE="autobot"

echo "Project: ${PROJECT_ID}"
echo "Instance: ${INSTANCE}"
echo "Database: ${DATABASE}"
echo ""

echo "⚠️  This will modify the production database"
read -p "Continue? (yes/no): " -r
if [[ ! $REPLY =~ ^[Yy]es$ ]]; then
    echo "Cancelled."
    exit 0
fi

echo ""
echo "Running migration..."

gcloud sql connect autobot-db \
  --project=${PROJECT_ID} \
  --database=${DATABASE} \
  < database/migrations/add_admin_handoff_timeout.sql

echo ""
echo "✅ Migration deployed successfully!"
echo ""
echo "Verify with:"
echo "  gcloud sql connect autobot-db --project=${PROJECT_ID} --database=${DATABASE}"
echo "  Then run: SHOW COLUMNS FROM chat_sessions LIKE 'last_admin_message_at';"
echo ""
