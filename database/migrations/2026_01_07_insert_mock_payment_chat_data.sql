-- ============================================================================
-- Mock Data for Payment + Chat Messages Testing
-- Run this to insert sample data for testing payment-history page
-- Created: 2026-01-07
-- ============================================================================

-- ============================================================================
-- 1. Get sample payments to work with
-- ============================================================================

-- Get a verified payment
SET @sample_payment_id = (SELECT id FROM payments WHERE status = 'verified' ORDER BY id DESC LIMIT 1);
SET @sample_customer_name = (SELECT COALESCE(customer_name, 'ลูกค้า') FROM payments WHERE id = @sample_payment_id);
SET @sample_platform = (SELECT COALESCE(customer_platform, 'line') FROM payments WHERE id = @sample_payment_id);
SET @sample_platform_id = (SELECT COALESCE(customer_platform_id, CONCAT('U', FLOOR(RAND() * 1000000000))) FROM payments WHERE id = @sample_payment_id);
SET @sample_payment_date = (SELECT COALESCE(payment_date, NOW()) FROM payments WHERE id = @sample_payment_id);
SET @sample_amount = (SELECT COALESCE(amount, 0) FROM payments WHERE id = @sample_payment_id);

-- Build conversation_id
SET @conversation_id = CONCAT(@sample_platform, '_1_', @sample_platform_id);

-- ============================================================================
-- 2. Create conversation record first (required for FK)
-- ============================================================================

