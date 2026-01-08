#!/bin/bash
# Monitor admin handoff in real-time

echo "📊 Monitoring Admin Handoff Logs"
echo "================================="
echo ""
echo "Watching for admin commands..."
echo "Press Ctrl+C to stop"
echo ""

gcloud logging tail \
  --service=autobot \
  --project=autobot-prod-251215-22549 \
  --format="table(timestamp, textPayload)" \
  --filter='textPayload=~"ADMIN_HANDOFF" OR textPayload=~"admin"'
