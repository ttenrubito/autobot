-- Migration: Add channel_id to customer_profiles for tenant isolation
-- Date: 2026-02-03
-- Purpose: Ensure customer profiles are filtered by user's channels

-- Step 1: Add channel_id column if not exists
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'customer_profiles' 
    AND COLUMN_NAME = 'channel_id'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE customer_profiles ADD COLUMN channel_id INT NULL AFTER tenant_id, ADD INDEX idx_channel_id (channel_id)',
    'SELECT "channel_id column already exists" as status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Update existing customer_profiles with channel_id from cases
UPDATE customer_profiles cp
SET channel_id = (
    SELECT c.channel_id 
    FROM cases c 
    WHERE c.external_user_id = cp.platform_user_id 
    AND c.platform = cp.platform 
    LIMIT 1
)
WHERE cp.channel_id IS NULL;

-- Step 3: Update from chat_sessions for any remaining NULL
UPDATE customer_profiles cp
SET channel_id = (
    SELECT cs.channel_id 
    FROM chat_sessions cs 
    WHERE cs.external_user_id = cp.platform_user_id 
    LIMIT 1
)
WHERE cp.channel_id IS NULL;

-- Verify results
SELECT 
    'has_channel_id' as metric, 
    COUNT(*) as count 
FROM customer_profiles WHERE channel_id IS NOT NULL
UNION ALL
SELECT 
    'no_channel_id' as metric, 
    COUNT(*) as count 
FROM customer_profiles WHERE channel_id IS NULL;
