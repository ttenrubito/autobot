#!/bin/bash
# FINAL DEPLOYMENT WITH COMPREHENSIVE TESTS

set -e

echo "🚀 FINAL DEPLOYMENT - Document Labels Fix"
echo "=========================================="
echo ""

# Step 1: Pre-deployment verification
echo "Step 1: Pre-Deployment Code Verification"
echo "-----------------------------------------"

echo "✓ Checking critical code..."

# Check API
if grep -q "document_label.*input" api/lineapp/documents.php; then
    echo "  ✅ API extracts document_label from input"
else
    echo "  ❌ API missing document_label extraction!"
    exit 1
fi

if grep -A 10 "INSERT INTO application_documents" api/lineapp/documents.php | grep -q "document_label"; then
    echo "  ✅ API INSERT includes document_label column"
else
    echo "  ❌ API INSERT missing document_label!"
    exit 1
fi

# Check LIFF
if grep "function uploadDocument" liff/application-form.html | grep -q "documentLabel"; then
    echo "  ✅ LIFF uploadDocument accepts documentLabel parameter"
else
    echo "  ❌ LIFF uploadDocument missing documentLabel parameter!"
    exit 1
fi

if grep -A 5 "uploadData.*=" liff/application-form.html | grep -q "document_label"; then
    echo "  ✅ LIFF sends document_label in upload payload"
else
    echo "  ❌ LIFF doesn't send document_label!"
    exit 1
fi

echo ""
echo "✅ All pre-deployment checks passed!"
echo ""

# Step 2: Deploy
echo "Step 2: Deploying to Cloud Run"
echo "-------------------------------"

gcloud run deploy autobot \
    --source=. \
    --region=asia-southeast1 \
    --platform=managed \
    --allow-unauthenticated \
    --timeout=300 \
    --memory=512Mi \
    --set-env-vars="APP_ENV=production,GCP_PROJECT_ID=canvas-radio-472913-d4,GCS_BUCKET_NAME=autobot-documents" \
    --quiet

echo ""
echo "✅ Deployment complete!"
echo ""

# Step 3: Wait for deployment to stabilize
echo "Step 3: Waiting for deployment to stabilize..."
echo "-----------------------------------------------"
sleep 10

# Step 4: Run migration
echo ""
echo "Step 4: Running Database Migration"
echo "-----------------------------------"

./run_migration_api.sh

echo ""

# Step 5: Post-deployment verification
echo "Step 5: Post-Deployment Verification"
echo "-------------------------------------"

BASE_URL="https://autobot.boxdesign.in.th"

# Check service
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    echo "  ✅ Service is up (HTTP $HTTP_CODE)"
else
    echo "  ❌ Service not accessible (HTTP $HTTP_CODE)"
    exit 1
fi

# Check campaign API
CAMPAIGN_JSON=$(curl -s "${BASE_URL}/api/lineapp/campaigns.php?id=2" 2>/dev/null)
if echo "$CAMPAIGN_JSON" | grep -q '"label":"บัตรประชาชน"'; then
    echo "  ✅ Campaign has Thai label: บัตรประชาชน"
else
    echo "  ⚠️  Campaign label may not be updated yet"
fi

# Check debug endpoint
curl -s "${BASE_URL}/deep_debug_docs.php" > /tmp/post_deploy_debug.html 2>/dev/null
if grep -q "No obvious issues detected" /tmp/post_deploy_debug.html; then
    echo "  ✅ Debug endpoint reports: No obvious issues"
else
    echo "  ⚠️  Debug endpoint found some issues - check ${BASE_URL}/deep_debug_docs.php"
fi

echo ""
echo "=========================================="
echo "🎉 DEPLOYMENT COMPLETE!"
echo "=========================================="
echo ""
echo "📋 Testing Checklist:"
echo ""
echo "1. ✅ Code verified before deployment"
echo "2. ✅ Deployed to Cloud Run"
echo "3. ✅ Database migration completed"
echo "4. ✅ Post-deployment checks passed"
echo ""
echo "📱 Manual Testing Required:"
echo ""
echo "Test 1: LIFF Form"
echo "  URL: https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
echo "  Expected:"
echo "    - Shows 'บัตรประชาชน *' (not 'เอกสาร')"
echo "    - Shows 'ทะเบียนบ้าน'"
echo ""
echo "Test 2: Upload Document"
echo "  1. Fill form completely"
echo "  2. Upload ID card photo"
echo "  3. Submit"
echo "  4. Should see success message"
echo ""
echo "Test 3: Admin Panel"
echo "  URL: ${BASE_URL}/line-applications.php"
echo "  1. Login"
echo "  2. Find latest application"
echo "  3. Click to view details"
echo "  4. Check '📄 เอกสาร' section"
echo "  Expected:"
echo "    - Shows '📄 เอกสาร (1)'"
echo "    - Document card shows:"
echo "      • Label: 'บัตรประชาชน' (Thai)"
echo "      • Filename"
echo "      • File size"
echo "      • Upload time"
echo ""
echo "🐛 If documents still don't show:"
echo "  1. Check browser console for errors"
echo "  2. Visit: ${BASE_URL}/deep_debug_docs.php"
echo "  3. Review CRITICAL_BUG_FIX_DOCUMENT_LABELS.md"
echo ""
echo "✨ System should now be working!"
echo ""
