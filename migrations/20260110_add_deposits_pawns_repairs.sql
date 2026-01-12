-- ============================================================
-- Migration: Add Deposits, Pawns, and Repairs Tables
-- Date: 2026-01-10
-- Author: Autobot System
-- Description: 
--   - deposits: มัดจำสินค้า (10%, ~14 วัน)
--   - pawns + pawn_payments: ฝากจำนำ/ต่อดอก (2%/เดือน, 30 วัน)
--   - repairs: งานซ่อม/เซอร์วิส
--   - returns: เปลี่ยน/คืนสินค้า (10-15% หัก)
--   - Alter installment_contracts: เพิ่ม 3 งวด + 3% fee
-- ============================================================

-- ============================================================
-- 1. DEPOSITS TABLE (มัดจำสินค้า)
-- ============================================================
CREATE TABLE IF NOT EXISTS `deposits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `deposit_no` VARCHAR(50) NOT NULL COMMENT 'เลขที่มัดจำ DEP-YYYYMMDD-XXXXX',
    
    -- Tenant & Customer
    `tenant_id` VARCHAR(50) NOT NULL DEFAULT 'default',
    `customer_id` INT NULL COMMENT 'FK to users (optional)',
    `customer_profile_id` INT NULL COMMENT 'FK to customer_profiles',
    `channel_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to customer_services',
    `external_user_id` VARCHAR(255) NOT NULL COMMENT 'LINE/FB user ID',
    `platform` ENUM('line', 'facebook', 'web', 'instagram') NOT NULL,
    
    -- Customer Info (cached)
    `customer_name` VARCHAR(255) NULL,
    `customer_phone` VARCHAR(50) NULL,
    `customer_line_name` VARCHAR(255) NULL,
    
    -- Product Info
    `product_ref_id` VARCHAR(100) NOT NULL COMMENT 'รหัสสินค้าอ้างอิง',
    `product_name` VARCHAR(255) NOT NULL,
    `product_code` VARCHAR(100) NULL,
    `product_price` DECIMAL(12,2) NOT NULL COMMENT 'ราคาเต็มสินค้า',
    
    -- Deposit Details
    `deposit_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'เปอร์เซ็นต์มัดจำ (default 10%)',
    `deposit_amount` DECIMAL(12,2) NOT NULL COMMENT 'ยอดมัดจำ',
    `remaining_amount` DECIMAL(12,2) GENERATED ALWAYS AS (`product_price` - `deposit_amount`) STORED,
    
    -- Validity
    `valid_days` INT NOT NULL DEFAULT 14 COMMENT 'กันได้กี่วัน',
    `expires_at` DATETIME NOT NULL COMMENT 'วันหมดอายุ',
    
    -- Status
    `status` ENUM(
        'pending_payment',   -- รอชำระมัดจำ
        'deposited',         -- มัดจำแล้ว รอดำเนินการ
        'converted',         -- แปลงเป็น order แล้ว
        'expired',           -- หมดอายุ
        'cancelled',         -- ยกเลิก
        'refunded'           -- คืนเงิน
    ) NOT NULL DEFAULT 'pending_payment',
    
    -- Payment Info
    `payment_slip_url` TEXT NULL,
    `payment_ref` VARCHAR(100) NULL,
    `payment_verified_at` TIMESTAMP NULL,
    `payment_verified_by` INT NULL,
    
    -- Conversion
    `converted_order_id` INT NULL COMMENT 'FK to orders เมื่อแปลงเป็น order',
    `converted_at` TIMESTAMP NULL,
    
    -- Tracking
    `case_id` INT NULL COMMENT 'FK to cases',
    `admin_notes` TEXT NULL,
    `reminder_sent_at` TIMESTAMP NULL COMMENT 'วันที่ส่ง reminder ล่าสุด',
    
    -- Timestamps
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_deposit_no` (`deposit_no`),
    KEY `idx_channel_user` (`channel_id`, `external_user_id`),
    KEY `idx_product` (`product_ref_id`),
    KEY `idx_status` (`status`),
    KEY `idx_expires` (`expires_at`),
    KEY `idx_customer_phone` (`customer_phone`),
    KEY `idx_tenant` (`tenant_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ตารางมัดจำสินค้า - กันสินค้าไว้ 10% ประมาณ 2 สัปดาห์';

-- ============================================================
-- 2. PAWNS TABLE (ฝากจำนำ)
-- ============================================================
CREATE TABLE IF NOT EXISTS `pawns` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pawn_no` VARCHAR(50) NOT NULL COMMENT 'เลขที่ใบจำนำ PWN-YYYYMMDD-XXXXX',
    
    -- Tenant & Customer
    `tenant_id` VARCHAR(50) NOT NULL DEFAULT 'default',
    `customer_id` INT NULL,
    `customer_profile_id` INT NULL,
    `channel_id` BIGINT UNSIGNED NOT NULL,
    `external_user_id` VARCHAR(255) NOT NULL,
    `platform` ENUM('line', 'facebook', 'web', 'instagram') NOT NULL,
    
    -- Customer Info
    `customer_name` VARCHAR(255) NULL,
    `customer_phone` VARCHAR(50) NULL,
    `customer_line_name` VARCHAR(255) NULL,
    `customer_id_card` VARCHAR(20) NULL COMMENT 'เลขบัตรประชาชน (encrypted)',
    
    -- Product Info
    `product_ref_id` VARCHAR(100) NULL COMMENT 'รหัสสินค้า (ถ้ามี)',
    `product_name` VARCHAR(255) NOT NULL COMMENT 'รายละเอียดสินค้าที่จำนำ',
    `product_description` TEXT NULL COMMENT 'รายละเอียดเพิ่มเติม (รุ่น, serial, สภาพ)',
    `product_images` JSON NULL COMMENT 'รูปสินค้าที่จำนำ',
    
    -- Valuation
    `appraisal_value` DECIMAL(12,2) NOT NULL COMMENT 'ราคาประเมิน',
    `pawn_percent` DECIMAL(5,2) NOT NULL DEFAULT 65.00 COMMENT 'เปอร์เซ็นต์ที่ให้ยืม (65-70%)',
    `pawn_amount` DECIMAL(12,2) NOT NULL COMMENT 'ยอดจำนำ (เงินที่ลูกค้าได้รับ)',
    
    -- Interest
    `interest_rate` DECIMAL(5,2) NOT NULL DEFAULT 2.00 COMMENT 'อัตราดอกเบี้ย %/เดือน',
    `interest_period_days` INT NOT NULL DEFAULT 30 COMMENT 'รอบชำระดอก (วัน)',
    `interest_amount` DECIMAL(12,2) GENERATED ALWAYS AS (
        ROUND(`pawn_amount` * (`interest_rate` / 100), 2)
    ) STORED COMMENT 'ดอกเบี้ยต่อรอบ',
    
    -- Payment Tracking
    `total_interest_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'ดอกเบี้ยที่ชำระแล้วทั้งหมด',
    `last_interest_paid_at` TIMESTAMP NULL COMMENT 'วันที่ชำระดอกล่าสุด',
    `next_due_date` DATE NOT NULL COMMENT 'วันครบกำหนดชำระดอกถัดไป',
    `periods_paid` INT NOT NULL DEFAULT 0 COMMENT 'จำนวนรอบที่ชำระแล้ว',
    
    -- Status
    `status` ENUM(
        'pending_approval', -- รอประเมิน/อนุมัติ
        'active',           -- กำลังจำนำอยู่
        'overdue',          -- เกินกำหนด
        'redeemed',         -- ไถ่ถอนแล้ว
        'forfeited',        -- หลุดจำนำ
        'cancelled'         -- ยกเลิก
    ) NOT NULL DEFAULT 'pending_approval',
    
    -- Redemption
    `redemption_amount` DECIMAL(12,2) NULL COMMENT 'ยอดไถ่ถอน (เงินต้น + ดอกค้าง)',
    `redeemed_at` TIMESTAMP NULL,
    `redeemed_slip_url` TEXT NULL,
    
    -- Forfeiture
    `forfeited_at` TIMESTAMP NULL,
    `forfeiture_reason` VARCHAR(500) NULL,
    
    -- Documents
    `guarantee_doc_url` TEXT NULL COMMENT 'ใบรับฝากจำนำ',
    `id_card_image_url` TEXT NULL COMMENT 'รูปบัตรประชาชน',
    
    -- Approval
    `approved_by` INT NULL,
    `approved_at` TIMESTAMP NULL,
    `approval_notes` TEXT NULL,
    
    -- Tracking
    `case_id` INT NULL,
    `admin_notes` TEXT NULL,
    `reminder_sent_at` TIMESTAMP NULL,
    
    -- Timestamps
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pawn_no` (`pawn_no`),
    KEY `idx_channel_user` (`channel_id`, `external_user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_next_due` (`next_due_date`),
    KEY `idx_customer_phone` (`customer_phone`),
    KEY `idx_tenant` (`tenant_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ตารางฝากจำนำ - ดอกเบี้ย 2%/เดือน ชำระทุก 30 วัน';

-- ============================================================
-- 3. PAWN_PAYMENTS TABLE (ประวัติชำระดอก/ไถ่ถอน)
-- ============================================================
CREATE TABLE IF NOT EXISTS `pawn_payments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_no` VARCHAR(50) NOT NULL COMMENT 'เลขที่ PWNPAY-YYYYMMDD-XXXXX',
    `pawn_id` BIGINT UNSIGNED NOT NULL,
    
    -- Payment Details
    `payment_type` ENUM(
        'interest',          -- ชำระดอกเบี้ย
        'partial_redemption',-- ชำระบางส่วน
        'full_redemption',   -- ไถ่ถอนเต็มจำนวน
        'penalty'            -- ค่าปรับ
    ) NOT NULL DEFAULT 'interest',
    
    `amount` DECIMAL(12,2) NOT NULL COMMENT 'จำนวนเงินที่ชำระ',
    `principal_portion` DECIMAL(12,2) NULL COMMENT 'ส่วนที่เป็นเงินต้น',
    `interest_portion` DECIMAL(12,2) NULL COMMENT 'ส่วนที่เป็นดอกเบี้ย',
    `penalty_portion` DECIMAL(12,2) NULL COMMENT 'ส่วนที่เป็นค่าปรับ',
    
    -- Period Info
    `for_period` INT NULL COMMENT 'สำหรับรอบที่',
    `period_start_date` DATE NULL,
    `period_end_date` DATE NULL,
    
    -- Status
    `status` ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    `verified_by` INT NULL,
    `verified_at` TIMESTAMP NULL,
    `rejection_reason` VARCHAR(500) NULL,
    
    -- Payment Proof
    `slip_image_url` TEXT NULL,
    `ocr_data` JSON NULL,
    `payment_ref` VARCHAR(100) NULL,
    `sender_name` VARCHAR(255) NULL,
    `transfer_time` DATETIME NULL,
    
    -- Next Due
    `next_due_date` DATE NULL COMMENT 'วันครบกำหนดถัดไป (หลังชำระ)',
    
    -- Notes
    `note` TEXT NULL,
    `case_id` INT NULL,
    
    -- Timestamps
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_payment_no` (`payment_no`),
    KEY `idx_pawn` (`pawn_id`),
    KEY `idx_status` (`status`),
    KEY `idx_type` (`payment_type`),
    KEY `idx_created` (`created_at`),
    
    CONSTRAINT `fk_pawn_payment_pawn` FOREIGN KEY (`pawn_id`) 
        REFERENCES `pawns` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ประวัติการชำระดอกเบี้ย/ไถ่ถอนจำนำ';

-- ============================================================
-- 4. REPAIRS TABLE (งานซ่อม/เซอร์วิส)
-- ============================================================
CREATE TABLE IF NOT EXISTS `repairs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `repair_no` VARCHAR(50) NOT NULL COMMENT 'เลขที่งานซ่อม REP-YYYYMMDD-XXXXX',
    
    -- Tenant & Customer
    `tenant_id` VARCHAR(50) NOT NULL DEFAULT 'default',
    `customer_id` INT NULL,
    `customer_profile_id` INT NULL,
    `channel_id` BIGINT UNSIGNED NOT NULL,
    `external_user_id` VARCHAR(255) NOT NULL,
    `platform` ENUM('line', 'facebook', 'web', 'instagram') NOT NULL,
    
    -- Customer Info
    `customer_name` VARCHAR(255) NULL,
    `customer_phone` VARCHAR(50) NULL,
    `customer_line_name` VARCHAR(255) NULL,
    
    -- Product Info
    `product_ref_id` VARCHAR(100) NULL COMMENT 'รหัสสินค้า (ถ้าเคยซื้อจากร้าน)',
    `product_name` VARCHAR(255) NOT NULL COMMENT 'ชื่อสินค้า/รุ่น',
    `product_brand` VARCHAR(100) NULL,
    `product_model` VARCHAR(100) NULL,
    `product_serial` VARCHAR(100) NULL,
    `product_description` TEXT NULL COMMENT 'รายละเอียดสินค้า',
    `product_images` JSON NULL COMMENT 'รูปสินค้าก่อนซ่อม',
    
    -- Issue
    `issue_description` TEXT NOT NULL COMMENT 'รายละเอียดปัญหา/อาการ',
    `issue_category` ENUM(
        'battery',           -- แบตเตอรี่
        'glass',             -- กระจก
        'band',              -- สาย
        'crown',             -- เม็ดมะยม
        'movement',          -- เครื่อง
        'water_damage',      -- น้ำเข้า
        'polish',            -- ขัดเงา
        'service',           -- เซอร์วิส
        'resize',            -- ปรับขนาด
        'stone_setting',     -- ฝังเพชร
        'clasp',             -- ตะขอ
        'other'              -- อื่นๆ
    ) NULL DEFAULT 'other',
    
    -- Estimation
    `estimated_cost` DECIMAL(12,2) NULL COMMENT 'ราคาประเมิน',
    `estimated_days` INT NULL COMMENT 'จำนวนวันที่คาดว่าจะเสร็จ',
    `estimated_completion_date` DATE NULL,
    
    -- Final
    `final_cost` DECIMAL(12,2) NULL COMMENT 'ราคาสุดท้าย',
    `parts_cost` DECIMAL(12,2) NULL COMMENT 'ค่าอะไหล่',
    `labor_cost` DECIMAL(12,2) NULL COMMENT 'ค่าแรง',
    
    -- Status
    `status` ENUM(
        'pending_assessment', -- รอประเมิน
        'quoted',             -- เสนอราคาแล้ว
        'customer_approved',  -- ลูกค้าอนุมัติ
        'in_progress',        -- กำลังซ่อม
        'completed',          -- ซ่อมเสร็จ
        'ready_for_pickup',   -- พร้อมรับ
        'delivered',          -- ส่งมอบแล้ว
        'cancelled'           -- ยกเลิก
    ) NOT NULL DEFAULT 'pending_assessment',
    
    -- Important Dates
    `received_at` TIMESTAMP NULL COMMENT 'วันที่รับเข้า',
    `quoted_at` TIMESTAMP NULL COMMENT 'วันที่เสนอราคา',
    `approved_at` TIMESTAMP NULL COMMENT 'วันที่ลูกค้าอนุมัติ',
    `started_at` TIMESTAMP NULL COMMENT 'วันที่เริ่มซ่อม',
    `completed_at` TIMESTAMP NULL COMMENT 'วันที่ซ่อมเสร็จ',
    `delivered_at` TIMESTAMP NULL COMMENT 'วันที่ส่งมอบ',
    
    -- Payment
    `payment_status` ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid',
    `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `payment_slip_url` TEXT NULL,
    
    -- Quality
    `before_images` JSON NULL COMMENT 'รูปก่อนซ่อม',
    `after_images` JSON NULL COMMENT 'รูปหลังซ่อม',
    `warranty_days` INT NULL DEFAULT 30 COMMENT 'รับประกันกี่วัน',
    `warranty_expires_at` DATE NULL,
    
    -- Tracking
    `assigned_to` INT NULL COMMENT 'ช่างที่รับผิดชอบ',
    `case_id` INT NULL,
    `admin_notes` TEXT NULL,
    `technician_notes` TEXT NULL COMMENT 'หมายเหตุช่าง',
    
    -- Timestamps
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_repair_no` (`repair_no`),
    KEY `idx_channel_user` (`channel_id`, `external_user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_customer_phone` (`customer_phone`),
    KEY `idx_tenant` (`tenant_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ตารางงานซ่อม/เซอร์วิส';

-- ============================================================
-- 5. PRODUCT_RETURNS TABLE (เปลี่ยน/คืนสินค้า)
-- ============================================================
CREATE TABLE IF NOT EXISTS `product_returns` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `return_no` VARCHAR(50) NOT NULL COMMENT 'เลขที่ RTN-YYYYMMDD-XXXXX',
    
    -- Tenant & Customer
    `tenant_id` VARCHAR(50) NOT NULL DEFAULT 'default',
    `customer_id` INT NULL,
    `customer_profile_id` INT NULL,
    `channel_id` BIGINT UNSIGNED NOT NULL,
    `external_user_id` VARCHAR(255) NOT NULL,
    `platform` ENUM('line', 'facebook', 'web', 'instagram') NOT NULL,
    
    -- Customer Info
    `customer_name` VARCHAR(255) NULL,
    `customer_phone` VARCHAR(50) NULL,
    
    -- Original Order
    `original_order_id` INT NOT NULL COMMENT 'FK to orders',
    `original_order_no` VARCHAR(50) NULL,
    `original_product_ref_id` VARCHAR(100) NOT NULL,
    `original_product_name` VARCHAR(255) NOT NULL,
    `original_price` DECIMAL(12,2) NOT NULL COMMENT 'ราคาที่ซื้อเดิม',
    `original_guarantee_no` VARCHAR(100) NULL COMMENT 'เลขใบรับประกันเดิม',
    
    -- Return Type
    `return_type` ENUM(
        'exchange_higher',   -- เปลี่ยนเป็นสินค้าราคาสูงกว่า (หัก 10%)
        'exchange_lower',    -- เปลี่ยนเป็นสินค้าราคาต่ำกว่า (หัก 15%)
        'refund'             -- คืนเงิน (หัก 15%)
    ) NOT NULL,
    
    -- Deduction
    `deduction_percent` DECIMAL(5,2) NOT NULL COMMENT '10% หรือ 15%',
    `deduction_amount` DECIMAL(12,2) NOT NULL COMMENT 'จำนวนเงินที่หัก',
    `net_value` DECIMAL(12,2) NOT NULL COMMENT 'มูลค่าสุทธิหลังหัก',
    
    -- New Product (for exchange)
    `new_product_ref_id` VARCHAR(100) NULL,
    `new_product_name` VARCHAR(255) NULL,
    `new_product_price` DECIMAL(12,2) NULL,
    `additional_payment` DECIMAL(12,2) NULL COMMENT 'ต้องจ่ายเพิ่ม (สำหรับ exchange_higher)',
    `refund_amount` DECIMAL(12,2) NULL COMMENT 'เงินคืน (สำหรับ refund หรือ exchange_lower)',
    
    -- New Order
    `new_order_id` INT NULL COMMENT 'FK to orders (ถ้าเปลี่ยนสินค้า)',
    
    -- Status
    `status` ENUM(
        'pending_review',    -- รอตรวจสอบ
        'approved',          -- อนุมัติ
        'processing',        -- กำลังดำเนินการ
        'completed',         -- เสร็จสิ้น
        'rejected'           -- ปฏิเสธ
    ) NOT NULL DEFAULT 'pending_review',
    
    -- Reason
    `return_reason` TEXT NULL COMMENT 'เหตุผลที่เปลี่ยน/คืน',
    `rejection_reason` TEXT NULL,
    
    -- Proof
    `guarantee_image_url` TEXT NULL COMMENT 'รูปใบรับประกัน',
    `product_images` JSON NULL COMMENT 'รูปสินค้าที่คืน',
    
    -- Approval
    `reviewed_by` INT NULL,
    `reviewed_at` TIMESTAMP NULL,
    
    -- Tracking
    `case_id` INT NULL,
    `admin_notes` TEXT NULL,
    
    -- Timestamps
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_return_no` (`return_no`),
    KEY `idx_original_order` (`original_order_id`),
    KEY `idx_channel_user` (`channel_id`, `external_user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_return_type` (`return_type`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ตารางเปลี่ยน/คืนสินค้า - หัก 10-15% ตามเงื่อนไข';

-- ============================================================
-- 6. ALTER INSTALLMENT_CONTRACTS (เพิ่ม fields สำหรับ 3 งวด + 3%)
-- ============================================================
ALTER TABLE `installment_contracts`
    ADD COLUMN IF NOT EXISTS `processing_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 3.00 
        COMMENT 'ค่าดำเนินการ % (default 3%)' AFTER `total_interest`,
    ADD COLUMN IF NOT EXISTS `processing_fee_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 
        COMMENT 'ยอดค่าดำเนินการ' AFTER `processing_fee_percent`,
    ADD COLUMN IF NOT EXISTS `max_completion_days` INT NOT NULL DEFAULT 60 
        COMMENT 'ต้องชำระครบภายในกี่วัน (default 60)' AFTER `processing_fee_amount`,
    ADD COLUMN IF NOT EXISTS `deadline_date` DATE NULL 
        COMMENT 'วันครบกำหนดชำระครบ' AFTER `max_completion_days`,
    ADD COLUMN IF NOT EXISTS `first_payment_amount` DECIMAL(12,2) NULL 
        COMMENT 'ยอดงวดแรก (รวมค่าดำเนินการ)' AFTER `deadline_date`,
    ADD COLUMN IF NOT EXISTS `first_payment_date` DATE NULL 
        COMMENT 'วันที่ชำระงวดแรก (ล็อควัน)' AFTER `first_payment_amount`,
    ADD COLUMN IF NOT EXISTS `deposit_id` BIGINT UNSIGNED NULL 
        COMMENT 'FK ถ้าแปลงมาจากมัดจำ' AFTER `order_id`,
    ADD COLUMN IF NOT EXISTS `guarantee_number` VARCHAR(100) NULL 
        COMMENT 'เลขใบรับประกัน' AFTER `deposit_id`;

-- ============================================================
-- 7. ALTER ORDERS (เพิ่ม fields)
-- ============================================================
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `deposit_id` BIGINT UNSIGNED NULL 
        COMMENT 'FK ถ้าแปลงมาจากมัดจำ' AFTER `savings_goal_id`,
    ADD COLUMN IF NOT EXISTS `shipping_method` ENUM('pickup', 'post', 'grab', 'other') NULL DEFAULT 'pickup'
        COMMENT 'วิธีจัดส่ง' AFTER `deposit_id`,
    ADD COLUMN IF NOT EXISTS `guarantee_number` VARCHAR(100) NULL 
        COMMENT 'เลขใบรับประกัน' AFTER `shipping_method`,
    ADD COLUMN IF NOT EXISTS `return_policy_accepted` TINYINT(1) NOT NULL DEFAULT 0 
        COMMENT 'ยอมรับเงื่อนไขเปลี่ยน/คืนแล้ว' AFTER `guarantee_number`;

-- ============================================================
-- 8. ALTER CASES (เพิ่ม case_type ใหม่)
-- ============================================================
ALTER TABLE `cases`
    MODIFY COLUMN `case_type` ENUM(
        'product_inquiry',
        'payment_full',
        'payment_installment',
        'payment_savings',
        'deposit',           -- มัดจำ
        'pawn',              -- จำนำ
        'repair',            -- ซ่อม
        'return_exchange',   -- เปลี่ยน/คืน
        'support',
        'other'
    ) DEFAULT 'other',
    ADD COLUMN IF NOT EXISTS `deposit_id` BIGINT UNSIGNED NULL AFTER `savings_id`,
    ADD COLUMN IF NOT EXISTS `pawn_id` BIGINT UNSIGNED NULL AFTER `deposit_id`,
    ADD COLUMN IF NOT EXISTS `repair_id` BIGINT UNSIGNED NULL AFTER `pawn_id`,
    ADD COLUMN IF NOT EXISTS `return_id` BIGINT UNSIGNED NULL AFTER `repair_id`;

-- ============================================================
-- 9. BANK_ACCOUNTS TABLE (บัญชีธนาคาร)
-- ============================================================
CREATE TABLE IF NOT EXISTS `bank_accounts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` VARCHAR(50) NOT NULL DEFAULT 'default',
    `channel_id` BIGINT UNSIGNED NULL COMMENT 'FK to customer_services (NULL = all channels)',
    
    `bank_code` VARCHAR(20) NOT NULL COMMENT 'SCB, KBank, BBL, etc.',
    `bank_name` VARCHAR(100) NOT NULL COMMENT 'ธนาคารไทยพาณิชย์',
    `account_number` VARCHAR(20) NOT NULL,
    `account_name` VARCHAR(255) NOT NULL COMMENT 'ชื่อบัญชี',
    `account_type` ENUM('savings', 'current') NOT NULL DEFAULT 'savings',
    
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'บัญชีหลัก',
    `display_order` INT NOT NULL DEFAULT 0,
    
    `promptpay_number` VARCHAR(20) NULL COMMENT 'เบอร์/เลขบัตร PromptPay',
    `qr_code_url` TEXT NULL COMMENT 'รูป QR Code',
    
    `note` TEXT NULL,
    
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_tenant` (`tenant_id`),
    KEY `idx_channel` (`channel_id`),
    KEY `idx_active` (`is_active`),
    UNIQUE KEY `uk_account` (`tenant_id`, `bank_code`, `account_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ตารางบัญชีธนาคารสำหรับรับชำระ';

-- Insert default bank accounts for ร้าน ฮ.เฮง เฮง
INSERT INTO `bank_accounts` (`tenant_id`, `bank_code`, `bank_name`, `account_number`, `account_name`, `is_primary`, `display_order`)
VALUES 
    ('default', 'SCB', 'ธนาคารไทยพาณิชย์', '1653014242', 'บจก. เพชรวิบวับ', 1, 1),
    ('default', 'BAY', 'ธนาคารกรุงศรี', '8000029282', 'บจก. เฮงเฮงโฮลดิ้ง', 0, 2)
ON DUPLICATE KEY UPDATE 
    `bank_name` = VALUES(`bank_name`),
    `account_name` = VALUES(`account_name`);

-- ============================================================
-- 10. NOTIFICATION_TEMPLATES TABLE (แม่แบบแจ้งเตือน)
-- ============================================================
CREATE TABLE IF NOT EXISTS `notification_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` VARCHAR(50) NOT NULL DEFAULT 'default',
    
    `code` VARCHAR(100) NOT NULL COMMENT 'deposit_expiring, pawn_due_reminder, etc.',
    `name` VARCHAR(255) NOT NULL,
    `category` ENUM('deposit', 'pawn', 'installment', 'repair', 'order', 'general') NOT NULL,
    
    `subject` VARCHAR(500) NULL COMMENT 'หัวข้อ (สำหรับ email)',
    `body_template` TEXT NOT NULL COMMENT 'เนื้อหา (รองรับ {{variable}})',
    
    `trigger_type` ENUM('manual', 'scheduled', 'event') NOT NULL DEFAULT 'manual',
    `trigger_days_before` INT NULL COMMENT 'ส่งก่อนวันครบกำหนดกี่วัน',
    `trigger_event` VARCHAR(100) NULL COMMENT 'event name ที่ trigger',
    
    `channels` JSON NULL COMMENT '["line", "facebook", "sms", "email"]',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code` (`tenant_id`, `code`),
    KEY `idx_category` (`category`),
    KEY `idx_trigger` (`trigger_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='แม่แบบข้อความแจ้งเตือน';

-- Insert default notification templates
INSERT INTO `notification_templates` (`tenant_id`, `code`, `name`, `category`, `body_template`, `trigger_type`, `trigger_days_before`) VALUES
    ('default', 'deposit_expiring_3d', 'มัดจำใกล้หมดอายุ (3 วัน)', 'deposit', 
     'สวัสดีค่ะ คุณ {{customer_name}} 📢\nมัดจำสินค้า {{product_name}} จะหมดอายุในอีก 3 วัน ({{expires_at}})\nหากต้องการดำเนินการต่อ รบกวนติดต่อทางร้านด้วยนะคะ 🙏', 
     'scheduled', 3),
    ('default', 'deposit_expiring_1d', 'มัดจำใกล้หมดอายุ (1 วัน)', 'deposit', 
     'สวัสดีค่ะ คุณ {{customer_name}} ⚠️\nมัดจำสินค้า {{product_name}} จะหมดอายุพรุ่งนี้ค่ะ!\nรีบติดต่อทางร้านด้วยนะคะ 📞', 
     'scheduled', 1),
    ('default', 'deposit_expired', 'มัดจำหมดอายุแล้ว', 'deposit', 
     'สวัสดีค่ะ คุณ {{customer_name}} 📋\nมัดจำสินค้า {{product_name}} หมดอายุแล้วค่ะ\nหากยังสนใจ สามารถติดต่อทางร้านเพื่อดำเนินการใหม่ได้เลยค่ะ', 
     'event', NULL),
    ('default', 'pawn_due_reminder_3d', 'จำนำครบกำหนด (3 วัน)', 'pawn', 
     'สวัสดีค่ะ คุณ {{customer_name}} 📢\nใบจำนำเลขที่ {{pawn_no}} ครบกำหนดชำระดอกในอีก 3 วัน\nยอดชำระ: {{interest_amount}} บาท\nโอนแล้วส่งสลิปมาได้เลยนะคะ 🙏', 
     'scheduled', 3),
    ('default', 'pawn_due_reminder_0d', 'จำนำครบกำหนดวันนี้', 'pawn', 
     'สวัสดีค่ะ คุณ {{customer_name}} ⚠️\nวันนี้ครบกำหนดชำระดอกค่ะ\nใบจำนำ: {{pawn_no}}\nยอดชำระ: {{interest_amount}} บาท\nรบกวนชำระภายในวันนี้นะคะ 🙏', 
     'scheduled', 0),
    ('default', 'pawn_overdue', 'จำนำเกินกำหนด', 'pawn', 
     'สวัสดีค่ะ คุณ {{customer_name}} 🔴\nใบจำนำเลขที่ {{pawn_no}} เกินกำหนดชำระดอกแล้ว {{overdue_days}} วัน\nกรุณาติดต่อทางร้านโดยด่วนค่ะ 📞', 
     'event', NULL),
    ('default', 'installment_due_reminder_3d', 'ผ่อนครบกำหนด (3 วัน)', 'installment', 
     'สวัสดีค่ะ คุณ {{customer_name}} 📢\nสัญญาผ่อน {{contract_no}} งวดที่ {{period_number}} ครบกำหนดชำระในอีก 3 วัน\nยอดชำระ: {{amount}} บาท', 
     'scheduled', 3),
    ('default', 'repair_completed', 'งานซ่อมเสร็จแล้ว', 'repair', 
     'สวัสดีค่ะ คุณ {{customer_name}} ✅\nงานซ่อม {{repair_no}} เสร็จเรียบร้อยแล้วค่ะ!\n{{product_name}}\nค่าบริการ: {{final_cost}} บาท\nพร้อมรับสินค้าได้เลยนะคะ 🎉', 
     'event', NULL)
ON DUPLICATE KEY UPDATE 
    `name` = VALUES(`name`),
    `body_template` = VALUES(`body_template`);

-- ============================================================
-- 11. SCHEDULED_NOTIFICATIONS TABLE (คิวแจ้งเตือน)
-- ============================================================
CREATE TABLE IF NOT EXISTS `scheduled_notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` VARCHAR(50) NOT NULL DEFAULT 'default',
    
    `template_id` INT UNSIGNED NULL COMMENT 'FK to notification_templates',
    `template_code` VARCHAR(100) NULL COMMENT 'fallback if no template_id',
    
    -- Target
    `channel_id` BIGINT UNSIGNED NOT NULL,
    `external_user_id` VARCHAR(255) NOT NULL,
    `platform` ENUM('line', 'facebook', 'web', 'sms', 'email') NOT NULL,
    
    -- Related Entity
    `entity_type` ENUM('deposit', 'pawn', 'installment', 'repair', 'order', 'other') NOT NULL,
    `entity_id` BIGINT UNSIGNED NOT NULL,
    
    -- Content
    `subject` VARCHAR(500) NULL,
    `message` TEXT NOT NULL COMMENT 'Rendered message',
    
    -- Schedule
    `scheduled_at` DATETIME NOT NULL COMMENT 'วันเวลาที่จะส่ง',
    `sent_at` DATETIME NULL,
    
    -- Status
    `status` ENUM('pending', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    `error_message` TEXT NULL,
    `retry_count` INT NOT NULL DEFAULT 0,
    
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_scheduled` (`scheduled_at`, `status`),
    KEY `idx_entity` (`entity_type`, `entity_id`),
    KEY `idx_channel` (`channel_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='คิวแจ้งเตือนที่รอส่ง';

-- ============================================================
-- SUMMARY
-- ============================================================
-- Created tables:
--   1. deposits           - มัดจำสินค้า
--   2. pawns              - ฝากจำนำ  
--   3. pawn_payments      - ประวัติชำระดอก
--   4. repairs            - งานซ่อม
--   5. product_returns    - เปลี่ยน/คืนสินค้า
--   6. bank_accounts      - บัญชีธนาคาร
--   7. notification_templates - แม่แบบแจ้งเตือน
--   8. scheduled_notifications - คิวแจ้งเตือน
-- 
-- Altered tables:
--   - installment_contracts (เพิ่ม 3 งวด + 3% fields)
--   - orders (เพิ่ม deposit_id, shipping_method, guarantee)
--   - cases (เพิ่ม case_type ใหม่ + FK columns)
-- ============================================================
