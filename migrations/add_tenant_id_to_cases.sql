-- Add tenant_id column to cases table if not exists
-- Run this migration to fix the cases API

-- Check and add tenant_id if missing
SET @dbname = DATABASE();
SET @tablename = 'cases';
SET @columnname = 'tenant_id';
SET @preparedStatement = (
    SELECT IF(
        (
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @dbname
            AND TABLE_NAME = @tablename
            AND COLUMN_NAME = @columnname
        ) > 0,
        'SELECT "tenant_id column already exists"',
        'ALTER TABLE cases ADD COLUMN tenant_id VARCHAR(50) NOT NULL DEFAULT "default" AFTER case_no'
    )
);
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add index on tenant_id for performance
CREATE INDEX IF NOT EXISTS idx_cases_tenant_id ON cases(tenant_id);
