-- Mock Data for test1@gmail.com (user_id = 4)
-- Simulating real chat-originated transactions from LINE/Facebook bot
-- Run: mysql -u root autobot < mock_data_test1.sql

SET @user_id = 4;
SET @tenant_id = 'default';

-- ============================================
-- 1. สร้าง Customer Service สำหรับ user 4 (LINE Bot)
-- ============================================
INSERT IGNORE INTO customer_services (user_id, service_type_id, service_name, platform, api_key, status)
VALUES 
(@user_id, 2, 'LINE Bot - ร้านเฮงเฮงเฮง Test', 'line', 'line_test1_mock_key', 'active');

SET @channel_id = LAST_INSERT_ID();
SELECT IFNULL(@channel_id, 5) INTO @channel_id;

-- ============================================
-- 2. สร้าง Customer Addresses (ที่อยู่จัดส่ง)
-- ============================================
INSERT INTO customer_addresses (customer_id, tenant_id, recipient_name, phone, address_line1, address_line2, subdistrict, district, province, postal_code, is_default, created_at)
VALUES
(@user_id, @tenant_id, 'คุณสมชาย ใจดี', '0891234567', '123/45 ซอยสุขุมวิท 55', 'อาคาร A ชั้น 3 ห้อง 301', 'คลองตันเหนือ', 'วัฒนา', 'กรุงเทพมหานคร', '10110', 1, NOW());
SET @addr1_id = LAST_INSERT_ID();

INSERT INTO customer_addresses (customer_id, tenant_id, recipient_name, phone, address_line1, address_line2, subdistrict, district, province, postal_code, is_default, created_at)
VALUES
(@user_id, @tenant_id, 'คุณสมหญิง ใจดี', '0899876543', '789 ถนนลาดพร้าว', 'คอนโด The Line ชั้น 25', 'จอมพล', 'จตุจักร', 'กรุงเทพมหานคร', '10900', 0, NOW());
SET @addr2_id = LAST_INSERT_ID();

INSERT INTO customer_addresses (customer_id, tenant_id, recipient_name, phone, address_line1, address_line2, subdistrict, district, province, postal_code, is_default, created_at)
VALUES
(@user_id, @tenant_id, 'คุณสมชาย ใจดี (ออฟฟิศ)', '0812345678', '555 อาคารเอ็มไพร์ทาวเวอร์', 'ชั้น 15 บริษัท ABC จำกัด', 'สาทร', 'สาทร', 'กรุงเทพมหานคร', '10120', 0, NOW());
SET @addr3_id = LAST_INSERT_ID();

-- ============================================
-- 3. สร้าง Orders (คำสั่งซื้อจากแชท)
-- ============================================

-- Order 1: ซื้อนาฬิกา Rolex - จ่ายเต็ม - ส่งแล้ว
INSERT INTO orders (order_no, customer_id, tenant_id, product_name, product_code, product_ref_id, quantity, unit_price, total_amount, payment_type, shipping_address_id, status, source, notes, created_at, shipped_at)
VALUES 
('ORD-20260101-001', @user_id, @tenant_id, 'Rolex Submariner Date 126610LN', 'ROL-SUB-001', 'PROD-ROL-001', 1, 385000.00, 385000.00, 'full', @addr1_id, 'delivered', 'line_chat', 'ลูกค้าสนใจจากรูปที่ส่งมา ต้องการซื้อทันที', '2026-01-01 10:30:00', '2026-01-02 14:00:00');

SET @order1_id = LAST_INSERT_ID();

