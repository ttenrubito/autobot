#!/bin/bash
# Post-Deployment Test - Document Labels Fix

echo "🧪 Testing Document Labels Fix"
echo "==============================="
echo ""

BASE_URL="https://autobot.boxdesign.in.th"

# Wait for deployment
echo "⏳ Waiting for deployment (max 2 minutes)..."
for i in {1..24}; do
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL" 2>/dev/null)
    if [ "$HTTP_CODE" = "200" ]; then
        echo "✅ Service is up!"
        break
    fi
    echo -n "."
    sleep 5
done
echo ""

# Test 1: Fix campaign labels
echo ""
echo "Test 1: Fixing Campaign Labels"
echo "--------------------------------"
echo "Running migration..."
./run_migration_api.sh > /tmp/migration_result.txt 2>&1

if grep -q "Done" /tmp/migration_result.txt; then
    echo "✅ Migration completed"
else
    echo "⚠️  Check migration output:"
    cat /tmp/migration_result.txt
fi

# Test 2: Verify campaign API
echo ""
echo "Test 2: Campaign API Response"
echo "------------------------------"
CAMPAIGN_JSON=$(curl -s "${BASE_URL}/api/lineapp/campaigns.php?id=2")

echo "$CAMPAIGN_JSON" | grep -q '"label":"บัตรประชาชน"' && \
    echo "✅ Campaign has Thai label: บัตรประชาชน" || \
    echo "❌ Campaign label still empty!"

echo "$CAMPAIGN_JSON" | grep -q '"label":"ทะเบียนบ้าน"' && \
    echo "✅ Campaign has Thai label: ทะเบียนบ้าน" || \
    echo "⚠️  Second label may be missing"

# Test 3: Check deep debug
echo ""
echo "Test 3: Deep Debug Endpoint"
echo "----------------------------"
curl -s "${BASE_URL}/deep_debug_docs.php" > /tmp/debug_result.html

if grep -q "Issue Analysis" /tmp/debug_result.html; then
    echo "✅ Debug endpoint accessible"
    
    # Extract issues
    if grep -q "No obvious issues detected" /tmp/debug_result.html; then
        echo "✅ No issues detected in database"
    else
        echo "⚠️  Issues found:"
        grep -A 5 "Issues Found:" /tmp/debug_result.html | sed 's/<[^>]*>//g' | grep -v "^$"
    fi
else
    echo "⚠️  Debug endpoint may not be deployed yet"
fi

echo ""
echo "========================================="
echo "📋 Next Steps"
echo "========================================="
echo ""
echo "1. Open LIFF in LINE app:"
echo "   https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
echo ""
echo "2. Verify fields show:"
echo "   ✅ บัตรประชาชน *"
echo "   ✅ ทะเบียนบ้าน"
echo ""
echo "3. Upload test document and submit"
echo ""
echo "4. Check admin panel:"
echo "   ${BASE_URL}/line-applications.php"
echo ""
echo "5. Open latest application"
echo "   → Documents section MUST show:"
echo "     ✅ Document type with Thai label"
echo "     ✅ Filename and size"
echo "     ✅ Upload timestamp"
echo ""
echo "6. View debug page:"
echo "   ${BASE_URL}/deep_debug_docs.php"
echo ""
