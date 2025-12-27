-- Migration: Create customer knowledge base system
-- Created: 2025-12-15
-- Purpose: Store customer-specific Q&A, product info, pricing, services

CREATE TABLE IF NOT EXISTS `customer_knowledge_base` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'Customer/user who owns this knowledge',
  `category` enum('product','service','pricing','faq','general') NOT NULL DEFAULT 'general' COMMENT 'Knowledge category',
  `question` text NOT NULL COMMENT 'Question keywords or patterns',
  `answer` text NOT NULL COMMENT 'Answer/response text',
  `keywords` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Keywords for matching' CHECK (json_valid(`keywords`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional data (price, product_id, etc)' CHECK (json_valid(`metadata`)),
  `priority` int(11) DEFAULT 0 COMMENT 'Higher priority = shown first',
  `is_active` tinyint(1) DEFAULT 1,
  `is_deleted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_category` (`user_id`,`category`),
  KEY `idx_user_active` (`user_id`,`is_active`),
  FULLTEXT KEY `ft_question` (`question`),
  FULLTEXT KEY `ft_answer` (`answer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample knowledge base entries for demo customer (user_id = 1)
INSERT INTO `customer_knowledge_base` (`user_id`, `category`, `question`, `answer`, `keywords`, `metadata`, `priority`, `is_active`) VALUES
(1, 'product', 'iPhone 15 Pro Max มีไหม ราคาเท่าไหร่', 'มีค่ะ iPhone 15 Pro Max 256GB ราคา 45,900 บาท มีทุกสี (Natural Titanium, Blue Titanium, White Titanium, Black Titanium) พร้อมส่งทันทีค่ะ ผ่อน 0% 10 เดือนได้นะคะ', 
'["iPhone 15 Pro Max", "iPhone", "มีไหม", "ราคา", "ราคาเท่าไหร่", "สต็อก"]', 
'{"product_id": "iphone15pm-256", "price": 45900, "in_stock": true, "installment_available": true, "category": "smartphone"}', 
100, 1),

(1, 'product', 'AirPods Pro มีไหม', 'มีค่ะ AirPods Pro (2nd generation) ราคา 9,900 บาท รุ่นใหม่ล่าสุด รองรับ USB-C แล้วค่ะ มีของพร้อมส่งเลยค่ะ', 
'["AirPods Pro", "AirPods", "หูฟัง", "มีไหม", "ราคา"]', 
'{"product_id": "airpods-pro-2", "price": 9900, "in_stock": true}', 
90, 1),

(1, 'service', 'ผ่อนได้ไหม ผ่อน 0%', 'ผ่อนได้ค่ะ 💳 เรามีโปรผ่อน 0% นาน 10 เดือน สำหรับสินค้าที่ราคา 3,000 บาทขึ้นไป ใช้บัตรเครดิต กสิกรไทย, BBL, SCB, KTC ค่ะ', 
'["ผ่อน", "ผ่อน 0%", "ดอกเบี้ย", "ผ่อนชำระ", "แบ่งจ่าย"]', 
'{"service_type": "installment", "min_amount": 3000, "max_months": 10, "interest_rate": 0}', 
80, 1),

(1, 'service', 'จัดส่งฟรีไหม ค่าส่ง', 'จัดส่งฟรีทั่วไทยค่ะ 🚚 สำหรับคำสั่งซื้อ 1,000 บาทขึ้นไป ใช้เวลา 2-3 วันทำการ หากต่ำกว่า 1,000 บาท คิดค่าส่ง 50 บาทค่ะ', 
'["จัดส่ง", "ส่งของ", "ค่าส่ง", "ส่งฟรี", "ฟรีไหม"]', 
'{"service_type": "shipping", "free_threshold": 1000, "shipping_fee": 50, "delivery_days": "2-3"}', 
70, 1),

(1, 'faq', 'เปิดทำการกี่โมง', 'ร้านเปิดทุกวัน จันทร์-ศุกร์ 10:00-20:00 น. เสาร์-อาทิตย์ 11:00-21:00 น. ค่ะ 🕐', 
'["เปิดกี่โมง", "ปิดกี่โมง", "เวลาทำการ", "เปิดทำการ"]', 
'{"service_type": "operating_hours", "weekday": "10:00-20:00", "weekend": "11:00-21:00"}', 
60, 1),

(1, 'faq', 'ที่อยู่ร้าน สาขา ติดต่อ', 'ร้านอยู่ที่ ชั้น 3 ห้าง Central World เขตปทุมวัน กรุงเทพฯ โทร 02-123-4567 หรือ Line @myshop ค่ะ', 
'["ที่อยู่", "สาขา", "ติดต่อ", "เบอร์โทร", "Line"]', 
'{"address": "Central World Floor 3", "phone": "02-123-4567", "line_id": "@myshop"}', 
50, 1);
