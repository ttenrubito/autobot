#!/bin/bash
echo "🚀 Quick deploy debug endpoint..."
gcloud run deploy autobot \
  --source=. \
  --region=asia-southeast1 \
  --platform=managed \
  --allow-unauthenticated \
  --timeout=300 \
  --memory=512Mi \
  --quiet 2>&1 | tail -15
