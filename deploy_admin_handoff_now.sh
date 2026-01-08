#!/bin/bash
set -e

echo "🚀 Deploying Admin Handoff to Production"
echo "=========================================="
echo ""

cd /opt/lampp/htdocs/autobot

# Set project
gcloud config set project autobot-prod-251215-22549

# Deploy
echo "📦 Building and deploying..."
gcloud run deploy autobot \
  --source . \
  --region=asia-southeast1 \
  --allow-unauthenticated \
  --quiet

echo ""
echo "✅ Deployment complete!"
echo ""
echo "Next step: Fix production database"
echo "Run: ./FIX_PROD_DB_NOW.sh"
