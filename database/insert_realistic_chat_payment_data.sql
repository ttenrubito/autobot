-- ============================================================================
-- Insert Realistic Chat Payment Data for Payment History Demo
-- ============================================================================
-- This script inserts realistic mock data that simulates:
-- - LINE chat conversations with customers
-- - Payment slips uploaded via chat
-- - Orders with different payment statuses
-- Target user: test1@gmail.com
-- ============================================================================

SET @test_user_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');

-- If user doesn't exist, create it
INSERT INTO users (email, password_hash, full_name, phone, status)
VALUES (
    'test1@gmail.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'ทดสอบ ระบบ',
    '0812345678',
    'active'
)
ON DUPLICATE KEY UPDATE
    full_name = 'ทดสอบ ระบบ',
    phone = '0812345678',
    updated_at = NOW();

SET @test_user_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');

-- Cleanup existing test data
DELETE FROM conversation_messages WHERE conversation_id IN (
    SELECT conversation_id FROM conversations WHERE customer_id = @test_user_id
);
DELETE FROM conversations WHERE customer_id = @test_user_id;
DELETE FROM installment_schedules WHERE order_id IN (
    SELECT id FROM (SELECT id FROM orders WHERE customer_id = @test_user_id) AS temp_orders
);
DELETE FROM payments WHERE customer_id = @test_user_id;
DELETE FROM orders WHERE customer_id = @test_user_id;
DELETE FROM customer_addresses WHERE customer_id = @test_user_id;

SELECT CONCAT('Cleaned up existing data for test1@gmail.com (ID: ', @test_user_id, ')') AS status;

-- ============================================
-- 1. CREATE CUSTOMER ADDRESSES
-- ============================================

INSERT INTO customer_addresses (
    customer_id,
    tenant_id,
    contact_name,
    phone,
    address_line1,
    address_line2,
    city,
    state,
    postal_code,
    country,
    is_default,
    additional_info
) VALUES
(
    @test_user_id,
    'default',
    'คุณทดสอบ ระบบ',
    '0812345678',
    '123/45 ซอยสุขุมวิท 21',
    'แขวงคลองเตยเหนือ',
    'กรุงเทพมหานคร',
    'กรุงเทพฯ',
    '10110',
    'TH',
    1,
    JSON_OBJECT(
        'delivery_note', 'ส่งช่วงเช้า 9-12 น.',
        'building', 'อาคาร A ชั้น 5'
    )
);

SET @address_id = LAST_INSERT_ID();

SELECT CONCAT('Created address ID: ', @address_id) AS status;

-- ============================================
-- 2. CREATE CONVERSATIONS (LINE CHATS)
-- ============================================

-- Conversation 1: สั่งซื้อสินค้าเดี่ยว (จ่ายเต็ม - อนุมัติแล้ว)
INSERT INTO conversations (
    conversation_id,
    customer_id,
    tenant_id,
    platform,
    platform_user_id,
    platform_user_name,
    status,
    metadata,
    created_at,
    updated_at
) VALUES (
    'LINE_CONV_001',
    @test_user_id,
    'default',
    'line',
    'U1234567890abcdef',
    'ทดสอบ ระบบ',
    'active',
    JSON_OBJECT(
        'line_profile_url', 'https://profile.line-scdn.net/0h_bHyPEE9OGFrSQzI5zs6cHZYDnUZSzotB15TMBobcDFYBjpxBQ4aYh8bczdcAWtwUwkfMhsacjI',
        'user_phone', '0812345678',
        'tags', JSON_ARRAY('ลูกค้าประจำ', 'ชำระตรงเวลา'),
        'display_name', 'ทดสอบ ระบบ',
        'status_message', 'มั่นใจในคุณภาพสินค้า'
    ),
    DATE_SUB(NOW(), INTERVAL 3 DAY),
    DATE_SUB(NOW(), INTERVAL 2 DAY)
);