-- Order 2: ซื้อแหวนเพชร - ผ่อนชำระ 6 งวด - กำลังดำเนินการ
INSERT INTO orders (order_no, customer_id, tenant_id, product_name, product_code, product_ref_id, quantity, unit_price, total_amount, payment_type, installment_months, deposit_amount, remaining_amount, shipping_address_id, status, source, notes, created_at)
VALUES 
('ORD-20260103-002', @user_id, @tenant_id, 'แหวนเพชรแท้ 1 กะรัต VVS1', 'DIA-RING-002', 'PROD-DIA-002', 1, 189000.00, 189000.00, 'installment', 6, 30000.00, 159000.00, @addr2_id, 'processing', 'line_chat', 'ลูกค้าต้องการผ่อน 6 งวด วางมัดจำ 30,000', '2026-01-03 15:45:00');

SET @order2_id = LAST_INSERT_ID();

-- Order 3: ซื้อกำไล Cartier - ออมเงิน - รอครบยอด
INSERT INTO orders (order_no, customer_id, tenant_id, product_name, product_code, product_ref_id, quantity, unit_price, total_amount, payment_type, status, source, notes, created_at)
VALUES 
('ORD-20260105-003', @user_id, @tenant_id, 'Cartier Love Bracelet Rose Gold', 'CAR-LOVE-003', 'PROD-CAR-003', 1, 245000.00, 245000.00, 'savings', 'pending', 'line_chat', 'ลูกค้าเลือกออมเงินเพื่อซื้อสินค้า กันของไว้ให้', '2026-01-05 09:20:00');

SET @order3_id = LAST_INSERT_ID();

-- Order 4: ซื้อสร้อยคอทอง - รอดำเนินการ
INSERT INTO orders (order_no, customer_id, tenant_id, product_name, product_code, product_ref_id, quantity, unit_price, total_amount, payment_type, shipping_address_id, status, source, notes, created_at)
VALUES 
('ORD-20260107-004', @user_id, @tenant_id, 'สร้อยคอทองคำแท้ 96.5% น้ำหนัก 2 บาท', 'GOLD-NCK-004', 'PROD-GOLD-004', 1, 68000.00, 68000.00, 'full', @addr1_id, 'pending', 'facebook_chat', 'ลูกค้าสั่งซื้อผ่าน Facebook รอยืนยันการโอนเงิน', '2026-01-07 08:15:00');

SET @order4_id = LAST_INSERT_ID();

-- ============================================
-- 4. สร้าง Payments (การชำระเงิน)
-- ============================================

-- Payment 1: ชำระเต็มสำหรับ Order 1 (Rolex) - ยืนยันแล้ว
INSERT INTO payments (payment_no, order_id, customer_id, tenant_id, amount, payment_type, payment_method, status, slip_image, payment_details, verified_by, verified_at, payment_date, source, created_at)
VALUES 
('PAY-20260101-001', @order1_id, @user_id, @tenant_id, 385000.00, 'full', 'bank_transfer', 'verified', 
 'https://storage.googleapis.com/autobot-documents/slips/slip_20260101_001.jpg',
 '{"bank":"กสิกรไทย","account":"xxx-x-xx567-x","sender_name":"สมชาย ใจดี","transfer_time":"2026-01-01 11:25:33","ref":"202601011125ABC"}',
 1, '2026-01-01 12:00:00', '2026-01-01 11:25:33', 'line_chat', '2026-01-01 11:30:00');

-- Payment 2: มัดจำสำหรับ Order 2 (แหวนเพชร) - ยืนยันแล้ว
INSERT INTO payments (payment_no, order_id, customer_id, tenant_id, amount, payment_type, payment_method, current_period, status, slip_image, payment_details, verified_by, verified_at, payment_date, source, created_at)
VALUES 
('PAY-20260103-002', @order2_id, @user_id, @tenant_id, 30000.00, 'deposit', 'promptpay', 0, 'verified',
 'https://storage.googleapis.com/autobot-documents/slips/slip_20260103_002.jpg',
 '{"bank":"พร้อมเพย์","promptpay_id":"089-xxx-xxxx","sender_name":"สมชาย ใ.","transfer_time":"2026-01-03 16:10:15","ref":"PP2026010316ABC"}',
 1, '2026-01-03 16:30:00', '2026-01-03 16:10:15', 'line_chat', '2026-01-03 16:15:00');

