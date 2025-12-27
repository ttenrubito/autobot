-- ============================================
-- Quick Test Data - Simplified Version
-- ============================================
USE autobot;

SET @demo_user_id = (SELECT id FROM users WHERE email = 'demo@aiautomation.com');

-- Get existing service IDs (already created)
SET @service1 = (SELECT id FROM customer_services WHERE user_id = @demo_user_id LIMIT 1);
SET @service2 = (SELECT id FROM customer_services WHERE user_id = @demo_user_id LIMIT 1 OFFSET 1);

-- Bot Messages (24 messages = 12 conversations)
INSERT INTO bot_chat_logs (customer_service_id, platform_user_id, direction, message_type, message_content, created_at) VALUES
(@service1, 'user_001', 'incoming', 'text', 'สินค้ามีสีอะไรบ้าง', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@service1, 'user_001', 'outgoing', 'text', 'เรามีสีดำ สีขาว สีเทา ค่ะ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@service1, 'user_002', 'incoming', 'text', 'ราคาเท่าไหร่', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@service1, 'user_002', 'outgoing', 'text', 'ราคา 1,990 บาท ค่ะ', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@service1, 'user_003', 'incoming', 'text', 'มีของพร้อมส่งไหม', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@service1, 'user_003', 'outgoing', 'text', 'มีพร้อมส่งค่ะ', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@service1, 'user_004', 'incoming', 'text', 'ส่งฟรีไหม', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(@service1, 'user_004', 'outgoing', 'text', 'ส่งฟรีทั่วประเทศค่ะ', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(@service2, 'line_001', 'incoming', 'text', 'ติดตามพัสดุ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@service2, 'line_001', 'outgoing', 'text', 'กรุณาส่งเลขพัสดุค่ะ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@service2, 'line_002', 'incoming', 'text', 'เปลี่ยนที่อยู่', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@service2, 'line_002', 'outgoing', 'text', 'กรุณาส่งที่อยู่ใหม่ค่ะ', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@service2, 'line_003', 'incoming', 'text', 'สินค้าชำรุด', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@service2, 'line_003', 'outgoing', 'text', 'ขออภัยค่ะ รบกวนส่งรูปค่ะ', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@service2, 'line_004', 'incoming', 'text', 'ขอใบกำกับภาษี', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(@service2, 'line_004', 'outgoing', 'text', 'ส่งทาง email ค่ะ', DATE_SUB(NOW(), INTERVAL 4 DAY));

-- Invoices & Transactions
SET @plan_price = 990.00;

-- Invoice 1 - PAID
INSERT INTO invoices (user_id, invoice_number, amount, tax, total, status, due_date, paid_at, created_at)
VALUES (@demo_user_id, CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 2 MONTH), '%Y%m'), '-001'),
        @plan_price, @plan_price * 0.07, @plan_price * 1.07, 'paid',
        DATE_SUB(NOW(), INTERVAL 2 MONTH), DATE_SUB(NOW(), INTERVAL 2 MONTH) + INTERVAL 1 DAY,
        DATE_SUB(NOW(), INTERVAL 2 MONTH));

INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, amount)
VALUES (LAST_INSERT_ID(), 'Pro Plan - Monthly', 1, @plan_price, @plan_price);

INSERT INTO transactions (user_id, invoice_id, amount, payment_method, status, omise_charge_id, created_at)
VALUES (@demo_user_id, LAST_INSERT_ID(), @plan_price * 1.07, 'credit_card', 'completed',
        CONCAT('chrg_', UNIX_TIMESTAMP()), DATE_SUB(NOW(), INTERVAL 2 MONTH) + INTERVAL 1 DAY);

-- Invoice 2 - PAID
INSERT INTO invoices (user_id, invoice_number, amount, tax, total, status, due_date, paid_at, created_at)
VALUES (@demo_user_id, CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y%m'), '-001'),
        @plan_price, @plan_price * 0.07, @plan_price * 1.07, 'paid',
        DATE_SUB(NOW(), INTERVAL 1 MONTH), DATE_SUB(NOW(), INTERVAL 1 MONTH) + INTERVAL 1 DAY,
        DATE_SUB(NOW(), INTERVAL 1 MONTH));

INSERT INTO invoice_items (invoice_id,description, quantity, unit_price, amount)
VALUES (LAST_INSERT_ID(), 'Pro Plan - Monthly', 1, @plan_price, @plan_price);

INSERT INTO transactions (user_id, invoice_id, amount, payment_method, status, omise_charge_id, created_at)
VALUES (@demo_user_id, LAST_INSERT_ID(), @plan_price * 1.07, 'credit_card', 'completed',
        CONCAT('chrg_', UNIX_TIMESTAMP()), DATE_SUB(NOW(), INTERVAL 1 MONTH) + INTERVAL 1 DAY);

-- Invoice 3 - PENDING
INSERT INTO invoices (user_id, invoice_number, amount, tax, total, status, due_date, created_at)
VALUES (@demo_user_id, CONCAT('INV-', DATE_FORMAT(NOW(), '%Y%m'), '-001'),
        @plan_price, @plan_price * 0.07, @plan_price * 1.07, 'pending',
        DATE_ADD(NOW(), INTERVAL 5 DAY), NOW());

INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, amount)
VALUES (LAST_INSERT_ID(), 'Pro Plan - Monthly', 1, @plan_price, @plan_price);

-- Payment Methods
INSERT INTO payment_methods (user_id, type, omise_card_id, brand, last_digits, expiry_month, expiry_year, is_default, created_at)
VALUES 
(@demo_user_id, 'credit_card', 'card_test_001', 'Visa', '4242', 12, 2027, TRUE, DATE_SUB(NOW(), INTERVAL 60 DAY)),
(@demo_user_id, 'credit_card', 'card_test_002', 'Mastercard', '5555', 6, 2026, FALSE, DATE_SUB(NOW(), INTERVAL 30 DAY));

-- Summary
SELECT '=== Test Data Loaded ===' as status;
SELECT CONCAT('Bot Messages: ', COUNT(*)) as info FROM bot_chat_logs;
SELECT CONCAT('Invoices: ', COUNT(*)) as info FROM invoices WHERE user_id = @demo_user_id;
SELECT CONCAT('Transactions: ', COUNT(*)) as info FROM transactions WHERE user_id = @demo_user_id;
SELECT CONCAT('Payment Methods: ', COUNT(*)) as info FROM payment_methods WHERE user_id = @demo_user_id;
