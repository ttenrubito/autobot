#!/bin/bash
# Fix Campaign Labels - Direct Cloud SQL Connection

PROJECT_ID="canvas-radio-472913-d4"
INSTANCE="autobot-db"
DATABASE="autobot_db"

echo "🔧 Fixing Campaign DEMO2026 labels via Cloud SQL Proxy..."
echo ""

# Check if cloud_sql_proxy is running
if ! pgrep -f cloud_sql_proxy > /dev/null; then
    echo "⚠️  Starting Cloud SQL Proxy..."
    cloud_sql_proxy -instances=${PROJECT_ID}:asia-southeast1:${INSTANCE}=tcp:3307 &
    PROXY_PID=$!
    sleep 3
fi

# SQL to fix campaign
SQL="UPDATE campaigns 
SET required_documents = '[
  {\"type\":\"id_card\",\"label\":\"บัตรประชาชน\",\"required\":true,\"accept\":\"image/*\"},
  {\"type\":\"house_registration\",\"label\":\"ทะเบียนบ้าน\",\"required\":false,\"accept\":\"image/*,application/pdf\"}
]' 
WHERE code = 'DEMO2026';"

echo "📝 Executing SQL via proxy..."
echo "$SQL" | gcloud sql connect ${INSTANCE} --user=root --database=${DATABASE} --quiet

echo ""
echo "✅ Verifying campaign configuration..."
gcloud sql connect ${INSTANCE} --user=root --database=${DATABASE} --quiet <<EOFSQL
SELECT 
    id, 
    code, 
    name, 
    JSON_PRETTY(required_documents) as required_docs
FROM campaigns 
WHERE code = 'DEMO2026';
EOFSQL

echo ""
echo "🎉 Campaign fix completed!"
echo ""
echo "📍 Next steps:"
echo "   1. Test LIFF: https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
echo "   2. Check Admin: https://autobot.boxdesign.in.th/line-applications.php"
echo ""