-- Conversation 2: สั่งซื้อผ่อนชำระ (งวด 1 รออนุมัติ, งวด 2 ถูกปฏิเสธ)
INSERT INTO conversations (
    conversation_id,
    customer_id,
    tenant_id,
    platform,
    platform_user_id,
    platform_user_name,
    status,
    metadata,
    created_at,
    updated_at
) VALUES (
    'LINE_CONV_002',
    @test_user_id,
    'default',
    'line',
    'U1234567890abcdef',
    'ทดสอบ ระบบ',
    'active',
    JSON_OBJECT(
        'line_profile_url', 'https://profile.line-scdn.net/0h_bHyPEE9OGFrSQzI5zs6cHZYDnUZSzotB15TMBobcDFYBjpxBQ4aYh8bczdcAWtwUwkfMhsacjI',
        'user_phone', '0812345678',
        'tags', JSON_ARRAY('ผ่อนชำระ'),
        'display_name', 'ทดสอบ ระบบ',
        'status_message', 'พร้อมชำระตรงเวลา'
    ),
    DATE_SUB(NOW(), INTERVAL 15 DAY),
    DATE_SUB(NOW(), INTERVAL 1 HOUR)
);

-- ============================================
-- 3. CREATE CONVERSATION MESSAGES
-- ============================================

-- Messages for Conversation 1 (จ่ายเต็ม)
INSERT INTO conversation_messages (
    conversation_id,
    sender_type,
    sender_id,
    message_type,
    message_content,
    metadata,
    created_at
) VALUES
-- Customer starts conversation
(
    'LINE_CONV_001',
    'customer',
    'U1234567890abcdef',
    'text',
    'สวัสดีครับ สนใจสั่งซื้อสินค้าครับ',
    JSON_OBJECT('platform', 'line'),
    DATE_SUB(NOW(), INTERVAL 3 DAY)
),
-- Bot responds
(
    'LINE_CONV_001',
    'bot',
    'bot_system',
    'text',
    'สวัสดีครับ ยินดีให้บริการครับ 😊 ต้องการสั่งซื้ออะไรดีครับ?',
    JSON_OBJECT('platform', 'line', 'intent', 'greeting'),
    DATE_SUB(NOW(), INTERVAL 3 DAY)
),
-- Customer orders
(
    'LINE_CONV_001',
    'customer',
    'U1234567890abcdef',
    'text',
    'อยากได้ชุด API Integration Package ครับ',
    JSON_OBJECT('platform', 'line'),
    DATE_SUB(NOW(), INTERVAL 3 DAY)
),
-- Bot confirms order
(
    'LINE_CONV_001',
    'bot',
    'bot_system',
    'text',
    '✅ ยืนยันคำสั่งซื้อ\n📦 API Integration Package\n💰 ราคา: 1,490.00 บาท\n\nกรุณาโอนเงินและส่งสลิปมาที่แชทนี้ครับ',
    JSON_OBJECT(
        'platform', 'line',
        'order_created', true,
        'order_no', 'ORDER-LINE-001',
        'amount', 1490.00
    ),
    DATE_SUB(NOW(), INTERVAL 3 DAY)
),
-- Customer sends slip
(
    'LINE_CONV_001',
    'customer',
    'U1234567890abcdef',
    'image',
    'โอนเงินเรียบร้อยแล้วครับ',
    JSON_OBJECT(
        'platform', 'line',
        'image_url', '/images/slip-kbank.svg',
        'file_type', 'image/svg+xml'
    ),
    DATE_SUB(NOW(), INTERVAL 2 DAY)
),
-- Bot acknowledges
(
    'LINE_CONV_001',
    'bot',
    'bot_system',
    'text',
    '✅ ได้รับสลิปแล้วครับ\nระบบกำลังตรวจสอบ OCR และยอดเงิน...\n⏱ โปรดรอสักครู่',
    JSON_OBJECT(
        'platform', 'line',
        'slip_received', true,
        'verification_started', true
    ),
    DATE_SUB(NOW(), INTERVAL 2 DAY)
),
-- System approves
(
    'LINE_CONV_001',
    'bot',
    'bot_system',
    'text',
    '🎉 ตรวจสอบเรียบร้อย!\n✅ ยืนยันการชำระเงินเรียบร้อยแล้ว\n📦 คำสั่งซื้อของคุณกำลังดำเนินการจัดส่ง',
    JSON_OBJECT(
        'platform', 'line',
        'payment_verified', true
    ),
    DATE_SUB(NOW(), INTERVAL 2 DAY)
);

