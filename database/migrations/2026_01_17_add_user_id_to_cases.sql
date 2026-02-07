-- Migration: Add user_id column to cases table
-- Purpose: Allow filtering cases by user for customer portal
-- Date: 2026-01-17

-- Add user_id column (nullable - chatbot cases don't have user_id)
ALTER TABLE cases 
ADD COLUMN user_id INT UNSIGNED NULL AFTER customer_id,
ADD INDEX idx_cases_user_id (user_id);

-- Add foreign key constraint
ALTER TABLE cases 
ADD CONSTRAINT fk_cases_user_id 
FOREIGN KEY (user_id) REFERENCES users(id) 
ON DELETE SET NULL ON UPDATE CASCADE;

-- Update existing cases: link to user if external_user_id matches web_user pattern
UPDATE cases c
JOIN users u ON c.external_user_id = CONCAT('web_user_', u.id)
SET c.user_id = u.id
WHERE c.user_id IS NULL AND c.platform = 'web';

-- Verification
SELECT 
    'Cases with user_id' as metric,
    COUNT(*) as count 
FROM cases WHERE user_id IS NOT NULL
UNION ALL
SELECT 
    'Cases without user_id (chatbot)',
    COUNT(*) 
FROM cases WHERE user_id IS NULL;
