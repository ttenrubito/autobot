#!/bin/bash
# Complete Integration Test - Document Upload Flow

set -e

BASE_URL="https://autobot.boxdesign.in.th"
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

PASSED=0
FAILED=0

pass() {
    echo -e "${GREEN}✅ PASS${NC}: $1"
    ((PASSED++))
}

fail() {
    echo -e "${RED}❌ FAIL${NC}: $1"
    [ -n "$2" ] && echo "   Detail: $2"
    ((FAILED++))
}

info() {
    echo -e "${YELLOW}ℹ️  INFO${NC}: $1"
}

echo "🧪 INTEGRATION TEST - Document Upload Flow"
echo "==========================================="
echo ""

# ============================================================================
# TEST 1: Check Local Code Files
# ============================================================================
echo "Test 1: Local Code Check"
echo "------------------------"

# Check API file
if [ -f "api/lineapp/documents.php" ]; then
    pass "API file exists"
    
    if grep -q "document_label" api/lineapp/documents.php; then
        pass "API handles document_label"
    else
        fail "API missing document_label handling" "Search for 'document_label' in api/lineapp/documents.php"
    fi
else
    fail "API file not found"
fi

# Check LIFF file
if [ -f "liff/application-form.html" ]; then
    pass "LIFF file exists"
    
    if grep -q "document_label" liff/application-form.html; then
        pass "LIFF sends document_label"
    else
        fail "LIFF missing document_label in upload" "Search for 'document_label' in liff/application-form.html"
    fi
    
    if grep -q "renderDocumentFields" liff/application-form.html; then
        pass "LIFF has dynamic rendering"
    else
        fail "LIFF missing renderDocumentFields function"
    fi
else
    fail "LIFF file not found"
fi

echo ""

# ============================================================================
# TEST 2: Production Deployment Check
# ============================================================================
echo "Test 2: Production Deployment"
echo "------------------------------"

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    pass "Production site accessible (HTTP $HTTP_CODE)"
else
    fail "Production site not accessible (HTTP $HTTP_CODE)"
fi

echo ""

# ============================================================================
# TEST 3: Campaign Configuration via API
# ============================================================================
echo "Test 3: Campaign Configuration"
echo "-------------------------------"

CAMPAIGN_JSON=$(curl -s "${BASE_URL}/api/lineapp/campaigns.php?id=2" 2>/dev/null || echo "{}")

if echo "$CAMPAIGN_JSON" | grep -q '"success":true'; then
    pass "Campaign API responds"
    
    if echo "$CAMPAIGN_JSON" | grep -q '"code":"DEMO2026"'; then
        pass "Campaign DEMO2026 found"
        
        # Check for Thai labels
        if echo "$CAMPAIGN_JSON" | grep -q '"label":"บัตรประชาชน"'; then
            pass "Campaign has Thai label: บัตรประชาชน"
        else
            fail "Campaign missing Thai label 'บัตรประชาชน'" "Run: ./run_migration_api.sh"
        fi
        
        if echo "$CAMPAIGN_JSON" | grep -q '"label":"ทะเบียนบ้าน"'; then
            pass "Campaign has Thai label: ทะเบียนบ้าน"
        else
            fail "Campaign missing Thai label 'ทะเบียนบ้าน'" "Run: ./run_migration_api.sh"
        fi
        
        # Check for empty labels (BAD!)
        if echo "$CAMPAIGN_JSON" | grep -q '"label":""' || echo "$CAMPAIGN_JSON" | grep -q '"label":null'; then
            fail "Campaign has EMPTY labels!" "This will cause documents not to display"
        else
            pass "No empty labels detected"
        fi
    else
        fail "Campaign DEMO2026 not found in API response"
    fi
else
    fail "Campaign API returned error" "Response: $(echo $CAMPAIGN_JSON | head -c 100)"
fi

echo ""

# ============================================================================
# TEST 4: GCS Helper Class
# ============================================================================
echo "Test 4: GCS Integration"
echo "-----------------------"

if [ -f "includes/GoogleCloudStorage.php" ]; then
    pass "GCS helper class exists"
    
    if grep -q "uploadFile" includes/GoogleCloudStorage.php; then
        pass "GCS has uploadFile method"
    else
        fail "GCS missing uploadFile method"
    fi
    
    if grep -q "generateSignedUrl" includes/GoogleCloudStorage.php; then
        pass "GCS has generateSignedUrl method"
    else
        fail "GCS missing generateSignedUrl method"
    fi
