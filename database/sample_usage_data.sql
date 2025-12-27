-- ============================================
-- Sample Usage Data for Testing
-- ============================================
-- This creates sample bot chat logs and API usage logs for the last 7 days

-- Insert sample bot chat logs (Facebook Bot - Service ID 1)
INSERT INTO bot_chat_logs (customer_service_id, platform_user_id, direction, message_type, message_content, created_at) VALUES
-- Day 1 (today)
(1, 'user123', 'incoming', 'text', 'สวัสดีครับ', DATE_SUB(NOW(), INTERVAL 0 DAY)),
(1, 'user123', 'outgoing', 'text', 'สวัสดีครับ ยินดีให้บริการ', DATE_SUB(NOW(), INTERVAL 0 DAY)),
(1, 'user456', 'incoming', 'text', 'สอบถามราคาสินค้าหน่อยครับ', DATE_SUB(NOW(), INTERVAL 0 DAY)),
(1, 'user456', 'outgoing', 'text', 'ราคาเริ่มต้นที่ 999 บาทครับ', DATE_SUB(NOW(), INTERVAL 0 DAY)),
(1, 'user789', 'incoming', 'text', 'ขอดูรูปสินค้าครับ', DATE_SUB(NOW(), INTERVAL 0 DAY)),
-- Day 2
(1, 'user123', 'incoming', 'text', 'ขอบคุณครับ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, 'user456', 'incoming', 'text', 'มีส่วนลดไหมครับ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, 'user456', 'outgoing', 'text', 'มีโปรโมชั่นลด 20% ครับ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
-- Day 3
(1, 'user789', 'incoming', 'text', 'สั่งซื้อได้ยังไงครับ', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'user789', 'outgoing', 'text', 'กดที่เมนู สั่งซื้อเลยครับ', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'user101', 'incoming', 'text', 'มีของพร้อมส่งไหม', DATE_SUB(NOW(), INTERVAL 2 DAY)),
-- Day 4
(1, 'user202', 'incoming', 'text', 'ส่งไวไหมครับ', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 'user202', 'outgoing', 'text', 'ส่งภายใน 24 ชั่วโมงครับ', DATE_SUB(NOW(), INTERVAL 3 DAY)),
-- Day 5
(1, 'user303', 'incoming', 'text', 'ติดต่อแอดมินได้ที่ไหน', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(1, 'user404', 'incoming', 'text', 'เปิดบริการกี่โมง', DATE_SUB(NOW(), INTERVAL 4 DAY)),
-- Day 6
(1, 'user505', 'incoming', 'text', 'สินค้าหมดแล้วหรือยัง', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 'user606', 'incoming', 'text', 'รับประกันกี่ปี', DATE_SUB(NOW(), INTERVAL 5 DAY)),
-- Day 7
(1, 'user707', 'incoming', 'text', 'ขอใบเสร็จได้ไหม', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(1, 'user808', 'incoming', 'text', 'ชำระเงินยังไงครับ', DATE_SUB(NOW(), INTERVAL 6 DAY));

-- Insert sample bot chat logs (LINE Bot - Service ID 2)
INSERT INTO bot_chat_logs (customer_service_id, platform_user_id, direction, message_type, message_content, created_at) VALUES
-- Day 1 (today)
(2, 'lineuser1', 'incoming', 'text', 'ข่าวสารวันนี้มีอะไรบ้าง', DATE_SUB(NOW(), INTERVAL 0 DAY)),
(2, 'lineuser1', 'outgoing', 'text', 'วันนี้มีข่าวดีมากมายครับ', DATE_SUB(NOW(), INTERVAL 0 DAY)),
-- Day 2
(2, 'lineuser2', 'incoming', 'text', 'สมัครสมาชิกยังไง', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 'lineuser2', 'outgoing', 'text', 'กรอกข้อมูลที่ฟอร์มนี้เลยครับ', DATE_SUB(NOW(), INTERVAL 1 DAY)),
-- Day 3
(2, 'lineuser3', 'incoming', 'text', 'อยากรับข่าวสาร', DATE_SUB(NOW(), INTERVAL 2 DAY)),
-- Day 4
(2, 'lineuser4', 'incoming', 'text', 'ขอบคุณครับ', DATE_SUB(NOW(), INTERVAL 3 DAY)),
-- Day 5
(2, 'lineuser5', 'incoming', 'text', 'มีโปรโมชั่นอะไรบ้าง', DATE_SUB(NOW(), INTERVAL 4 DAY));

-- Insert sample API usage logs (Google Vision - Service ID 3)
INSERT INTO api_usage_logs (customer_service_id, api_type, endpoint, request_count, response_time, status_code, cost, created_at) VALUES
-- Day 1 (today)
(3, 'google_vision', 'labels', 15, 250, 200, 7.50, DATE_SUB(NOW(), INTERVAL 0 DAY)),
(3, 'google_vision', 'text_detection', 8, 320, 200, 4.00, DATE_SUB(NOW(), INTERVAL 0 DAY)),
(3, 'google_vision', 'face_detection', 5, 280, 200, 2.50, DATE_SUB(NOW(), INTERVAL 0 DAY)),
-- Day 2
(3, 'google_vision', 'labels', 12, 240, 200, 6.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, 'google_vision', 'text_detection', 10, 310, 200, 5.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
-- Day 3
(3, 'google_vision', 'labels', 18, 260, 200, 9.00, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3, 'google_vision', 'face_detection', 7, 290, 200, 3.50, DATE_SUB(NOW(), INTERVAL 2 DAY)),
-- Day 4
(3, 'google_vision', 'labels', 20, 245, 200, 10.00, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(3, 'google_vision', 'text_detection', 6, 300, 200, 3.00, DATE_SUB(NOW(), INTERVAL 3 DAY)),
-- Day 5
(3, 'google_vision', 'labels', 14, 255, 200, 7.00, DATE_SUB(NOW(), INTERVAL 4 DAY)),
-- Day 6
(3, 'google_vision', 'text_detection', 9, 315, 200, 4.50, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(3, 'google_vision', 'face_detection', 4, 275, 200, 2.00, DATE_SUB(NOW(), INTERVAL 5 DAY)),
-- Day 7
(3, 'google_vision', 'labels', 11, 250, 200, 5.50, DATE_SUB(NOW(), INTERVAL 6 DAY));

-- Insert sample API usage logs (Google NL - Service ID 4)
INSERT INTO api_usage_logs (customer_service_id, api_type, endpoint, request_count, response_time, status_code, cost, created_at) VALUES
-- Day 1 (today)
(4, 'google_nl', 'sentiment', 25, 180, 200, 7.50, DATE_SUB(NOW(), INTERVAL 0 DAY)),
(4, 'google_nl', 'entities', 12, 200, 200, 3.60, DATE_SUB(NOW(), INTERVAL 0 DAY)),
-- Day 2
(4, 'google_nl', 'sentiment', 30, 175, 200, 9.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(4, 'google_nl', 'entities', 15, 195, 200, 4.50, DATE_SUB(NOW(), INTERVAL 1 DAY)),
-- Day 3
(4, 'google_nl', 'sentiment', 28, 185, 200, 8.40, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(4, 'google_nl', 'entities', 10, 210, 200, 3.00, DATE_SUB(NOW(), INTERVAL 2 DAY)),
-- Day 4
(4, 'google_nl', 'sentiment', 22, 190, 200, 6.60, DATE_SUB(NOW(), INTERVAL 3 DAY)),
-- Day 5
(4, 'google_nl', 'sentiment', 35, 170, 200, 10.50, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4, 'google_nl', 'entities', 18, 205, 200, 5.40, DATE_SUB(NOW(), INTERVAL 4 DAY)),
-- Day 6
(4, 'google_nl', 'sentiment', 27, 180, 200, 8.10, DATE_SUB(NOW(), INTERVAL 5 DAY)),
-- Day 7
(4, 'google_nl', 'sentiment', 20, 185, 200, 6.00, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(4, 'google_nl', 'entities', 8, 200, 200, 2.40, DATE_SUB(NOW(), INTERVAL 6 DAY));
