-- ============================================================
-- Migration: ปรับ pawns table สำหรับร้านแบรนด์เนมมือสอง
-- Date: 2026-01-31
-- Purpose: เพิ่ม order_id เพื่อเชื่อมกับสินค้าที่เคยซื้อจากร้าน
-- ============================================================

-- 1. เพิ่ม order_id column เพื่อเชื่อมกับ orders table
ALTER TABLE `pawns` 
ADD COLUMN `order_id` INT NULL COMMENT 'FK to orders.id - สินค้าที่ลูกค้าเคยซื้อจากร้าน' AFTER `customer_id`,
ADD COLUMN `product_code` VARCHAR(100) NULL COMMENT 'รหัสสินค้า (จาก orders หรือ products)' AFTER `order_id`,
ADD COLUMN `original_purchase_price` DECIMAL(12,2) NULL COMMENT 'ราคาที่ลูกค้าซื้อไป (จาก orders)' AFTER `product_code`;

-- 2. เพิ่ม Index สำหรับ order_id
ALTER TABLE `pawns`
ADD KEY `idx_pawns_order_id` (`order_id`);

-- 3. เพิ่ม case_type สำหรับ pawn ใน cases table
ALTER TABLE `cases` 
MODIFY COLUMN `case_type` ENUM(
    'product_inquiry',
    'payment_full',
    'payment_installment',
    'payment_savings',
    'pawn_inquiry',        -- NEW: สอบถาม/เริ่มต้นฝากสินค้า
    'pawn_interest',       -- NEW: ต่อดอกเบี้ย
    'pawn_redemption',     -- NEW: ไถ่คืนสินค้า
    'general_inquiry',
    'complaint',
    'other'
) NOT NULL DEFAULT 'general_inquiry';

-- 4. เพิ่ม pawn_id ใน cases table
ALTER TABLE `cases`
ADD COLUMN `pawn_id` INT NULL COMMENT 'FK to pawns.id' AFTER `savings_account_id`;

-- 5. เพิ่ม payment_type สำหรับ pawn ใน payments table (optional - ถ้าต้องการ track ใน payments ด้วย)
-- หมายเหตุ: pawn_payments table มีอยู่แล้ว อาจไม่จำเป็นต้องแก้ payments
-- ALTER TABLE `payments`
-- MODIFY COLUMN `payment_type` ENUM('full','installment','deposit','savings','pawn_interest','pawn_redemption') NOT NULL DEFAULT 'full';

-- ============================================================
-- สรุป Flow การทำงาน:
-- 
-- 1. ลูกค้าเคยซื้อสินค้า → มี record ใน orders (order_id, product_code)
-- 2. ลูกค้าต้องการฝากสินค้า → สร้าง pawn record โดยอ้างอิง order_id
-- 3. ระบบคำนวณวงเงินจาก original_purchase_price * loan_percentage
-- 4. ลูกค้าจ่ายดอกเบี้ย → บันทึกใน pawn_payments
-- 5. ลูกค้าไถ่คืน → อัพเดท pawn status = 'redeemed'
-- ============================================================