-- Payment 3: งวดที่ 1 สำหรับ Order 2 - ยืนยันแล้ว
INSERT INTO payments (payment_no, order_id, customer_id, tenant_id, amount, payment_type, payment_method, current_period, status, slip_image, payment_details, verified_by, verified_at, payment_date, source, created_at)
VALUES 
('PAY-20260105-003', @order2_id, @user_id, @tenant_id, 26500.00, 'installment', 'bank_transfer', 1, 'verified',
 'https://storage.googleapis.com/autobot-documents/slips/slip_20260105_003.jpg',
 '{"bank":"ไทยพาณิชย์","account":"xxx-x-xxxxx-x","sender_name":"สมชาย ใจดี","transfer_time":"2026-01-05 09:45:22","ref":"SCB2026010509DEF"}',
 1, '2026-01-05 10:15:00', '2026-01-05 09:45:22', 'line_chat', '2026-01-05 09:50:00');

-- Payment 4: งวดที่ 2 สำหรับ Order 2 - รอตรวจสอบ
INSERT INTO payments (payment_no, order_id, customer_id, tenant_id, amount, payment_type, payment_method, current_period, status, slip_image, payment_details, payment_date, source, created_at)
VALUES 
('PAY-20260107-004', @order2_id, @user_id, @tenant_id, 26500.00, 'installment', 'promptpay', 2, 'pending',
 'https://storage.googleapis.com/autobot-documents/slips/slip_20260107_004.jpg',
 '{"bank":"พร้อมเพย์","promptpay_id":"089-xxx-xxxx","sender_name":"สมชาย ใ.","transfer_time":"2026-01-07 08:30:45","ref":"PP2026010708GHI"}',
 '2026-01-07 08:30:45', 'line_chat', '2026-01-07 08:35:00');

-- Payment 5: ชำระสำหรับ Order 4 (สร้อยคอทอง) - รอตรวจสอบ
INSERT INTO payments (payment_no, order_id, customer_id, tenant_id, amount, payment_type, payment_method, status, slip_image, payment_details, payment_date, source, created_at)
VALUES 
('PAY-20260107-005', @order4_id, @user_id, @tenant_id, 68000.00, 'full', 'bank_transfer', 'pending',
 'https://storage.googleapis.com/autobot-documents/slips/slip_20260107_005.jpg',
 '{"bank":"กรุงเทพ","account":"xxx-x-xxxxx-x","sender_name":"สมชาย ใจดี","transfer_time":"2026-01-07 08:45:10","ref":"BBL2026010708JKL"}',
 '2026-01-07 08:45:10', 'facebook_chat', '2026-01-07 08:50:00');

-- ============================================
-- 5. สร้าง Savings Accounts (บัญชีออมเงิน)
-- ============================================

-- Savings 1: ออมซื้อกำไล Cartier - กำลังออม
INSERT INTO savings_accounts (account_no, tenant_id, customer_id, channel_id, external_user_id, platform, product_ref_id, product_name, product_price, target_amount, current_amount, status, created_at)
VALUES 
('SAV-20260105-001', @tenant_id, @user_id, @channel_id, 'U1234567890abcdef', 'line', 'PROD-CAR-003', 'Cartier Love Bracelet Rose Gold', 245000.00, 245000.00, 85000.00, 'active', '2026-01-05 09:25:00');

SET @savings1_id = LAST_INSERT_ID();

