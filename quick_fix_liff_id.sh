#!/bin/bash
# Quick Fix: Update LIFF ID in Production Database

echo "🔧 Quick Fix: Update LIFF ID in Production"
echo "==========================================="
echo ""

# Configuration
PROJECT_ID="autobot-prod-251215-22549"
INSTANCE_NAME="autobot-db-prod"
DB_NAME="autobot_db"
DB_USER="autobot_user"

echo "📋 What you need:"
echo "1. Campaign Code (e.g., DEMO2026)"
echo "2. LIFF ID from LINE Developers Console (e.g., 2006605048-y0Qx9abD)"
echo ""

# Prompt for campaign code
read -p "Enter Campaign Code: " CAMPAIGN_CODE

if [ -z "$CAMPAIGN_CODE" ]; then
    echo "❌ Campaign code is required!"
    exit 1
fi

# Prompt for LIFF ID
read -p "Enter LIFF ID: " LIFF_ID

if [ -z "$LIFF_ID" ]; then
    echo "❌ LIFF ID is required!"
    exit 1
fi

# Validate LIFF ID format (should be like: 1234567890-AbCdEfGh)
if ! [[ "$LIFF_ID" =~ ^[0-9]{10}-[A-Za-z0-9]{8,}$ ]]; then
    echo "⚠️  Warning: LIFF ID format looks unusual (expected: 1234567890-AbCdEfGh)"
    read -p "Continue anyway? (y/N): " CONFIRM
    if [ "$CONFIRM" != "y" ] && [ "$CONFIRM" != "Y" ]; then
        echo "❌ Cancelled"
        exit 1
    fi
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📝 Summary:"
echo "   Campaign: $CAMPAIGN_CODE"
echo "   LIFF ID: $LIFF_ID"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

read -p "Proceed with update? (y/N): " FINAL_CONFIRM
if [ "$FINAL_CONFIRM" != "y" ] && [ "$FINAL_CONFIRM" != "Y" ]; then
    echo "❌ Cancelled"
    exit 1
fi

echo ""
echo "🔄 Connecting to Production Database..."
echo ""

# Create SQL commands
SQL_COMMANDS="
-- Check current state
SELECT 'Current State:' as info;
SELECT id, code, name, liff_id, is_active 
FROM campaigns 
WHERE code = '$CAMPAIGN_CODE';

-- Update LIFF ID
UPDATE campaigns 
SET liff_id = '$LIFF_ID',
    updated_at = NOW()
WHERE code = '$CAMPAIGN_CODE';

-- Verify update
SELECT 'After Update:' as info;
SELECT id, code, name, liff_id, is_active 
FROM campaigns 
WHERE code = '$CAMPAIGN_CODE';

-- Show LIFF URL
SELECT 
    CONCAT('LIFF URL: https://liff.line.me/', liff_id, '?campaign=', code) as liff_url
FROM campaigns 
WHERE code = '$CAMPAIGN_CODE';
"

# Execute on Cloud SQL
echo "$SQL_COMMANDS" | gcloud sql connect "$INSTANCE_NAME" \
    --user="$DB_USER" \
    --database="$DB_NAME" \
    --project="$PROJECT_ID" \
    --quiet

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ LIFF ID updated successfully!"
    echo ""
    echo "📱 Next Steps:"
    echo "1. Open LINE app"
    echo "2. Send message: สมัคร"
    echo "3. You should see LIFF URL: https://liff.line.me/$LIFF_ID?campaign=$CAMPAIGN_CODE"
    echo ""
else
    echo ""
    echo "❌ Failed to update LIFF ID"
    echo ""
    echo "💡 Alternative: Use Cloud Console SQL directly"
    echo "   https://console.cloud.google.com/sql/instances/$INSTANCE_NAME/query?project=$PROJECT_ID"
    echo ""
    echo "   Run this SQL:"
    echo "   UPDATE campaigns SET liff_id = '$LIFF_ID' WHERE code = '$CAMPAIGN_CODE';"
    echo ""
fi
