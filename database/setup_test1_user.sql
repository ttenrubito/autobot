-- ============================================
-- Setup Script for test1@gmail.com
-- Created: 2025-12-23
-- Description: Create test user with sample data for chat, addresses, orders, and payments
-- ============================================

-- ============================================
-- 1. ENSURE TEST USER EXISTS
-- ============================================

-- Create user if not exists (for localhost), update if exists (for prod)
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
    updated_at = NOW();

-- Get user ID
SET @test_user_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');

SELECT CONCAT('Using user: test1@gmail.com (ID: ', @test_user_id, ')') AS status;

-- ============================================
-- 2. CLEANUP EXISTING DATA (IF ANY)
-- ============================================

-- Delete conversations (CASCADE will delete chat_messages and chat_events)
DELETE FROM conversations WHERE customer_id = @test_user_id;

-- Delete payments and installments (must be before orders due to FK)
DELETE FROM installment_schedules WHERE order_id IN (
    SELECT id FROM (SELECT id FROM orders WHERE customer_id = @test_user_id) AS temp_orders
);
DELETE FROM payments WHERE customer_id = @test_user_id;

-- Delete orders and addresses
DELETE FROM orders WHERE customer_id = @test_user_id;
DELETE FROM customer_addresses WHERE customer_id = @test_user_id;

SELECT 'Cleaned up existing test data for test1@gmail.com' AS status;

-- ============================================
-- 3. USER MENU CONFIGURATION
-- ============================================

INSERT INTO user_menu_config (user_email, menu_items, is_active)
VALUES (
    'test1@gmail.com',
    JSON_OBJECT(
        'menus', JSON_ARRAY(
            JSON_OBJECT('id', 'dashboard', 'label', 'Dashboard', 'enabled', true, 'icon', '📊'),
            JSON_OBJECT('id', 'chat_history', 'label', 'ประวัติการสนทนา', 'enabled', true, 'icon', '💬'),
            JSON_OBJECT('id', 'orders', 'label', 'คำสั่งซื้อ', 'enabled', true, 'icon', '📦'),
            JSON_OBJECT('id', 'addresses', 'label', 'ที่อยู่จัดส่ง', 'enabled', true, 'icon', '📍'),
            JSON_OBJECT('id', 'payment_history', 'label', 'ประวัติการชำระ', 'enabled', true, 'icon', '💰'),
            JSON_OBJECT('id', 'profile', 'label', 'โปรไฟล์', 'enabled', true, 'icon', '👤')
        )
    ),
    1
)
ON DUPLICATE KEY UPDATE
    menu_items = JSON_OBJECT(
        'menus', JSON_ARRAY(
            JSON_OBJECT('id', 'dashboard', 'label', 'Dashboard', 'enabled', true, 'icon', '📊'),
            JSON_OBJECT('id', 'chat_history', 'label', 'ประวัติการสนทนา', 'enabled', true, 'icon', '💬'),
            JSON_OBJECT('id', 'orders', 'label', 'คำสั่งซื้อ', 'enabled', true, 'icon', '📦'),
            JSON_OBJECT('id', 'addresses', 'label', 'ที่อยู่จัดส่ง', 'enabled', true, 'icon', '📍'),
            JSON_OBJECT('id', 'payment_history', 'label', 'ประวัติการชำระ', 'enabled', true, 'icon', '💰'),
            JSON_OBJECT('id', 'profile', 'label', 'โปรไฟล์', 'enabled', true, 'icon', '👤')
        )
    );

SELECT 'Created custom menu config for test1@gmail.com' AS status;

-- ============================================
-- 4. CUSTOMER ADDRESSES
-- ============================================

-- Address 1: Default shipping address
INSERT INTO customer_addresses (
    customer_id, tenant_id, address_type, recipient_name, phone,
    address_line1, address_line2, subdistrict, district, province, postal_code,
    additional_info, is_default
) VALUES (
    @test_user_id, 'default', 'shipping', 'คุณทดสอบ ระบบ', '0812345678',
    '123/45 หมู่บ้านสุขสันต์', 'ซอยสุขุมวิท 101', 'บางจาก', 'พระโขนง', 'กรุงเทพมหานคร', '10260',
    JSON_OBJECT(
        'landmark', 'ตรงข้าม Big C บางนา',
        'delivery_note', 'ส่งช่วงเย็นหลัง 17:00 น.',
        'collected_via', 'line_chatbot'
    ),
    1
);

