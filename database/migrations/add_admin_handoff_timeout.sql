-- Add admin handoff timeout tracking to chat_sessions
-- This enables AI to detect when admin is active and pause responses

ALTER TABLE chat_sessions 
ADD COLUMN last_admin_message_at TIMESTAMP NULL DEFAULT NULL 
COMMENT 'Timestamp of last admin message - used for 1-hour timeout pause'
AFTER summary;

-- Index for efficient timeout queries
ALTER TABLE chat_sessions
ADD INDEX idx_admin_timeout (last_admin_message_at);

-- Verify changes
SELECT 'Migration completed successfully - chat_sessions now has last_admin_message_at column' AS status;
