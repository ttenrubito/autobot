#!/bin/bash
# ============================================
# 🧪 Message Gateway Simulator
# ============================================
# Simulate chat messages via Gateway API
# 
# Usage: ./simulate_chat.sh [message] [localhost|production]
# ============================================

MESSAGE="${1:-สวัสดีครับ}"
ENV="${2:-localhost}"
API_KEY="ch_vpxp6tj2mj3lbfco"  # Facebook channel
PLATFORM_USER_ID="test_user_$(date +%s)"

if [ "$ENV" = "production" ]; then
    BASE_URL="https://autobot.boxdesign.in.th"
    echo "🌐 Testing on PRODUCTION"
else
    BASE_URL="http://localhost/autobot"
    echo "🏠 Testing on LOCALHOST"
fi

echo ""
echo "============================================"
echo "📤 Sending Message: \"$MESSAGE\""
echo "============================================"
echo ""

# Simulate Facebook webhook payload format (Gateway expects 'text' at root level)
PAYLOAD=$(cat <<EOF
{
  "inbound_api_key": "$API_KEY",
  "platform": "facebook",
  "external_user_id": "$PLATFORM_USER_ID",
  "text": "$MESSAGE",
  "message_type": "text",
  "sender": {
    "id": "$PLATFORM_USER_ID",
    "name": "Test User"
  },
  "metadata": {
    "mid": "m.$(date +%s)",
    "timestamp": $(date +%s)000
  }
}
EOF
)

echo "📋 Payload:"
echo "$PAYLOAD" | jq .

echo ""
echo "📡 Sending to: $BASE_URL/api/gateway/message.php"
echo ""

RESPONSE=$(curl -s -X POST "$BASE_URL/api/gateway/message.php" \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD")

echo "📥 Response:"
echo "$RESPONSE" | jq .

echo ""
echo "============================================"
echo "✅ Message sent!"
echo "============================================"
