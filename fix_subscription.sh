#!/bin/bash
# Quick fix for Facebook channel - Overdue Invoice (HTTP 402)
set -e

PROJECT_ID="autobot-prod-251215-22549"
INSTANCE_NAME="autobot-db"
DATABASE="autobot_db"

echo "============================================"
echo "🔧 Fix Overdue Invoice (HTTP 402)"
echo "============================================"
echo ""
echo "User ID: 3"
echo "Invoice: INV-20251217-00003-8"
echo "Page ID: 548866645142339"
echo ""

# Run SQL fix
echo "📊 Marking invoice as paid..."
gcloud sql connect ${INSTANCE_NAME} \
  --user=app_user \
  --database=${DATABASE} \
  --project=${PROJECT_ID} \
  --quiet < fix_overdue_invoice.sql

echo ""
echo "✅ Invoice marked as paid!"
echo ""
echo "Next steps:"
echo "1. Test sending message to Facebook page"
echo "2. Check logs: gcloud run services logs tail autobot --project=${PROJECT_ID}"
echo ""