-- Address 2: Work address
INSERT INTO customer_addresses (
    customer_id, tenant_id, address_type, recipient_name, phone,
    address_line1, address_line2, subdistrict, district, province, postal_code,
    additional_info, is_default
) VALUES (
    @test_user_id, 'default', 'shipping', 'คุณทดสอบ ระบบ', '0812345678',
    '999 อาคารสาธรสแควร์', 'ชั้น 15', 'สีลม', 'บางรัก', 'กรุงเทพมหานคร', '10500',
    JSON_OBJECT(
        'landmark', 'อาคารสาธรสแควร์ ติด BTS สุรศักดิ์',
        'delivery_note', 'ส่งตอนเที่ยงได้ 12:00-13:00',
        'collected_via', 'chatbot'
    ),
    0
);

SELECT CONCAT('Created ', COUNT(*), ' addresses for test1@gmail.com') AS status
FROM customer_addresses WHERE customer_id = @test_user_id;

-- ============================================
-- 5. SAMPLE CONVERSATIONS
-- ============================================

-- Conversation 1: Product inquiry (LINE)
INSERT INTO conversations (
    conversation_id, customer_id, tenant_id, platform, platform_user_id,
    started_at, last_message_at, status, message_count,
    conversation_summary
) VALUES (
    'conv_line_001_test1',
    @test_user_id,
    'default',
    'line',
    'U1234567890abcdef',
    DATE_SUB(NOW(), INTERVAL 3 DAY),
    DATE_SUB(NOW(), INTERVAL 3 DAY),
    'ended',
    8,
    JSON_OBJECT(
        'outcome', 'product_inquiry',
        'product_name', 'Rolex Submariner',
        'intent', 'check_price',
        'handled_by', 'bot'
    )
);

-- Messages for Conversation 1
INSERT INTO chat_messages (
    conversation_id, tenant_id, message_id, platform, direction, sender_type,
    message_type, message_text, intent, confidence, sent_at
) VALUES
(
    'conv_line_001_test1', 'default', 'msg_001', 'line', 'incoming', 'customer',
    'text', 'สวัสดีครับ อยากถามราคา Rolex Submariner', 'product_inquiry', 0.95,
    DATE_SUB(NOW(), INTERVAL 3 DAY)
),
(
    'conv_line_001_test1', 'default', 'msg_002', 'line', 'outgoing', 'bot',
    'text', 'สวัสดีครับ Rolex Submariner ราคา 350,000 บาท มีสินค้าพร้อมส่งครับ', NULL, NULL,
    DATE_SUB(NOW(), INTERVAL 3 DAY)
);

-- Conversation 2: Order placement (Facebook)
INSERT INTO conversations (
    conversation_id, customer_id, tenant_id, platform, platform_user_id,
    started_at, last_message_at, status, message_count,
    conversation_summary
) VALUES (
    'conv_fb_002_test1',
    @test_user_id,
    'default',
    'facebook',
    '1234567890',
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    'ended',
    12,
    JSON_OBJECT(
        'outcome', 'order_placed',
        'order_id', 'ORD-20251221-001',
        'product_name', 'Omega Seamaster',
        'total_amount', 280000,
        'payment_type', 'installment'
    )
);

-- Messages for Conversation 2
INSERT INTO chat_messages (
    conversation_id, tenant_id, message_id, platform, direction, sender_type,
    message_type, message_text, intent, confidence, entities, sent_at
) VALUES
(
    'conv_fb_002_test1', 'default', 'msg_fb_001', 'facebook', 'incoming', 'customer',
    'text', 'ผมต้องการสั่ง Omega Seamaster ครับ', 'order_intent', 0.92,
    JSON_OBJECT('product_name', 'Omega Seamaster'),
    DATE_SUB(NOW(), INTERVAL 2 DAY)
),
(
    'conv_fb_002_test1', 'default', 'msg_fb_002', 'facebook', 'outgoing', 'bot',
    'text', 'Omega Seamaster ราคา 280,000 บาท ต้องการผ่อนชำระหรือจ่ายเต็มครับ?', NULL, NULL,
    NULL,
    DATE_SUB(NOW(), INTERVAL 2 DAY)
),
(
    'conv_fb_002_test1', 'default', 'msg_fb_003', 'facebook', 'incoming', 'customer',
    'text', 'ผ่อน 6 เดือนได้ไหมครับ', 'installment_inquiry', 0.88,
    JSON_OBJECT('months', 6),
    DATE_SUB(NOW(), INTERVAL 2 DAY)
);

