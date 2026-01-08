#!/bin/bash
# Apply GCS migration and fix campaign config

echo "🔧 Applying GCS Support Migration & Fixing Campaign Config"
echo "=========================================================="

# Get Cloud SQL connection details (PRODUCTION)
PROJECT_ID="autobot-prod-251215-22549"
INSTANCE_NAME="autobot-db"
DB_NAME="autobot"

echo "📦 Project: $PROJECT_ID"
echo "🗄️  Instance: $INSTANCE_NAME"
echo "💾 Database: $DB_NAME"
echo ""

# Create combined SQL script
cat > /tmp/apply_fixes.sql << 'EOSQL'
-- Step 0: Ensure base columns exist (prod might be missing these)
ALTER TABLE application_documents
  ADD COLUMN IF NOT EXISTS file_path VARCHAR(500) NULL COMMENT 'Legacy: Local file path (deprecated - use gcs_path instead)',
  ADD COLUMN IF NOT EXISTS document_label VARCHAR(255) NULL COMMENT 'Human readable label (e.g., บัตรประชาชน)';

-- Step 1: Add GCS columns to application_documents (if not exists)
ALTER TABLE application_documents
  ADD COLUMN IF NOT EXISTS gcs_path VARCHAR(500) NULL COMMENT 'Path in Google Cloud Storage bucket' AFTER file_path,
  ADD COLUMN IF NOT EXISTS gcs_signed_url TEXT NULL COMMENT 'GCS signed URL (temporary, expires)' AFTER gcs_path,
  ADD COLUMN IF NOT EXISTS gcs_signed_url_expires_at DATETIME NULL COMMENT 'Expiration time for signed URL' AFTER gcs_signed_url;

-- Add index for GCS paths
CREATE INDEX IF NOT EXISTS idx_gcs_path ON application_documents(gcs_path);

-- Step 2: Fix DEMO2026 campaign required_documents
UPDATE campaigns
SET required_documents = '[
    {
        "type": "id_card",
        "label": "บัตรประชาชน",
        "required": true,
        "accept": "image/*"
    },
    {
        "type": "house_registration",
        "label": "ทะเบียนบ้าน",
        "required": false,
        "accept": "image/*,application/pdf"
    }
]'
WHERE code = 'DEMO2026';

-- Step 3: Verify changes
SELECT '=== Verifying application_documents columns ===' as status;
SHOW COLUMNS FROM application_documents;

SELECT '=== Verifying GCS Columns ===' as status;
SHOW COLUMNS FROM application_documents LIKE 'gcs%';

SELECT '=== Verifying Campaign Config ===' as status;
SELECT 
    id,
    code,
    name,
    JSON_PRETTY(required_documents) as required_documents_config
FROM campaigns
WHERE code = 'DEMO2026';

SELECT '=== Migration Complete ===' as status;
EOSQL

echo "📄 SQL script created: /tmp/apply_fixes.sql"
echo ""
echo "🚀 Applying to Cloud SQL..."
echo ""

# Apply to Cloud SQL
gcloud sql connect $INSTANCE_NAME \
    --project=$PROJECT_ID \
    --user=root \
    --database=$DB_NAME \
    --quiet < /tmp/apply_fixes.sql

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ Migration applied successfully!"
    echo ""
    echo "📋 Next steps:"
    echo "  1. Test LIFF form again: https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026"
    echo "  2. Upload a document"
    echo "  3. Check if it appears in admin panel"
    echo ""
else
    echo ""
    echo "❌ Migration failed!"
    echo "Please check the error above"
    exit 1
fi
