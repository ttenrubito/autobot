-- Add GCS support columns to application_documents table
-- Run this migration on production database

ALTER TABLE application_documents
ADD COLUMN IF NOT EXISTS gcs_path VARCHAR(500) COMMENT 'Path in Google Cloud Storage',
ADD COLUMN IF NOT EXISTS gcs_signed_url TEXT COMMENT 'GCS signed URL (temporary)',
ADD COLUMN IF NOT EXISTS gcs_signed_url_expires_at DATETIME COMMENT 'Expiration time for signed URL';

-- Add index for faster lookups
ALTER TABLE application_documents
ADD INDEX idx_gcs_path (gcs_path);

-- Comment
COMMENT ON COLUMN application_documents.gcs_path IS 'Full path in GCS bucket (e.g., documents/U123/doc_123.jpg)';
COMMENT ON COLUMN application_documents.file_path IS 'Legacy: Local file path (deprecated, use gcs_path)';
