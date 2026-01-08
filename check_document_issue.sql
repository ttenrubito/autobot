-- Quick check campaign configuration
SELECT 
    id,
    code,
    name,
    JSON_PRETTY(required_documents) as required_documents_config,
    JSON_PRETTY(form_config) as form_config
FROM campaigns
WHERE id = 2;

-- Check application documents
SELECT 
    ad.id,
    ad.application_id,
    ad.document_type,
    ad.file_name,
    ad.file_path,
    ad.gcs_path,
    LENGTH(ad.gcs_signed_url) as signed_url_length,
    ad.uploaded_at
FROM application_documents ad
WHERE ad.application_id = 1;

-- Check if gcs columns exist
SHOW COLUMNS FROM application_documents LIKE 'gcs%';