-- Messages for Conversation 2 (ผ่อนชำระ 3 งวด)
INSERT INTO conversation_messages (
    conversation_id,
    sender_type,
    sender_id,
    message_type,
    message_content,
    metadata,
    created_at
) VALUES
-- Customer starts
(
    'LINE_CONV_002',
    'customer',
    'U1234567890abcdef',
    'text',
    'อยากสั่ง Chatbot Premium Package แบบผ่อนได้ไหมครับ',
    JSON_OBJECT('platform', 'line'),
    DATE_SUB(NOW(), INTERVAL 15 DAY)
),
-- Bot responds
(
    'LINE_CONV_002',
    'bot',
    'bot_system',
    'text',
    '✅ ได้เลยครับ!\n📦 Chatbot Premium Package\n💰 ราคา: 1,497.00 บาท\n📅 ผ่อน 3 งวด (งวดละ 499.00 บาท)',
    JSON_OBJECT(
        'platform', 'line',
        'order_created', true,
        'order_no', 'ORDER-LINE-002',
        'total_amount', 1497.00,
        'installment', true,
        'periods', 3
    ),
    DATE_SUB(NOW(), INTERVAL 15 DAY)
),
-- Customer pays period 1 (pending approval)
(
    'LINE_CONV_002',
    'customer',
    'U1234567890abcdef',
    'image',
    'งวดแรกครับ โอนผ่าน PromptPay',
    JSON_OBJECT(
        'platform', 'line',
        'image_url', '/images/slip-promptpay.svg',
        'file_type', 'image/svg+xml',
        'period', 1
    ),
    DATE_SUB(NOW(), INTERVAL 12 HOUR)
),
(
    'LINE_CONV_002',
    'bot',
    'bot_system',
    'text',
    '✅ ได้รับสลิปงวดที่ 1 แล้วครับ\n⏳ รอตรวจสอบจากเจ้าหน้าที่',
    JSON_OBJECT('platform', 'line'),
    DATE_SUB(NOW(), INTERVAL 12 HOUR)
),
-- Customer tries to pay period 2 (will be rejected - blurry slip)
(
    'LINE_CONV_002',
    'customer',
    'U1234567890abcdef',
    'image',
    'งวดที่ 2 ครับ',
    JSON_OBJECT(
        'platform', 'line',
        'image_url', '/images/slip-scb.svg',
        'file_type', 'image/svg+xml',
        'period', 2
    ),
    DATE_SUB(NOW(), INTERVAL 30 MINUTE)
),
(
    'LINE_CONV_002',
    'bot',
    'bot_system',
    'text',
    '❌ ขออภัยครับ สลิปนี้ไม่ชัดเจน\nกรุณาส่งสลิปใหม่ที่มีความชัดเจนกว่านี้',
    JSON_OBJECT(
        'platform', 'line',
        'payment_rejected', true,
        'reason', 'สลิปไม่ชัดเจน ตรวจสอบยอดเงินไม่ได้'
    ),
    DATE_SUB(NOW(), INTERVAL 25 MINUTE)
);

SELECT 'Conversation messages created' AS status;

-- ============================================
-- 4. CREATE ORDERS
-- ============================================

-- Order 1: จ่ายเต็ม (verified)
INSERT INTO orders (
    order_no,
    customer_id,
    tenant_id,
    total_amount,
    payment_type,
    payment_method,
    status,
    shipping_address_id,
    conversation_id,
    order_details,
    notes,
    created_at,
    updated_at
) VALUES (
    'ORDER-LINE-001',
    @test_user_id,
    'default',
    1490.00,
    'full',
    'bank_transfer',
    'paid',
    @address_id,
    'LINE_CONV_001',
    JSON_OBJECT(
        'items', JSON_ARRAY(
            JSON_OBJECT(
                'name', 'API Integration Package',
                'quantity', 1,
                'unit_price', 1490.00,
                'description', 'ระบบ API Gateway พร้อม rate limiting'
            )
        ),
        'source', 'line_chat',
        'customer_phone', '0812345678'
    ),
    'ลูกค้าสั่งผ่าน LINE OA',
    DATE_SUB(NOW(), INTERVAL 3 DAY),
    DATE_SUB(NOW(), INTERVAL 2 DAY)
);

SET @order1_id = LAST_INSERT_ID();

