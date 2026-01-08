#!/bin/bash
#
# Deploy LIFF with GCS Integration to Production
# Date: 2026-01-04
#

set -e  # Exit on error

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_FILE="deploy_liff_gcs_${TIMESTAMP}.log"

echo "🚀 Deploying LIFF with Google Cloud Storage Integration"
echo "=================================================="
echo "Timestamp: $TIMESTAMP"
echo "Log file: $LOG_FILE"
echo ""

# Log function
log() {
    echo "$1" | tee -a "$LOG_FILE"
}

log "📋 Deployment Summary:"
log "  1. ✅ Added Google Cloud Storage SDK (composer)"
log "  2. ✅ Created GoogleCloudStorage helper class"
log "  3. ✅ Moved service account key to config/gcp/"
log "  4. ✅ Updated documents API to use GCS"
log "  5. ✅ Made LIFF form document fields dynamic"
log "  6. ✅ Document upload now reads from campaign.required_documents"
log ""

# Check if gcloud is installed
if ! command -v gcloud &> /dev/null; then
    log "❌ Error: gcloud CLI not found"
    log "Please install: https://cloud.google.com/sdk/docs/install"
    exit 1
fi

log "✅ gcloud CLI found"

# Set project
PROJECT_ID="canvas-radio-472913-d4"
SERVICE_NAME="autobot"
REGION="asia-southeast1"

log "📦 Project: $PROJECT_ID"
log "🚢 Service: $SERVICE_NAME"
log "🌏 Region: $REGION"
log ""

# Authenticate (if needed)
log "🔐 Checking authentication..."
gcloud config set project "$PROJECT_ID" 2>&1 | tee -a "$LOG_FILE"

# Create Dockerfile if needed
if [ ! -f "Dockerfile" ]; then
    log "❌ Dockerfile not found!"
    exit 1
fi

log "✅ Dockerfile found"

# Build and deploy
log ""
log "🏗️  Building and deploying to Cloud Run..."
log ""

gcloud run deploy "$SERVICE_NAME" \
    --source . \
    --platform managed \
    --region "$REGION" \
    --allow-unauthenticated \
    --set-env-vars "GCP_PROJECT_ID=${PROJECT_ID},GCS_BUCKET_NAME=autobot-documents,APP_ENV=production" \
    --memory 512Mi \
    --cpu 1 \
    --timeout 300 \
    --max-instances 10 \
    --quiet \
    2>&1 | tee -a "$LOG_FILE"

if [ $? -eq 0 ]; then
    log ""
    log "✅ Deployment successful!"
    log ""
    log "📋 Next Steps:"
    log "  1. Test LIFF form with campaign that has required_documents"
    log "  2. Upload a document and verify it's stored in GCS"
    log "  3. Check GCS bucket: https://console.cloud.google.com/storage/browser/autobot-documents"
    log "  4. Verify signed URLs work for viewing documents"
    log ""
    log "🔗 Service URL: https://${SERVICE_NAME}-XXXXXXXXXX-${REGION:0:2}.a.run.app"
    log ""
    log "📊 View logs:"
    log "  gcloud run services logs read $SERVICE_NAME --region $REGION --limit 50"
    log ""
else
    log ""
    log "❌ Deployment failed!"
    log "Check the log file: $LOG_FILE"
    exit 1
fi