-- Savings transactions for Savings 1
INSERT INTO savings_transactions (transaction_no, savings_account_id, tenant_id, transaction_type, amount, balance_after, payment_method, slip_image_url, sender_name, status, verified_by, verified_at, created_at)
VALUES 
('SAVTX-20260105-001', @savings1_id, @tenant_id, 'deposit', 50000.00, 50000.00, 'bank_transfer', 'https://storage.googleapis.com/autobot-documents/slips/sav_slip_20260105_001.jpg', 'สมชาย ใจดี', 'verified', 1, '2026-01-05 10:00:00', '2026-01-05 09:30:00'),
('SAVTX-20260106-002', @savings1_id, @tenant_id, 'deposit', 35000.00, 85000.00, 'promptpay', 'https://storage.googleapis.com/autobot-documents/slips/sav_slip_20260106_002.jpg', 'สมชาย ใ.', 'verified', 1, '2026-01-06 11:00:00', '2026-01-06 10:15:00');

-- Update savings current_amount
UPDATE savings_accounts SET current_amount = 85000.00 WHERE id = @savings1_id;

-- Savings 2: ออมซื้อนาฬิกา Patek - เพิ่งเริ่มออม
INSERT INTO savings_accounts (account_no, tenant_id, customer_id, channel_id, external_user_id, platform, product_ref_id, product_name, product_price, target_amount, current_amount, status, created_at)
VALUES 
('SAV-20260107-002', @tenant_id, @user_id, @channel_id, 'U1234567890abcdef', 'line', 'PROD-PAT-005', 'Patek Philippe Nautilus 5711/1A', 1850000.00, 1850000.00, 100000.00, 'active', '2026-01-07 07:00:00');

SET @savings2_id = LAST_INSERT_ID();

INSERT INTO savings_transactions (transaction_no, savings_account_id, tenant_id, transaction_type, amount, balance_after, payment_method, slip_image_url, sender_name, status, verified_by, verified_at, created_at)
VALUES 
('SAVTX-20260107-003', @savings2_id, @tenant_id, 'deposit', 100000.00, 100000.00, 'bank_transfer', 'https://storage.googleapis.com/autobot-documents/slips/sav_slip_20260107_003.jpg', 'สมชาย ใจดี', 'verified', 1, '2026-01-07 08:00:00', '2026-01-07 07:15:00');

UPDATE savings_accounts SET current_amount = 100000.00 WHERE id = @savings2_id;

-- ============================================
-- 6. สร้าง Installment Contracts (สัญญาผ่อนชำระ)
-- ============================================

-- Installment 1: ผ่อนแหวนเพชร 6 งวด - กำลังผ่อน
INSERT INTO installment_contracts (
    contract_no, tenant_id, customer_id, channel_id, external_user_id, platform,
    customer_name, customer_phone, product_ref_id, product_name, product_code,
    product_price, total_amount, down_payment, financed_amount,
    total_periods, amount_per_period, paid_periods, paid_amount,
    contract_date, start_date, next_due_date, status, created_at
) VALUES (
    'IC-20260103-001', @tenant_id, @user_id, @channel_id, 'U1234567890abcdef', 'line',
    'คุณสมชาย ใจดี', '0891234567', 'PROD-DIA-002', 'แหวนเพชรแท้ 1 กะรัต VVS1', 'DIA-RING-002',
    189000.00, 189000.00, 30000.00, 159000.00,
    6, 26500.00, 2, 53000.00,
    '2026-01-03', '2026-01-05', '2026-02-05', 'active', '2026-01-03 16:00:00'
);

SET @ic1_id = LAST_INSERT_ID();

-- Installment payments for IC1
INSERT INTO installment_payments (contract_id, payment_no, period_number, amount, payment_type, payment_method, due_date, paid_date, status, slip_image_url, sender_name, verified_by, verified_at, created_at)
VALUES 
(@ic1_id, 'ICPAY-20260103-001', 0, 30000.00, 'down_payment', 'promptpay', '2026-01-03', '2026-01-03', 'verified', 'https://storage.googleapis.com/autobot-documents/slips/ic_slip_20260103_001.jpg', 'สมชาย ใ.', 1, '2026-01-03 16:30:00', '2026-01-03 16:15:00'),
(@ic1_id, 'ICPAY-20260105-002', 1, 26500.00, 'regular', 'bank_transfer', '2026-01-05', '2026-01-05', 'verified', 'https://storage.googleapis.com/autobot-documents/slips/ic_slip_20260105_002.jpg', 'สมชาย ใจดี', 1, '2026-01-05 10:15:00', '2026-01-05 09:50:00'),
(@ic1_id, 'ICPAY-20260107-003', 2, 26500.00, 'regular', 'promptpay', '2026-02-05', '2026-01-07', 'pending', 'https://storage.googleapis.com/autobot-documents/slips/ic_slip_20260107_003.jpg', 'สมชาย ใ.', NULL, NULL, '2026-01-07 08:35:00');

