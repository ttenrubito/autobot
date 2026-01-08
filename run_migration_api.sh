#!/bin/bash
# Direct SQL execution via Cloud SQL REST API

PROJECT_ID="canvas-radio-472913-d4"
INSTANCE="autobot-db"
DATABASE="autobot_db"

echo "🔧 Executing migration via REST API..."

# Get access token
ACCESS_TOKEN=$(gcloud auth print-access-token)

# Execute SQL statements one by one
declare -a STATEMENTS=(
    "ALTER TABLE application_documents ADD COLUMN IF NOT EXISTS gcs_path VARCHAR(500) COMMENT 'Path in GCS bucket'"
    "ALTER TABLE application_documents ADD COLUMN IF NOT EXISTS gcs_signed_url TEXT COMMENT 'GCS signed URL (7 days)'"
    "ALTER TABLE application_documents ADD COLUMN IF NOT EXISTS gcs_signed_url_expires_at DATETIME COMMENT 'URL expiration'"

    # Ensure campaign required_documents has Thai labels (form rendering)
    "UPDATE campaigns SET required_documents = '[{\\"type\\":\\"id_card\\",\\"label\\":\\"บัตรประชาชน\\",\\"required\\":true,\\"accept\\":\\"image/*\\"},{\\"type\\":\\"house_registration\\",\\"label\\":\\"สำเนาทะเบียนบ้าน\\",\\"required\\":false,\\"accept\\":\\"image/*,application/pdf\\"},{\\"type\\":\\"book_bank\\",\\"label\\":\\"สมุดบัญชีธนาคาร\\",\\"required\\":false,\\"accept\\":\\"image/*,application/pdf\\"}]' WHERE code = 'DEMO2026'"

    # Backfill existing rows where document_label is empty/null
    "UPDATE application_documents SET document_label = CASE document_type WHEN 'id_card' THEN 'บัตรประชาชน' WHEN 'house_registration' THEN 'สำเนาทะเบียนบ้าน' WHEN 'book_bank' THEN 'สมุดบัญชีธนาคาร' WHEN 'other' THEN 'เอกสารอื่นๆ' ELSE COALESCE(document_type,'เอกสารอื่นๆ') END WHERE document_label IS NULL OR document_label = ''"
)

for SQL in "${STATEMENTS[@]}"; do
    echo "Executing: ${SQL:0:80}..."

    RESPONSE=$(curl -s -X POST \
        "https://sqladmin.googleapis.com/v1/projects/$PROJECT_ID/instances/$INSTANCE/executeStatement" \
        -H "Authorization: Bearer $ACCESS_TOKEN" \
        -H "Content-Type: application/json" \
        -d "{\n            \"database\": \"$DATABASE\",\n            \"statement\": \"$SQL\"\n        }")

    if echo "$RESPONSE" | grep -q '"error"'; then
        echo "  ❌ Error"
        echo "$RESPONSE" | head -c 500
        echo ""
    else
        echo "  ✅ Done"
    fi
done

echo ""
echo "✅ Migration completed!"
echo ""
echo "🧪 Now test at: https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
