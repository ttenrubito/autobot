#!/bin/bash
# ============================================
# 🧪 Complete Purchase Flow Test
# ============================================
# ทดสอบ Flow ซื้อสินค้าตั้งแต่ต้นจนจบ
# ============================================

ENV="${1:-localhost}"
API_KEY="ch_vpxp6tj2mj3lbfco"
# ใช้ user id เดียวกันตลอด flow!
PLATFORM_USER_ID="flow_test_user_001"

if [ "$ENV" = "production" ]; then
    BASE_URL="https://autobot.boxdesign.in.th"
    echo "🌐 Testing on PRODUCTION"
else
    BASE_URL="http://localhost/autobot"
    echo "🏠 Testing on LOCALHOST"
fi

send_message() {
    local MESSAGE="$1"
    local DESC="$2"
    
    echo ""
    echo "============================================"
    echo "📤 $DESC"
    echo "   Message: \"$MESSAGE\""
    echo "============================================"
    
    PAYLOAD="{
      \"inbound_api_key\": \"$API_KEY\",
      \"platform\": \"facebook\",
      \"external_user_id\": \"$PLATFORM_USER_ID\",
      \"text\": \"$MESSAGE\",
      \"message_type\": \"text\",
      \"sender\": { \"id\": \"$PLATFORM_USER_ID\", \"name\": \"Test User\" },
      \"metadata\": { \"mid\": \"m.$(date +%s%N)\", \"timestamp\": $(date +%s)000 }
    }"
    
    RESPONSE=$(curl -s -X POST "$BASE_URL/api/gateway/message.php" \
        -H "Content-Type: application/json" \
        -d "$PAYLOAD")
    
    echo "📥 Bot Reply:"
    echo "$RESPONSE" | jq -r '.data.reply_text // .data.reply_texts[0] // "No reply"'
    echo ""
    echo "📋 Route: $(echo $RESPONSE | jq -r '.data.meta.route // "null"')"
    echo "📁 Case: $(echo $RESPONSE | jq -r '.data.meta.case.case_no // "null"')"
    
    # รอ 2 วินาทีให้ระบบประมวลผล
    sleep 2
}

echo ""
echo "🛒 STARTING COMPLETE PURCHASE FLOW TEST"
echo "👤 User ID: $PLATFORM_USER_ID"
echo "============================================"

# Step 1: Greeting
send_message "สวัสดีครับ" "Step 1: Customer Greeting"

# Step 2: Product Inquiry by Code
send_message "ROL-SUB-002" "Step 2: Product Inquiry (Code)"

# Step 3: Express Interest
send_message "สนใจครับ เอาเลย" "Step 3: Express Interest"

# Step 4: Select Payment Method
send_message "โอนเต็ม" "Step 4: Select Payment (Full)"

# Step 5: Provide Address
send_message "ศรัณยู คำแสง 0847910206 169/3 หมู่ 3 ต.โนน อ.เมือง จ.อุดรธานี 41000" "Step 5: Shipping Address"

# Step 6: Confirm
send_message "ยืนยัน" "Step 6: Confirm Order"

echo ""
echo "============================================"
echo "✅ FLOW TEST COMPLETE!"
echo "============================================"
echo ""
echo "🔍 Check Results (Shop Owner Dashboard):"
echo "   - Cases: $BASE_URL/public/cases.php"
echo "   - Orders: $BASE_URL/public/orders.php"
echo ""
echo "📋 To check via API:"
echo "   TOKEN=\$(curl -s -X POST '$BASE_URL/api/auth/login.php' -H 'Content-Type: application/json' -d '{\"email\":\"test1@gmail.com\",\"password\":\"demo1234\"}' | jq -r '.data.token')"
echo "   curl -s -H 'Authorization: Bearer \$TOKEN' '$BASE_URL/api/v2/cases.php?limit=1' | jq ."
echo ""
