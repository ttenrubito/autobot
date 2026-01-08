#!/bin/bash

echo "🔍 Connecting to Production Database"
echo "======================================"
echo ""
echo "⚠️  This will connect to Cloud SQL and run the migration"
echo ""

cd /opt/lampp/htdocs/autobot

# Connect and run SQL
echo "📊 Executing migration SQL..."
gcloud sql connect autobot-db \
  --user=root \
  --project=autobot-prod-251215-22549 \
  < add_column_to_prod.sql

echo ""
echo "✅ Database migration complete!"
echo ""
echo "🎯 Next: Test admin handoff in Facebook Messenger"
echo "   1. Send message: admin"
echo "   2. Bot should stop replying"
echo "   3. Check logs: gcloud logging tail --service=autobot"
