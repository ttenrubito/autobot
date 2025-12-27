-- ========================================
-- Add Mock Payment Data with Slips for test1@gmail.com
-- ========================================
-- Usage: Run this after setup_test1_user.sql to add realistic payment records
-- with slip images for testing the payment history UI
-- ========================================

USE autobot;

-- Get user ID for test1@gmail.com
SET @test1_user_id = (SELECT id FROM users WHERE email = 'test1@gmail.com' LIMIT 1);

-- Exit if user doesn't exist
SELECT IF(@test1_user_id IS NULL, 
    'ERROR: User test1@gmail.com not found. Please run setup_test1_user.sql first.',
    CONCAT('✅ Found user test1@gmail.com (ID: ', @test1_user_id, ')')) as status;

-- ========================================
-- 1. Add conversations for payment context
-- ========================================

-- Get or create a LINE channel for test1
SET @test1_channel_id = (
    SELECT id FROM customer_channels 
    WHERE user_id = @test1_user_id 
    AND type = 'line' 
    AND is_deleted = 0 
    LIMIT 1
);

-- If no channel exists, create one
INSERT INTO customer_channels (
    user_id, type, name, status, 
    inbound_api_key, config, is_deleted, created_at
)
SELECT 
    @test1_user_id,
    'line',
    'LINE Official - Test1 Shop',
    'active',
    CONCAT('ch_line_test1_', SUBSTRING(MD5(RAND()), 1, 16)),
    JSON_OBJECT(
        'channel_id', '2000000001',
        'channel_secret', 'test_secret_123',
        'channel_access_token', 'test_token_456',
        'bot_basic_id', '@test1shop'
    ),
    0,
    NOW()
WHERE @test1_channel_id IS NULL;

-- Update channel ID if we just created it
SET @test1_channel_id = COALESCE(@test1_channel_id, LAST_INSERT_ID());

-- Create payment-related conversations
INSERT INTO conversations (
    channel_id, external_user_id, external_conversation_id,
    platform, metadata, status, last_message_at, created_at
)
VALUES
-- Conversation 1: Customer who paid full amount
(
    @test1_channel_id,
    'U_line_customer_001',
    'conv_payment_001',
    'line',
    JSON_OBJECT(
        'line_user_name', 'สมชาย ใจดี',
        'line_profile_url', 'https://profile.line-scdn.net/0h1a2b3c4d5e',
        'user_phone', '081-234-5678'
    ),
    'active',
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    DATE_SUB(NOW(), INTERVAL 5 DAY)
),
-- Conversation 2: Customer paying installments
(
    @test1_channel_id,
    'U_line_customer_002',
    'conv_payment_002',
    'line',
    JSON_OBJECT(
        'line_user_name', 'สมหญิง รักสวย',
        'line_profile_url', 'https://profile.line-scdn.net/0h2b3c4d5e6f',
        'user_phone', '082-345-6789'
    ),
    'active',
    DATE_SUB(NOW(), INTERVAL 12 HOUR),
    DATE_SUB(NOW(), INTERVAL 10 DAY)
),
-- Conversation 3: Recent payment with issue
(
    @test1_channel_id,
    'U_line_customer_003',
    'conv_payment_003',
    'line',
    JSON_OBJECT(
        'line_user_name', 'นายทดสอบ ระบบ',
        'line_profile_url', 'https://profile.line-scdn.net/0h3c4d5e6f7g',
        'user_phone', '083-456-7890'
    ),
    'active',
    DATE_SUB(NOW(), INTERVAL 30 MINUTE),
    DATE_SUB(NOW(), INTERVAL 3 DAY)
)
ON DUPLICATE KEY UPDATE last_message_at = VALUES(last_message_at);

-- Get conversation IDs
SET @conv_id_1 = (SELECT conversation_id FROM conversations WHERE external_conversation_id = 'conv_payment_001' LIMIT 1);
SET @conv_id_2 = (SELECT conversation_id FROM conversations WHERE external_conversation_id = 'conv_payment_002' LIMIT 1);
SET @conv_id_3 = (SELECT conversation_id FROM conversations WHERE external_conversation_id = 'conv_payment_003' LIMIT 1);

-- ========================================
-- 2. Create Orders for these conversations
-- ========================================

