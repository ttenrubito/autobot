#!/bin/bash
set -e

echo "🚀 Simple Deploy - No Tests"
cd /opt/lampp/htdocs/autobot

gcloud run deploy autobot \
  --source . \
  --region asia-southeast1 \
  --platform managed \
  --allow-unauthenticated \
  --memory 512Mi \
  --timeout 300 \
  --max-instances 10 \
  --quiet

echo "✅ Deploy complete!"
gcloud run services describe autobot --region=asia-southeast1 --format="value(status.latestReadyRevisionName)"
