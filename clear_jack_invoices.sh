#!/bin/bash
# Quick fix: Clear invoices for jack@gmail.com and activate subscription
set -e

PROJECT_ID="autobot-prod-251215-22549"
INSTANCE="autobot-db"
DB_NAME="autobot_db"

echo "============================================"
echo "🧹 Clear Invoices for jack@gmail.com"
echo "============================================"
echo ""
echo "⚠️  This will DELETE all invoice data!"
echo ""
read -p "Continue? (yes/no): " -r
if [[ ! $REPLY =~ ^[Yy]es$ ]]; then
    echo "Cancelled."
    exit 0
fi

echo ""
echo "📊 Running SQL fix..."
gcloud sql connect ${INSTANCE} \
  --user=app_user \
  --database=${DB_NAME} \
  --project=${PROJECT_ID} \
  --quiet < clear_jack_invoices.sql

echo ""
echo "✅ Done! Test Facebook bot now."
echo ""
