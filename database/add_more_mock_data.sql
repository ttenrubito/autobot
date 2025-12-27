-- Additional Mock Data for test1@gmail.com
-- Run this AFTER setup_test1_user.sql

SET @test_user_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');
SET @default_addr_id = (SELECT id FROM customer_addresses WHERE customer_id = @test_user_id AND is_default = 1 LIMIT 1);

-- ============================================
-- 1. เพิ่มที่อยู่ (3 ที่อยู่เพิ่ม = รวม 5 ที่อยู่)
-- ============================================

INSERT INTO customer_addresses (
    customer_id, tenant_id, address_type, recipient_name, phone,
    address_line1, address_line2, subdistrict, district, province, postal_code,
    additional_info, is_default
) VALUES
(
    @test_user_id, 'default', 'shipping', 'คุณทดสอบ ระบบ', '0812345678',
    '456 ถนนพระราม 4', 'แขวงพระโขนง', 'พระโขนง', 'คลองเตย', 'กรุงเทพมหานคร', '10110',
    JSON_OBJECT('landmark', 'ใกล้ BTS พระโขนง', 'delivery_note', 'โทรก่อนส่ง'),
    0
),
(
    @test_user_id, 'default', 'shipping', 'น้องเต้ (ญาติ)', '0898765432',
    '789/12 หมู่บ้านเศรษฐกิจ', 'ซอยรามคำแหง 24', 'หัวหมาก', 'บางกะปิ', 'กรุงเทพมหานคร', '10240',
    JSON_OBJECT('landmark', 'ตรงข้าม The Mall บางกะปิ', 'delivery_note', 'ส่งวันเสาร์-อาทิตย์ได้'),
    0
),
(
    @test_user_id, 'default', 'shipping', 'คุณทดสอบ ระบบ', '0812345678',
    '321 อาคารจัสมิน', 'ชั้น 22 ห้อง 2205', 'ลุมพินี', 'ปทุมวัน', 'กรุงเทพมหานคร', '10330',
    JSON_OBJECT('landmark', 'อาคารจัสมิน ติด BTS ช่องนนทรี', 'delivery_note', 'ฝากยาม'),
    0
);

SELECT CONCAT('เพิ่มที่อยู่แล้ว รวม ', COUNT(*), ' ที่อยู่') AS status
FROM customer_addresses WHERE customer_id = @test_user_id;

-- ============================================
-- 2. เพิ่ม Conversations (2 conversations เพิ่ม = รวม 5)
-- ============================================

-- Conversation 4: ถามข้อมูลผ่อน (LINE)
INSERT INTO conversations (
    conversation_id, customer_id, tenant_id, platform, platform_user_id,
    started_at, last_message_at, status, message_count,
    conversation_summary
) VALUES (
    'conv_line_004_test1',
    @test_user_id,
    'default',
    'line',
    'U1234567890abcdef',
    DATE_SUB(NOW(), INTERVAL 7 DAY),
    DATE_SUB(NOW(), INTERVAL 7 DAY),
    'ended',
    6,
    JSON_OBJECT(
        'outcome', 'installment_inquiry',
        'product_name', 'Cartier Tank',
        'intent', 'ask_installment',
        'handled_by', 'bot'
    )
);

INSERT INTO chat_messages (
    conversation_id, tenant_id, message_id, platform, direction, sender_type,
    message_type, message_text, intent, sent_at
) VALUES
(
    'conv_line_004_test1', 'default', 'msg_004_01', 'line', 'incoming', 'customer',
    'text', 'นาฬิกา Cartier Tank ผ่อน 0% ได้ไหมครับ', 'installment_inquiry',
    DATE_SUB(NOW(), INTERVAL 7 DAY)
),
(
    'conv_line_004_test1', 'default', 'msg_004_02', 'line', 'outgoing', 'bot',
    'text', 'ได้ครับ ผ่อน 0% นาน 10 เดือน งวดละ 15,000 บาทครับ', NULL,
    DATE_SUB(NOW(), INTERVAL 7 DAY)
);