-- Order 2: ผ่อนชำระ 3 งวด (processing)
INSERT INTO orders (
    order_no,
    customer_id,
    tenant_id,
    total_amount,
    payment_type,
    payment_method,
    installment_period,
    status,
    shipping_address_id,
    conversation_id,
    order_details,
    notes,
    created_at,
    updated_at
) VALUES (
    'ORDER-LINE-002',
    @test_user_id,
    'default',
    1497.00,
    'installment',
    'bank_transfer',
    3,
    'processing',
    @address_id,
    'LINE_CONV_002',
    JSON_OBJECT(
        'items', JSON_ARRAY(
            JSON_OBJECT(
                'name', 'Chatbot Premium Package',
                'quantity', 1,
                'unit_price', 1497.00,
                'description', 'LINE Chatbot + Google Vision + Knowledge Base'
            )
        ),
        'source', 'line_chat',
        'customer_phone', '0812345678',
        'installment_terms', 'ผ่อน 3 งวด (งวดละ 499 บาท)'
    ),
    'ลูกค้าเลือกผ่อนชำระ 3 งวด',
    DATE_SUB(NOW(), INTERVAL 15 DAY),
    DATE_SUB(NOW(), INTERVAL 1 HOUR)
);

SET @order2_id = LAST_INSERT_ID();

SELECT CONCAT('Created orders: ', @order1_id, ', ', @order2_id) AS status;

-- ============================================
-- 5. CREATE PAYMENTS
-- ============================================

-- Payment 1: Full payment (verified)
INSERT INTO payments (
    payment_no,
    order_id,
    customer_id,
    tenant_id,
    amount,
    payment_type,
    payment_method,
    installment_period,
    current_period,
    status,
    slip_image,
    payment_details,
    verified_by,
    verified_at,
    payment_date,
    created_at,
    updated_at,
    source
) VALUES (
    'PAY-LINE-001',
    @order1_id,
    @test_user_id,
    'default',
    1490.00,
    'full',
    'bank_transfer',
    NULL,
    NULL,
    'verified',
    '/images/slip-kbank.svg',
    JSON_OBJECT(
        'bank', 'KBANK',
        'bank_name', 'ธนาคารกสิกรไทย',
        'transfer_time', DATE_SUB(NOW(), INTERVAL 2 DAY),
        'ocr_result', JSON_OBJECT(
            'amount', 1490.00,
            'ref', 'KB20251223001',
            'confidence', 0.98
        ),
        'conversation_id', 'LINE_CONV_001',
        'line_user', 'ทดสอบ ระบบ'
    ),
    @test_user_id,
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    NOW(),
    'chatbot'
);

-- Payment 2: Installment period 1 (pending approval)
INSERT INTO payments (
    payment_no,
    order_id,
    customer_id,
    tenant_id,
    amount,
    payment_type,
    payment_method,
    installment_period,
    current_period,
    status,
    slip_image,
    payment_details,
    payment_date,
    created_at,
    updated_at,
    source
) VALUES (
    'PAY-LINE-002-P1',
    @order2_id,
    @test_user_id,
    'default',
    499.00,
    'installment',
    'promptpay',
    3,
    1,
    'pending',
    '/images/slip-promptpay.svg',
    JSON_OBJECT(
        'method', 'PromptPay',
        'promptpay_ref', 'PP20251223001',
        'transfer_time', DATE_SUB(NOW(), INTERVAL 12 HOUR),
        'ocr_result', JSON_OBJECT(
            'amount', 499.00,
            'confidence', 0.95,
            'status', 'pending_review'
        ),
        'conversation_id', 'LINE_CONV_002',
        'line_user', 'ทดสอบ ระบบ',
        'period_info', 'งวดที่ 1/3'
    ),
    DATE_SUB(NOW(), INTERVAL 12 HOUR),
    DATE_SUB(NOW(), INTERVAL 12 HOUR),
    NOW(),
    'chatbot'
);