else
    fail "GCS helper class not found"
fi

# Check service account
if [ -f "config/gcp/service-account.json" ]; then
    pass "Service account file exists"
else
    fail "Service account file missing" "Required for GCS upload"
fi

echo ""

# ============================================================================
# TEST 5: Database Migration Check (via debug endpoint)
# ============================================================================
echo "Test 5: Database Schema (via Debug Endpoint)"
echo "---------------------------------------------"

if curl -s "${BASE_URL}/deep_debug_docs.php" > /tmp/debug_test.html 2>/dev/null; then
    if grep -q "Documents Debug" /tmp/debug_test.html; then
        pass "Debug endpoint accessible"
        
        # Check for GCS columns
        if grep -q "gcs_path" /tmp/debug_test.html; then
            pass "Database has gcs_path column"
        else
            fail "Database missing gcs_path column" "Run: ./run_migration_api.sh"
        fi
        
        if grep -q "gcs_signed_url" /tmp/debug_test.html; then
            pass "Database has gcs_signed_url column"
        else
            fail "Database missing gcs_signed_url column" "Run: ./run_migration_api.sh"
        fi
        
        # Check for issues
        if grep -q "No obvious issues detected" /tmp/debug_test.html; then
            pass "Debug reports no issues"
        else
            info "Debug endpoint reports issues - check ${BASE_URL}/deep_debug_docs.php"
        fi
    else
        fail "Debug endpoint not properly deployed"
    fi
else
    fail "Cannot access debug endpoint" "May not be deployed yet"
fi

echo ""

# ============================================================================
# TEST 6: Code Logic Verification
# ============================================================================
echo "Test 6: Code Logic Verification"
echo "--------------------------------"

# Check if API INSERT statement includes document_label
if grep -A 10 "INSERT INTO application_documents" api/lineapp/documents.php | grep -q "document_label"; then
    pass "API INSERT includes document_label"
else
    fail "API INSERT missing document_label" "Documents won't save labels!"
fi

# Check if API gets label from input
if grep -q '$documentLabel.*=.*\$input\[.document_label.\]' api/lineapp/documents.php; then
    pass "API extracts document_label from request"
else
    fail "API doesn't extract document_label from input"
fi

# Check if LIFF sends label in uploadData
if grep -A 5 "uploadData.*=" liff/application-form.html | grep -q "document_label"; then
    pass "LIFF includes document_label in upload payload"
else
    fail "LIFF doesn't send document_label" "API won't receive it!"
fi

# Check if LIFF function signature includes label parameter
if grep "function uploadDocument" liff/application-form.html | grep -q "documentLabel"; then
    pass "LIFF uploadDocument function accepts documentLabel"
else
    fail "LIFF uploadDocument missing documentLabel parameter"
fi

echo ""

# ============================================================================
# SUMMARY
# ============================================================================
echo "==========================================="
echo "TEST SUMMARY"
echo "==========================================="
echo -e "✅ Passed: ${GREEN}$PASSED${NC}"
echo -e "❌ Failed: ${RED}$FAILED${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}🎉 ALL TESTS PASSED!${NC}"
    echo ""
    echo "✅ System is ready for deployment!"
    echo ""
    echo "Next steps:"
    echo "  1. Deploy to production:"
    echo "     gcloud run deploy autobot --source=. --region=asia-southeast1 --allow-unauthenticated"
    echo ""
    echo "  2. After deployment, run migration:"
    echo "     ./run_migration_api.sh"
    echo ""
    echo "  3. Test LIFF upload:"
    echo "     https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
    echo ""
    echo "  4. Verify in admin:"
    echo "     ${BASE_URL}/line-applications.php"
    echo ""
    exit 0
else
    echo -e "${RED}⚠️  ${FAILED} TEST(S) FAILED!${NC}"
    echo ""
    echo "❌ DO NOT DEPLOY until all tests pass!"
    echo ""
    echo "Fix the issues above and run again:"
    echo "  ./integration_test.sh"
    echo ""
    
    if [ $FAILED -gt 5 ]; then
        echo "💡 Many failures detected. Possible causes:"
        echo "   1. Code changes not saved/committed"
        echo "   2. Production not deployed yet"
        echo "   3. Migration not run"
        echo ""
        echo "Try:"
        echo "   - Verify local changes: git status"
        echo "   - Check if deployment running: ps aux | grep gcloud"
        echo "   - Review debug endpoint: ${BASE_URL}/deep_debug_docs.php"
    fi
    exit 1
fi
