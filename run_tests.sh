#!/bin/bash
# Master Test Runner
# Runs all test suites and provides comprehensive report

echo "🧪 AI Automation Portal - Complete Test Suite"
echo "=============================================="
echo ""

FAILED=0

# Run Database Tests
echo "1️⃣  Running Database Tests..."
/opt/lampp/bin/php tests/unit/database_tests.php
if [ $? -ne 0 ]; then
    FAILED=$((FAILED + 1))
fi
echo ""

# Run API Tests
echo "2️⃣  Running API Unit Tests..."
/opt/lampp/bin/php tests/unit/api_tests.php
if [ $? -ne 0 ]; then
    FAILED=$((FAILED + 1))
fi
echo ""

# Run Frontend Tests
echo "3️⃣  Running Frontend Page Tests..."
/opt/lampp/bin/php tests/unit/frontend_tests.php
if [ $? -ne 0 ]; then
    FAILED=$((FAILED + 1))
fi
echo ""

# Run Integration Tests
echo "4️⃣  Running Integration Tests..."
/opt/lampp/bin/php tests/integration/gateway_test.php
if [ $? -ne 0 ]; then
    FAILED=$((FAILED + 1))
fi
echo ""

# Final Summary
echo "=============================================="
echo "📊 Test Suite Summary"
echo "=============================================="

if [ $FAILED -eq 0 ]; then
    echo -e "\033[32m✅ All test suites passed!\033[0m"
    echo ""
    echo "System Status: PRODUCTION READY ✨"
    exit 0
else
    echo -e "\033[31m❌ $FAILED test suite(s) failed\033[0m"
    echo ""
    echo "Please review the failures above and fix before deployment."
    exit 1
fi
