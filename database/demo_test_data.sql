-- ============================================
-- Comprehensive Test Data for Demo User
-- ============================================
-- Run this after main schema is created
-- User: demo@aiautomation.com / demo1234

USE autobot;

-- ============================================
-- 1. Subscription & Service Setup
-- ============================================

-- Get demo user ID
SET @demo_user_id = (SELECT id FROM users WHERE email = 'demo@aiautomation.com');

-- Subscribe to Pro plan
INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end, created_at)
SELECT @demo_user_id, id, 'active', DATE_SUB(NOW(), INTERVAL 15 DAY), DATE_ADD(NOW(), INTERVAL 15 DAY), DATE_SUB(NOW(), INTERVAL 15 DAY)
FROM subscription_plans WHERE name = 'Pro' LIMIT 1;

-- Create customer service (Facebook Bot + LINE Bot)
INSERT INTO customer_services (user_id, service_type_id, service_name, status, config, created_at)
SELECT 
    @demo_user_id,
    st.id,
    CASE 
        WHEN st.name = 'Facebook Messenger Bot' THEN 'สอบถามข้อมูลสินค้า Bot'
        WHEN st.name = 'LINE Official Bot' THEN 'ฝ่ายบริการลูกค้า Bot'
        ELSE CONCAT(st.name, ' Service')
    END,
    'active',
    JSON_OBJECT('page_id', CONCAT('demo_', st.id)),
    DATE_SUB(NOW(), INTERVAL 15 DAY)
FROM service_types st
WHERE st.name IN ('Facebook Messenger Bot', 'LINE Official Bot')
LIMIT 2;

-- ============================================
-- 2. Bot Chat Messages (Last 30 days)
-- ============================================

SET @facebook_service_id = (SELECT id FROM customer_services WHERE user_id = @demo_user_id AND service_name LIKE '%สอบถามข้อมูลสินค้า%' LIMIT 1);
SET @line_service_id = (SELECT id FROM customer_services WHERE user_id = @demo_user_id AND service_name LIKE '%ฝ่ายบริการลูกค้า%' LIMIT 1);

