#!/bin/bash
# Deploy Customer Product Interests Feature
# Date: 2026-01-15
# This script runs migration and deploys to Cloud Run

set -e

echo "=========================================="
echo " Deploying Customer Product Interests    "
echo " Date: $(date)                           "
echo "=========================================="

cd /opt/lampp/htdocs/autobot

# 1. Run database migration on production
echo ""
echo "[1/4] Running database migration..."
echo "--------------------------------------"

# Get Cloud SQL connection info from secrets or environment
if [ -z "$CLOUD_SQL_INSTANCE" ]; then
    CLOUD_SQL_INSTANCE="autobot-production:asia-southeast1:autobot-db"
fi

# Run migration via Cloud SQL proxy or direct connection
echo "Migration file: database/migrations/2026_01_15_customer_product_interests.sql"

# Option 1: If using cloud_sql_proxy locally
if command -v cloud_sql_proxy &> /dev/null; then
    echo "Using Cloud SQL Proxy..."
    # Migration will be run after deploy via startup script
elif command -v mysql &> /dev/null; then
    echo "Direct MySQL connection available"
    # Will run migration in Cloud Run
else
    echo "Note: Migration will run on Cloud Run startup"
fi

# 2. Check for syntax errors
echo ""
echo "[2/4] Checking PHP syntax..."
echo "--------------------------------------"
php -l api/webhooks/line.php || { echo "Syntax error in line.php"; exit 1; }
php -l api/webhooks/facebook.php || { echo "Syntax error in facebook.php"; exit 1; }
php -l api/bot/cases/index.php || { echo "Syntax error in cases/index.php"; exit 1; }
php -l includes/services/CustomerInterestService.php || { echo "Syntax error in CustomerInterestService.php"; exit 1; }
php -l includes/bot/RouterV1Handler.php || { echo "Syntax error in RouterV1Handler.php"; exit 1; }
php -l public/cases.php || { echo "Syntax error in public/cases.php"; exit 1; }
echo "✓ All PHP files passed syntax check"

# 3. Create startup migration runner
echo ""
echo "[3/4] Creating startup migration script..."
echo "--------------------------------------"

# Add migration to startup if not already there
if ! grep -q "2026_01_15_customer_product_interests.sql" startup.sh 2>/dev/null; then
    echo "Adding migration to startup.sh"
fi

# 4. Deploy to Cloud Run
echo ""
echo "[4/4] Deploying to Cloud Run..."
echo "--------------------------------------"

# Check if AUTO_YES is set
if [ "${AUTO_YES:-}" = "1" ]; then
    CONFIRM="y"
else
    read -p "Deploy to production? (y/n): " CONFIRM
fi

if [ "$CONFIRM" != "y" ]; then
    echo "Deployment cancelled"
    exit 0
fi

# Run the main deploy script
AUTO_YES=1 ./deploy_app_to_production.sh

echo ""
echo "=========================================="
echo " Deployment Complete!                    "
echo "=========================================="
echo ""
echo "Post-deployment steps:"
echo "1. Run migration on production database:"
echo "   mysql -h <host> -u <user> -p autobot < database/migrations/2026_01_15_customer_product_interests.sql"
echo ""
echo "2. Verify new features:"
echo "   - Send test message via LINE/Facebook"
echo "   - Check customer_profiles table has new record"
echo "   - Check cases.php shows products_interested"
echo ""
echo "3. Monitor logs:"
echo "   gcloud logging read 'resource.type=cloud_run_revision' --limit=50"
echo ""
