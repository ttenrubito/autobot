-- Migration SQL for Cloud Run Job deployment
USE autobot;

-- Add role column if not exists
ALTER TABLE chat_messages 
ADD COLUMN IF NOT EXISTS role ENUM('user','assistant','system','admin') NOT NULL DEFAULT 'user' 
COMMENT 'Message sender role - enables admin handoff detection'
AFTER sender_type;

-- Migrate existing data  
UPDATE chat_messages 
SET role = CASE 
    WHEN sender_type = 'customer' THEN 'user'
    WHEN sender_type = 'bot' THEN 'assistant'
    WHEN sender_type = 'agent' THEN 'admin'
    ELSE 'system'
END
WHERE role = 'user';

-- Verify
SELECT 'Migration completed!' as status;