-- Facebook Bot Messages (incoming = user messages)
INSERT INTO bot_chat_logs (customer_service_id, platform_user_id, direction, message_type, message_content, created_at) VALUES
(@facebook_service_id, 'fb_user_001', 'incoming', 'text', 'สินค้ามีสีอะไรบ้าง', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@facebook_service_id, 'fb_user_001', 'outgoing', 'text', 'เรามีสีดำ สีขาว สีเทา และสีน้ำเงินค่ะ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@facebook_service_id, 'fb_user_002', 'incoming', 'text', 'ราคาเท่าไหร่', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@facebook_service_id, 'fb_user_002', 'outgoing', 'text', 'ราคา 1,990 บาท ส่งฟรีทั่วประเทศค่ะ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@facebook_service_id, 'fb_user_003', 'incoming', 'text', 'มีของพร้อมส่งไหม', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@facebook_service_id, 'fb_user_003', 'outgoing', 'text', 'มีพร้อมส่งทุกสีเลยค่ะ สั่งวันนี้ส่งวันพรุ่งนี้', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@facebook_service_id, 'fb_user_004', 'incoming', 'text', 'มีโปรโมชั่นไหม', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@facebook_service_id, 'fb_user_004', 'outgoing', 'text', 'ช่วงนี้ซื้อ 2 ลด 10% ค่ะ', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@facebook_service_id, 'fb_user_005', 'incoming', 'text', 'รับประกันนานแค่ไหน', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(@facebook_service_id, 'fb_user_005', 'outgoing', 'text', 'รับประกัน 1 ปีเต็มค่ะ', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(@facebook_service_id, 'fb_user_006', 'incoming', 'text', 'ชำระเงินยังไง', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(@facebook_service_id, 'fb_user_006', 'outgoing', 'text', 'รับโอนผ่านธนาคาร พร้อมเพย์ หรือบัตรเครดิตค่ะ', DATE_SUB(NOW(), INTERVAL 5 DAY));

-- LINE Bot Messages  
INSERT INTO bot_chat_logs (customer_service_id, platform_user_id, direction, message_type, message_content, created_at) VALUES
(@line_service_id, 'line_user_001', 'incoming', 'text', 'ติดตามพัสดุ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@line_service_id, 'line_user_001', 'outgoing', 'text', 'กรุณาส่งเลขพัสดุมาค่ะ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@line_service_id, 'line_user_002', 'incoming', 'text', 'เปลี่ยนที่อยู่จัดส่ง', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@line_service_id, 'line_user_002', 'outgoing', 'text', 'กรุณาส่งที่อยู่ใหม่และเลขออเดอร์มาค่ะ', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@line_service_id, 'line_user_003', 'incoming', 'text', 'สินค้าชำรุด', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@line_service_id, 'line_user_003', 'outgoing', 'text', 'ขออภัยค่ะ รบกวนส่งรูปมาด้วยนะคะ', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@line_service_id, 'line_user_004', 'incoming', 'text', 'ขอใบกำกับภาษี', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(@line_service_id, 'line_user_004', 'outgoing', 'text', 'ส่งให้ทาง email ได้เลยค่ะ', DATE_SUB(NOW(), INTERVAL 4 DAY));

-- ============================================
-- 3. API Usage Logs (Google Vision & NL)
-- ============================================

SET @cs_id = (SELECT id FROM customer_services WHERE user_id = @demo_user_id LIMIT 1);

-- Vision API Usage (Last 14 days)
INSERT INTO api_usage_logs (customer_service_id, api_type, endpoint, request_count, response_time, status_code, cost, created_at)
SELECT @cs_id, 'google_vision_labels', 'labels', 
    FLOOR(1 + RAND() * 10), 
    ROUND(200 + RAND() * 300, 2), 
    200, 
    0.015,
    DATE_SUB(NOW(), INTERVAL day DAY) + INTERVAL FLOOR(RAND() * 86400) SECOND
FROM (SELECT 0 as day UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 
      UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13) days;

INSERT INTO api_usage_logs (customer_service_id, api_type, endpoint, request_count, response_time, status_code, cost, created_at)
SELECT @cs_id, 'google_vision_text', 'text', 
    FLOOR(1 + RAND() * 8), 
    ROUND(250 + RAND() * 350, 2), 
    200, 
    0.015,
    DATE_SUB(NOW(), INTERVAL day DAY) + INTERVAL FLOOR(RAND() * 86400) SECOND
FROM (SELECT 0 as day UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) days;

-- Natural Language API Usage
INSERT INTO api_usage_logs (customer_service_id, api_type, endpoint, request_count, response_time, status_code, cost, created_at)
SELECT @cs_id, 'google_nl_sentiment', 'sentiment', 
    FLOOR(1 + RAND() * 15), 
    ROUND(150 + RAND() * 250, 2), 
    200, 
    0.01,
    DATE_SUB(NOW(), INTERVAL day DAY) + INTERVAL FLOOR(RAND() * 86400) SECOND
FROM (SELECT 0 as day UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 
      UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10) days;

-- ============================================
-- 4. Invoices & Transactions
-- ============================================

-- Create monthly invoices for last 3 months
SET @plan_price = 990.00; -- Pro plan price

-- Invoice 1 (2 months ago) - PAID
INSERT INTO invoices (user_id, invoice_number, amount, tax, total, status, due_date, paid_at, created_at)
VALUES (
    @demo_user_id,
    CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 2 MONTH), '%Y%m'), '-001'),
    @plan_price,
    @plan_price * 0.07,
    @plan_price * 1.07,
    'paid',
    DATE_SUB(NOW(), INTERVAL 2 MONTH),
    DATE_SUB(NOW(), INTERVAL 2 MONTH) + INTERVAL 1 DAY,
    DATE_SUB(NOW(), INTERVAL 2 MONTH)
);

SET @inv1_id = LAST_INSERT_ID();

INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, amount)
VALUES (@inv1_id, 'Pro Plan - Monthly Subscription', 1, @plan_price, @plan_price);

INSERT INTO transactions (user_id, invoice_id, amount, payment_method, status, omise_charge_id, created_at)
VALUES (@demo_user_id, @inv1_id, @plan_price * 1.07, 'credit_card', 'completed', CONCAT('chrg_test_', UNIX_TIMESTAMP()), DATE_SUB(NOW(), INTERVAL 2 MONTH) + INTERVAL 1 DAY);

-- Invoice 2 (1 month ago) - PAID
INSERT INTO invoices (user_id, invoice_number, amount, tax, total, status, due_date, paid_at, created_at)
VALUES (
    @demo_user_id,
    CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y%m'), '-001'),
    @plan_price,
    @plan_price * 0.07,
    @plan_price * 1.07,
    'paid',
    DATE_SUB(NOW(), INTERVAL 1 MONTH),
    DATE_SUB(NOW(), INTERVAL 1 MONTH) + INTERVAL 1 DAY,
    DATE_SUB(NOW(), INTERVAL 1 MONTH)
);

SET @inv2_id = LAST_INSERT_ID();

INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, amount)
VALUES (@inv2_id, 'Pro Plan - Monthly Subscription', 1, @plan_price, @plan_price);

