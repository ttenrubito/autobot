#!/bin/bash
gcloud run deploy autobot \
  --source . \
  --region=asia-southeast1 \
  --project=autobot-prod-251215-22549 \
  --allow-unauthenticated
