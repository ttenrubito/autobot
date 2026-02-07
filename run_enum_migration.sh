#!/bin/bash
# Quick migration: Add deposit/savings to payment_type ENUM
# Usage: ./run_enum_migration.sh

set -e

PROJECT_ID="autobot-prod-251215-22549"
INSTANCE_NAME="autobot-db"
DB_NAME="autobot"
BACKUP_BUCKET="gs://${PROJECT_ID}-backups"
SQL_FILE="migrations/add_deposit_savings_enum.sql"

echo "🔧 Running ENUM Migration: deposit/savings"
echo "============================================"

# Upload SQL to GCS
echo "📤 Uploading migration SQL..."
gsutil cp ${SQL_FILE} ${BACKUP_BUCKET}/migrations/add_deposit_savings_enum.sql

# Run migration via Cloud SQL import
echo "⚡ Running migration on Cloud SQL..."
gcloud sql import sql ${INSTANCE_NAME} \
  ${BACKUP_BUCKET}/migrations/add_deposit_savings_enum.sql \
  --database=${DB_NAME} \
  --project=${PROJECT_ID} \
  --quiet

echo ""
echo "✅ Migration complete!"
echo ""
echo "Verify with:"
echo "  SHOW COLUMNS FROM payments WHERE Field = 'payment_type';"
echo "  SHOW COLUMNS FROM orders WHERE Field = 'payment_type';"