INSERT INTO transactions (user_id, invoice_id, amount, payment_method, status, omise_charge_id, created_at)
VALUES (@demo_user_id, @inv2_id, @plan_price * 1.07, 'credit_card', 'completed', CONCAT('chrg_test_', UNIX_TIMESTAMP()), DATE_SUB(NOW(), INTERVAL 1 MONTH) + INTERVAL 1 DAY);

-- Invoice 3 (Current month) - PENDING
INSERT INTO invoices (user_id, invoice_number, amount, tax, total, status, due_date, created_at)
VALUES (
    @demo_user_id,
    CONCAT('INV-', DATE_FORMAT(NOW(), '%Y%m'), '-001'),
    @plan_price,
    @plan_price * 0.07,
    @plan_price * 1.07,
    'pending',
    DATE_ADD(NOW(), INTERVAL 5 DAY),
    NOW()
);

SET @inv3_id = LAST_INSERT_ID();

INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, amount)
VALUES (@inv3_id, 'Pro Plan - Monthly Subscription', 1, @plan_price, @plan_price);

-- ============================================
-- 5. Payment Methods (Credit Cards)
-- ============================================

INSERT INTO payment_methods (user_id, type, omise_card_id, brand, last_digits, expiry_month, expiry_year, is_default, created_at)
VALUES 
(@demo_user_id, 'credit_card', 'card_test_001', 'Visa', '4242', 12, 2027, TRUE, DATE_SUB(NOW(), INTERVAL 60 DAY)),
(@demo_user_id, 'credit_card', 'card_test_002', 'Mastercard', '5555', 6, 2026, FALSE, DATE_SUB(NOW(), INTERVAL 30 DAY));

-- ============================================
-- 6. Activity Logs
-- ============================================

INSERT INTO activity_logs (user_id, action, ip_address, user_agent, created_at) VALUES
(@demo_user_id, 'login', '192.168.1.100', 'Mozilla/5.0', NOW()),
(@demo_user_id, 'login', '192.168.1.100', 'Mozilla/5.0', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@demo_user_id, 'view_dashboard', '192.168.1.100', 'Mozilla/5.0', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@demo_user_id, 'view_usage', '192.168.1.100', 'Mozilla/5.0', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@demo_user_id, 'login', '192.168.1.100', 'Mozilla/5.0', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@demo_user_id, 'view_billing', '192.168.1.100', 'Mozilla/5.0', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@demo_user_id, 'update_profile', '192.168.1.100', 'Mozilla/5.0', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(@demo_user_id, 'login', '192.168.1.100', 'Mozilla/5.0', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(@demo_user_id, 'add_payment_method', '192.168.1.100', 'Mozilla/5.0', DATE_SUB(NOW(), INTERVAL 30 DAY));

-- ============================================
-- 7. API Keys for n8n Integration
-- ============================================

-- Ensure API key exists or create new one
DELETE FROM api_keys WHERE user_id = @demo_user_id;

INSERT INTO api_keys (user_id, api_key, is_active, created_at)
VALUES (@demo_user_id, 'ak_db070bf99d1762c5dc4cdabeb453554b', TRUE, DATE_SUB(NOW(), INTERVAL 15 DAY));

-- Enable all Google AI services for demo user
INSERT INTO customer_api_access (user_id, service_code, is_enabled, daily_limit, monthly_limit)
SELECT @demo_user_id, service_code, TRUE, rate_limit_per_day, rate_limit_per_day * 30
FROM api_service_config
WHERE service_code LIKE 'google_%'
ON DUPLICATE KEY UPDATE is_enabled = TRUE;

-- ============================================
-- Summary
-- ============================================

SELECT '=== Test Data Created Successfully ===' as status;
SELECT CONCAT('User ID: ', @demo_user_id) as info;
SELECT CONCAT('Facebook Service ID: ', @facebook_service_id) as info;
SELECT CONCAT('LINE Service ID: ', @line_service_id) as info;
SELECT CONCAT('Total Bot Messages: ', COUNT(*)) as info FROM bot_chat_logs WHERE customer_service_id IN (@facebook_service_id, @line_service_id);
SELECT CONCAT('Total API Calls: ', COUNT(*)) as info FROM api_usage_logs WHERE customer_service_id = @cs_id;
SELECT CONCAT('Total Invoices: ', COUNT(*)) as info FROM invoices WHERE user_id = @demo_user_id;
SELECT CONCAT('Total Transactions: ', COUNT(*)) as info FROM transactions WHERE user_id = @demo_user_id;
SELECT CONCAT('Payment Methods: ', COUNT(*)) as info FROM payment_methods WHERE user_id = @demo_user_id;
SELECT CONCAT('API Key: ak_db070bf99d1762c5dc4cdabeb453554b') as info;
