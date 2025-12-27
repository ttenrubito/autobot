-- Migration: Add index for bot_chat_logs rate limiting query
-- Run this before deploying to production

-- Add composite index for efficient rate limiting queries
CREATE INDEX IF NOT EXISTS idx_chat_logs_rate_limit 
ON bot_chat_logs(customer_service_id, created_at);

-- Verify the index
SHOW INDEX FROM bot_chat_logs WHERE Key_name = 'idx_chat_logs_rate_limit';
