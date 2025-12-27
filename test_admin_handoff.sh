#!/bin/bash
# Test Admin Handoff Detection
# Usage: ./test_admin_handoff.sh [platform] [message]
# Example: ./test_admin_handoff.sh line "admin ตรวจสอบสักครู่"

set -e

PLATFORM=${1:-"line"}
MESSAGE=${2:-"admin test message"}
API_URL=${API_URL:-"https://autobot-693230987450.asia-southeast1.run.app/api/gateway/message.php"}

# Get inbound API key from database
echo "🔍 Fetching inbound API key for $PLATFORM channel..."
INBOUND_KEY=$(/opt/lampp/bin/mysql -u root autobot -sN -e "
SELECT inbound_api_key 
FROM customer_channels 
WHERE type='$PLATFORM' AND status='active' AND is_deleted=0 
LIMIT 1;
")

if [ -z "$INBOUND_KEY" ]; then
    echo "❌ No active $PLATFORM channel found"
    exit 1
fi

echo "✅ Found API key: ${INBOUND_KEY:0:20}..."

# Generate test user ID
TEST_USER_ID="test_user_$(date +%s)"

echo ""
echo "📨 Sending test message to gateway..."
echo "Platform: $PLATFORM"
echo "User ID: $TEST_USER_ID"
echo "Message: $MESSAGE"
echo ""

# Call gateway API
RESPONSE=$(curl -s -X POST "$API_URL" \
  -H "Content-Type: application/json" \
  -d "{
    \"inbound_api_key\": \"$INBOUND_KEY\",
    \"external_user_id\": \"$TEST_USER_ID\",
    \"text\": \"$MESSAGE\",
    \"message_type\": \"text\",
    \"channel_type\": \"$PLATFORM\",
    \"metadata\": {
      \"test_mode\": true,
      \"platform\": \"$PLATFORM\"
    }
  }")

echo "📥 Gateway Response:"
echo "$RESPONSE" | jq '.' 2>/dev/null || echo "$RESPONSE"
echo ""

# Check database for admin detection
echo "🔍 Checking database for admin detection..."

SESSION_ID=$(/opt/lampp/bin/mysql -u root autobot -sN -e "
SELECT id 
FROM chat_sessions 
WHERE external_user_id='$TEST_USER_ID' 
LIMIT 1;
")

if [ -n "$SESSION_ID" ]; then
    echo "✅ Session created: $SESSION_ID"
    
    # Check last_admin_message_at
    ADMIN_TIME=$(/opt/lampp/bin/mysql -u root autobot -sN -e "
    SELECT last_admin_message_at 
    FROM chat_sessions 
    WHERE id=$SESSION_ID;
    ")
    
    if [ -n "$ADMIN_TIME" ] && [ "$ADMIN_TIME" != "NULL" ]; then
        echo "✅ Admin intervention detected!"
        echo "   Timestamp: $ADMIN_TIME"
    else
        echo "❌ Admin intervention NOT detected"
        echo "   last_admin_message_at is NULL"
    fi
    
    # Check messages
    echo ""
    echo "📝 Recent messages in this session:"
    /opt/lampp/bin/mysql -u root autobot -e "
    SELECT id, role, LEFT(message_text, 50) as text, sent_at 
    FROM chat_messages 
    WHERE conversation_id IN (
        SELECT CONCAT('line_', external_user_id) 
        FROM chat_sessions 
        WHERE id=$SESSION_ID
    )
    ORDER BY sent_at DESC 
    LIMIT 5;
    " 2>/dev/null || echo "Note: role column may not exist yet"
else
    echo "❌ No session found for user $TEST_USER_ID"
fi

echo ""
echo "✅ Test complete!"
