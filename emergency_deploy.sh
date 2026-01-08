#!/bin/bash
###############################################################################
# EMERGENCY DEPLOY - Skip All Tests
# Use this when you're SURE the code works and just need to deploy NOW
###############################################################################

set -e

echo "🚨 EMERGENCY DEPLOY (NO TESTS)"
echo "================================"
echo ""
echo "⚠️  WARNING: This skips ALL safety checks!"
echo "⚠️  Only use if you're SURE the code works."
echo ""

# Confirm
if [ "${FORCE}" != "1" ]; then
    read -p "Continue? (yes/no): " CONFIRM
    if [ "$CONFIRM" != "yes" ]; then
        echo "❌ Deploy cancelled"
        exit 1
    fi
fi

# Set project
echo ""
echo "📋 Setting project..."
gcloud config set project autobot-prod-251215-22549

# Deploy
echo ""
echo "🚀 Deploying to Cloud Run..."
echo ""

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
    --set-env-vars="INSTANCE_CONN_NAME=autobot-prod-251215-22549:asia-southeast1:autobot-db,DB_NAME=autobot,DB_USER=root" \
    --set-secrets="DB_PASSWORD=db-password:latest,LINE_CHANNEL_SECRET=line-channel-secret:latest,LINE_CHANNEL_ACCESS_TOKEN=line-channel-access-token:latest,FACEBOOK_VERIFY_TOKEN=facebook-verify-token:latest,FACEBOOK_PAGE_ACCESS_TOKEN=facebook-page-access-token:latest,GEMINI_API_KEY=gemini-api-key:latest,ADMIN_USERNAME=admin-username:latest,ADMIN_PASSWORD_HASH=admin-password-hash:latest"

echo ""
echo "✅ DEPLOY COMPLETE!"
echo ""
echo "📋 Next: Check if column exists in production DB"
echo "   Run: gcloud sql connect autobot-db --user=root --project=autobot-prod-251215-22549"
echo "   Then: USE autobot; SHOW COLUMNS FROM chat_sessions LIKE 'last_admin_message_at';"
