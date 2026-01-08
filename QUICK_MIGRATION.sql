-- ===================================================================
-- Quick Migration Script for GCS Support + Demo Campaign Setup
-- Run this after deployment completes
-- Date: 2026-01-04
-- ===================================================================

-- Step 1: Add GCS columns to application_documents
ALTER TABLE application_documents
ADD COLUMN IF NOT EXISTS gcs_path VARCHAR(500) COMMENT 'Path in Google Cloud Storage bucket' AFTER file_path,
ADD COLUMN IF NOT EXISTS gcs_signed_url TEXT COMMENT 'GCS signed URL (temporary, expires)' AFTER gcs_path,
ADD COLUMN IF NOT EXISTS gcs_signed_url_expires_at DATETIME COMMENT 'Expiration time for signed URL' AFTER gcs_signed_url;

-- Step 2: Add index
ALTER TABLE application_documents
ADD INDEX IF NOT EXISTS idx_gcs_path (gcs_path);

-- Step 3: Update file_path comment
ALTER TABLE application_documents
MODIFY COLUMN file_path VARCHAR(500) COMMENT 'Legacy: Local file path (deprecated - use gcs_path)';

-- Step 4: Update DEMO2026 campaign with required_documents
UPDATE campaigns
SET required_documents = JSON_ARRAY(
    JSON_OBJECT(
        'type', 'id_card',
        'label', 'บัตรประชาชน',
        'required', true,
        'accept', 'image/*'
    ),
    JSON_OBJECT(
        'type', 'house_registration',
        'label', 'ทะเบียนบ้าน',
        'required', true,
        'accept', 'image/*,application/pdf'
    ),
    JSON_OBJECT(
        'type', 'bank_statement',
        'label', 'Statement 3 เดือน',
        'required', false,
        'accept', 'image/*,application/pdf'
    ),
    JSON_OBJECT(
        'type', 'income_proof',
        'label', 'หลักฐานการมีรายได้',
        'required', false,
        'accept', 'image/*,application/pdf'
    )
)
WHERE code = 'DEMO2026';

-- Verify changes
SELECT 
    'GCS Columns Added' as status,
    COUNT(*) as column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'application_documents'
  AND COLUMN_NAME IN ('gcs_path', 'gcs_signed_url', 'gcs_signed_url_expires_at');

-- Check DEMO2026 configuration
SELECT 
    id,
    code,
    name,
    JSON_PRETTY(required_documents) as required_documents_config
FROM campaigns
WHERE code = 'DEMO2026';

SELECT '✅ Migration completed successfully!' as result;
