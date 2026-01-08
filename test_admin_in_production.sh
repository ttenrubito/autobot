#!/bin/bash
# Test admin handoff in production by checking recent logs

echo "🔍 Testing Admin Handoff in Production"
echo "========================================"
echo ""
echo "📋 Instructions:"
echo "1. Send 'admin' or 'admin มาตอบ' in Facebook/LINE"
echo "2. Wait 5 seconds"
echo "3. This script will show if it worked"
echo ""
read -p "Press Enter after you sent the message..."

echo ""
echo "📊 Checking logs for last 2 minutes..."
echo ""

# Check for admin handoff detection
gcloud logging read \
  "resource.type=cloud_run_revision 
   AND resource.labels.service_name=autobot 
   AND (textPayload=~\"ADMIN_HANDOFF\" OR textPayload=~\"admin_handoff\" OR textPayload=~\"V2_BOXDESIGN.*admin\")" \
  --limit=20 \
  --project=autobot-prod-251215-22549 \
  --format="table(timestamp, textPayload)" \
  --freshness=2m

echo ""
echo "🔍 Checking for database errors..."
gcloud logging read \
  "resource.type=cloud_run_revision 
   AND resource.labels.service_name=autobot 
   AND severity>=ERROR
   AND (textPayload=~\"last_admin_message_at\" OR textPayload=~\"Unknown column\" OR textPayload=~\"Column not found\")" \
  --limit=10 \
  --project=autobot-prod-251215-22549 \
  --format="table(timestamp, severity, textPayload)" \
  --freshness=5m

echo ""
echo "📋 What to look for:"
echo "  ✅ Should see: [ADMIN_HANDOFF] Manual command detected"
echo "  ✅ Should see: [V2_BOXDESIGN] Bot paused - admin handoff active"
echo "  ❌ Should NOT see: Unknown column 'last_admin_message_at'"
echo ""
