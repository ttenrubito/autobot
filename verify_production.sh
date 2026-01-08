#!/bin/bash

###############################################################################
# Production Verification Script
# Purpose: Verify admin handoff feature is deployed and working
###############################################################################

set -e

echo "🔍 Production Verification Report"
echo "=================================="
echo ""

# 1. Check Cloud Run deployment
echo "1️⃣ Cloud Run Service Status"
echo "----------------------------"
gcloud run services describe autobot \
    --region=asia-southeast1 \
    --format="table(
        status.url,
        status.conditions.status,
        status.latestReadyRevisionName,
        metadata.generation
    )" 2>/dev/null || echo "⚠️  Could not fetch Cloud Run status"

echo ""

# 2. Get latest revision details
echo "2️⃣ Latest Revision Info"
echo "------------------------"
LATEST_REVISION=$(gcloud run services describe autobot \
    --region=asia-southeast1 \
    --format="value(status.latestReadyRevisionName)" 2>/dev/null || echo "unknown")

echo "Latest Revision: $LATEST_REVISION"
echo ""

# 3. Check if RouterV1Handler.php has admin handoff code
echo "3️⃣ Code Verification (RouterV1Handler.php)"
echo "--------------------------------------------"
if [ -f includes/bot/RouterV1Handler.php ]; then
    if grep -q "last_admin_message_at" includes/bot/RouterV1Handler.php; then
        echo "✅ Admin handoff code found in RouterV1Handler.php"
    else
        echo "❌ Admin handoff code NOT found!"
    fi
else
    echo "⚠️  RouterV1Handler.php not found"
fi
echo ""

# 4. Check database migration status
echo "4️⃣ Database Migration Status"
echo "------------------------------"
if [ -f .env.production ]; then
    source .env.production
    
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="${DB_PORT:-3306}"
    DB_NAME="${DB_NAME:-autobot}"
    DB_USER="${DB_USER:-root}"
    
    COLUMN_EXISTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -D"$DB_NAME" -se \
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='chat_sessions' AND COLUMN_NAME='last_admin_message_at';" 2>/dev/null || echo "0")
    
    if [ "$COLUMN_EXISTS" -eq "1" ]; then
        echo "✅ Column 'last_admin_message_at' exists in production DB"
    else
        echo "❌ Column 'last_admin_message_at' NOT found in production DB"
        echo "⚠️  Migration required! Run: ./run_migration_production.sh"
    fi
else
    echo "⚠️  .env.production not found"
fi
echo ""

# 5. Check production logs for errors
echo "5️⃣ Recent Production Logs (last 50 lines)"
echo "-------------------------------------------"
gcloud logging read "resource.type=cloud_run_revision AND resource.labels.service_name=autobot" \
    --limit=50 \
    --format="table(timestamp, severity, textPayload)" \
    --freshness=1h 2>/dev/null || echo "⚠️  Could not fetch logs"

echo ""
echo "=================================="
echo "✅ Verification Complete"
echo ""
echo "📋 Next Steps:"
echo "  1. If DB migration needed: ./run_migration_production.sh"
echo "  2. Test admin command in Facebook/LINE"
echo "  3. Monitor logs: gcloud logging tail --service=autobot"
