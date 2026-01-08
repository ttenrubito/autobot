#!/bin/bash
# Watch for admin handoff logs in real-time

echo "🔍 Watching for ADMIN_HANDOFF logs (real-time)..."
echo "💡 Please type 'admin' in Facebook Messenger now"
echo ""

gcloud logging tail "resource.type=\"cloud_run_revision\" AND resource.labels.service_name=\"autobot\"" \
  --project=autobot-prod-251215-22549 \
  --format="value(timestamp,textPayload,jsonPayload.message)" \
  | grep --line-buffered -i "admin"
