#!/bin/bash

echo "========================================"
echo "🧪 Admin Handoff Live Test"
echo "========================================"
echo ""
echo "📝 Instructions:"
echo "1. Open Facebook Messenger (Box Design page)"
echo "2. Type: admin"
echo "3. Press Enter"
echo ""
echo "⏳ Watching logs for 60 seconds..."
echo "========================================"
echo ""

# Get timestamp now
START_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# Wait a bit for user to type
sleep 5

# Watch logs
gcloud logging tail \
  "resource.type=\"cloud_run_revision\" AND resource.labels.service_name=\"autobot\"" \
  --project=autobot-prod-251215-22549 \
  --format="value(timestamp,jsonPayload.message,jsonPayload.context.text_preview)" \
  2>&1 &

TAIL_PID=$!

# Wait 60 seconds
sleep 60

# Kill tail
kill $TAIL_PID 2>/dev/null

echo ""
echo "========================================"
echo "📊 Checking for admin command logs..."
echo "========================================"

gcloud logging read \
  "resource.type=\"cloud_run_revision\" AND resource.labels.service_name=\"autobot\" AND timestamp>=\"${START_TIME}\"" \
  --limit=50 \
  --project=autobot-prod-251215-22549 \
  --format=json \
  | python3 -c "
import json, sys
logs = json.load(sys.stdin)
found_admin = False
for log in logs:
    msg = log.get('jsonPayload', {}).get('message', '')
    ctx = log.get('jsonPayload', {}).get('context', {})
    text_preview = ctx.get('text_preview', '')
    
    if 'admin' in msg.lower() or 'admin' in str(text_preview).lower():
        found_admin = True
        print(f\"✅ Found: {msg}\")
        if text_preview:
            print(f\"   Text: {text_preview}\")
        print(f\"   Context keys: {list(ctx.keys())}\")
        print()

if not found_admin:
    print('❌ No admin-related logs found')
    print('💡 Did you type \"admin\" in Facebook Messenger?')
"

echo ""
echo "========================================"
echo "Done!"
echo "========================================"
