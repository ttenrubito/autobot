#!/bin/bash
# ============================================================================
# Deploy Application Code to Production (Cloud Run)
# ============================================================================
# 
# This script will:
# 1. Build Docker image
# 2. Deploy to Cloud Run
# 3. Verify deployment
#
# Usage: ./deploy_app_to_production.sh
# ============================================================================

set -e  # Exit on error

# Optional non-interactive mode:
#   AUTO_YES=1 -> auto-confirm prompts
AUTO_YES=${AUTO_YES:-0}

# Configuration
PROJECT_ID="autobot-prod-251215-22549"
REGION="asia-southeast1"
SERVICE_NAME="autobot"
CLOUD_SQL_INSTANCE="autobot-prod-251215-22549:asia-southeast1:autobot-db"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "============================================"
echo "🚀 Deploy Application to Cloud Run"
echo "============================================"
echo ""
echo "Project: ${PROJECT_ID}"
echo "Service: ${SERVICE_NAME}"
echo "Region: ${REGION}"
echo ""

# ============================================
# Pre-deployment Checks
# ============================================
echo -e "${BLUE}🔍 Running pre-deployment checks...${NC}"
echo ""

# Check for hardcoded paths
if [ -f "./scripts/fix-hardcoded-paths.sh" ]; then
    if ! bash ./scripts/fix-hardcoded-paths.sh > /dev/null 2>&1; then
        echo -e "${RED}❌ Found hardcoded image/asset paths!${NC}"
        echo ""
        bash ./scripts/fix-hardcoded-paths.sh
        echo ""
        echo -e "${YELLOW}Please fix the above issues before deploying.${NC}"
        exit 1
    fi
    echo -e "${GREEN}✅ No hardcoded paths found${NC}"
else
    echo -e "${YELLOW}⚠️  Skipping path check (script not found)${NC}"
fi
echo ""

# Confirm deployment
echo -e "${YELLOW}⚠️  This will deploy to PRODUCTION${NC}"
echo ""
if [ "$AUTO_YES" = "1" ]; then
  echo "AUTO_YES=1 -> auto-confirmed"
else
  read -p "Continue? (yes/no): " -r
  echo ""
  if [[ ! $REPLY =~ ^[Yy]es$ ]]; then
      echo "Deployment cancelled."
      exit 0
  fi
fi

# Step 1: Build and Deploy
echo "============================================"
echo "🏗️  Step 1: Building and Deploying..."
echo "============================================"
echo ""

gcloud run deploy ${SERVICE_NAME} \
  --source . \
  --region=${REGION} \
  --platform=managed \
  --allow-unauthenticated \
  --port=8080 \
  --add-cloudsql-instances="${CLOUD_SQL_INSTANCE}" \
  --set-env-vars="APP_ENV=production,FACEBOOK_VERIFY_TOKEN=autobot_verify_2024,APP_URL=https://autobot.boxdesign.in.th" \
  --project=${PROJECT_ID} || {
    echo -e "${RED}❌ Deployment failed!${NC}"
    exit 1
}

echo ""
echo -e "${GREEN}✅ Application deployed successfully!${NC}"
echo ""

# Step 2: Get service URL
echo "============================================"
echo "🔍 Step 2: Getting service URL..."
echo "============================================"
echo ""

SERVICE_URL=$(gcloud run services describe ${SERVICE_NAME} \
  --region=${REGION} \
  --project=${PROJECT_ID} \
  --format='value(status.url)')

echo -e "${BLUE}Service URL: ${SERVICE_URL}${NC}"
echo ""

# Step 3: Verify deployment
echo "============================================"
echo "✅ Step 3: Verifying deployment..."
echo "============================================"
echo ""

echo "Testing health endpoint..."
# Capture both body and status code (last line)
HEALTH_RAW=$(curl -sS -w "\n%{http_code}" "${SERVICE_URL}/api/health.php" || true)
HEALTH_BODY=$(echo "$HEALTH_RAW" | sed '$d')
HEALTH_CODE=$(echo "$HEALTH_RAW" | tail -n 1)

# Extract status robustly (handles pretty-printed JSON with spaces/newlines)
HEALTH_STATUS=$(php -r '$in = stream_get_contents(STDIN); $j = json_decode($in, true); echo is_array($j) && isset($j["status"]) ? $j["status"] : "";' <<< "$HEALTH_BODY" 2>/dev/null || true)
if [ -z "$HEALTH_STATUS" ]; then
  HEALTH_STATUS=$(echo "$HEALTH_BODY" | grep -Eo '"status"\s*:\s*"[^"]+"' | head -n 1 | sed -E 's/.*"status"\s*:\s*"([^"]+)".*/\1/')
fi

if [ "$HEALTH_CODE" = "200" ] && [ "$HEALTH_STATUS" = "healthy" ]; then
    echo -e "${GREEN}✅ Health check passed!${NC}"
else
    echo -e "${RED}❌ Health check failed!${NC}"
    echo "HTTP: ${HEALTH_CODE}"
    echo "Parsed status: ${HEALTH_STATUS}"
    echo "Response: ${HEALTH_BODY}"
fi

echo ""
echo "Testing login endpoint..."
# App serves the public folder at web root; login is /login.html, not /public/login.html
LOGIN_TEST=$(curl -s -o /dev/null -w "%{http_code}" "${SERVICE_URL}/login.html")

if [ "$LOGIN_TEST" = "200" ] || [ "$LOGIN_TEST" = "301" ] || [ "$LOGIN_TEST" = "302" ]; then
    echo -e "${GREEN}✅ Login page accessible!${NC} (HTTP ${LOGIN_TEST})"
else
    echo -e "${YELLOW}⚠️  Login page returned: ${LOGIN_TEST}${NC}"
fi

echo ""
echo "============================================"
echo "✅ DEPLOYMENT COMPLETE!"
echo "============================================"
echo ""
echo "🌐 Production URLs:"
echo "   Service: ${SERVICE_URL}"
echo "   Login: ${SERVICE_URL}/login.html"
echo "   API Health: ${SERVICE_URL}/api/health.php"
echo ""
echo "👤 Test Account:"
echo "   Email: test1@gmail.com"
echo "   Password: password123"
echo ""
echo "📊 Monitoring:"
echo "   Logs: gcloud run services logs tail ${SERVICE_NAME} --project=${PROJECT_ID}"
echo "   Metrics: https://console.cloud.google.com/run/detail/${REGION}/${SERVICE_NAME}/metrics?project=${PROJECT_ID}"
echo ""
