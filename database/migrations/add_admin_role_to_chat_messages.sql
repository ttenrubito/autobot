-- Add 'admin' role to chat_messages enum
-- This allows storing admin messages in conversation history for handoff detection

-- Check current enum values before modification
SELECT 
    COLUMN_TYPE 
FROM 
    INFORMATION_SCHEMA.COLUMNS 
WHERE 
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'chat_messages' 
    AND COLUMN_NAME = 'role';

-- Modify enum to include 'admin' role
ALTER TABLE chat_messages 
MODIFY COLUMN role ENUM('user','assistant','system','admin') NOT NULL
COMMENT 'Message sender role - admin enables handoff detection';

-- Verify the change
SELECT 
    COLUMN_TYPE 
FROM 
    INFORMATION_SCHEMA.COLUMNS 
WHERE 
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'chat_messages' 
    AND COLUMN_NAME = 'role';

SELECT 'Migration completed successfully - chat_messages.role now supports admin' AS status;