-- Conversation 3: Payment notification (LINE)
INSERT INTO conversations (
    conversation_id, customer_id, tenant_id, platform, platform_user_id,
    started_at, last_message_at, status, message_count,
    conversation_summary
) VALUES (
    'conv_line_003_test1',
    @test_user_id,
    'default',
    'line',
    'U1234567890abcdef',
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    'ended',
    5,
    JSON_OBJECT(
        'outcome', 'payment_submitted',
        'order_id', 'ORD-20251221-001',
        'payment_amount', 50000,
        'period', 1
    )
);

INSERT INTO chat_messages (
    conversation_id, tenant_id, message_id, platform, direction, sender_type,
    message_type, message_text, message_data, intent, sent_at
) VALUES
(
    'conv_line_003_test1', 'default', 'msg_pay_001', 'line', 'incoming', 'customer',
    'text', 'โอนเงินงวดที่ 1 แล้วครับ', NULL, 'payment_notification',
    DATE_SUB(NOW(), INTERVAL 1 DAY)
),
(
    'conv_line_003_test1', 'default', 'msg_pay_002', 'line', 'incoming', 'customer',
    'image', 'ส่งสลิปโอนเงิน',
    JSON_OBJECT('image_url', 'https://example.com/slip.jpg', 'file_size', 245678),
    'payment_slip_upload',
    DATE_SUB(NOW(), INTERVAL 1 DAY)
);

SELECT CONCAT('Created ', COUNT(*), ' conversations for test1@gmail.com') AS status
FROM conversations WHERE customer_id = @test_user_id;

-- ============================================
-- 6. SAMPLE ORDERS
-- ============================================

-- Get default address
SET @default_addr_id = (SELECT id FROM customer_addresses WHERE customer_id = @test_user_id AND is_default = 1 LIMIT 1);

-- Order 1: Omega Seamaster (Installment)
INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name, product_code,
    quantity, unit_price, total_amount, payment_type, installment_months,
    shipping_address_id, status, source, conversation_id, created_at
) VALUES (
    'ORD-20251221-001',
    @test_user_id,
    'default',
    'Omega Seamaster Professional 300M',
    'OMEGA-SEA-300',
    1,
    280000.00,
    280000.00,
    'installment',
    6,
    @default_addr_id,
    'processing',
    'chatbot',
    'conv_fb_002_test1',
    DATE_SUB(NOW(), INTERVAL 2 DAY)
);

SET @order1_id = LAST_INSERT_ID();

-- Order 2: Rolex Datejust (Full Payment - Completed)
INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name, product_code,
    quantity, unit_price, total_amount, payment_type, installment_months,
    shipping_address_id, status, source, delivered_at, created_at
) VALUES (
    'ORD-20251215-123',
    @test_user_id,
    'default',
    'Rolex Datejust 41',
    'ROLEX-DJ-41',
    1,
    420000.00,
    420000.00,
    'full',
    NULL,
    @default_addr_id,
    'delivered',
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 5 DAY),
    DATE_SUB(NOW(), INTERVAL 10 DAY)
);

SET @order2_id = LAST_INSERT_ID();

SELECT CONCAT('Created ', COUNT(*), ' orders for test1@gmail.com') AS status
FROM orders WHERE customer_id = @test_user_id;

-- ============================================
-- 7. INSTALLMENT SCHEDULES (For Order 1)
-- ============================================

-- 6 installments of 50,000 + 1 installment of 30,000 = 330,000 (includes interest)
INSERT INTO installment_schedules (order_id, tenant_id, period_number, due_date, amount, status, paid_amount, paid_at) VALUES
(@order1_id, 'default', 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 50000.00, 'paid', 50000.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@order1_id, 'default', 2, DATE_ADD(CURDATE(), INTERVAL 29 DAY), 50000.00, 'pending', 0, NULL),
(@order1_id, 'default', 3, DATE_ADD(CURDATE(), INTERVAL 59 DAY), 50000.00, 'pending', 0, NULL),
(@order1_id, 'default', 4, DATE_ADD(CURDATE(), INTERVAL 89 DAY), 50000.00, 'pending', 0, NULL),
(@order1_id, 'default', 5, DATE_ADD(CURDATE(), INTERVAL 119 DAY), 50000.00, 'pending', 0, NULL),
(@order1_id, 'default', 6, DATE_ADD(CURDATE(), INTERVAL 149 DAY), 50000.00, 'pending', 0, NULL);

SELECT CONCAT('Created ', COUNT(*), ' installment schedules') AS status
FROM installment_schedules WHERE order_id = @order1_id;

-- ============================================
-- 8. PAYMENTS
-- ============================================