-- Installment 2: ผ่อน MacBook Pro - เกินกำหนด (overdue)
INSERT INTO installment_contracts (
    contract_no, tenant_id, customer_id, channel_id, external_user_id, platform,
    customer_name, customer_phone, product_ref_id, product_name, product_code,
    product_price, total_amount, down_payment, financed_amount,
    total_periods, amount_per_period, paid_periods, paid_amount,
    contract_date, start_date, next_due_date, status, created_at
) VALUES (
    'IC-20251215-002', @tenant_id, @user_id, @channel_id, 'U1234567890abcdef', 'line',
    'คุณสมชาย ใจดี', '0891234567', 'PROD-MAC-006', 'MacBook Pro 16" M3 Max', 'MAC-PRO-006',
    139900.00, 139900.00, 20000.00, 119900.00,
    10, 11990.00, 1, 11990.00,
    '2025-12-15', '2025-12-20', '2026-01-05', 'overdue', '2025-12-15 14:00:00'
);

SET @ic2_id = LAST_INSERT_ID();

INSERT INTO installment_payments (contract_id, payment_no, period_number, amount, payment_type, payment_method, due_date, paid_date, status, slip_image_url, sender_name, verified_by, verified_at, created_at)
VALUES 
(@ic2_id, 'ICPAY-20251215-004', 0, 20000.00, 'down_payment', 'bank_transfer', '2025-12-15', '2025-12-15', 'verified', 'https://storage.googleapis.com/autobot-documents/slips/ic_slip_20251215_004.jpg', 'สมชาย ใจดี', 1, '2025-12-15 15:00:00', '2025-12-15 14:30:00'),
(@ic2_id, 'ICPAY-20251220-005', 1, 11990.00, 'regular', 'promptpay', '2025-12-20', '2025-12-20', 'verified', 'https://storage.googleapis.com/autobot-documents/slips/ic_slip_20251220_005.jpg', 'สมชาย ใ.', 1, '2025-12-20 11:00:00', '2025-12-20 10:15:00');

-- ============================================
-- 7. สร้าง Cases (เคสจากแชท)
-- ============================================
INSERT INTO cases (case_no, tenant_id, channel_id, external_user_id, customer_id, platform, case_type, status, product_ref_id, subject, description, assigned_to, created_at, updated_at)
VALUES
('CASE-20260101-001', @tenant_id, @channel_id, 'U1234567890abcdef', @user_id, 'line', 'product_inquiry', 'resolved', 'PROD-ROL-001', 'สอบถาม Rolex Submariner', 'ลูกค้าสอบถามนาฬิกา Rolex Submariner ตัดสินใจซื้อทันที', 1, '2026-01-01 10:00:00', '2026-01-01 12:00:00'),
('CASE-20260103-002', @tenant_id, @channel_id, 'U1234567890abcdef', @user_id, 'line', 'payment_installment', 'in_progress', 'PROD-DIA-002', 'ผ่อนแหวนเพชร 1 กะรัต', 'ลูกค้าเปิดผ่อนแหวนเพชร 6 งวด', 1, '2026-01-03 15:30:00', '2026-01-07 08:35:00'),
('CASE-20260105-003', @tenant_id, @channel_id, 'U1234567890abcdef', @user_id, 'line', 'payment_savings', 'in_progress', 'PROD-CAR-003', 'ออมซื้อกำไล Cartier', 'ลูกค้าเปิดออมเงินซื้อกำไล Cartier', 1, '2026-01-05 09:15:00', '2026-01-06 10:20:00'),
('CASE-20260107-004', @tenant_id, @channel_id, 'PSID_9876543210', @user_id, 'facebook', 'payment_full', 'pending_admin', 'PROD-GOLD-004', 'ซื้อสร้อยคอทอง', 'ลูกค้าสั่งซื้อผ่าน Facebook Messenger รอตรวจสอบการชำระเงิน', NULL, '2026-01-07 08:10:00', '2026-01-07 08:50:00');

