#!/bin/bash
# ============================================================================
# Deploy platform_user_id Migration to Production (Cloud SQL)
# ============================================================================
# 
# This script will:
# 1. Upload migration SQL to Cloud Storage
# 2. Import to Cloud SQL
# 3. Verify the deployment
#
# Usage: ./deploy_platform_user_id_migration.sh
# ============================================================================

set -e  # Exit on error

# Configuration
PROJECT_ID="autobot-prod-251215-22549"
REGION="asia-southeast1"
INSTANCE_NAME="autobot-db"
DB_NAME="autobot"
SQL_FILE="database/migrations/2026_01_15_add_platform_user_id_columns.sql"
BACKUP_BUCKET="gs://${PROJECT_ID}-backups"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo ""
echo -e "${BLUE}============================================${NC}"
echo -e "${BLUE}🚀 Deploy platform_user_id Migration${NC}"
echo -e "${BLUE}============================================${NC}"
echo ""
echo "Project: ${PROJECT_ID}"
echo "Instance: ${INSTANCE_NAME}"
echo "Database: ${DB_NAME}"
echo "SQL File: ${SQL_FILE}"
echo ""

# Check if SQL file exists
if [ ! -f "${SQL_FILE}" ]; then
    echo -e "${RED}❌ Error: SQL file not found: ${SQL_FILE}${NC}"
    exit 1
fi

# Confirm deployment
echo -e "${YELLOW}⚠️  WARNING: This will deploy migration to PRODUCTION!${NC}"
echo ""
read -p "Continue? (yes/no): " -r
if [[ ! $REPLY =~ ^[Yy]es$ ]]; then
    echo "Cancelled."
    exit 0
fi

# Step 1: Upload SQL to Cloud Storage
echo ""
echo -e "${BLUE}📤 Step 1: Uploading SQL to Cloud Storage...${NC}"
gsutil cp ${SQL_FILE} ${BACKUP_BUCKET}/migrations/2026_01_15_add_platform_user_id_columns.sql

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Upload successful${NC}"
else
    echo -e "${RED}❌ Upload failed${NC}"
    exit 1
fi

# Step 2: Import SQL to Cloud SQL
echo ""
echo -e "${BLUE}📥 Step 2: Importing SQL to Cloud SQL...${NC}"
gcloud sql import sql ${INSTANCE_NAME} \
  ${BACKUP_BUCKET}/migrations/2026_01_15_add_platform_user_id_columns.sql \
  --database=${DB_NAME} \
  --project=${PROJECT_ID} \
  --quiet

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ SQL import successful${NC}"
else
    echo -e "${RED}❌ SQL import failed${NC}"
    exit 1
fi

# Step 3: Verify
echo ""
echo -e "${BLUE}🔍 Step 3: Verifying...${NC}"
echo ""

echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}✅ MIGRATION DEPLOYED SUCCESSFULLY!${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo "Changes applied:"
echo "  ✅ Added platform_user_id column to payments, orders, repairs, pawns"
echo "  ✅ Added platform column to payments, orders"
echo "  ✅ Added user_id column to repairs, pawns"
echo "  ✅ Created indexes for fast lookups"
echo "  ✅ Backfilled existing data"
echo ""
echo "Next: Deploy application code"
echo "  ./deploy_app_to_production.sh"
echo ""
