-- Migration: Add user_id to deposits table for data isolation
-- Date: 2026-01-25
-- Purpose: Each deposit should belong to a specific shop owner (user)

-- Check if column exists, if not add it
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'deposits' 
     AND COLUMN_NAME = 'user_id') > 0,
    'SELECT "Column user_id already exists in deposits table"',
    'ALTER TABLE deposits ADD COLUMN user_id INT UNSIGNED NULL AFTER tenant_id, ADD INDEX idx_deposits_user_id (user_id)'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Populate user_id from existing data
-- Option 1: Set user_id based on customer_id -> customer_profiles -> channel -> user
-- Option 2: Set default value (for existing data)

-- For now, we'll update based on tenant_id -> users mapping
UPDATE deposits d
JOIN users u ON u.tenant_id = d.tenant_id
SET d.user_id = u.id
WHERE d.user_id IS NULL;

-- Add foreign key constraint (optional, may fail if orphaned records exist)
-- ALTER TABLE deposits ADD CONSTRAINT fk_deposits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

SELECT 'Migration completed: user_id added to deposits table' as status;
