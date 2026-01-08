#!/bin/bash

echo "🔍 Running Production Database Migration via Cloud SQL Proxy"
echo "=============================================================="
echo ""

cd /opt/lampp/htdocs/autobot

# Method 1: Try using gcloud beta
echo "Attempting connection..."
gcloud beta sql connect autobot-db \
  --user=root \
  --project=autobot-prod-251215-22549 \
  < add_column_to_prod.sql

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ Migration completed successfully!"
else
    echo ""
    echo "⚠️  If connection failed, you can:"
    echo "   1. Run manually via GCP Console SQL Editor"
    echo "   2. Copy this SQL and run it:"
    echo ""
    cat add_column_to_prod.sql
fi
