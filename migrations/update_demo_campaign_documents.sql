-- Sample campaign with required_documents configuration
-- Use this to test dynamic document fields

-- Update existing DEMO2026 campaign to include required_documents
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

-- Verify update
SELECT 
    id,
    code,
    name,
    JSON_PRETTY(required_documents) as required_documents_formatted
FROM campaigns
WHERE code = 'DEMO2026';
