#!/bin/bash
# ============================================
# 🧪 Complete Flow Test Script
# ============================================
# ทดสอบ Flow ตั้งแต่ลูกค้าทักแชท จนปิด Case
# 
# วิธีใช้: ./test_complete_flow.sh [production|localhost]
# ============================================

ENV="${1:-localhost}"

if [ "$ENV" = "production" ]; then
    BASE_URL="https://autobot.boxdesign.in.th"
    echo "🌐 Testing on PRODUCTION"
else
    BASE_URL="http://localhost/autobot"
    echo "🏠 Testing on LOCALHOST"
fi

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo ""
echo "============================================"
echo "📋 STEP 1: Login & Get Token"
echo "============================================"

TOKEN=$(curl -s -X POST "$BASE_URL/api/auth/login.php" \
    -H "Content-Type: application/json" \
    -d '{"email":"test1@gmail.com","password":"demo1234"}' | jq -r '.data.token')

if [ "$TOKEN" = "null" ] || [ -z "$TOKEN" ]; then
    echo -e "${RED}❌ Login failed!${NC}"
    exit 1
fi
echo -e "${GREEN}✅ Token: ${TOKEN:0:30}...${NC}"

echo ""
echo "============================================"
echo "📋 STEP 2: Check Current Cases (Before)"
echo "============================================"

CASES_BEFORE=$(curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/v2/cases.php" | jq '.data.count')
echo "📁 Current cases: $CASES_BEFORE"

echo ""
echo "============================================"
echo "📋 STEP 3: Simulate Chat Message (via Gateway)"
echo "============================================"
echo "⚠️  Note: To test real webhook, use Facebook/LINE Messenger"
echo ""
echo "📱 Test Messages to send:"
echo ""
echo -e "${YELLOW}--- Flow 1: Product Inquiry ---${NC}"
echo '1. "สนใจนาฬิกา Rolex"'
echo '2. "ROL-SUB-002"  (รหัสสินค้า)'
echo ""
echo -e "${YELLOW}--- Flow 2: Purchase ---${NC}"
echo '3. "สนใจ" หรือ "เอาเลย"'
echo '4. "โอนเต็ม" หรือ "ผ่อน"'
echo '5. Send shipping address: "ศรัณยู 0847910206 169/3 ต.โนน อ.เมือง จ.อุดร 41000"'
echo '6. [ส่งรูปสลิป]'
echo ""
echo -e "${YELLOW}--- Flow 3: Admin Commands ---${NC}"
echo '7. "แอดมิน" - เรียกแอดมิน (Handoff)'
echo ""

echo ""
echo "============================================"
echo "📋 STEP 4: Check APIs Status"
echo "============================================"

# Test Orders API
echo -n "📦 Orders API: "
ORDERS=$(curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/v2/orders.php")
ORDER_SUCCESS=$(echo $ORDERS | jq -r '.success')
ORDER_COUNT=$(echo $ORDERS | jq -r '.data.count')
if [ "$ORDER_SUCCESS" = "true" ]; then
    echo -e "${GREEN}✅ Working (count: $ORDER_COUNT)${NC}"
else
    echo -e "${RED}❌ Failed${NC}"
fi

# Test Cases API
echo -n "📁 Cases API: "
CASES=$(curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/v2/cases.php")
CASE_SUCCESS=$(echo $CASES | jq -r '.success')
CASE_COUNT=$(echo $CASES | jq -r '.data.count')
if [ "$CASE_SUCCESS" = "true" ]; then
    echo -e "${GREEN}✅ Working (count: $CASE_COUNT)${NC}"
else
    echo -e "${RED}❌ Failed${NC}"
fi

# Test Pawns API
echo -n "🏆 Pawns API: "
PAWNS=$(curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/v2/pawns.php")
PAWN_SUCCESS=$(echo $PAWNS | jq -r '.success')
PAWN_COUNT=$(echo $PAWNS | jq -r '.data.count')
if [ "$PAWN_SUCCESS" = "true" ]; then
    echo -e "${GREEN}✅ Working (count: $PAWN_COUNT)${NC}"
else
    echo -e "${RED}❌ Failed${NC}"
fi

echo ""
echo "============================================"
echo "📋 STEP 5: Shop Owner Dashboard URLs"
echo "============================================"
echo ""
echo "🏪 Shop Owner Dashboard (เจ้าของร้าน):"
echo "   - Cases:    $BASE_URL/public/cases.php"
echo "   - Orders:   $BASE_URL/public/orders.php"
echo "   - Pawns:    $BASE_URL/public/pawns.php"
echo "   - Payments: $BASE_URL/public/payment-history.php"
echo ""
echo "📱 API v2 (for shop owner frontend):"
echo "   - GET /api/v2/cases.php"
echo "   - GET /api/v2/orders.php"
echo "   - GET /api/v2/pawns.php"
echo ""

echo "============================================"
echo "📋 STEP 6: View Recent Cases"
echo "============================================"

echo "📁 Last 3 Cases:"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/v2/cases.php?limit=3" | jq '.data.items[] | {id, case_no, case_type, status, created_at}'

echo ""
echo "============================================"
echo "📋 STEP 7: View Recent Orders"
echo "============================================"

echo "📦 Last 3 Orders:"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/v2/orders.php?limit=3" | jq '.data.items[] | {id, order_number, status, total_amount, created_at}'

echo ""
echo "============================================"
echo "✅ TEST COMPLETE"
echo "============================================"
echo ""
echo "🔗 Next Steps:"
echo "1. ทดสอบจริงผ่าน Facebook Messenger ไปที่ Page ที่เชื่อมต่อ"
echo "2. ดูผลลัพธ์ใน Admin Panel"
echo "3. ทดสอบปิด Case ผ่าน Admin"
echo ""
