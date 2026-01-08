-- Migration: Fix application_documents schema to support LIFF uploads + GCS
-- Target: Cloud SQL (production)
-- Date: 2026-01-05
--
-- Problem:
--   API insert fails with: Unknown column 'file_path'
--
-- Strategy:
--   1) Ensure `file_path` exists (legacy column used by API as a generic path)
--   2) Ensure `original_filename` exists (some queries reference it)
--   3) Ensure GCS columns exist (gcs_path, gcs_signed_url, gcs_signed_url_expires_at)
--   4) Add index for gcs_path

-- Inspect current columns
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'application_documents'
ORDER BY ORDINAL_POSITION;

-- 1) Add legacy file_path if missing
ALTER TABLE application_documents
  ADD COLUMN IF NOT EXISTS file_path VARCHAR(500) NULL COMMENT 'Legacy: Local file path (deprecated - use gcs_path instead)';

-- 2) Add original_filename if your schema uses file_name but not original_filename
ALTER TABLE application_documents
  ADD COLUMN IF NOT EXISTS original_filename VARCHAR(255) NULL COMMENT 'Original uploaded filename';

-- 3) Add GCS columns
ALTER TABLE application_documents
  ADD COLUMN IF NOT EXISTS gcs_path VARCHAR(500) NULL COMMENT 'Path in Google Cloud Storage bucket',
  ADD COLUMN IF NOT EXISTS gcs_signed_url TEXT NULL COMMENT 'GCS signed URL (temporary, expires)',
  ADD COLUMN IF NOT EXISTS gcs_signed_url_expires_at DATETIME NULL COMMENT 'Expiration time for signed URL';

-- 4) Index
CREATE INDEX IF NOT EXISTS idx_gcs_path ON application_documents(gcs_path);

-- Verify expected columns
SELECT COLUMN_NAME, COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'application_documents'
  AND COLUMN_NAME IN ('file_path','original_filename','gcs_path','gcs_signed_url','gcs_signed_url_expires_at')
ORDER BY COLUMN_NAME;
