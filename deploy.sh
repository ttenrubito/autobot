#!/bin/bash
# Deploy script for Autobot to Cloud Run
# Run this from Google Cloud Shell or local machine with gcloud installed

cd /opt/lampp/htdocs/autobot

echo "🚀 Deploying Autobot to Cloud Run..."
echo ""

gcloud run deploy autobot \
  --source . \
  --region=asia-southeast1 \
  --platform=managed \
  --allow-unauthenticated \
  --add-cloudsql-instances="autobot-prod-251215-22549:asia-southeast1:autobot-db" \
  --project=autobot-prod-251215-22549

echo ""
echo "✅ Deployment complete!"
echo ""
echo "Testing product search API..."
curl -X POST https://autobot.boxdesign.in.th/api/products/search \
  -H "Content-Type: application/json" \
  -d '{"q":"Rolex"}' \
  -s | grep -q '"success":true' && echo "✅ API works!" || echo "❌ API still failing"
