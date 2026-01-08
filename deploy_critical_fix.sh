#!/bin/bash
set -e

echo "🚨 CRITICAL FIX - Document Labels Missing"
echo "=========================================="
echo ""
echo "Changes:"
echo "  1. API: Save document_label to database"
echo "  2. LIFF: Send document_label in upload request"
echo "  3. Migration: Fix campaign labels"
echo ""

# Deploy
echo "📦 Deploying to Cloud Run..."
gcloud run deploy autobot \
  --source=. \
  --region=asia-southeast1 \
  --allow-unauthenticated \
  --timeout=300 \
  --memory=512Mi \
  --quiet

echo ""
echo "✅ Deployment complete!"
echo ""

# Wait a bit for deployment to stabilize
echo "⏳ Waiting 10 seconds for deployment..."
sleep 10

# Fix campaign labels via REST API
echo ""
echo "🔧 Fixing campaign labels..."
./run_migration_api.sh

echo ""
echo "=========================================="
echo "✅ CRITICAL FIX COMPLETE!"
echo "=========================================="
echo ""
echo "📱 Test now:"
echo "   1. Open LIFF: https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
echo "   2. Should show 'บัตรประชาชน' and 'ทะเบียนบ้าน'"
echo "   3. Upload ID card photo"
echo "   4. Submit form"
echo "   5. Check admin: https://autobot.boxdesign.in.th/line-applications.php"
echo "   6. Documents MUST appear with correct labels"
echo ""
