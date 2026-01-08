#!/bin/bash
###############################################################################
# Diagnostic Script - Check Why Admin Handoff Doesn't Work
###############################################################################

echo "🔍 Admin Handoff Diagnostic Report"
echo "===================================="
echo ""

# 1. Check local code
echo "1️⃣ LOCAL CODE CHECK"
echo "--------------------"
if grep -q "admin_handoff_manual_command" includes/bot/RouterV1Handler.php; then
    echo "✅ Admin handoff code EXISTS in local RouterV1Handler.php"
else
    echo "❌ Admin handoff code NOT FOUND in local RouterV1Handler.php"
fi

if grep -q "last_admin_message_at" includes/bot/RouterV1Handler.php; then
    echo "✅ Admin timeout code EXISTS in local RouterV1Handler.php"
else
    echo "❌ Admin timeout code NOT FOUND in local RouterV1Handler.php"
fi
echo ""

# 2. Check local database
echo "2️⃣ LOCAL DATABASE CHECK"
echo "------------------------"
COLUMN_EXISTS=$(/opt/lampp/bin/mysql -hlocalhost -uroot autobot -se \
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='autobot' AND TABLE_NAME='chat_sessions' AND COLUMN_NAME='last_admin_message_at';" 2>/dev/null || echo "0")

if [ "$COLUMN_EXISTS" -eq "1" ]; then
    echo "✅ Column 'last_admin_message_at' EXISTS in local database"
else
    echo "❌ Column 'last_admin_message_at' NOT FOUND in local database"
fi
echo ""

# 3. Check production deployment status
echo "3️⃣ PRODUCTION DEPLOYMENT STATUS"
echo "---------------------------------"
LATEST_REVISION=$(gcloud run services describe autobot \
    --region=asia-southeast1 \
    --project=autobot-prod-251215-22549 \
    --format="value(status.latestReadyRevisionName)" 2>/dev/null || echo "unknown")

if [ "$LATEST_REVISION" != "unknown" ]; then
    echo "✅ Latest revision: $LATEST_REVISION"
    
    # Get deployment time
    DEPLOY_TIME=$(gcloud run revisions describe "$LATEST_REVISION" \
        --region=asia-southeast1 \
        --project=autobot-prod-251215-22549 \
        --format="value(metadata.creationTimestamp)" 2>/dev/null || echo "unknown")
    echo "   Deployed at: $DEPLOY_TIME"
else
    echo "❌ Cannot fetch production revision info"
fi
echo ""

# 4. Check production database
echo "4️⃣ PRODUCTION DATABASE CHECK"
echo "------------------------------"
echo "⚠️  Checking if column exists in production DB..."

# Try to connect to Cloud SQL
PROD_DB_CHECK=$(gcloud sql databases list \
    --instance=autobot-db \
    --project=autobot-prod-251215-22549 \
    --format="value(name)" 2>/dev/null | grep -c "autobot" || echo "0")

if [ "$PROD_DB_CHECK" -gt "0" ]; then
    echo "✅ Production database 'autobot' found"
    echo "   To check column, run:"
    echo "   gcloud sql connect autobot-db --user=root --project=autobot-prod-251215-22549"
    echo "   USE autobot;"
    echo "   SHOW COLUMNS FROM chat_sessions LIKE 'last_admin_message_at';"
else
    echo "⚠️  Cannot verify production database automatically"
fi
echo ""

# 5. Check recent production logs
echo "5️⃣ PRODUCTION LOGS (Recent Activity)"
echo "--------------------------------------"
echo "Searching for admin-related activity..."

gcloud logging read \
    "resource.type=cloud_run_revision AND resource.labels.service_name=autobot AND (textPayload=~\"admin\" OR jsonPayload.message=~\"admin\")" \
    --limit=10 \
    --project=autobot-prod-251215-22549 \
    --format="table(timestamp, severity, textPayload)" \
    --freshness=2h 2>/dev/null || echo "⚠️  No recent admin-related logs found"

echo ""
echo "===================================="
echo "📋 DIAGNOSIS SUMMARY"
echo "===================================="
echo ""

# Determine the issue
if [ "$COLUMN_EXISTS" -ne "1" ]; then
    echo "🔴 ISSUE FOUND: Local database missing column!"
    echo "   FIX: Run ./run_migration_production.sh"
    echo ""
fi

if [ "$LATEST_REVISION" == "unknown" ]; then
    echo "🔴 ISSUE FOUND: Cannot verify production deployment!"
    echo "   FIX: Deploy with ./emergency_deploy.sh"
    echo ""
fi

echo "🎯 RECOMMENDED ACTIONS:"
echo "   1. Deploy code: FORCE=1 ./emergency_deploy.sh"
echo "   2. Check prod DB: gcloud sql connect autobot-db --user=root"
echo "   3. Run migration if needed: ALTER TABLE chat_sessions ADD COLUMN..."
echo "   4. Test: Send 'admin' message in Facebook"
echo "   5. Monitor: gcloud logging tail --service=autobot"
echo ""
