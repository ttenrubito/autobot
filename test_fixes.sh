#!/bin/bash

# Test Knowledge Base Matching Fix

echo "======================================"
echo "Test 1: Knowledge Base Matching"
echo "======================================"

echo -e "\n1. Exact match 'ที่อยู่ร้าน' - should MATCH:"
curl -s -X POST https://autobot.boxdesign.in.th/api/gateway/message.php   -H "Content-Type: application/json"   -d '{
    "inbound_api_key": "ch_tnxy2uaomj9j3kpp",
    "channel_type": "facebook",
    "external_user_id": "KB_TEST_EXACT",
    "message_type": "text",
    "text": "ที่อยู่ร้าน"
  }' | grep -o '"matched_keyword":"[^"]*"'

echo -e "\n2. Partial 'ที่อ' - should NOT match:"
curl -s -X POST https://autobot.boxdesign.in.th/api/gateway/message.php   -H "Content-Type: application/json"   -d '{
    "inbound_api_key": "ch_tnxy2uaomj9j3kpp",
    "channel_type": "facebook",
    "external_user_id": "KB_TEST_PARTIAL",
    "message_type": "text",
    "text": "ที่อ"
  }' | grep -o '"reason":"[^"]*"'

echo -e "\n3. Unrelated 'งาน' - should NOT match:"
curl -s -X POST https://autobot.boxdesign.in.th/api/gateway/message.php \
  -H "Content-Type: application/json" \
  -d '{
    "inbound_api_key": "ch_tnxy2uaomj9j3kpp",
    "channel_type": "facebook",
    "external_user_id": "KB_TEST_UNRELATED",
    "message_type": "text",
    "text": "งาน"
  }' | grep -o '"reason":"[^"]*"'

echo ""
echo "======================================"
echo "Test 2: Anti-Spam"
echo "======================================"

USER_ID="SPAM_TEST_$(date +%s)"
echo "Using user: $USER_ID"

for i in {1..5}; do
  echo -e "\nMessage $i:"
  result=$(curl -s -X POST https://autobot.boxdesign.in.th/api/gateway/message.php \
    -H "Content-Type: application/json" \
    -d "{
      \"inbound_api_key\": \"ch_tnxy2uaomj9j3kpp\",
      \"channel_type\": \"facebook\",
      \"external_user_id\": \"$USER_ID\",
      \"message_type\": \"text\",
      \"text\": \"สวัสดีครับ\"
    }")
  
  if echo "$result" | grep -q "repeat_detected"; then
    echo "  ✅ ANTI-SPAM DETECTED!"
  else
    echo "  Normal: $(echo "$result" | grep -o '"reason":"[^"]*"' | sed 's/"reason":"\([^"]*\)"/\1/')"
  fi
  
  sleep 1
done

echo -e "\n======================================"
echo "Tests Complete!"
echo "======================================"
