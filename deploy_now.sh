#!/bin/bash
###############################################################################
# Quick Deploy Script (Skip Tests - Use Only When Tests Already Verified)
###############################################################################

set -e

echo "🚀 Quick Deploy to Cloud Run (Tests Skipped)"
echo "=============================================="

# Set project
gcloud config set project autobot-prod-251215-22549

# Deploy
echo ""
echo "📦 Deploying to Cloud Run..."
gcloud run deploy autobot \
    --source . \
    --region=asia-southeast1 \
    --platform=managed \
    --allow-unauthenticated \
    --timeout=60 \
    --memory=512Mi \
    --cpu=1 \
    --min-instances=0 \
    --max-instances=10 \
    --set-env-vars="^:^INSTANCE_CONN_NAME=autobot-prod-251215-22549:asia-southeast1:autobot-db:DB_NAME=autobot:DB_USER=root" \
    --set-secrets="DB_PASSWORD=db-password:latest,LINE_CHANNEL_SECRET=line-channel-secret:latest,LINE_CHANNEL_ACCESS_TOKEN=line-channel-access-token:latest,FACEBOOK_VERIFY_TOKEN=facebook-verify-token:latest,FACEBOOK_PAGE_ACCESS_TOKEN=facebook-page-access-token:latest,GEMINI_API_KEY=gemini-api-key:latest,ADMIN_USERNAME=admin-username:latest,ADMIN_PASSWORD_HASH=admin-password-hash:latest" \
    --quiet

echo ""
echo "✅ Deployment Complete!"
echo ""
echo "🔍 Next Steps:"
echo "  1. Run DB migration: ./run_migration_production.sh"
echo "  2. Verify deployment: ./verify_production.sh"
echo "  3. Test admin handoff in Facebook/LINE"
