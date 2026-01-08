#!/bin/bash
# Automated Testing Script for LIFF GCS Integration

BASE_URL="https://autobot.boxdesign.in.th"
LIFF_URL="https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"

echo "🧪 LIFF + GCS Integration - Automated Tests"
echo "==========================================="
echo ""

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

pass=0
fail=0

# Helper functions
test_pass() {
    echo -e "${GREEN}✅ PASS${NC}: $1"
    ((pass++))
}

test_fail() {
    echo -e "${RED}❌ FAIL${NC}: $1"
    ((fail++))
}

test_info() {
    echo -e "${YELLOW}ℹ️  INFO${NC}: $1"
}

# Test 1: Check if service is deployed
echo "Test 1: Service Availability"
echo "----------------------------"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}")
if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "302" ]; then
    test_pass "Service is accessible (HTTP $HTTP_CODE)"
else
    test_fail "Service not accessible (HTTP $HTTP_CODE)"
fi
echo ""

# Test 2: Check campaign API
echo "Test 2: Campaign API"
echo "--------------------"
CAMPAIGN_JSON=$(curl -s "${BASE_URL}/api/lineapp/campaigns.php?id=2")
if echo "$CAMPAIGN_JSON" | grep -q "DEMO2026"; then
    test_pass "Campaign API returns DEMO2026"
    
    # Check if required_documents has labels
    if echo "$CAMPAIGN_JSON" | grep -q "บัตรประชาชน"; then
        test_pass "Campaign has Thai labels (บัตรประชาชน)"
    else
        test_fail "Campaign missing Thai labels"
        test_info "Labels may be empty - run fix endpoint"
    fi
else
    test_fail "Campaign API failed or campaign not found"
fi
echo ""

# Test 3: Check debug endpoint
echo "Test 3: Debug Endpoint"
echo "----------------------"
DEBUG_HTML=$(curl -s "${BASE_URL}/api/debug/check-documents.php")
if echo "$DEBUG_HTML" | grep -q "Documents Debug"; then
    test_pass "Debug endpoint accessible"
    
    # Check if there are any applications
    if echo "$DEBUG_HTML" | grep -q "Application #"; then
        APP_COUNT=$(echo "$DEBUG_HTML" | grep -c "Application #")
        test_pass "Found $APP_COUNT application(s)"
    else
        test_info "No applications found yet (this is OK for fresh install)"
    fi
else
    test_fail "Debug endpoint not accessible"
fi
echo ""

# Test 4: Check GCS helper class
echo "Test 4: GCS Helper Class"
echo "------------------------"
if [ -f "includes/GoogleCloudStorage.php" ]; then
    test_pass "GoogleCloudStorage.php exists"
    
    # Check if it has required methods
    if grep -q "uploadFile" includes/GoogleCloudStorage.php; then
        test_pass "GoogleCloudStorage has uploadFile method"
    else
        test_fail "GoogleCloudStorage missing uploadFile method"
    fi
else
    test_fail "GoogleCloudStorage.php not found"
fi
echo ""

# Test 5: Check LIFF form structure
echo "Test 5: LIFF Form"
echo "-----------------"
if [ -f "liff/application-form.html" ]; then
    test_pass "LIFF form file exists"
    
    # Check for dynamic rendering
    if grep -q "renderDocumentFields" liff/application-form.html; then
        test_pass "LIFF has renderDocumentFields function"
    else
        test_fail "LIFF missing renderDocumentFields"
    fi
    
    # Check for hardcoded inputs (should NOT exist)
    if grep -q 'id="idCard"' liff/application-form.html; then
        test_fail "LIFF still has hardcoded idCard field"
    else
        test_pass "LIFF has no hardcoded document fields"
    fi
else
    test_fail "LIFF form file not found"
fi
echo ""

# Test 6: Check API endpoints
echo "Test 6: API Endpoints"
echo "---------------------"
for endpoint in "campaigns.php" "applications.php" "documents.php"; do
    if [ -f "api/lineapp/${endpoint}" ]; then
        test_pass "API endpoint ${endpoint} exists"
    else
        test_fail "API endpoint ${endpoint} missing"
    fi
done
echo ""

# Test 7: Check service account
echo "Test 7: GCS Service Account"
echo "---------------------------"
if [ -f "config/gcp/service-account.json" ]; then
    test_pass "Service account file exists"
    
    # Check if it's valid JSON
    if jq empty config/gcp/service-account.json 2>/dev/null; then
        test_pass "Service account JSON is valid"
        
        PROJECT=$(jq -r '.project_id' config/gcp/service-account.json 2>/dev/null)
        if [ "$PROJECT" = "canvas-radio-472913-d4" ]; then
            test_pass "Service account for correct project"
        else
            test_fail "Service account project mismatch (found: $PROJECT)"
        fi
    else
        test_fail "Service account JSON is invalid"
    fi
else
    test_fail "Service account file not found"
fi
echo ""

# Test 8: Check Composer dependencies
echo "Test 8: Dependencies"
echo "--------------------"
if [ -f "composer.json" ]; then
    if grep -q "google/cloud-storage" composer.json; then
        test_pass "composer.json includes google/cloud-storage"
    else
        test_fail "composer.json missing google/cloud-storage"
    fi
    
    if [ -f "vendor/autoload.php" ]; then
        test_pass "Composer dependencies installed"
    else
        test_info "Composer dependencies may need installation"
    fi
else
    test_fail "composer.json not found"
fi
echo ""

# Summary
echo "========================================="
echo "Test Summary"
echo "========================================="
echo -e "${GREEN}Passed: $pass${NC}"
echo -e "${RED}Failed: $fail${NC}"
echo ""

if [ $fail -eq 0 ]; then
    echo -e "${GREEN}🎉 All tests passed!${NC}"
    echo ""
    echo "📍 Next Steps:"
    echo "   1. Open LIFF: $LIFF_URL"
    echo "   2. Test document upload"
    echo "   3. Check admin panel: ${BASE_URL}/line-applications.php"
    exit 0
else
    echo -e "${YELLOW}⚠️  Some tests failed. Review above.${NC}"
    echo ""
    echo "🔧 Troubleshooting:"
    echo "   • Run fix endpoint: ${BASE_URL}/api/admin/fix-campaign-labels.php?secret=fix_demo2026_labels_now"
    echo "   • Check logs for errors"
    echo "   • Verify deployment completed"
    exit 1
fi
