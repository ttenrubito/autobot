#!/bin/bash
# ============================================================================
# Deploy Installment Fix Migration to Production
# ============================================================================
# 
# This script will deploy the installment fix migration to Cloud SQL
# to change DEFAULT total_periods from 12 to 3
#
# Usage: ./deploy_installment_fix.sh
# ============================================================================

set -e

# Configuration
PROJECT_ID="autobot-prod-251215-22549"
INSTANCE_NAME="autobot-db"
DB_NAME="autobot"
MIGRATION_FILE="database/migrations/2026_01_18_fix_installment_defaults.sql"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo ""
echo -e "${BLUE}============================================${NC}"
echo -e "${BLUE}🔧 Deploy Installment Fix Migration${NC}"
echo -e "${BLUE}============================================${NC}"
echo ""
echo -e "Project:    ${YELLOW}${PROJECT_ID}${NC}"
echo -e "Instance:   ${YELLOW}${INSTANCE_NAME}${NC}"
echo -e "Database:   ${YELLOW}${DB_NAME}${NC}"
echo -e "Migration:  ${YELLOW}${MIGRATION_FILE}${NC}"
echo ""

# Check if migration file exists
if [ ! -f "${MIGRATION_FILE}" ]; then
    echo -e "${RED}❌ Error: Migration file not found: ${MIGRATION_FILE}${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Migration file found${NC}"
echo ""
echo "Migration content:"
echo "----------------------------------------"
cat "${MIGRATION_FILE}"
echo "----------------------------------------"
echo ""

# Confirm
if [ "${AUTO_YES}" != "1" ]; then
    read -p "Deploy this migration to production? (y/N): " confirm
    if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
        echo "Cancelled."
        exit 0
    fi
fi

echo ""
echo -e "${YELLOW}📤 Deploying migration...${NC}"

# Method 1: Try using Cloud SQL Proxy if available
if command -v cloud_sql_proxy &> /dev/null; then
    echo "Using Cloud SQL Proxy..."
    # Start proxy in background
    cloud_sql_proxy -instances=${PROJECT_ID}:asia-southeast1:${INSTANCE_NAME}=tcp:3307 &
    PROXY_PID=$!
    sleep 3
    
    # Run migration
    mysql -h 127.0.0.1 -P 3307 -u root -p ${DB_NAME} < "${MIGRATION_FILE}"
    
    # Stop proxy
    kill $PROXY_PID 2>/dev/null || true
    
elif command -v gcloud &> /dev/null; then
    # Method 2: Use gcloud sql connect
    echo "Using gcloud sql connect..."
    echo ""
    echo -e "${YELLOW}⚠️  You will be prompted for the database password${NC}"
    echo ""
    
    # Create a temp file with just the essential SQL
    TEMP_SQL=$(mktemp)
    cat > "${TEMP_SQL}" << 'EOF'
-- Fix DEFAULT total_periods from 12 to 3
ALTER TABLE installment_contracts 
MODIFY COLUMN total_periods INT NOT NULL DEFAULT 3 
COMMENT '3 งวด ภายใน 60 วัน (ตามนโยบายร้าน)';

-- Update any contracts with wrong defaults
UPDATE installment_contracts 
SET total_periods = 3, updated_at = NOW()
WHERE total_periods = 12 
AND paid_periods = 0
AND status IN ('pending', 'active');

-- Verify
SELECT 'Migration completed' as status, 
       (SELECT COUNT(*) FROM installment_contracts WHERE total_periods = 3) as contracts_with_3_periods;
EOF
    
    echo "SQL to execute:"
    cat "${TEMP_SQL}"
    echo ""
    
    # Run via gcloud
    gcloud sql connect ${INSTANCE_NAME} \
        --database=${DB_NAME} \
        --project=${PROJECT_ID} \
        --user=root < "${TEMP_SQL}"
    
    rm -f "${TEMP_SQL}"
    
else
    echo -e "${RED}❌ Error: Neither cloud_sql_proxy nor gcloud CLI found${NC}"
    echo ""
    echo "Please run this SQL manually in Cloud Console:"
    echo "https://console.cloud.google.com/sql/instances/${INSTANCE_NAME}/databases?project=${PROJECT_ID}"
    echo ""
    echo "SQL to run:"
    echo "----------------------------------------"
    cat "${MIGRATION_FILE}"
    echo "----------------------------------------"
    exit 1
fi

echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}✅ Migration deployed successfully!${NC}"
echo -e "${GREEN}============================================${NC}"
