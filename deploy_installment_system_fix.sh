#!/bin/bash
# Deploy Installment System Fix
# 1. Run migration to add missing columns
# 2. Deploy code to production

set -e
cd /opt/lampp/htdocs/autobot

echo "=============================================="
echo "🚀 Deploy Installment System Fix"
echo "=============================================="

# Step 1: Check what we're deploying
echo ""
echo "📋 Changes to deploy:"
echo "  - api/customer/orders.php (create installment_contracts)"
echo "  - api/admin/payments.php (update installment_contracts on verify)"
echo "  - database/migrations/2026_01_18_consolidate_installment_tables.sql"
echo ""

# Step 2: Run migration on production first (via Cloud SQL)
echo "⚠️  IMPORTANT: Run this SQL on production BEFORE code deploy!"
echo ""
echo "Migration SQL:"
echo "=============================================="
cat database/migrations/2026_01_18_consolidate_installment_tables.sql
echo ""
echo "=============================================="
echo ""

# Check if AUTO_YES is set
if [ -z "$AUTO_YES" ]; then
    read -p "Have you run the migration SQL on production? (y/n): " answer
    if [ "$answer" != "y" ] && [ "$answer" != "Y" ]; then
        echo "❌ Please run migration first before deploying code!"
        echo ""
        echo "You can copy the SQL above and run it on Cloud SQL Console:"
        echo "https://console.cloud.google.com/sql/instances"
        exit 1
    fi
fi

# Step 3: Deploy code
echo ""
echo "📦 Deploying code to Cloud Run..."
echo ""

AUTO_YES=1 ./deploy_app_to_production.sh

echo ""
echo "✅ Deployment complete!"
echo ""
echo "📋 Post-deployment checklist:"
echo "  1. Test: Create order with installment from cases.php"
echo "  2. Test: Customer sends slip in chat"
echo "  3. Test: Admin verifies payment in payment-history"
echo "  4. Test: Check installments.php shows correct data"
echo ""
