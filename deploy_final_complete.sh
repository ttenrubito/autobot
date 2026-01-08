#!/bin/bash
set -e

echo "🚀 Final Complete Deployment - LIFF GCS + Campaign Fix"
echo "======================================================"
echo ""

PROJECT_ID="autobot-prod-251215-22549"
REGION="asia-southeast1"
SERVICE="autobot"

echo "📦 Building and deploying to Cloud Run..."
gcloud run deploy ${SERVICE} \
    --source=. \
    --region=${REGION} \
    --platform=managed \
    --allow-unauthenticated \
    --timeout=300 \
    --memory=512Mi \
    --project=${PROJECT_ID} \
    --set-env-vars="APP_ENV=production,GCP_PROJECT_ID=${PROJECT_ID},GCS_BUCKET_NAME=autobot-documents" \
    --quiet

echo ""
echo "✅ Deployment completed!"
echo ""
echo "🔧 Now fixing campaign labels via deployed endpoint..."
sleep 3

# Call fix endpoint
FIX_URL="https://autobot.boxdesign.in.th/api/admin/fix-campaign-labels.php?secret=fix_demo2026_labels_now"
echo "📍 Opening: $FIX_URL"
echo ""

curl -s "$FIX_URL" > fix_result.html

if grep -q "Update Successful" fix_result.html; then
    echo "✅ Campaign labels fixed successfully!"
    echo ""
    grep -A 5 "Next Steps:" fix_result.html | sed 's/<[^>]*>//g' | sed 's/&nbsp;/ /g'
else
    echo "⚠️  Fix may need manual verification. Check: $FIX_URL"
fi

echo ""
echo "🎉 DEPLOYMENT COMPLETE!"
echo ""
echo "📍 Test URLs:"
echo "   • LIFF Form: https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
echo "   • Admin Panel: https://autobot.boxdesign.in.th/line-applications.php"
echo "   • Debug Docs: https://autobot.boxdesign.in.th/api/debug/check-documents.php"
echo "   • Fix Result: file://$(pwd)/fix_result.html"
echo ""
echo "✨ System is ready for testing!"
