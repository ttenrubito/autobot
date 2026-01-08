#!/bin/bash
# Real-time debugging for admin handoff

echo "🔍 Admin Handoff Live Debugging"
echo "================================"
echo ""
echo "📋 Step 1: Send 'admin มาตอบ' in Facebook/LINE NOW"
echo ""
read -p "Press Enter AFTER you sent the message..."

echo ""
echo "⏳ Fetching logs from last 2 minutes..."
echo ""

# Get ALL recent logs (not just admin-related)
gcloud logging read \
  "resource.type=cloud_run_revision 
   AND resource.labels.service_name=autobot
   AND timestamp>=\"$(date -u -d '2 minutes ago' +%Y-%m-%dT%H:%M:%SZ)\"" \
  --limit=200 \
  --project=autobot-prod-251215-22549 \
  --format=json > /tmp/recent_logs.json

echo "📊 Analyzing logs..."
echo ""

# Check 1: Did webhook receive the message?
echo "1️⃣ Webhook Activity:"
cat /tmp/recent_logs.json | jq -r '.[] | select(.textPayload != null) | .textPayload' | grep -i "webhook\|facebook\|line\|messaging" | tail -10
echo ""

# Check 2: Which handler was selected?
echo "2️⃣ Handler Selection:"
cat /tmp/recent_logs.json | jq -r '.[] | select(.textPayload != null) | .textPayload' | grep -i "FACTORY\|handler" | tail -5
echo ""

# Check 3: Admin detection
echo "3️⃣ Admin Detection:"
cat /tmp/recent_logs.json | jq -r '.[] | select(.textPayload != null) | .textPayload' | grep -i "admin" | tail -10
echo ""

# Check 4: Database operations
echo "4️⃣ Database Operations:"
cat /tmp/recent_logs.json | jq -r '.[] | select(.textPayload != null) | .textPayload' | grep -i "last_admin_message_at\|UPDATE chat_sessions" | tail -5
echo ""

# Check 5: Errors
echo "5️⃣ Errors (if any):"
cat /tmp/recent_logs.json | jq -r '.[] | select(.severity == "ERROR") | .textPayload' | tail -10
echo ""

echo "================================"
echo "📋 What to look for:"
echo ""
echo "✅ Good signs:"
echo "  - '[FACTORY] Instantiating RouterV2BoxDesignHandler'"
echo "  - '[ADMIN_HANDOFF] Manual command detected'"
echo "  - '[V2_BOXDESIGN] Bot paused - admin handoff active'"
echo ""
echo "❌ Bad signs:"
echo "  - No '[FACTORY]' logs → webhook not reaching gateway"
echo "  - No '[ADMIN_HANDOFF]' logs → pattern not matching"
echo "  - SQL errors → database issue"
echo ""
echo "Full logs saved to: /tmp/recent_logs.json"
