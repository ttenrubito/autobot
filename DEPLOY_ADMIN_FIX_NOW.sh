#!/bin/bash
set -e

echo "🚀 EMERGENCY FIX: Deploy Admin Pattern Fix"
echo "==========================================="
echo ""
echo "✅ Fixed: Now detects 'admin มาตอบ' and similar patterns"
echo ""

cd /opt/lampp/htdocs/autobot

gcloud config set project autobot-prod-251215-22549

echo "📦 Deploying to Cloud Run..."
gcloud run deploy autobot \
  --source . \
  --region=asia-southeast1 \
  --allow-unauthenticated \
  --quiet

echo ""
echo "✅ DEPLOYMENT COMPLETE!"
echo ""
echo "🎯 Test immediately:"
echo "   1. Facebook Messenger: พิมพ์ 'admin มาตอบ'"
echo "   2. Bot should STOP replying"
echo ""
echo "📊 Monitor logs:"
echo "   gcloud logging tail --service=autobot --project=autobot-prod-251215-22549"
