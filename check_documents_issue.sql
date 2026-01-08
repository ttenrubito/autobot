-- Check applications
SELECT 
    id,
    application_no,
    line_display_name,
    status,
    campaign_id
FROM line_applications
ORDER BY id DESC
LIMIT 5;

-- Check documents for recent applications
SELECT 
    ad.id,
    ad.application_id,
    ad.document_type,
    ad.document_label,
    ad.file_name,
    ad.original_filename,
    ad.file_path,
    ad.gcs_path,
    ad.uploaded_at,
    la.application_no
FROM application_documents ad
LEFT JOIN line_applications la ON ad.application_id = la.id
ORDER BY ad.id DESC
LIMIT 10;

-- Count documents per application
SELECT 
    application_id,
    COUNT(*) as doc_count,
    GROUP_CONCAT(document_type) as types
FROM application_documents
GROUP BY application_id
ORDER BY application_id DESC;
