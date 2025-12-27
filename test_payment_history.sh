#!/bin/bash
# Quick Test Script for Payment History Page
# Run this after deployment to verify all fixes

echo "╔═══════════════════════════════════════════════════════╗"
echo "║  🧪 Payment History Page - Quick Test Script        ║"
echo "╚═══════════════════════════════════════════════════════╝"
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

PROD_URL="https://autobot.boxdesign.in.th"

echo "🌐 Testing Production URL: $PROD_URL"
echo ""

# Test 1: Check if page loads
echo "Test 1: Payment History Page Loads"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$PROD_URL/payment-history.php")
if [ "$STATUS" -eq 200 ]; then
    echo -e "${GREEN}✅ PASS${NC} - Page loads (HTTP $STATUS)"
else
    echo -e "${RED}❌ FAIL${NC} - Page failed to load (HTTP $STATUS)"
fi
echo ""

# Test 2: Check if JavaScript file exists
echo "Test 2: Payment History JavaScript Exists"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
JS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$PROD_URL/assets/js/payment-history.js")
if [ "$JS_STATUS" -eq 200 ]; then
    echo -e "${GREEN}✅ PASS${NC} - JavaScript file exists (HTTP $JS_STATUS)"
else
    echo -e "${RED}❌ FAIL${NC} - JavaScript file missing (HTTP $JS_STATUS)"
fi
echo ""

# Test 3: Check if date filter HTML exists
echo "Test 3: Date Filter HTML Present"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
PAGE_CONTENT=$(curl -s "$PROD_URL/payment-history.php")
if echo "$PAGE_CONTENT" | grep -q "date-filter-container"; then
    echo -e "${GREEN}✅ PASS${NC} - Date filter HTML found"
else
    echo -e "${RED}❌ FAIL${NC} - Date filter HTML not found"
fi
echo ""

# Test 4: Check if date input fields exist
echo "Test 4: Date Input Fields Present"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if echo "$PAGE_CONTENT" | grep -q 'id="startDate"' && echo "$PAGE_CONTENT" | grep -q 'id="endDate"'; then
    echo -e "${GREEN}✅ PASS${NC} - Start and end date inputs found"
else
    echo -e "${RED}❌ FAIL${NC} - Date inputs missing"
fi
echo ""

# Test 5: Check if filter functions exist in JS
echo "Test 5: Date Filter Functions in JavaScript"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
JS_CONTENT=$(curl -s "$PROD_URL/assets/js/payment-history.js")
FUNCTIONS_FOUND=0

if echo "$JS_CONTENT" | grep -q "function setupDateFilter"; then
    echo -e "${GREEN}✅${NC} setupDateFilter() found"
    ((FUNCTIONS_FOUND++))
else
    echo -e "${RED}❌${NC} setupDateFilter() missing"
fi

if echo "$JS_CONTENT" | grep -q "function applyDateFilter"; then
    echo -e "${GREEN}✅${NC} applyDateFilter() found"
    ((FUNCTIONS_FOUND++))
else
    echo -e "${RED}❌${NC} applyDateFilter() missing"
fi

if echo "$JS_CONTENT" | grep -q "function clearDateFilter"; then
    echo -e "${GREEN}✅${NC} clearDateFilter() found"
    ((FUNCTIONS_FOUND++))
else
    echo -e "${RED}❌${NC} clearDateFilter() missing"
fi

if echo "$JS_CONTENT" | grep -q "function applyAllFilters"; then
    echo -e "${GREEN}✅${NC} applyAllFilters() found"
    ((FUNCTIONS_FOUND++))
else
    echo -e "${RED}❌${NC} applyAllFilters() missing"
fi

if [ $FUNCTIONS_FOUND -eq 4 ]; then
    echo -e "${GREEN}✅ PASS${NC} - All date filter functions present ($FUNCTIONS_FOUND/4)"
else
    echo -e "${RED}❌ FAIL${NC} - Some functions missing ($FUNCTIONS_FOUND/4)"
fi
echo ""

# Test 6: Check if modal CSS is fixed
echo "Test 6: Modal Layout CSS Fixed"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if echo "$PAGE_CONTENT" | grep -q "grid-template-columns: 1fr 1fr"; then
    echo -e "${GREEN}✅ PASS${NC} - Modal uses equal columns (1fr 1fr)"
else
    echo -e "${YELLOW}⚠️  WARNING${NC} - Modal layout may not be fixed"
fi
echo ""

# Test 7: Check API endpoint
echo "Test 7: Customer Payments API Endpoint"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
API_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$PROD_URL/api/customer/payments.php")
if [ "$API_STATUS" -eq 200 ] || [ "$API_STATUS" -eq 401 ]; then
    echo -e "${GREEN}✅ PASS${NC} - API endpoint exists (HTTP $API_STATUS)"
    echo "   Note: 401 is expected without authentication"
else
    echo -e "${RED}❌ FAIL${NC} - API endpoint issue (HTTP $API_STATUS)"
fi
echo ""

# Test 8: Check database slip paths
echo "Test 8: Database Slip Image Paths"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo -e "${YELLOW}⚠️  MANUAL${NC} - Check production database for slip_image paths"
echo "   Should be: /uploads/slips/filename.jpg"
echo "   NOT: /autobot/public/uploads/slips/filename.jpg"
echo ""

# Summary
echo "╔═══════════════════════════════════════════════════════╗"
echo "║  📊 Test Summary                                     ║"
echo "╚═══════════════════════════════════════════════════════╝"
echo ""
echo "✅ Production URL: $PROD_URL"
echo "📅 Date filter UI should be visible"
echo "🔍 Modal should have equal-width columns"
echo "🖼️  Slip images should load from /uploads/slips/"
echo ""
echo "Next Steps:"
echo "1. Open browser: $PROD_URL/payment-history.php"
echo "2. Click on a payment card to open modal"
echo "3. Check if layout is 50/50 (not cramped)"
echo "4. Try date filter with calendar picker"
echo "5. Verify slip images display correctly"
echo ""
echo "For detailed testing checklist, see:"
echo "→ /docs/PAYMENT_HISTORY_FIXES.md"
echo "→ /docs/DEPLOYMENT_STATUS_20241224.txt"
echo ""
