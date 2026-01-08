#!/bin/bash
# Post-Deployment Final Check and Fix

echo "🔍 Post-Deployment Check"
echo "========================"
echo ""

BASE_URL="https://autobot.boxdesign.in.th"

# Wait for deployment if needed
echo "⏳ Waiting for deployment to complete (max 2 minutes)..."
for i in {1..24}; do
    RESPONSE=$(curl -s "${BASE_URL}/api/admin/fix-campaign-labels.php?secret=test" 2>&1)
    
    if echo "$RESPONSE" | grep -q "Route not found"; then
        echo -n "."
        sleep 5
    else
        echo ""
        echo "✅ Deployment detected!"
        break
    fi
    
    if [ $i -eq 24 ]; then
        echo ""
        echo "⚠️  Deployment taking longer than expected"
        echo "   Please check: gcloud run services describe autobot --region=asia-southeast1"
        exit 1
    fi
done

echo ""
echo "🔧 Running fix and test..."
echo ""

# Run fix
./quick_fix_and_test.sh

echo ""
echo "========================================="
echo "✅ POST-DEPLOYMENT COMPLETE"
echo "========================================="
echo ""
echo "📱 Open LIFF and test:"
echo "   https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
echo ""
echo "💻 Check admin panel:"
echo "   ${BASE_URL}/line-applications.php"
echo ""
