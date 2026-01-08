-- Create Test Data for User ID 4
USE autobot;

INSERT INTO customer_services (user_id, service_type_id, service_name, platform, api_key, status, created_at) VALUES
(4, 1, 'ร้านกาแฟของฉัน - Facebook', 'facebook', 'test_fb_key_001', 'active', DATE_SUB(NOW(), INTERVAL 30 DAY)),
(4, 2, 'ร้านอาหาร - LINE', 'line', 'test_line_key_001', 'active', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(4, 1, 'ร้านเสื้อผ้า - Facebook', 'facebook', 'test_fb_key_002', 'active', DATE_SUB(NOW(), INTERVAL 10 DAY));

SET @service1_id = (SELECT id FROM customer_services WHERE user_id = 4 AND service_name LIKE 'ร้านกาแฟ%' LIMIT 1);
SET @service2_id = (SELECT id FROM customer_services WHERE user_id = 4 AND service_name LIKE 'ร้านอาหาร%' LIMIT 1);
SET @service3_id = (SELECT id FROM customer_services WHERE user_id = 4 AND service_name LIKE 'ร้านเสื้อผ้า%' LIMIT 1);

INSERT INTO bot_chat_logs (customer_service_id, platform_user_id, platform_user_name, message, bot_response, created_at) VALUES
(@service1_id, 'user001', 'ลูกค้า A', 'สวัสดีครับ', 'สวัสดีค่ะ ยินดีต้อนรับค่ะ', NOW()),  
(@service2_id, 'user002', 'ลูกค้า B', 'เปิดกี่โมง', 'เปิดทุกวัน 10:00-22:00 ค่ะ', NOW()),
(@service1_id, 'user003', 'ลูกค้า C', 'ราคาเท่าไหร่', 'ราคาเริ่มต้น 45 บาทค่ะ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@service3_id, 'user004', 'ลูกค้า D', 'มี wifi ไหม', 'มีค่ะ รหัสผ่านอยู่บนโต๊ะค่ะ', DATE_SUB(NOW(), INTERVAL 2 DAY));

INSERT INTO api_usage_logs (customer_service_id, endpoint, request_count, created_at) VALUES
(@service1_id, '/api/chat', 25, NOW()),
(@service2_id, '/api/chat', 18, NOW()),
(@service1_id, '/api/chat', 30, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@service3_id, '/api/chat', 15, DATE_SUB(NOW(), INTERVAL 2 DAY));

INSERT INTO activity_logs (user_id, action, resource_type, resource_id, created_at) VALUES
(4, 'สร้างบริการใหม่', 'service', @service1_id, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(4, 'สร้างบริการใหม่', 'service', @service2_id, DATE_SUB(NOW(), INTERVAL 20 DAY)),
(4, 'เข้าสู่ระบบ', 'login', 4, NOW());

SELECT 'Data created successfully' as status;