-- Payment 3: Installment period 2 (rejected - blurry slip)
INSERT INTO payments (
    payment_no,
    order_id,
    customer_id,
    tenant_id,
    amount,
    payment_type,
    payment_method,
    installment_period,
    current_period,
    status,
    slip_image,
    payment_details,
    verified_by,
    verified_at,
    rejection_reason,
    payment_date,
    created_at,
    updated_at,
    source
) VALUES (
    'PAY-LINE-002-P2',
    @order2_id,
    @test_user_id,
    'default',
    499.00,
    'installment',
    'bank_transfer',
    3,
    2,
    'rejected',
    '/images/slip-scb.svg',
    JSON_OBJECT(
        'bank', 'SCB',
        'bank_name', 'ธนาคารไทยพาณิชย์',
        'transfer_time', DATE_SUB(NOW(), INTERVAL 30 MINUTE),
        'ocr_result', JSON_OBJECT(
            'status', 'failed',
            'error', 'Image too blurry',
            'confidence', 0.42
        ),
        'conversation_id', 'LINE_CONV_002',
        'line_user', 'ทดสอบ ระบบ',
        'period_info', 'งวดที่ 2/3'
    ),
    @test_user_id,
    DATE_SUB(NOW(), INTERVAL 25 MINUTE),
    'สลิปไม่ชัดเจน ตรวจสอบยอดเงินไม่ได้ กรุณาส่งสลิปใหม่ที่มีความชัดเจน',
    DATE_SUB(NOW(), INTERVAL 30 MINUTE),
    DATE_SUB(NOW(), INTERVAL 30 MINUTE),
    NOW(),
    'chatbot'
);

SELECT 'Payments created successfully!' AS status;

-- ============================================
-- 6. CREATE INSTALLMENT SCHEDULES
-- ============================================

INSERT INTO installment_schedules (
    order_id,
    tenant_id,
    period_number,
    due_date,
    amount,
    paid_amount,
    status,
    paid_at,
    payment_id
) VALUES
-- Period 1 (pending - waiting for approval)
(
    @order2_id,
    'default',
    1,
    DATE_ADD(DATE_SUB(NOW(), INTERVAL 15 DAY), INTERVAL 30 DAY),
    499.00,
    0,
    'pending',
    NULL,
    (SELECT id FROM payments WHERE payment_no = 'PAY-LINE-002-P1')
),
-- Period 2 (pending - rejected payment)
(
    @order2_id,
    'default',
    2,
    DATE_ADD(DATE_SUB(NOW(), INTERVAL 15 DAY), INTERVAL 60 DAY),
    499.00,
    0,
    'pending',
    NULL,
    NULL
),
-- Period 3 (pending - not paid yet)
(
    @order2_id,
    'default',
    3,
    DATE_ADD(DATE_SUB(NOW(), INTERVAL 15 DAY), INTERVAL 90 DAY),
    499.00,
    0,
    'pending',
    NULL,
    NULL
);

SELECT 'Installment schedules created successfully!' AS status;

-- ============================================
-- SUMMARY
-- ============================================

SELECT '========================================' AS '';
SELECT 'DATA INSERTION COMPLETE!' AS '';
SELECT '========================================' AS '';
SELECT '' AS '';
SELECT 'Summary for test1@gmail.com:' AS '';
SELECT CONCAT('- User ID: ', @test_user_id) AS '';
SELECT CONCAT('- Addresses: 1') AS '';
SELECT CONCAT('- Conversations: 2 (LINE chats)') AS '';
SELECT CONCAT('- Messages: ', (SELECT COUNT(*) FROM conversation_messages WHERE conversation_id IN ('LINE_CONV_001', 'LINE_CONV_002'))) AS '';
SELECT CONCAT('- Orders: 2') AS '';
SELECT CONCAT('  • ORDER-LINE-001: Full payment (✅ verified)') AS '';
SELECT CONCAT('  • ORDER-LINE-002: Installment 3 periods') AS '';
SELECT CONCAT('- Payments: 3') AS '';
SELECT CONCAT('  • PAY-LINE-001: ฿1,490 (✅ verified) - KBank slip') AS '';
SELECT CONCAT('  • PAY-LINE-002-P1: ฿499 (⏳ pending) - PromptPay slip') AS '';
SELECT CONCAT('  • PAY-LINE-002-P2: ฿499 (❌ rejected) - SCB slip') AS '';
SELECT '' AS '';
SELECT '✅ Ready for payment-history.php demo!' AS '';
SELECT '========================================' AS '';