INSERT INTO orders (
    order_no, customer_id, tenant_id, conversation_id,
    subtotal, discount, tax, shipping_fee, total,
    payment_type, payment_method, installment_period,
    status, order_details, created_at
)
VALUES
-- Order 1: Full payment - verified
(
    'ORDER-CHAT-00123',
    @test1_user_id,
    'default',
    @conv_id_1,
    1350.00, 0.00, 94.50, 50.00, 1494.50,
    'full', 'bank_transfer', NULL,
    'completed',
    JSON_OBJECT(
        'items', JSON_ARRAY(
            JSON_OBJECT('product_name', 'iPhone 15 Pro Case', 'quantity', 1, 'price', 890.00),
            JSON_OBJECT('product_name', 'Screen Protector', 'quantity', 2, 'price', 230.00)
        ),
        'line_user', 'สมชาย ใจดี',
        'shipping_address', 'กรุงเทพฯ 10110'
    ),
    DATE_SUB(NOW(), INTERVAL 2 DAY)
),
-- Order 2: Installment payment - ongoing
(
    'ORDER-CHAT-00124',
    @test1_user_id,
    'default',
    @conv_id_2,
    1420.00, 0.00, 99.40, 50.00, 1569.40,
    'installment', 'bank_transfer', 3,
    'processing',
    JSON_OBJECT(
        'items', JSON_ARRAY(
            JSON_OBJECT('product_name', 'Samsung Galaxy Buds', 'quantity', 1, 'price', 1420.00)
        ),
        'line_user', 'สมหญิง รักสวย',
        'shipping_address', 'เชียงใหม่ 50000'
    ),
    DATE_SUB(NOW(), INTERVAL 10 DAY)
),
-- Order 3: Recent order - payment pending review
(
    'ORDER-CHAT-00125',
    @test1_user_id,
    'default',
    @conv_id_3,
    2890.00, 200.00, 188.30, 0.00, 2878.30,
    'full', 'promptpay', NULL,
    'pending',
    JSON_OBJECT(
        'items', JSON_ARRAY(
            JSON_OBJECT('product_name', 'Smart Watch Series 8', 'quantity', 1, 'price', 2890.00)
        ),
        'line_user', 'นายทดสอบ ระบบ',
        'shipping_address', 'ภูเก็ต 83000',
        'discount_code', 'WELCOME200'
    ),
    DATE_SUB(NOW(), INTERVAL 3 DAY)
)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Get order IDs
SET @order_id_1 = (SELECT id FROM orders WHERE order_no = 'ORDER-CHAT-00123' LIMIT 1);
SET @order_id_2 = (SELECT id FROM orders WHERE order_no = 'ORDER-CHAT-00124' LIMIT 1);
SET @order_id_3 = (SELECT id FROM orders WHERE order_no = 'ORDER-CHAT-00125' LIMIT 1);

-- ========================================
-- 3. Add Payments with Slip Images
-- ========================================

-- Payment 1: Verified full payment with K-Bank slip
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id,
    amount, payment_type, payment_method,
    installment_period, current_period,
    status, slip_image, payment_details,
    verified_by, verified_at,
    payment_date, source, created_at
)
VALUES (
    'PAY-LINE-001',
    @order_id_1,
    @test1_user_id,
    'default',
    1494.50,
    'full',
    'bank_transfer',
    NULL, NULL,
    'verified',
    '/public/images/slip-kbank.svg',
    JSON_OBJECT(
        'bank_name', 'ธนาคารกสิกรไทย',
        'account_number', 'xxx-x-x1234-x',
        'sender_name', 'สมชาย ใจดี',
        'line_user', 'สมชาย ใจดี',
        'line_message_id', 'msg_001',
        'ocr_verified', TRUE,
        'ocr_amount', '1494.50',
        'ocr_time', '2024-12-22 14:35:21',
        'verification_method', 'auto_ocr',
        'matched_order', 'ORDER-CHAT-00123'
    ),
    1, -- Admin verified
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 2 DAY)
)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Payment 2a: First installment (verified) - SCB slip
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id,
    amount, payment_type, payment_method,
    installment_period, current_period,
    status, slip_image, payment_details,
    verified_by, verified_at,
    payment_date, source, created_at
)
VALUES (
    'PAY-LINE-002A',
    @order_id_2,
    @test1_user_id,
    'default',
    523.13, -- 1569.40 / 3
    'installment',
    'bank_transfer',
    3, 1,
    'verified',
    '/public/images/slip-scb.svg',
    JSON_OBJECT(
        'bank_name', 'ธนาคารไทยพาณิชย์',
        'account_number', 'xxx-x-x5678-x',
        'sender_name', 'สมหญิง รักสวย',
        'line_user', 'สมหญิง รักสวย',
        'line_message_id', 'msg_002a',
        'ocr_verified', TRUE,
        'ocr_amount', '523.13',
        'ocr_time', '2024-12-14 16:20:00',
        'verification_method', 'auto_ocr',
        'installment_info', 'งวดที่ 1/3'
    ),
    1,
    DATE_SUB(NOW(), INTERVAL 10 DAY),
    DATE_SUB(NOW(), INTERVAL 10 DAY),
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 10 DAY)
)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Payment 2b: Second installment (pending review) - PromptPay slip
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id,
    amount, payment_type, payment_method,
    installment_period, current_period,
    status, slip_image, payment_details,
    payment_date, source, created_at
)
VALUES (
    'PAY-LINE-002B',
    @order_id_2,
    @test1_user_id,
    'default',
    523.13,
    'installment',
    'promptpay',
    3, 2,
    'pending',
    '/public/images/slip-promptpay.svg',
    JSON_OBJECT(
        'payment_method', 'PromptPay',
        'promptpay_ref', 'REF123456789',
        'sender_name', 'สมหญิง รักสวย',
        'line_user', 'สมหญิง รักสวย',
        'line_message_id', 'msg_002b',
        'ocr_verified', FALSE,
        'ocr_status', 'pending',
        'installment_info', 'งวดที่ 2/3'
    ),
    DATE_SUB(NOW(), INTERVAL 12 HOUR),
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 12 HOUR)
)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Payment 3: Recent payment (rejected - blurry slip) - K-Bank
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id,
    amount, payment_type, payment_method,
    status, slip_image, payment_details,
    rejection_reason,
    payment_date, source, created_at
)
VALUES (
    'PAY-LINE-003',
    @order_id_3,
    @test1_user_id,
    'default',
    2878.30,
    'full',
    'bank_transfer',
    'rejected',
    '/public/images/slip-kbank.svg',
    JSON_OBJECT(
        'bank_name', 'ธนาคารกสิกรไทย',
        'sender_name', 'นายทดสอบ ระบบ',
        'line_user', 'นายทดสอบ ระบบ',
        'line_message_id', 'msg_003',
        'ocr_verified', FALSE,
        'ocr_status', 'failed',
        'ocr_error', 'ไม่สามารถอ่านยอดเงินได้ชัดเจน',
        'rejection_note', 'กรุณาส่งสลิปที่ชัดเจนกว่านี้ หรือติดต่อเจ้าหน้าที่'
    ),
    'สลิปไม่ชัดเจน ไม่สามารถตรวจสอบยอดเงินได้ กรุณาส่งสลิปใหม่ที่มีความชัดเจนกว่านี้',
    DATE_SUB(NOW(), INTERVAL 30 MINUTE),
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 30 MINUTE)
)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ========================================
-- 4. Create Installment Schedule for Order 2
-- ========================================