-- Insert or update conversation for verified payment
INSERT INTO conversations (
    conversation_id,
    customer_id,
    tenant_id,
    platform,
    platform_user_id,
    platform_user_name,
    started_at,
    last_message_at,
    status,
    message_count,
    created_at,
    updated_at
) VALUES (
    @conversation_id,
    NULL,
    'default',
    @sample_platform,
    @sample_platform_id,
    @sample_customer_name,
    DATE_SUB(@sample_payment_date, INTERVAL 30 MINUTE),
    @sample_payment_date,
    'active',
    7,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE 
    last_message_at = @sample_payment_date,
    message_count = 7,
    updated_at = NOW();

-- Update the payment with conversation_id
UPDATE payments 
SET payment_details = JSON_SET(
    COALESCE(payment_details, '{}'),
    '$.conversation_id', @conversation_id,
    '$.platform_user_id', @sample_platform_id
),
customer_platform_id = COALESCE(customer_platform_id, @sample_platform_id)
WHERE id = @sample_payment_id;

-- ============================================================================
-- 3. Insert chat messages for verified payment
-- ============================================================================

-- Delete existing mock messages for this conversation
DELETE FROM chat_messages WHERE conversation_id = @conversation_id AND message_id LIKE 'mock_%';

-- Insert chat messages
INSERT INTO chat_messages (
    conversation_id, tenant_id, message_id, platform, direction, sender_type, sender_id,
    message_type, message_text, message_data, sent_at, received_at, created_at
) VALUES
-- Customer asks about product (30 min before payment)
(
    @conversation_id, 'default', CONCAT('mock_', @sample_payment_id, '_1'),
    @sample_platform, 'incoming', 'customer', @sample_platform_id,
    'text', 'สวัสดีครับ อยากสอบถามสินค้าหน่อยครับ', NULL,
    DATE_SUB(@sample_payment_date, INTERVAL 30 MINUTE),
    DATE_SUB(@sample_payment_date, INTERVAL 30 MINUTE), NOW()
),
-- Bot responds
(
    @conversation_id, 'default', CONCAT('mock_', @sample_payment_id, '_2'),
    @sample_platform, 'outgoing', 'bot', 'bot',
    'text', 'สวัสดีค่ะ! ยินดีให้บริการค่ะ มีสินค้าอะไรที่สนใจเป็นพิเศษไหมคะ? 😊', NULL,
    DATE_SUB(@sample_payment_date, INTERVAL 29 MINUTE),
    DATE_SUB(@sample_payment_date, INTERVAL 29 MINUTE), NOW()
),
-- Customer asks for payment
(
    @conversation_id, 'default', CONCAT('mock_', @sample_payment_id, '_3'),
    @sample_platform, 'incoming', 'customer', @sample_platform_id,
    'text', CONCAT('ขอชำระเงินครับ ยอด ', FORMAT(@sample_amount, 0), ' บาท'), NULL,
    DATE_SUB(@sample_payment_date, INTERVAL 5 MINUTE),
    DATE_SUB(@sample_payment_date, INTERVAL 5 MINUTE), NOW()
),
-- Bot confirms payment info
(
    @conversation_id, 'default', CONCAT('mock_', @sample_payment_id, '_4'),
    @sample_platform, 'outgoing', 'bot', 'bot',
    'text', CONCAT('รับทราบค่ะ ยอดชำระ ', FORMAT(@sample_amount, 0), ' บาท\n\nกรุณาโอนเงินมาที่:\n📱 พร้อมเพย์: 089-xxx-xxxx\n🏦 กสิกร: xxx-x-xxxxx-x\n\nแล้วส่งสลิปมาให้ด้วยนะคะ ✨'), NULL,
    DATE_SUB(@sample_payment_date, INTERVAL 4 MINUTE),
    DATE_SUB(@sample_payment_date, INTERVAL 4 MINUTE), NOW()
),
-- Customer sends slip image
(
    @conversation_id, 'default', CONCAT('mock_', @sample_payment_id, '_5'),
    @sample_platform, 'incoming', 'customer', @sample_platform_id,
    'image', '[รูปภาพสลิปโอนเงิน]',
    JSON_OBJECT('attachments', JSON_ARRAY(JSON_OBJECT('type', 'image', 'url', '/images/slip-kbank.svg'))),
    @sample_payment_date, @sample_payment_date, NOW()
),
-- Bot acknowledges
(
    @conversation_id, 'default', CONCAT('mock_', @sample_payment_id, '_6'),
    @sample_platform, 'outgoing', 'bot', 'bot',
    'text', 'ได้รับสลิปแล้วค่ะ! 🎉 กำลังตรวจสอบยอดเงิน กรุณารอสักครู่นะคะ...', NULL,
    DATE_ADD(@sample_payment_date, INTERVAL 10 SECOND),
    DATE_ADD(@sample_payment_date, INTERVAL 10 SECOND), NOW()
),
-- System confirms
(
    @conversation_id, 'default', CONCAT('mock_', @sample_payment_id, '_7'),
    @sample_platform, 'outgoing', 'bot', 'bot',
    'text', CONCAT('✅ ยืนยันการรับชำระเงินเรียบร้อยแล้วค่ะ!\n💰 ยอด: ', FORMAT(@sample_amount, 0), ' บาท\n\nขอบคุณที่ใช้บริการค่ะ 🙏'), NULL,
    DATE_ADD(@sample_payment_date, INTERVAL 5 MINUTE),
    DATE_ADD(@sample_payment_date, INTERVAL 5 MINUTE), NOW()
);

-- ============================================================================
-- 4. Do the same for a pending payment
-- ============================================================================

SET @pending_payment_id = (SELECT id FROM payments WHERE status = 'pending' ORDER BY id DESC LIMIT 1);
SET @pending_customer_name = (SELECT COALESCE(customer_name, 'ลูกค้า') FROM payments WHERE id = @pending_payment_id);
SET @pending_platform = (SELECT COALESCE(customer_platform, 'facebook') FROM payments WHERE id = @pending_payment_id);
SET @pending_platform_id = (SELECT COALESCE(customer_platform_id, CONCAT('FB', FLOOR(RAND() * 1000000000))) FROM payments WHERE id = @pending_payment_id);
SET @pending_payment_date = (SELECT COALESCE(payment_date, NOW()) FROM payments WHERE id = @pending_payment_id);
SET @pending_amount = (SELECT COALESCE(amount, 0) FROM payments WHERE id = @pending_payment_id);
SET @pending_conversation_id = CONCAT(@pending_platform, '_1_', @pending_platform_id);

-- Create conversation for pending payment
INSERT INTO conversations (
    conversation_id, customer_id, tenant_id, platform, platform_user_id, platform_user_name,
    started_at, last_message_at, status, message_count, created_at, updated_at
) VALUES (
    @pending_conversation_id, NULL, 'default', @pending_platform, @pending_platform_id, @pending_customer_name,
    DATE_SUB(@pending_payment_date, INTERVAL 20 MINUTE), @pending_payment_date, 'active', 4, NOW(), NOW()
) ON DUPLICATE KEY UPDATE 
    last_message_at = @pending_payment_date,
    message_count = 4,
    updated_at = NOW();

-- Update pending payment with conversation_id
UPDATE payments 
SET payment_details = JSON_SET(
    COALESCE(payment_details, '{}'),
    '$.conversation_id', @pending_conversation_id,
    '$.platform_user_id', @pending_platform_id
),
customer_platform_id = COALESCE(customer_platform_id, @pending_platform_id)
WHERE id = @pending_payment_id;

-- Delete existing mock messages
DELETE FROM chat_messages WHERE conversation_id = @pending_conversation_id AND message_id LIKE 'mock_%';

-- Insert messages for pending payment
INSERT INTO chat_messages (
    conversation_id, tenant_id, message_id, platform, direction, sender_type, sender_id,
    message_type, message_text, message_data, sent_at, received_at, created_at
) VALUES
(
    @pending_conversation_id, 'default', CONCAT('mock_pending_', @pending_payment_id, '_1'),
    @pending_platform, 'incoming', 'customer', @pending_platform_id,
    'text', 'สวัสดีค่ะ ขอผ่อนชำระสินค้าได้ไหมคะ?', NULL,
    DATE_SUB(@pending_payment_date, INTERVAL 20 MINUTE),
    DATE_SUB(@pending_payment_date, INTERVAL 20 MINUTE), NOW()
),
(
    @pending_conversation_id, 'default', CONCAT('mock_pending_', @pending_payment_id, '_2'),
    @pending_platform, 'outgoing', 'bot', 'bot',
    'text', 'สวัสดีค่ะ! ได้เลยค่ะ เราเปิดให้ผ่อนได้สูงสุด 6 งวดค่ะ 😊', NULL,
    DATE_SUB(@pending_payment_date, INTERVAL 19 MINUTE),
    DATE_SUB(@pending_payment_date, INTERVAL 19 MINUTE), NOW()
),
(
    @pending_conversation_id, 'default', CONCAT('mock_pending_', @pending_payment_id, '_3'),
    @pending_platform, 'incoming', 'customer', @pending_platform_id,
    'image', '[รูปภาพสลิป]', 
    JSON_OBJECT('attachments', JSON_ARRAY(JSON_OBJECT('type', 'image', 'url', '/images/slip-scb.svg'))),
    @pending_payment_date, @pending_payment_date, NOW()
),
(
    @pending_conversation_id, 'default', CONCAT('mock_pending_', @pending_payment_id, '_4'),
    @pending_platform, 'outgoing', 'bot', 'bot',
    'text', 'ได้รับสลิปแล้วค่ะ! กำลังรอการตรวจสอบจากเจ้าหน้าที่ค่ะ ⏳', NULL,
    DATE_ADD(@pending_payment_date, INTERVAL 10 SECOND),
    DATE_ADD(@pending_payment_date, INTERVAL 10 SECOND), NOW()
);

-- ============================================================================
-- Summary
-- ============================================================================
SELECT CONCAT('✅ Mock chat data created for verified payment ID: ', @sample_payment_id) as result1;
SELECT CONCAT('✅ Mock chat data created for pending payment ID: ', @pending_payment_id) as result2;
SELECT 'You can now test the payment-history page to see real chat data in the modal.' as note;
