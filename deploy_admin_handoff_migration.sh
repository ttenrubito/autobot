#!/bin/bash
# ============================================================================
# Deploy Admin Handoff Migration to Localhost & Production
# ============================================================================
# 
# This script deploys the admin handoff timeout migration to:
# 1. Localhost database (XAMPP/LAMPP)
# 2. Production Cloud SQL
#
# Usage: ./deploy_admin_handoff_migration.sh
# ============================================================================

set -e  # Exit on error

# Configuration
PROJECT_ID="autobot-prod-251215-22549"
INSTANCE_NAME="autobot-db"
DB_NAME="autobot"
MIGRATION_FILE="database/migrations/add_admin_handoff_timeout.sql"
TEMP_BUCKET="gs://${PROJECT_ID}_cloudbuild"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo "============================================"
echo "🚀 Admin Handoff Migration Deployment"
echo "============================================"
echo ""

# Check if migration file exists
if [ ! -f "${MIGRATION_FILE}" ]; then
    echo -e "${RED}❌ Error: Migration file not found: ${MIGRATION_FILE}${NC}"
    exit 1
fi

echo "Migration file: ${MIGRATION_FILE}"
echo ""
echo "This will deploy to:"
echo "  1. Localhost (XAMPP/LAMPP)"
echo "  2. Production Cloud SQL"
echo ""

# ============================================================================
# STEP 1: Deploy to Localhost
# ============================================================================
echo "============================================"
echo "📍 Step 1: Deploying to LOCALHOST"
echo "============================================"
echo ""

# Try different MySQL paths
if [ -x "/opt/lampp/bin/mysql" ]; then
    MYSQL_CMD="/opt/lampp/bin/mysql"
elif command -v mysql &> /dev/null; then
    MYSQL_CMD="mysql"
else
    echo -e "${YELLOW}⚠️  MySQL command not found. Skipping localhost deployment.${NC}"
    echo -e "${YELLOW}    To deploy manually, run:${NC}"
    echo -e "${YELLOW}    mysql -u root autobot < ${MIGRATION_FILE}${NC}"
    echo ""
    MYSQL_CMD=""
fi

if [ -n "$MYSQL_CMD" ]; then
    echo "Running migration on localhost..."
    $MYSQL_CMD -u root autobot < ${MIGRATION_FILE} && {
        echo -e "${GREEN}✅ Localhost migration completed successfully!${NC}"
    } || {
        echo -e "${RED}❌ Localhost migration failed!${NC}"
        echo -e "${YELLOW}You may need to run manually:${NC}"
        echo -e "${YELLOW}mysql -u root autobot < ${MIGRATION_FILE}${NC}"
    }
    echo ""
fi

# ============================================================================
# STEP 2: Deploy to Production
# ============================================================================
echo "============================================"
echo "🌐 Step 2: Deploying to PRODUCTION"
echo "============================================"
echo ""

echo -e "${YELLOW}⚠️  WARNING: This will modify PRODUCTION database!${NC}"
echo ""
read -p "Continue with production deployment? (yes/no): " -r
echo ""
if [[ ! $REPLY =~ ^[Yy]es$ ]]; then
    echo "Production deployment cancelled."
    echo ""
    echo -e "${GREEN}✅ Localhost deployment completed!${NC}"
    echo ""
    echo "To deploy to production later, run:"
    echo "  ./deploy_admin_handoff_migration.sh"
    exit 0
fi

# Upload migration file to Cloud Storage
echo "📤 Uploading migration to Cloud Storage..."
gsutil cp ${MIGRATION_FILE} ${TEMP_BUCKET}/migrations/add_admin_handoff_timeout.sql || {
    echo -e "${YELLOW}Creating bucket...${NC}"
    gsutil mb -p ${PROJECT_ID} ${TEMP_BUCKET} 2>/dev/null || true
    gsutil cp ${MIGRATION_FILE} ${TEMP_BUCKET}/migrations/add_admin_handoff_timeout.sql
}

echo -e "${GREEN}✅ Uploaded${NC}"
echo ""

# Import to Cloud SQL
echo "📦 Running migration on Cloud SQL..."
gcloud sql import sql ${INSTANCE_NAME} \
  ${TEMP_BUCKET}/migrations/add_admin_handoff_timeout.sql \
  --database=${DB_NAME} \
  --project=${PROJECT_ID} \
  --quiet || {
    echo -e "${RED}❌ Production migration failed!${NC}"
    exit 1
}

echo -e "${GREEN}✅ Production migration completed successfully!${NC}"
echo ""

# Verify
echo "🔍 Verifying migration..."
gcloud sql connect ${INSTANCE_NAME} \
  --user=root \
  --project=${PROJECT_ID} \
  --quiet << 'EOF'
USE autobot;
DESCRIBE chat_sessions;
SELECT COUNT(*) as sessions_with_admin_flag 
FROM chat_sessions 
WHERE last_admin_message_at IS NOT NULL;
EXIT;
EOF

echo ""
echo "============================================"
echo "✅ MIGRATION COMPLETE!"
echo "============================================"
echo ""
echo "Summary:"
echo "  ✅ Localhost: Deployed"
echo "  ✅ Production: Deployed"
echo ""
echo "Column added: last_admin_message_at (TIMESTAMP NULL)"
echo ""
echo "Next steps:"
echo "  1. For LINE: Add admin_user_ids to bot profile config"
echo "  2. Test Facebook admin intervention"
echo "  3. Test LINE admin intervention"
echo "  4. Monitor logs for [ADMIN_HANDOFF] entries"
echo ""
