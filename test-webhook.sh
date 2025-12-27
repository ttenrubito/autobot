#!/bin/bash
# Omise Webhook Test Script
# Tests webhook endpoint locally before deploying to Omise

echo "======================================"
echo "Omise Webhook Testing Script"
echo "======================================"
echo ""

# Configuration
WEBHOOK_URL="http://localhost/autobot/api/webhooks/omise.php"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0

# Function to run test
run_test() {
    local test_name=$1
    local payload=$2
    
    echo "----------------------------------------"
    echo "Test: $test_name"
    echo "----------------------------------------"
    
    response=$(curl -s -w "\n%{http_code}" -X POST "$WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "$payload")
    
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')
    
    if [ "$http_code" = "200" ]; then
        echo -e "${GREEN}✓ PASS${NC} - HTTP $http_code"
        echo "Response: $body"
        ((TESTS_PASSED++))
    else
        echo -e "${RED}✗ FAIL${NC} - HTTP $http_code"
        echo "Response: $body"
        ((TESTS_FAILED++))
    fi
    echo ""
}

# Test 1: Valid charge.complete event
echo "Test 1: Charge Complete (Success)"
run_test "charge.complete" '{
  "object": "event",
  "id": "evnt_test_123",
  "livemode": false,
  "key": "charge.complete",
  "created_at": "2025-12-14T15:30:00Z",
  "data": {
    "object": "charge",
    "id": "chrg_test_5xyz123abc",
    "livemode": false,
    "amount": 500000,
    "currency": "THB",
    "description": "Invoice #INV-20251214-00001-1",
    "status": "successful",
    "paid": true,
    "paid_at": "2025-12-14T15:30:00Z"
  }
}'

# Test 2: Charge failed event
echo "Test 2: Charge Failed"
run_test "charge.failed" '{
  "object": "event",
  "id": "evnt_test_456",
  "key": "charge.failed",
  "created_at": "2025-12-14T15:35:00Z",
  "data": {
    "object": "charge",
    "id": "chrg_test_failedxyz",
    "amount": 300000,
    "currency": "THB",
    "status": "failed",
    "paid": false,
    "failure_code": "payment_expired",
    "failure_message": "QR code expired"
  }
}'

# Test 3: Invalid payload (should handle gracefully)
echo "Test 3: Invalid Payload"
run_test "invalid_payload" '{
  "invalid": "data"
}'

# Test 4: Empty payload
echo "Test 4: Empty Payload"
run_test "empty_payload" '{}'

# Summary
echo "======================================"
echo "Test Summary"
echo "======================================"
echo -e "Passed: ${GREEN}$TESTS_PASSED${NC}"
echo -e "Failed: ${RED}$TESTS_FAILED${NC}"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}✓ All tests passed! Webhook is ready for Omise.${NC}"
    exit 0
else
    echo -e "${RED}✗ Some tests failed. Please fix errors before deploying.${NC}"
    exit 1
fi