INSERT INTO installment_schedules (
    order_id, tenant_id, period_number, due_date, amount,
    paid_amount, status, paid_at, payment_id
)
SELECT 
    @order_id_2,
    'default',
    1,
    DATE_SUB(CURDATE(), INTERVAL 10 DAY),
    523.13,
    523.13,
    'paid',
    DATE_SUB(NOW(), INTERVAL 10 DAY),
    (SELECT id FROM payments WHERE payment_no = 'PAY-LINE-002A' LIMIT 1)
WHERE @order_id_2 IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO installment_schedules (
    order_id, tenant_id, period_number, due_date, amount,
    paid_amount, status, paid_at, payment_id
)
SELECT 
    @order_id_2,
    'default',
    2,
    CURDATE(),
    523.13,
    523.13,
    'paid',
    DATE_SUB(NOW(), INTERVAL 12 HOUR),
    (SELECT id FROM payments WHERE payment_no = 'PAY-LINE-002B' LIMIT 1)
WHERE @order_id_2 IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO installment_schedules (
    order_id, tenant_id, period_number, due_date, amount,
    paid_amount, status
)
SELECT 
    @order_id_2,
    'default',
    3,
    DATE_ADD(CURDATE(), INTERVAL 30 DAY),
    523.14, -- รวมเศษ
    0.00,
    'pending'
WHERE @order_id_2 IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ========================================
-- Summary Report
-- ========================================

SELECT '========================================' as '';
SELECT '✅ MOCK PAYMENT DATA ADDED SUCCESSFULLY' as '';
SELECT '========================================' as '';

SELECT 
    CONCAT('User: test1@gmail.com (ID: ', @test1_user_id, ')') as 'User Info';

SELECT 
    COUNT(*) as 'Total Conversations Created'
FROM conversations 
WHERE channel_id = @test1_channel_id;

SELECT 
    COUNT(*) as 'Total Orders Created'
FROM orders 
WHERE customer_id = @test1_user_id 
AND order_no LIKE 'ORDER-CHAT-%';

SELECT 
    payment_no as 'Payment Number',
    order_no as 'Order',
    amount as 'Amount (฿)',
    payment_type as 'Type',
    CONCAT(COALESCE(current_period, '-'), '/', COALESCE(installment_period, '-')) as 'Period',
    status as 'Status',
    slip_image as 'Slip Image Path'
FROM payments p
JOIN orders o ON p.order_id = o.id
WHERE p.customer_id = @test1_user_id 
AND o.order_no LIKE 'ORDER-CHAT-%'
ORDER BY p.created_at DESC;

SELECT '========================================' as '';
SELECT '📝 Next Steps:' as '';
SELECT '1. Login as test1@gmail.com' as '';
SELECT '2. Go to Payment History page' as '';
SELECT '3. Click on any payment to see slip images' as '';
SELECT '4. Test approve/reject functions' as '';
SELECT '========================================' as '';
