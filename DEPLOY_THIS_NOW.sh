#!/bin/bash
# Quick Deploy - Run this manually
cd /opt/lampp/htdocs/autobot
gcloud config set project autobot-prod-251215-22549
gcloud run deploy autobot \
  --source . \
  --region=asia-southeast1 \
  --allow-unauthenticated