-- Conversation 5: แจ้งปัญหาสินค้า (Facebook)
INSERT INTO conversations (
    conversation_id, customer_id, tenant_id, platform, platform_user_id,
    started_at, last_message_at, status, message_count,
    conversation_summary
) VALUES (
    'conv_fb_005_test1',
    @test_user_id,
    'default',
    'facebook',
    '1234567890',
    DATE_SUB(NOW(), INTERVAL 4 DAY),
    DATE_SUB(NOW(), INTERVAL 4 DAY),
    'ended',
    8,
    JSON_OBJECT(
        'outcome', 'complaint',
        'order_id', 'ORD-20251215-123',
        'issue', 'delivery_delay',
        'handled_by', 'bot'
    )
);

INSERT INTO chat_messages (
    conversation_id, tenant_id, message_id, platform, direction, sender_type,
    message_type, message_text, intent, sent_at
) VALUES
(
    'conv_fb_005_test1', 'default', 'msg_fb_005_01', 'facebook', 'incoming', 'customer',
    'text', 'สั่งไปแล้ว 5 วัน ยังไม่ได้รับของเลยครับ', 'complaint',
    DATE_SUB(NOW(), INTERVAL 4 DAY)
),
(
    'conv_fb_005_test1', 'default', 'msg_fb_005_02', 'facebook', 'outgoing', 'bot',
    'text', 'ขออภัยครับ ตรวจสอบแล้วพัสดุอยู่ระหว่างขนส่ง คาดว่าจะถึง 2-3 วันครับ', NULL,
    DATE_SUB(NOW(), INTERVAL 4 DAY)
);

SELECT CONCAT('เพิ่ม conversations แล้ว รวม ', COUNT(*), ' conversations') AS status
FROM conversations WHERE customer_id = @test_user_id;

-- ============================================
-- 3. เพิ่ม Orders (3 orders เพิ่ม = รวม 5)
-- ============================================

-- Order 3: Cartier Tank (Installment 10 เดือน)
INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name, product_code,
    quantity, unit_price, total_amount, payment_type, installment_months,
    shipping_address_id, status, source, created_at
) VALUES (
    'ORD-20251210-456',
    @test_user_id,
    'default',
    'Cartier Tank Must Large',
    'CARTIER-TANK-L',
    1,
    150000.00,
    150000.00,
    'installment',
    10,
    @default_addr_id,
    'processing',
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 13 DAY)
);

SET @order3_id = LAST_INSERT_ID();

-- Order 4: TAG Heuer Carrera (Full Payment - Shipped)
INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name, product_code,
    quantity, unit_price, total_amount, payment_type,
    shipping_address_id, status, source, shipped_at, created_at
) VALUES (
    'ORD-20251218-789',
    @test_user_id,
    'default',
    'TAG Heuer Carrera Calibre 16',
    'TAG-CARRERA-16',
    1,
    175000.00,
    175000.00,
    'full',
    @default_addr_id,
    'shipped',
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    DATE_SUB(NOW(), INTERVAL 5 DAY)
);

SET @order4_id = LAST_INSERT_ID();

-- Order 5: Longines Master (Pending)
INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name, product_code,
    quantity, unit_price, total_amount, payment_type,
    shipping_address_id, status, source, created_at
) VALUES (
    'ORD-20251222-111',
    @test_user_id,
    'default',
    'Longines Master Collection',
    'LONGINES-MASTER',
    1,
    95000.00,
    95000.00,
    'full',
    @default_addr_id,
    'pending',
    'web',
    DATE_SUB(NOW(), INTERVAL 1 DAY)
);

SET @order5_id = LAST_INSERT_ID();

SELECT CONCAT('เพิ่ม orders แล้ว รวม ', COUNT(*), ' orders') AS status
FROM orders WHERE customer_id = @test_user_id;

-- ============================================
-- 4. เพิ่ม Installment Schedules (Order 3)
-- ============================================

