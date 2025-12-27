-- Quick SQL commands to check admin handoff status
-- Run these in Cloud SQL Console or via gcloud

-- 1. Check if role column exists
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT 
FROM 
    INFORMATION_SCHEMA.COLUMNS 
WHERE 
    TABLE_SCHEMA = 'autobot' 
    AND TABLE_NAME = 'chat_messages' 
    AND COLUMN_NAME IN ('role', 'sender_type');

-- 2. If role column doesn't exist, add it:
ALTER TABLE chat_messages 
ADD COLUMN IF NOT EXISTS role ENUM('user','assistant','system','admin') NOT NULL DEFAULT 'user' 
COMMENT 'Message sender role' 
AFTER sender_type;

-- 3. Migrate existing data
UPDATE chat_messages 
SET role = CASE 
    WHEN sender_type = 'customer' THEN 'user'
    WHEN sender_type = 'bot' THEN 'assistant'
    WHEN sender_type = 'agent' THEN 'admin'
    ELSE 'system'
END
WHERE role = 'user';

-- 4. Verify role column is added
SELECT 
    COLUMN_TYPE 
FROM 
    INFORMATION_SCHEMA.COLUMNS 
WHERE 
    TABLE_SCHEMA = 'autobot' 
    AND TABLE_NAME = 'chat_messages' 
    AND COLUMN_NAME = 'role';

-- 5. Check chat_sessions table has last_admin_message_at
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE 
FROM 
    INFORMATION_SCHEMA.COLUMNS 
WHERE 
    TABLE_SCHEMA = 'autobot' 
    AND TABLE_NAME = 'chat_sessions' 
    AND COLUMN_NAME = 'last_admin_message_at';

-- 6. Check recent admin interventions
SELECT 
    cs.id,
    cs.external_user_id,
    cs.last_admin_message_at,
    TIMESTAMPDIFF(SECOND, cs.last_admin_message_at, NOW()) as seconds_since_admin,
    IF(TIMESTAMPDIFF(SECOND, cs.last_admin_message_at, NOW()) < 3600, 'ACTIVE', 'EXPIRED') as status
FROM 
    chat_sessions cs
WHERE 
    cs.last_admin_message_at IS NOT NULL
ORDER BY 
    cs.last_admin_message_at DESC
LIMIT 10;

-- 7. View admin messages
SELECT 
    cm.id,
    cm.conversation_id,
    cm.role,
    cm.sender_type,
    LEFT(cm.message_text, 100) as message_preview,
    cm.sent_at
FROM 
    chat_messages cm
WHERE 
    cm.role = 'admin' 
    OR cm.sender_type = 'agent'
    OR LOWER(cm.message_text) LIKE '%admin%'
ORDER BY 
    cm.sent_at DESC
LIMIT 20;
