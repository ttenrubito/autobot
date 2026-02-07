-- Migration: Add user_id and platform_user_id to customer_addresses
-- Date: 2026-01-18
-- Purpose: Allow mapping addresses to platform users (for chatbot-collected addresses)

-- Add user_id column (for logged-in web users)
ALTER TABLE customer_addresses 
ADD COLUMN user_id INT UNSIGNED NULL AFTER customer_id;

-- Add platform_user_id column (for chatbot users - LINE userId, Facebook PSID)
ALTER TABLE customer_addresses 
ADD COLUMN platform_user_id VARCHAR(255) NULL AFTER user_id;

-- Add platform column to identify which platform the user is from
ALTER TABLE customer_addresses 
ADD COLUMN platform VARCHAR(50) NULL AFTER platform_user_id;

-- Add indexes for lookup
CREATE INDEX idx_customer_addresses_user_id ON customer_addresses(user_id);
CREATE INDEX idx_customer_addresses_platform_user ON customer_addresses(platform_user_id, platform);

-- Verify columns added
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'customer_addresses'
  AND COLUMN_NAME IN ('user_id', 'platform_user_id', 'platform');
