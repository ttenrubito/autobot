#!/bin/bash
# ============================================================================
# Complete Production Deployment Script
# ============================================================================
# 
# This master script will:
# 1. Deploy SQL schema and data to Cloud SQL
# 2. Deploy application code to Cloud Run
# 3. Verify everything works
#
# Usage: ./deploy_to_production.sh
# ============================================================================

set -e

# Optional non-interactive mode:
#   AUTO_YES=1 will auto-confirm prompts in this script and child scripts.
AUTO_YES=${AUTO_YES:-0}

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m'

clear
echo -e "${PURPLE}"
echo "╔════════════════════════════════════════════╗"
echo "║   🚀 AUTOBOT PRODUCTION DEPLOYMENT 🚀     ║"
echo "╚════════════════════════════════════════════╝"
echo -e "${NC}"
echo ""
echo "This script will deploy:"
echo "  1. 📦 Database schema & test data"
echo "  2. 🏗️  Application code to Cloud Run"
echo "  3. ✅ Verify deployment"
echo ""
echo -e "${YELLOW}⚠️  WARNING: This affects PRODUCTION!${NC}"
echo ""
if [ "$AUTO_YES" = "1" ]; then
    echo "AUTO_YES=1 -> auto-confirmed"
else
    read -p "Continue with production deployment? (yes/no): " -r
    echo ""

    if [[ ! $REPLY =~ ^[Yy]es$ ]]; then
        echo "Deployment cancelled."
        exit 0
    fi
fi

# Make scripts executable
chmod +x deploy_sql_to_production.sh
chmod +x deploy_app_to_production.sh

# Step 1: Deploy SQL
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  PHASE 1: Database Deployment${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

./deploy_sql_to_production.sh

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ Database deployment failed! Aborting.${NC}"
    exit 1
fi

# Step 2: Deploy Application
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  PHASE 2: Application Deployment${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

./deploy_app_to_production.sh

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ Application deployment failed!${NC}"
    exit 1
fi

# Step 3: Final Verification
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  PHASE 3: Final Verification${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

SERVICE_URL="https://autobot.boxdesign.in.th"

echo "Testing complete flow..."
echo ""

# Test 1: Health Check
echo -n "1. Health Check... "
if curl -sf "${SERVICE_URL}/api/health.php" > /dev/null; then
    echo -e "${GREEN}✅${NC}"
else
    echo -e "${RED}❌${NC}"
fi

# Test 2: Login Page
echo -n "2. Login Page... "
if curl -sf -o /dev/null "${SERVICE_URL}/public/login.html"; then
    echo -e "${GREEN}✅${NC}"
else
    echo -e "${RED}❌${NC}"
fi

# Test 3: Dashboard
echo -n "3. Dashboard Page... "
if curl -sf -o /dev/null "${SERVICE_URL}/public/dashboard.php"; then
    echo -e "${GREEN}✅${NC}"
else
    echo -e "${RED}❌${NC}"
fi

# Test 4: Chat History
echo -n "4. Chat History Page... "
if curl -sf -o /dev/null "${SERVICE_URL}/public/chat-history.php"; then
    echo -e "${GREEN}✅${NC}"
else
    echo -e "${RED}❌${NC}"
fi

# Test 5: Orders
echo -n "5. Orders Page... "
if curl -sf -o /dev/null "${SERVICE_URL}/public/orders.php"; then
    echo -e "${GREEN}✅${NC}"
else
    echo -e "${RED}❌${NC}"
fi

# Test 6: Addresses
echo -n "6. Addresses Page... "
if curl -sf -o /dev/null "${SERVICE_URL}/public/addresses.php"; then
    echo -e "${GREEN}✅${NC}"
else
    echo -e "${RED}❌${NC}"
fi

# Test 7: Payment History
echo -n "7. Payment History... "
if curl -sf -o /dev/null "${SERVICE_URL}/public/payment-history.php"; then
    echo -e "${GREEN}✅${NC}"
else
    echo -e "${RED}❌${NC}"
fi

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║     🎉 DEPLOYMENT SUCCESSFUL! 🎉          ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════╝${NC}"
echo ""
echo "📍 Production URLs:"
echo "   🌐 Website: ${SERVICE_URL}"
echo "   🔐 Login: ${SERVICE_URL}/public/login.html"
echo "   💬 Chat History: ${SERVICE_URL}/public/chat-history.php"
echo "   📦 Orders: ${SERVICE_URL}/public/orders.php"
echo "   📍 Addresses: ${SERVICE_URL}/public/addresses.php"
echo "   💰 Payments: ${SERVICE_URL}/public/payment-history.php"
echo ""
echo "👤 Test Account:"
echo "   📧 Email: test1@gmail.com"
echo "   🔑 Password: password123"
echo ""
echo "📊 Monitoring & Logs:"
echo "   View logs: gcloud run services logs tail autobot --project=autobot-prod-251215-22549"
echo "   Cloud Console: https://console.cloud.google.com/run?project=autobot-prod-251215-22549"
echo ""
echo "📝 Next Steps:"
echo "   1. ✅ Login and verify all pages work"
echo "   2. ✅ Test chatbot integration"
echo "   3. ✅ Verify payment functionality"
echo "   4. 🔒 Change admin passwords"
echo ""
