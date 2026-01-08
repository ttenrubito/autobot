-- Migration: Add Google Cloud Storage Support to application_documents
-- Date: 2026-01-04
-- Description: Add columns for GCS path and signed URLs

-- Check current table structure
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'application_documents'
ORDER BY ORDINAL_POSITION;

-- Add GCS columns
ALTER TABLE application_documents
ADD COLUMN IF NOT EXISTS gcs_path VARCHAR(500) COMMENT 'Path in Google Cloud Storage bucket' AFTER file_path,
ADD COLUMN IF NOT EXISTS gcs_signed_url TEXT COMMENT 'GCS signed URL (temporary, expires)' AFTER gcs_path,
ADD COLUMN IF NOT EXISTS gcs_signed_url_expires_at DATETIME COMMENT 'Expiration time for signed URL' AFTER gcs_signed_url;

-- Add index for faster GCS path lookups
ALTER TABLE application_documents
ADD INDEX IF NOT EXISTS idx_gcs_path (gcs_path);

-- Update comment on file_path to indicate it's deprecated
ALTER TABLE application_documents
MODIFY COLUMN file_path VARCHAR(500) COMMENT 'Legacy: Local file path (deprecated - use gcs_path instead)';

-- Verify changes
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'application_documents'
  AND COLUMN_NAME IN ('file_path', 'gcs_path', 'gcs_signed_url', 'gcs_signed_url_expires_at')
ORDER BY ORDINAL_POSITION;

-- Show sample of existing data
SELECT id, application_id, document_type, file_name, file_path, gcs_path, uploaded_at
FROM application_documents
ORDER BY uploaded_at DESC
LIMIT 5;
