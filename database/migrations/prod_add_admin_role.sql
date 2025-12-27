-- Migration script for production Cloud SQL
-- Add role column with 'admin' enum support to chat_messages table

USE autobot;

-- Check current schema
SELECT 
    TABLE_NAME,
    COLUMN_NAME, 
    COLUMN_TYPE 
FROM 
    INFORMATION_SCHEMA.COLUMNS 
WHERE 
    TABLE_SCHEMA = 'autobot'
    AND TABLE_NAME = 'chat_messages' 
    AND COLUMN_NAME IN ('role', 'sender_type');

-- Add role column if not exists
ALTER TABLE chat_messages 
ADD COLUMN IF NOT EXISTS role ENUM('user','assistant','system','admin') NOT NULL DEFAULT 'user' 
COMMENT 'Message sender role - enables admin handoff detection'
AFTER sender_type;

-- Migrate existing data from sender_type to role
UPDATE chat_messages 
SET role = CASE 
    WHEN sender_type = 'customer' THEN 'user'
    WHEN sender_type = 'bot' THEN 'assistant'
    WHEN sender_type = 'agent' THEN 'admin'
    ELSE 'system'
END
WHERE role = 'user';

-- Verify the change
SELECT 
    COLUMN_TYPE 
FROM 
    INFORMATION_SCHEMA.COLUMNS 
WHERE 
    TABLE_SCHEMA = 'autobot'
    AND TABLE_NAME = 'chat_messages' 
    AND COLUMN_NAME = 'role';

SELECT 'Migration completed - chat_messages.role now supports admin' AS status;
