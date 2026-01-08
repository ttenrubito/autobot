#!/bin/bash
# Quick diagnostic and fix for admin handoff not working

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

PROJECT="autobot-prod-251215-22549"
REGION="asia-southeast1"
SERVICE="autobot"
INSTANCE="autobot-db"
DATABASE="autobot"

echo "=================================================="
echo "🔍 Admin Handoff Diagnostic Tool"
echo "=================================================="
echo ""

# Check 1: Latest deployment
echo -e "${BLUE}1️⃣ Checking latest deployment...${NC}"
LATEST_REVISION=$(gcloud run services describe $SERVICE \
  --region=$REGION \
  --project=$PROJECT \
  --format="value(status.latestReadyRevisionName)" 2>/dev/null || echo "ERROR")

if [ "$LATEST_REVISION" = "ERROR" ]; then
    echo -e "${RED}❌ Failed to get service info${NC}"
else
    echo -e "${GREEN}✅ Latest revision: $LATEST_REVISION${NC}"
    
    LAST_DEPLOY=$(gcloud run services describe $SERVICE \
      --region=$REGION \
      --project=$PROJECT \
      --format="value(metadata.annotations.'serving.knative.dev/lastModifierTime')" 2>/dev/null || echo "unknown")
    echo "   Last deployed: $LAST_DEPLOY"
fi
echo ""

# Check 2: Database column exists
echo -e "${BLUE}2️⃣ Checking database schema...${NC}"
echo "   Connecting to Cloud SQL..."
DB_CHECK=$(gcloud sql connect $INSTANCE \
  --project=$PROJECT \
  --database=$DATABASE \
  --quiet << 'SQL' 2>&1 | grep -i "last_admin" || echo "NOT_FOUND"
SHOW COLUMNS FROM chat_sessions LIKE 'last_admin_message_at';
SQL
)

if echo "$DB_CHECK" | grep -qi "last_admin_message_at"; then
    echo -e "${GREEN}✅ Column 'last_admin_message_at' exists${NC}"
else
    echo -e "${RED}❌ Column 'last_admin_message_at' NOT FOUND${NC}"
    echo ""
    echo -e "${YELLOW}Fix: Run migration:${NC}"
    echo "  gcloud sql connect $INSTANCE --project=$PROJECT --database=$DATABASE"
    echo "  Then run:"
    echo "    ALTER TABLE chat_sessions ADD COLUMN last_admin_message_at TIMESTAMP NULL;"
    echo ""
fi
echo ""

# Check 3: Recent logs
echo -e "${BLUE}3️⃣ Checking recent logs for admin handoff...${NC}"
LOGS=$(gcloud run services logs read $SERVICE \
  --region=$REGION \
  --project=$PROJECT \
  --limit=100 \
  --format="value(textPayload)" 2>/dev/null | grep -i "ADMIN_HANDOFF" | tail -5 || echo "")

if [ -z "$LOGS" ]; then
    echo -e "${YELLOW}⚠️  No admin handoff logs found in recent 100 entries${NC}"
    echo "   This means either:"
    echo "   - No one has used 'admin' command yet, OR"
    echo "   - Code with admin handoff is not deployed"
else
    echo -e "${GREEN}✅ Found admin handoff logs:${NC}"
    echo "$LOGS" | while read line; do echo "   $line"; done
fi
echo ""

# Check 4: Recent errors
echo -e "${BLUE}4️⃣ Checking for errors...${NC}"
ERRORS=$(gcloud run services logs read $SERVICE \
  --region=$REGION \
  --project=$PROJECT \
  --limit=50 \
  --format="table(timestamp,severity,textPayload)" 2>/dev/null \
  | grep -i "error\|fatal\|exception" | tail -5 || echo "")

if [ -z "$ERRORS" ]; then
    echo -e "${GREEN}✅ No recent errors${NC}"
else
    echo -e "${RED}❌ Found errors:${NC}"
    echo "$ERRORS"
fi
echo ""

# Summary and recommendations
echo "=================================================="
echo "📋 Summary & Recommendations"
echo "=================================================="
echo ""

if [ "$DB_CHECK" != "NOT_FOUND" ] && [ "$LATEST_REVISION" != "ERROR" ]; then
    echo -e "${GREEN}✅ System looks healthy${NC}"
    echo ""
    echo "Next steps:"
    echo "  1. Test manually by sending 'admin' in Facebook/LINE"
    echo "  2. Watch logs: gcloud run services logs tail $SERVICE --project=$PROJECT"
    echo "  3. Refer to ADMIN_HANDOFF_TEST_GUIDE.md for testing steps"
else
    echo -e "${RED}⚠️  Issues detected${NC}"
    echo ""
    if [ "$DB_CHECK" = "NOT_FOUND" ]; then
        echo "  🔧 Need to run database migration"
        echo "     ./deploy_migration_now.sh"
    fi
    if [ "$LATEST_REVISION" = "ERROR" ]; then
        echo "  🔧 Need to deploy code"
        echo "     ./deploy_app_to_production.sh"
    fi
fi
echo ""
