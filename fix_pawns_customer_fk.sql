-- ============================================================
-- Fix pawns.customer_id Foreign Key
-- ============================================================
-- เปลี่ยน FK จาก users.id เป็น customer_profiles.id
-- เพราะลูกค้าแชท (LINE/Facebook) อยู่ใน customer_profiles ไม่ใช่ users
-- 
-- users = เจ้าของร้าน/tenants (shop owners)
-- customer_profiles = ลูกค้าแชท (end customers from LINE/FB)
-- ============================================================

-- Step 1: Drop existing FK constraint
ALTER TABLE `pawns` DROP FOREIGN KEY `fk_pawn_customer`;

-- Step 2: Add new FK to customer_profiles
-- Note: ใช้ ON DELETE SET NULL แทน CASCADE เพื่อไม่ให้ลบ pawn record เมื่อลบลูกค้า
ALTER TABLE `pawns` 
    MODIFY COLUMN `customer_id` int DEFAULT NULL,
    ADD CONSTRAINT `fk_pawn_customer_profile` 
    FOREIGN KEY (`customer_id`) REFERENCES `customer_profiles` (`id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

-- Step 3: Verify
-- SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME 
-- FROM information_schema.KEY_COLUMN_USAGE 
-- WHERE TABLE_NAME = 'pawns' AND REFERENCED_TABLE_NAME IS NOT NULL;
