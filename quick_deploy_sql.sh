#!/bin/bash
# ============================================================================
# Quick Deploy SQL to Production (No Backup)
# ============================================================================
# 
# This script deploys SQL directly without backup
# Use this if you don't need automatic backup
#
# ============================================================================

set -e

# Configuration
PROJECT_ID="autobot-prod-251215-22549"
REGION="asia-southeast1"
INSTANCE_NAME="autobot-db"
DB_NAME="autobot"
SQL_FILE="database/DEPLOY_CHATBOT_COMMERCE.sql"
TEMP_BUCKET="gs://${PROJECT_ID}_cloudbuild"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo "============================================"
echo "🚀 Quick Deploy SQL to Production"
echo "============================================"
echo ""
echo "Project: ${PROJECT_ID}"
echo "Instance: ${INSTANCE_NAME}"
echo "Database: ${DB_NAME}"
echo ""

# Check if SQL file exists
if [ ! -f "${SQL_FILE}" ]; then
    echo -e "${RED}❌ Error: SQL file not found${NC}"
    exit 1
fi

echo -e "${YELLOW}⚠️  Deploying to PRODUCTION (no backup)${NC}"
echo ""
read -p "Continue? (yes/no): " -r
echo ""
if [[ ! $REPLY =~ ^[Yy]es$ ]]; then
    echo "Cancelled."
    exit 0
fi

# Upload SQL file
echo "📤 Uploading SQL file..."
gsutil cp ${SQL_FILE} ${TEMP_BUCKET}/DEPLOY_CHATBOT_COMMERCE.sql || {
    echo -e "${RED}❌ Upload failed. Creating bucket...${NC}"
    gsutil mb -p ${PROJECT_ID} ${TEMP_BUCKET} || {
        echo -e "${RED}❌ Cannot create bucket${NC}"
        exit 1
    }
    gsutil cp ${SQL_FILE} ${TEMP_BUCKET}/DEPLOY_CHATBOT_COMMERCE.sql
}

echo -e "${GREEN}✅ Uploaded${NC}"
echo ""

# Import SQL
echo "📦 Importing SQL to Cloud SQL..."
gcloud sql import sql ${INSTANCE_NAME} \
  ${TEMP_BUCKET}/DEPLOY_CHATBOT_COMMERCE.sql \
  --database=${DB_NAME} \
  --project=${PROJECT_ID} || {
    echo -e "${RED}❌ Import failed!${NC}"
    exit 1
}

echo -e "${GREEN}✅ SQL imported successfully!${NC}"
echo ""

# Verify
echo "🔍 Verifying..."
gcloud sql connect ${INSTANCE_NAME} \
  --user=root \
  --project=${PROJECT_ID} \
  --quiet << 'EOF'
USE autobot;
SELECT COUNT(*) as conversations FROM conversations;
SELECT COUNT(*) as orders FROM orders;
SELECT COUNT(*) as payments FROM payments;
SELECT COUNT(*) as addresses FROM customer_addresses;
SELECT email, full_name FROM users WHERE email = 'test1@gmail.com';
EOF

echo ""
echo -e "${GREEN}✅ DEPLOYMENT COMPLETE!${NC}"
echo ""
echo "Test Account: test1@gmail.com / password123"
echo ""
