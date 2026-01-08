#!/bin/bash
# Quick test after deployment - 2 minutes

echo "🧪 Quick Test - Document Display"
echo "================================="
echo ""

BASE_URL="https://autobot.boxdesign.in.th"

# Test 1: Campaign API
echo "Test 1: Campaign Labels"
echo "-----------------------"
LABELS=$(curl -s "${BASE_URL}/api/lineapp/campaigns.php?id=2" 2>/dev/null | grep -o '"label":"[^"]*"' | head -2)
if echo "$LABELS" | grep -q "บัตรประชาชน"; then
    echo "✅ Campaign has Thai label"
else
    echo "❌ Campaign label missing - Run: ./run_migration_api.sh"
fi
echo ""

# Test 2: Debug Check
echo "Test 2: System Status"
echo "---------------------"
curl -s "${BASE_URL}/deep_debug_docs.php" > /tmp/quick_test.html 2>/dev/null
if grep -q "No obvious issues detected" /tmp/quick_test.html; then
    echo "✅ System reports: No issues"
elif grep -q "Documents Debug" /tmp/quick_test.html; then
    echo "⚠️  System has some issues - check ${BASE_URL}/deep_debug_docs.php"
else
    echo "❌ Debug endpoint not responding"
fi
echo ""

# Summary
echo "================================="
echo "�� NOW TEST MANUALLY:"
echo "================================="
echo ""
echo "1. Open LIFF in LINE:"
echo "   https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
echo ""
echo "2. Verify shows 'บัตรประชาชน' (not 'เอกสาร')"
echo ""
echo "3. Upload ID card photo"
echo ""
echo "4. Open Admin:"
echo "   ${BASE_URL}/line-applications.php"
echo ""
echo "5. Find your application"
echo ""
echo "6. Click to view details"
echo ""
echo "7. CHECK: '📄 เอกสาร' section must show:"
echo "   - Label in Thai: 'บัตรประชาชน'"
echo "   - Filename"
echo "   - File size"
echo ""
echo "✅ If you see Thai label = SUCCESS!"
echo "❌ If you see 'id_card' or nothing = FAILED"
echo ""
