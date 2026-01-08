#!/bin/bash
# Test document upload after migration

echo "🧪 Testing Document Upload API"
echo "================================"

# Create test file
TEST_DATA="Hello from test upload - $(date)"
BASE64_DATA=$(echo -n "$TEST_DATA" | base64)

echo "📤 Uploading test document to application_id=1..."
echo ""

RESPONSE=$(curl -s -X POST https://autobot.boxdesign.in.th/api/lineapp/documents.php \
  -H "Content-Type: application/json" \
  -d "{
    \"application_id\": 1,
    \"document_type\": \"test_upload\",
    \"file_name\": \"test_$(date +%s).txt\",
    \"file_data\": \"$BASE64_DATA\",
    \"file_type\": \"text/plain\"
  }")

echo "Response:"
echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
echo ""

# Check if upload succeeded
if echo "$RESPONSE" | grep -q '"success":true'; then
    echo "✅ Upload succeeded!"
    
    # Get document ID
    DOC_ID=$(echo "$RESPONSE" | grep -o '"document_id":[0-9]*' | cut -d: -f2)
    echo "📄 Document ID: $DOC_ID"
    
    # Check documents list
    echo ""
    echo "📋 Checking documents for application_id=1..."
    curl -s "https://autobot.boxdesign.in.th/api/lineapp/documents.php?application_id=1" | python3 -m json.tool
    
else
    echo "❌ Upload failed!"
    echo "Check the response above for errors"
fi
