-- Quick fix via MySQL command line
-- Connect: gcloud sql connect autobot-db --user=root --database=autobot_db

-- Fix campaign DEMO2026
UPDATE campaigns
SET required_documents = '[{"type":"id_card","label":"บัตรประชาชน","required":true,"accept":"image/*"},{"type":"house_registration","label":"ทะเบียนบ้าน","required":false,"accept":"image/*,application/pdf"}]'
WHERE code = 'DEMO2026';

-- Verify
SELECT 
    code, 
    name,
    required_documents
FROM campaigns 
WHERE code = 'DEMO2026';
