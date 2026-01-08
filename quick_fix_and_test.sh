#!/bin/bash
# Quick Fix and Test - LIFF Documents Display in Admin

set -e

BASE_URL="https://autobot.boxdesign.in.th"

echo "🚀 Quick Fix and Test - LIFF Documents"
echo "======================================="
echo ""

# Step 1: Fix Campaign Labels
echo "Step 1: Fixing Campaign Labels..."
echo "-----------------------------------"

FIX_URL="${BASE_URL}/api/admin/fix-campaign-labels.php?secret=fix_demo2026_labels_now"

echo "📍 Calling fix endpoint..."
RESPONSE=$(curl -s "$FIX_URL")

if echo "$RESPONSE" | grep -q "Update Successful"; then
    echo "✅ Campaign labels fixed!"
    
    # Extract verification info
    echo ""
    echo "📊 Verification:"
    echo "$RESPONSE" | grep -A 3 "Verified New State" | sed 's/<[^>]*>//g' | head -10
    
elif echo "$RESPONSE" | grep -q "Route not found"; then
    echo "⚠️  Fix endpoint not deployed yet."
    echo "   Waiting for deployment to complete..."
    exit 1
else
    echo "❌ Unexpected response:"
    echo "$RESPONSE" | head -20
    exit 1
fi

echo ""

# Step 2: Test Campaign API
echo "Step 2: Testing Campaign API..."
echo "--------------------------------"

CAMPAIGN_JSON=$(curl -s "${BASE_URL}/api/lineapp/campaigns.php?id=2")

if echo "$CAMPAIGN_JSON" | grep -q '"label":"บัตรประชาชน"'; then
    echo "✅ Campaign API returns correct Thai labels"
else
    echo "⚠️  Labels may not be updated yet"
    echo "Response preview:"
    echo "$CAMPAIGN_JSON" | head -10
fi

echo ""

# Step 3: Check Debug Endpoint
echo "Step 3: Checking Documents Debug..."
echo "------------------------------------"

DEBUG_URL="${BASE_URL}/api/debug/check-documents.php"
curl -s "$DEBUG_URL" > /tmp/debug_docs.html

if grep -q "Documents Debug" /tmp/debug_docs.html; then
    echo "✅ Debug endpoint accessible"
    
    # Count applications
    APP_COUNT=$(grep -c "Application #" /tmp/debug_docs.html || echo "0")
    DOC_COUNT=$(grep -c "Doc #" /tmp/debug_docs.html || echo "0")
    
    echo "   Applications found: $APP_COUNT"
    echo "   Documents found: $DOC_COUNT"
    
    if [ "$DOC_COUNT" -gt 0 ]; then
        echo ""
        echo "📄 Sample document info:"
        grep -A 2 "Type:" /tmp/debug_docs.html | head -6 | sed 's/<[^>]*>//g'
    fi
else
    echo "⚠️  Debug endpoint not accessible"
fi

echo ""

# Summary
echo "========================================="
echo "✅ System Ready for Testing!"
echo "========================================="
echo ""
echo "📱 Test URLs:"
echo ""
echo "1. LIFF Form (Open in LINE app):"
echo "   https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
echo ""
echo "2. Admin Panel (Browser):"
echo "   ${BASE_URL}/line-applications.php"
echo ""
echo "3. Debug Documents (Browser):"
echo "   ${DEBUG_URL}"
echo ""
echo "📋 Testing Steps:"
echo ""
echo "   ✓ Open LIFF in LINE"
echo "   ✓ Verify fields show: 'บัตรประชาชน' and 'ทะเบียนบ้าน'"
echo "   ✓ Fill form and upload ID card image"
echo "   ✓ Submit application"
echo "   ✓ Check admin panel - documents should appear"
echo "   ✓ View document details - should show file info"
echo ""
echo "🎉 Ready to test!"
