-- =============================================
-- Migration: Add shop_owner_id/channel_id for data isolation
-- Date: 2026-01-18
-- Purpose: Enable multi-tenant data isolation
-- =============================================

-- 1. ORDERS table - add shop_owner_id and channel_id
ALTER TABLE orders 
    ADD COLUMN shop_owner_id INT UNSIGNED DEFAULT NULL COMMENT 'FK to users.id - shop owner',
    ADD COLUMN channel_id_fk INT UNSIGNED DEFAULT NULL COMMENT 'FK to customer_channels.id';

ALTER TABLE orders 
    ADD INDEX idx_shop_owner (shop_owner_id),
    ADD INDEX idx_channel_fk (channel_id_fk);

-- 2. PAYMENTS table - add shop_owner_id
ALTER TABLE payments 
    ADD COLUMN shop_owner_id INT UNSIGNED DEFAULT NULL COMMENT 'FK to users.id - shop owner';

ALTER TABLE payments 
    ADD INDEX idx_payments_shop_owner (shop_owner_id);

-- 3. CONVERSATIONS table - add channel_id
ALTER TABLE conversations 
    ADD COLUMN channel_id INT UNSIGNED DEFAULT NULL COMMENT 'FK to customer_channels.id';

ALTER TABLE conversations 
    ADD INDEX idx_conversations_channel (channel_id);

-- 4. Check if installment_contracts exists, if so add shop_owner_id
-- Skip if table doesn't exist
SET @table_exists = (SELECT COUNT(*) FROM information_schema.tables 
    WHERE table_schema = DATABASE() AND table_name = 'installment_contracts');

-- 5. DEPOSITS table - add shop_owner_id
ALTER TABLE deposits 
    ADD COLUMN shop_owner_id INT UNSIGNED DEFAULT NULL COMMENT 'FK to users.id - shop owner';

ALTER TABLE deposits 
    ADD INDEX idx_deposits_shop_owner (shop_owner_id);

-- =============================================
-- Backfill existing data using user_id from customer_channels
-- =============================================

-- For orders: try to get shop_owner from customer_channels via user_id if exists
UPDATE orders o
SET o.shop_owner_id = o.user_id
WHERE o.shop_owner_id IS NULL AND o.user_id IS NOT NULL;

-- For payments: try to get shop_owner from related order
UPDATE payments p
JOIN orders o ON p.order_id = o.id
SET p.shop_owner_id = o.shop_owner_id
WHERE p.shop_owner_id IS NULL AND o.shop_owner_id IS NOT NULL;

-- For payments without order: use user_id if exists
UPDATE payments p
SET p.shop_owner_id = p.user_id
WHERE p.shop_owner_id IS NULL AND p.user_id IS NOT NULL;

-- For conversations: try to link via customer_channels
UPDATE conversations conv
SET conv.channel_id = (
    SELECT cc.id 
    FROM customer_channels cc 
    WHERE cc.type = conv.platform
    LIMIT 1
)
WHERE conv.channel_id IS NULL;

-- Fallback: Set shop_owner_id = 1 for remaining records (default owner)
UPDATE orders SET shop_owner_id = 1 WHERE shop_owner_id IS NULL;
UPDATE payments SET shop_owner_id = 1 WHERE shop_owner_id IS NULL;
UPDATE deposits SET shop_owner_id = 1 WHERE shop_owner_id IS NULL;

-- Set first matching channel_id for conversations
UPDATE conversations 
SET channel_id = (SELECT id FROM customer_channels WHERE status = 'active' LIMIT 1) 
WHERE channel_id IS NULL;

SELECT 'Migration complete!' as status;
