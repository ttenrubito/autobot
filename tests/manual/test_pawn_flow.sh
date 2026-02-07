#!/bin/bash
# ============================================
# 🏆 Pawn Flow Test
# ============================================
# ทดสอบ Flow จำนำสินค้า
# ============================================

ENV="${1:-localhost}"
# ใช้ Channel "facebook ร้านมือสอง" ที่มี router_v4 รองรับ pawn
API_KEY="ch_tnxy2uaomj9j3kpp"
PLATFORM_USER_ID="pawn_test_user_002"

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
      \"sender\": { \"id\": \"$PLATFORM_USER_ID\", \"name\": \"Pawn Test User\" },
      \"metadata\": { \"mid\": \"m.$(date +%s%N)\", \"timestamp\": $(date +%s)000 }
    }"
    
    RESPONSE=$(curl -s -X POST "$BASE_URL/api/gateway/message.php" \
        -H "Content-Type: application/json" \
        -d "$PAYLOAD")
    
    echo "📥 Bot Reply:"
    echo "$RESPONSE" | jq -r '.data.reply_text // .data.reply_texts[0] // "No reply"' | head -20
    echo ""
    echo "📋 Route: $(echo $RESPONSE | jq -r '.data.meta.route // "null"')"
    echo "📁 Case: $(echo $RESPONSE | jq -r '.data.meta.case.case_no // "null"')"
    
    sleep 2
}

echo ""
echo "🏆 STARTING PAWN FLOW TEST"
echo "👤 User ID: $PLATFORM_USER_ID"
echo "============================================"

# Step 1: Greeting
send_message "สวัสดีครับ" "Step 1: Customer Greeting"

# Step 2: Ask about pawn
send_message "อยากจำนำทอง" "Step 2: Pawn Inquiry"

# Step 3: Ask about pawn conditions
send_message "รับจำนำอะไรบ้าง" "Step 3: Ask Pawn Items"

# Step 4: Ask about interest rate
send_message "ดอกเบี้ยเท่าไหร่" "Step 4: Ask Interest Rate"

# Step 5: Ask about loan amount
send_message "วงเงินจำนำเท่าไหร่" "Step 5: Ask Loan Amount"

# Step 6: Express interest in pawning
send_message "จะเอาสร้อยทองมาจำนำ 2 บาท" "Step 6: Describe Item to Pawn"

# Step 7: Request admin
send_message "แอดมิน" "Step 7: Request Admin (Handoff)"

echo ""
echo "============================================"
echo "✅ PAWN FLOW TEST COMPLETE!"
echo "============================================"
echo ""

# Check created cases
echo "📋 Checking Cases for pawn_test_user_001..."
TOKEN=$(curl -s -X POST "$BASE_URL/api/auth/login.php" \
    -H "Content-Type: application/json" \
    -d '{"email":"test1@gmail.com","password":"demo1234"}' | jq -r '.data.token')

echo ""
echo "📁 Latest Cases:"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/v2/cases.php?limit=3" | jq '.data.items[] | {case_no, case_type, status}'

echo ""
echo "🏆 Pawns Status:"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/v2/pawns.php" | jq '.success, .data.count'

echo ""
echo "============================================"
echo "🔍 Shop Owner Dashboard (เจ้าของร้าน):"
echo "   - Cases: $BASE_URL/public/cases.php"
echo "   - Pawns: $BASE_URL/public/pawns.php"
echo "============================================"