-- ============================================
-- 8. สร้าง Installment Reminders (เตือนค่างวด)
-- ============================================
INSERT INTO installment_reminders (contract_id, reminder_type, due_date, period_number, status, sent_at, created_at)
VALUES
(@ic2_id, 'before_3_days', '2026-01-05', 2, 'sent', '2026-01-02 09:00:00', '2026-01-02 09:00:00'),
(@ic2_id, 'before_1_day', '2026-01-05', 2, 'sent', '2026-01-04 09:00:00', '2026-01-04 09:00:00'),
(@ic2_id, 'overdue_1_days', '2026-01-05', 2, 'sent', '2026-01-06 09:00:00', '2026-01-06 09:00:00');

-- ============================================
-- 9. สร้าง Push Notifications (การแจ้งเตือน)
-- ============================================
INSERT INTO push_notifications (platform, platform_user_id, channel_id, notification_type, message, message_data, status, sent_at, created_at)
VALUES
('line', 'U1234567890abcdef', @channel_id, 'payment_verified', 'ยืนยันการชำระเงินแล้วค่ะ ✅\nยอด: ฿385,000.00\nสินค้า: Rolex Submariner\nขอบคุณที่ใช้บริการค่ะ 🙏', '{"amount": 385000, "product_name": "Rolex Submariner"}', 'delivered', '2026-01-01 12:05:00', '2026-01-01 12:00:00'),
('line', 'U1234567890abcdef', @channel_id, 'installment_reminder', '⏰ แจ้งเตือนค่างวดค่ะ\n📅 ครบกำหนด: 05/02/2026\n💰 ยอด: ฿26,500.00\n📋 งวดที่: 3/6\nอย่าลืมชำระนะคะ 😊', '{"period_number": 3, "amount": 26500, "due_date": "2026-02-05"}', 'pending', NULL, '2026-01-07 09:00:00'),
('line', 'U1234567890abcdef', @channel_id, 'installment_overdue', '⚠️ ค่างวดเกินกำหนดแล้วค่ะ\n📅 ครบกำหนด: 05/01/2026\n💰 ยอด: ฿11,990.00\n📋 งวดที่: 2/10 (MacBook Pro)\nกรุณาชำระโดยเร็วนะคะ', '{"period_number": 2, "amount": 11990, "due_date": "2026-01-05", "days_overdue": 2}', 'sent', '2026-01-07 09:00:00', '2026-01-07 09:00:00');

-- ============================================
-- Done!
-- ============================================
SELECT 'Mock data created successfully for test1@gmail.com (user_id = 4)' AS status;
SELECT 'Orders:' AS table_name, COUNT(*) AS count FROM orders WHERE customer_id = @user_id
UNION ALL
SELECT 'Payments:', COUNT(*) FROM payments WHERE customer_id = @user_id
UNION ALL
SELECT 'Addresses:', COUNT(*) FROM customer_addresses WHERE customer_id = @user_id
UNION ALL
SELECT 'Savings:', COUNT(*) FROM savings_accounts WHERE customer_id = @user_id
UNION ALL
SELECT 'Installments:', COUNT(*) FROM installment_contracts WHERE customer_id = @user_id
UNION ALL
SELECT 'Cases:', COUNT(*) FROM cases WHERE external_user_id = 'U1234567890abcdef';