INSERT INTO installment_schedules (order_id, tenant_id, period_number, due_date, amount, status, paid_amount, paid_at) VALUES
(@order3_id, 'default', 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 15000.00, 'paid', 15000.00, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@order3_id, 'default', 2, DATE_ADD(CURDATE(), INTERVAL 27 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 3, DATE_ADD(CURDATE(), INTERVAL 57 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 4, DATE_ADD(CURDATE(), INTERVAL 87 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 5, DATE_ADD(CURDATE(), INTERVAL 117 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 6, DATE_ADD(CURDATE(), INTERVAL 147 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 7, DATE_ADD(CURDATE(), INTERVAL 177 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 8, DATE_ADD(CURDATE(), INTERVAL 207 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 9, DATE_ADD(CURDATE(), INTERVAL 237 DAY), 15000.00, 'pending', 0, NULL),
(@order3_id, 'default', 10, DATE_ADD(CURDATE(), INTERVAL 267 DAY), 15000.00, 'pending', 0, NULL);

-- ============================================
-- 5. เพิ่ม Payments (2 payments เพิ่ม = รวม 5)
-- ============================================

-- Payment 4: Installment งวดแรก Order 3 (Verified)
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id, amount,
    payment_type, payment_method, installment_period, current_period,
    status, slip_image, payment_details, verified_at, payment_date, source, created_at
) VALUES (
    'PAY-20251210-002',
    @order3_id,
    @test_user_id,
    'default',
    15000.00,
    'installment',
    'bank_transfer',
    10,
    1,
    'verified',
    '/autobot/public/uploads/slips/test1_payment4.png',
    JSON_OBJECT(
        'bank_info', JSON_OBJECT(
            'bank_name', 'ธนาคารไทยพาณิชย์',
            'bank_code', 'SCB',
            'transfer_time', '16:45'
        )
    ),
    DATE_SUB(NOW(), INTERVAL 12 DAY),
    DATE_SUB(NOW(), INTERVAL 13 DAY),
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 13 DAY)
);

SET @payment4_id = LAST_INSERT_ID();

UPDATE installment_schedules 
SET payment_id = @payment4_id 
WHERE order_id = @order3_id AND period_number = 1;

-- Payment 5: Full payment Order 4 (Pending)
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id, amount,
    payment_type, payment_method, status, slip_image,
    payment_details, payment_date, source, created_at
) VALUES (
    'PAY-20251218-003',
    @order4_id,
    @test_user_id,
    'default',
    175000.00,
    'full',
    'bank_transfer',
    'pending',
    '/autobot/public/uploads/slips/test1_payment5.png',
    JSON_OBJECT(
        'bank_info', JSON_OBJECT(
            'bank_name', 'ธนาคารกรุงไทย',
            'bank_code', 'KTB',
            'transfer_time', '11:20'
        )
    ),
    DATE_SUB(NOW(), INTERVAL 5 DAY),
    'chatbot',
    DATE_SUB(NOW(), INTERVAL 5 DAY)
);

SELECT CONCAT('เพิ่ม payments แล้ว รวม ', COUNT(*), ' payments') AS status
FROM payments WHERE customer_id = @test_user_id;

-- ============================================
-- SUMMARY
-- ============================================

SELECT '========================================' AS '';
SELECT 'เพิ่มข้อมูลตัวอย่างเสร็จสมบูรณ์!' AS status;
SELECT '========================================' AS '';
SELECT CONCAT('ที่อยู่ทั้งหมด: ', COUNT(*)) AS info FROM customer_addresses WHERE customer_id = @test_user_id;
SELECT CONCAT('Conversations ทั้งหมด: ', COUNT(*)) AS info FROM conversations WHERE customer_id = @test_user_id;
SELECT CONCAT('Orders ทั้งหมด: ', COUNT(*)) AS info FROM orders WHERE customer_id = @test_user_id;
SELECT CONCAT('Payments ทั้งหมด: ', COUNT(*)) AS info FROM payments WHERE customer_id = @test_user_id;
SELECT '========================================' AS '';
