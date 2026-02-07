#!/bin/bash
# Deploy pawn/repair migration to production
# Adds pawn_id and repair_id columns to payments table

set -e

PROJECT_ID="autobot-prod-251215-22549"
INSTANCE_NAME="autobot-db"
DB_NAME="autobot"
BUCKET="gs://${PROJECT_ID}-backups"
MIGRATION_FILE="migrations/20260201_add_pawn_repair_to_payments.sql"

echo "============================================"
echo "🚀 Deploy Pawn/Repair Migration to Production"
echo "============================================"
echo ""

# Check if file exists
if [ ! -f "${MIGRATION_FILE}" ]; then
    echo "❌ Migration file not found: ${MIGRATION_FILE}"
    exit 1
fi

echo "📤 Uploading migration file to Cloud Storage..."
gsutil cp ${MIGRATION_FILE} ${BUCKET}/migrations/20260201_add_pawn_repair_to_payments.sql

echo "📥 Importing migration to Cloud SQL..."
gcloud sql import sql ${INSTANCE_NAME} \
  ${BUCKET}/migrations/20260201_add_pawn_repair_to_payments.sql \
  --database=${DB_NAME} \
  --project=${PROJECT_ID} \
  --quiet

echo ""
echo "✅ Migration deployed successfully!"
echo ""
echo "Columns added:"
echo "  - payments.repair_id (INT NULL)"
echo "  - payments.pawn_id (INT NULL)"
