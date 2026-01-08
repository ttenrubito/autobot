-- ============================================
-- Customer Profile Fields Migration
-- เพิ่ม fields สำหรับเก็บข้อมูล profile ลูกค้าจาก LINE/Facebook
-- Date: 2026-01-07
-- ============================================

-- 1. เพิ่ม columns ใน conversations table สำหรับเก็บ profile
ALTER TABLE conversations 
ADD COLUMN IF NOT EXISTS platform_user_name VARCHAR(255) NULL COMMENT 'ชื่อผู้ใช้จาก LINE/Facebook',
ADD COLUMN IF NOT EXISTS platform_user_avatar VARCHAR(500) NULL COMMENT 'URL รูปโปรไฟล์จาก LINE/Facebook',
ADD COLUMN IF NOT EXISTS platform_user_status VARCHAR(255) NULL COMMENT 'Status message (LINE)',
ADD COLUMN IF NOT EXISTS metadata JSON NULL COMMENT 'ข้อมูลเพิ่มเติมจาก platform (phone, email, etc.)';

-- 2. เพิ่ม columns ใน orders table สำหรับ cache profile ลูกค้าที่สั่งซื้อ
ALTER TABLE orders 
ADD COLUMN IF NOT EXISTS customer_platform VARCHAR(20) NULL COMMENT 'platform ที่ลูกค้าใช้สั่งซื้อ (line/facebook)',
ADD COLUMN IF NOT EXISTS customer_platform_id VARCHAR(255) NULL COMMENT 'platform user id',
ADD COLUMN IF NOT EXISTS customer_name VARCHAR(255) NULL COMMENT 'ชื่อลูกค้าจาก platform',
ADD COLUMN IF NOT EXISTS customer_avatar VARCHAR(500) NULL COMMENT 'รูปโปรไฟล์ลูกค้า';

-- 3. เพิ่ม columns ใน payments table
ALTER TABLE payments 
ADD COLUMN IF NOT EXISTS customer_platform VARCHAR(20) NULL COMMENT 'platform ที่ลูกค้าใช้ชำระ',
ADD COLUMN IF NOT EXISTS customer_platform_id VARCHAR(255) NULL COMMENT 'platform user id',
ADD COLUMN IF NOT EXISTS customer_name VARCHAR(255) NULL COMMENT 'ชื่อลูกค้าจาก platform',
ADD COLUMN IF NOT EXISTS customer_avatar VARCHAR(500) NULL COMMENT 'รูปโปรไฟล์ลูกค้า';

-- 4. เพิ่ม columns ใน savings_accounts table
ALTER TABLE savings_accounts 
ADD COLUMN IF NOT EXISTS customer_platform VARCHAR(20) NULL COMMENT 'platform ที่ลูกค้าเปิดบัญชี',
ADD COLUMN IF NOT EXISTS customer_platform_id VARCHAR(255) NULL COMMENT 'platform user id',
ADD COLUMN IF NOT EXISTS customer_name VARCHAR(255) NULL COMMENT 'ชื่อลูกค้าจาก platform',
ADD COLUMN IF NOT EXISTS customer_avatar VARCHAR(500) NULL COMMENT 'รูปโปรไฟล์ลูกค้า';

-- 5. เพิ่ม columns ใน installment_contracts table
ALTER TABLE installment_contracts 
ADD COLUMN IF NOT EXISTS customer_platform VARCHAR(20) NULL COMMENT 'platform ที่ลูกค้าเปิดสัญญา',
ADD COLUMN IF NOT EXISTS customer_platform_id VARCHAR(255) NULL COMMENT 'platform user id',
ADD COLUMN IF NOT EXISTS customer_name VARCHAR(255) NULL COMMENT 'ชื่อลูกค้าจาก platform',
ADD COLUMN IF NOT EXISTS customer_avatar VARCHAR(500) NULL COMMENT 'รูปโปรไฟล์ลูกค้า';

-- 6. เพิ่ม columns ใน customer_addresses table
ALTER TABLE customer_addresses 
ADD COLUMN IF NOT EXISTS customer_platform VARCHAR(20) NULL COMMENT 'platform ที่ลูกค้าบันทึกที่อยู่',
ADD COLUMN IF NOT EXISTS customer_platform_id VARCHAR(255) NULL COMMENT 'platform user id',
ADD COLUMN IF NOT EXISTS customer_name VARCHAR(255) NULL COMMENT 'ชื่อลูกค้าจาก platform',
ADD COLUMN IF NOT EXISTS customer_avatar VARCHAR(500) NULL COMMENT 'รูปโปรไฟล์ลูกค้า';

-- 7. สร้าง Index สำหรับการค้นหา
CREATE INDEX IF NOT EXISTS idx_conversations_platform_user ON conversations(platform, platform_user_id);
CREATE INDEX IF NOT EXISTS idx_orders_customer_platform ON orders(customer_platform, customer_platform_id);
CREATE INDEX IF NOT EXISTS idx_payments_customer_platform ON payments(customer_platform, customer_platform_id);

-- 8. อัพเดทข้อมูลเดิมจาก conversations (ถ้ามี)
-- UPDATE orders o
-- JOIN conversations c ON o.conversation_id = c.conversation_id
-- SET o.customer_platform = c.platform,
--     o.customer_platform_id = c.platform_user_id,
--     o.customer_name = c.platform_user_name,
--     o.customer_avatar = c.platform_user_avatar
-- WHERE o.customer_platform IS NULL AND c.platform_user_name IS NOT NULL;

SELECT 'Migration completed: Customer profile fields added' AS status;