-- Payment 1: Full payment for Order 2 (Verified)
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id, amount,
    payment_type, payment_method, status, slip_image,
    payment_details, verified_at, payment_date, source, created_at
) VALUES (
    'PAY-20251215-001',
    @order2_id,
    @test_user_id,
    'default',
    420000.00,
    'full',
    'bank_transfer',
    'verified',
    '/uploads/slips/test1_payment1.jpg',
    JSON_OBJECT(
        'bank_info', JSON_OBJECT(
            'bank_name', 'ธนาคารกสิกรไทย',
            'bank_code', 'KBANK',
            'transfer_time', '14:30'
        ),
        'verification_notes', JSON_OBJECT(
            'verified_by_name', 'Admin',
            'notes', 'ตรวจสอบแล้วถูกต้อง'
        )
    ),
    DATE_SUB(NOW(), INTERVAL 8 DAY),
    DATE_SUB(NOW(), INTERVAL 9 DAY),
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 9 DAY)
);

-- Payment 2: First installment for Order 1 (Verified)
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id, amount,
    payment_type, payment_method, installment_period, current_period,
    status, slip_image, payment_details, verified_at, payment_date, source, created_at
) VALUES (
    'PAY-20251222-001',
    @order1_id,
    @test_user_id,
    'default',
    50000.00,
    'installment',
    'bank_transfer',
    6,
    1,
    'verified',
    '/uploads/slips/test1_payment2.jpg',
    JSON_OBJECT(
        'bank_info', JSON_OBJECT(
            'bank_name', 'ธนาคารกรุงเทพ',
            'bank_code', 'BBL',
            'transfer_time', '10:15'
        ),
        'chatbot_data', JSON_OBJECT(
            'platform', 'line',
            'conversation_id', 'conv_line_003_test1'
        )
    ),
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 1 DAY)
);

SET @payment2_id = LAST_INSERT_ID();

-- Link payment to installment schedule
UPDATE installment_schedules 
SET payment_id = @payment2_id 
WHERE order_id = @order1_id AND period_number = 1;

-- Payment 3: Second installment (Pending verification)
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id, amount,
    payment_type, payment_method, installment_period, current_period,
    status, slip_image, payment_details, payment_date, source, created_at
) VALUES (
    'PAY-20251223-001',
    @order1_id,
    @test_user_id,
    'default',
    50000.00,
    'installment',
    'promptpay',
    6,
    2,
    'pending',
    '/uploads/slips/test1_payment3.jpg',
    JSON_OBJECT(
        'bank_info', JSON_OBJECT(
            'payment_method', 'PromptPay',
            'transfer_time', '09:30'
        ),
        'chatbot_data', JSON_OBJECT(
            'platform', 'line',
            'message', 'โอนงวดที่ 2 แล้วครับ'
        )
    ),
    NOW(),
    'chatbot',
    NOW()
);

SELECT CONCAT('Created ', COUNT(*), ' payments for test1@gmail.com') AS status
FROM payments WHERE customer_id = @test_user_id;

-- ============================================
-- 9. CHAT EVENTS
-- ============================================

INSERT INTO chat_events (conversation_id, tenant_id, event_type, event_data) VALUES
(
    'conv_fb_002_test1',
    'default',
    'order_placed',
    JSON_OBJECT(
        'order_id', @order1_id,
        'order_no', 'ORD-20251221-001',
        'amount', 280000,
        'payment_type', 'installment'
    )
),
(
    'conv_line_003_test1',
    'default',
    'payment_submitted',
    JSON_OBJECT(
        'order_id', @order1_id,
        'payment_amount', 50000,
        'period', 1,
        'has_slip', true
    )
);

SELECT 'Created chat events' AS status;

-- ============================================
-- SUMMARY
-- ============================================

SELECT '========================================' AS '';
SELECT 'Setup completed for test1@gmail.com!' AS status;
SELECT '========================================' AS '';
SELECT CONCAT('User ID: ', @test_user_id) AS info;
SELECT CONCAT('Addresses: ', COUNT(*)) AS info FROM customer_addresses WHERE customer_id = @test_user_id;
SELECT CONCAT('Conversations: ', COUNT(*)) AS info FROM conversations WHERE customer_id = @test_user_id;
SELECT CONCAT('Orders: ', COUNT(*)) AS info FROM orders WHERE customer_id = @test_user_id;
SELECT CONCAT('Payments: ', COUNT(*)) AS info FROM payments WHERE customer_id = @test_user_id;
SELECT '========================================' AS '';
SELECT 'Login credentials:' AS '';
SELECT '  Email: test1@gmail.com' AS '';
SELECT '  Password: password123' AS '';
SELECT '========================================' AS '';
