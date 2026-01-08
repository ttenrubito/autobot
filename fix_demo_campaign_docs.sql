-- Fix Campaign DEMO2026 required_documents with proper labels
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

-- Verify update
SELECT 
    id,
    code,
    name,
    JSON_PRETTY(required_documents) as documents_config
FROM campaigns
WHERE code = 'DEMO2026';
