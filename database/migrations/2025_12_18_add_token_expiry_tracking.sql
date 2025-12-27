-- ============================================================
-- Database Schema Updates for Facebook Token Auto-Refresh
-- ============================================================

-- Add token tracking columns to customer_channels
ALTER TABLE customer_channels 
ADD COLUMN token_expires_at DATETIME DEFAULT NULL COMMENT 'วันที่ token จะหมดอายุ' AFTER config,
ADD COLUMN token_last_refreshed_at DATETIME DEFAULT NULL COMMENT 'วันที่ต่ออายุครั้งล่าสุด' AFTER token_expires_at;

-- Set initial expiry for existing Facebook channels (60 days from now)
UPDATE customer_channels 
SET token_expires_at = DATE_ADD(NOW(), INTERVAL 60 DAY),
    token_last_refreshed_at = NOW()
WHERE type = 'facebook' 
  AND status = 'active'
  AND is_deleted = 0
  AND token_expires_at IS NULL;

-- Create index for faster queries
CREATE INDEX idx_token_expiry ON customer_channels(type, token_expires_at, status);
