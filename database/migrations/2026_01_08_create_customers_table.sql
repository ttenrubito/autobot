-- ================================================================
-- Customer Profiles Table - Unified Customer Database
-- ================================================================
-- 
-- วัตถุประสงค์: รวมข้อมูลลูกค้าจากทุก Platform (LINE, Facebook, Web) ไว้ที่เดียว
-- 
-- ประโยชน์:
-- 1. Admin เห็นประวัติลูกค้าครบในหน้าจอเดียว
-- 2. Auto-complete เมื่อ Key Manual (พิมพ์ชื่อ → แสดง Profile)
-- 3. Merge Profiles เมื่อพบว่าลูกค้าคนเดียวกันทักมาหลาย Platform
-- 4. Segment & Tag ลูกค้าสำหรับ Marketing
-- 
-- Version: 1.0
-- Date: 2026-01-08
-- ================================================================

-- ================================================================
-- PART 1: สร้างตาราง customers
-- ================================================================

CREATE TABLE IF NOT EXISTS customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    customer_no VARCHAR(50) UNIQUE, -- รหัสลูกค้า เช่น CUST-00001
    
    -- ================================================================
    -- Profile Information
    -- ================================================================
    display_name VARCHAR(255),          -- ชื่อที่แสดง (จาก Platform)
    full_name VARCHAR(255),             -- ชื่อจริง (Admin กรอก)
    nickname VARCHAR(100),              -- ชื่อเล่น
    phone VARCHAR(20),                  -- เบอร์โทร (หลัก)
    phone_alt VARCHAR(20),              -- เบอร์โทรสำรอง
    email VARCHAR(255),                 -- Email
    avatar_url TEXT,                    -- รูป Profile
    gender ENUM('male', 'female', 'other', 'unknown') DEFAULT 'unknown',
    birth_date DATE,                    -- วันเกิด
    
    -- ================================================================
    -- Platform Identities (สำหรับ Merge Profiles)
    -- ================================================================
    line_user_id VARCHAR(100),          -- LINE User ID
    line_display_name VARCHAR(255),     -- LINE Display Name
    line_picture_url TEXT,              -- LINE Profile Picture
    
    facebook_user_id VARCHAR(100),      -- Facebook PSID
    facebook_name VARCHAR(255),         -- Facebook Name
    facebook_picture_url TEXT,          -- Facebook Profile Picture
    
    instagram_user_id VARCHAR(100),     -- Instagram User ID (future)
    
    web_user_id INT,                    -- Link to users table (Web Login)
    
    -- ================================================================
    -- Address (Default Shipping)
    -- ================================================================
    default_address_id INT,             -- FK to customer_addresses
    
    -- ================================================================
    -- Statistics (Calculated)
    -- ================================================================
    total_orders INT DEFAULT 0,
    total_spent DECIMAL(12,2) DEFAULT 0.00,
    total_savings DECIMAL(12,2) DEFAULT 0.00,
    total_installments INT DEFAULT 0,
    
    -- ================================================================
    -- Business Info
    -- ================================================================
    customer_type ENUM('individual', 'reseller', 'vip', 'wholesale') DEFAULT 'individual',
    credit_limit DECIMAL(12,2) DEFAULT 0.00,
    discount_rate DECIMAL(5,2) DEFAULT 0.00, -- % ส่วนลดประจำ
    
    -- ================================================================
    -- Status & Classification
    -- ================================================================
    status ENUM('active', 'inactive', 'blocked', 'potential') DEFAULT 'active',
    tier ENUM('bronze', 'silver', 'gold', 'platinum') DEFAULT 'bronze',
    tags JSON,                          -- ["VIP", "New", "Problem", "Influencer"]
    source VARCHAR(50),                 -- แหล่งที่มา: "line", "facebook", "referral", "walk-in"
    
    -- ================================================================
    -- Notes & Internal
    -- ================================================================
    notes TEXT,                         -- หมายเหตุภายใน
    internal_notes TEXT,                -- หมายเหตุ (ซ่อนจากลูกค้า)
    preferred_contact VARCHAR(20),      -- ช่องทางที่ลูกค้าชอบ: "line", "phone", "facebook"
    
    -- ================================================================
    -- Timestamps
    -- ================================================================
    first_contact_at DATETIME,          -- ติดต่อครั้งแรกเมื่อไหร่
    last_contact_at DATETIME,           -- ติดต่อล่าสุดเมื่อไหร่
    last_purchase_at DATETIME,          -- ซื้อล่าสุดเมื่อไหร่
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,                     -- FK to users (Admin ที่สร้าง)
    updated_by INT,                     -- FK to users (Admin ที่แก้ไขล่าสุด)
    
    -- ================================================================
    -- Indexes
    -- ================================================================
    INDEX idx_tenant (tenant_id),
    INDEX idx_customer_no (customer_no),
    INDEX idx_line (line_user_id),
    INDEX idx_facebook (facebook_user_id),
    INDEX idx_phone (phone),
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_tier (tier),
    INDEX idx_web_user (web_user_id),
    
    -- Composite indexes for common queries
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_tenant_tier (tenant_id, tier)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ================================================================
-- PART 2: Migration - ดึงข้อมูลจากตารางที่มีอยู่
-- ================================================================

-- ดึงจาก conversations (LINE/Facebook Users)
INSERT IGNORE INTO customers (
    tenant_id,
    customer_no,
    display_name,
    line_user_id,
    line_display_name,
    facebook_user_id,
    facebook_name,
    avatar_url,
    source,
    first_contact_at,
    last_contact_at,
    status
)
SELECT DISTINCT
    COALESCE(ch.tenant_id, 'default') as tenant_id,
    CONCAT('CUST-', LPAD(FLOOR(RAND() * 99999), 5, '0')) as customer_no,
    c.platform_user_name as display_name,
    CASE WHEN ch.platform = 'line' THEN c.external_user_id ELSE NULL END as line_user_id,
    CASE WHEN ch.platform = 'line' THEN c.platform_user_name ELSE NULL END as line_display_name,
    CASE WHEN ch.platform = 'facebook' THEN c.external_user_id ELSE NULL END as facebook_user_id,
    CASE WHEN ch.platform = 'facebook' THEN c.platform_user_name ELSE NULL END as facebook_name,
    JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.line_profile_url')) as avatar_url,
    ch.platform as source,
    c.created_at as first_contact_at,
    c.last_message_at as last_contact_at,
    'active' as status
FROM conversations c
JOIN channels ch ON c.channel_id = ch.id
WHERE c.external_user_id IS NOT NULL
  AND c.external_user_id != ''
  AND NOT EXISTS (
      SELECT 1 FROM customers cust 
      WHERE (ch.platform = 'line' AND cust.line_user_id = c.external_user_id)
         OR (ch.platform = 'facebook' AND cust.facebook_user_id = c.external_user_id)
  );


-- ================================================================
-- PART 3: Alter existing tables to link to customers
-- ================================================================

-- เพิ่ม customer_id ในตาราง cases
ALTER TABLE cases 
ADD COLUMN customer_id INT NULL AFTER tenant_id,
ADD CONSTRAINT fk_cases_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL;

-- เพิ่ม customer_id ในตาราง orders
ALTER TABLE orders 
ADD COLUMN customer_profile_id INT NULL AFTER customer_id,
ADD CONSTRAINT fk_orders_customer_profile FOREIGN KEY (customer_profile_id) REFERENCES customers(id) ON DELETE SET NULL;

-- เพิ่ม customer_profile_id ในตาราง savings_accounts
ALTER TABLE savings_accounts 
ADD COLUMN customer_profile_id INT NULL AFTER customer_id,
ADD CONSTRAINT fk_savings_customer_profile FOREIGN KEY (customer_profile_id) REFERENCES customers(id) ON DELETE SET NULL;

-- เพิ่ม customer_profile_id ในตาราง installment_contracts
ALTER TABLE installment_contracts 
ADD COLUMN customer_profile_id INT NULL AFTER customer_id,
ADD CONSTRAINT fk_installment_customer_profile FOREIGN KEY (customer_profile_id) REFERENCES customers(id) ON DELETE SET NULL;


-- ================================================================
-- PART 4: View สำหรับดู Customer Summary
-- ================================================================

CREATE OR REPLACE VIEW v_customer_summary AS
SELECT 
    c.id,
    c.customer_no,
    c.display_name,
    c.full_name,
    c.phone,
    c.email,
    c.avatar_url,
    c.status,
    c.tier,
    c.tags,
    c.source,
    c.total_orders,
    c.total_spent,
    c.total_savings,
    c.total_installments,
    c.first_contact_at,
    c.last_contact_at,
    c.last_purchase_at,
    
    -- Platform info
    CASE 
        WHEN c.line_user_id IS NOT NULL THEN 'LINE'
        WHEN c.facebook_user_id IS NOT NULL THEN 'Facebook'
        ELSE 'Other'
    END as primary_platform,
    
    -- Active cases count
    (SELECT COUNT(*) FROM cases WHERE customer_id = c.id AND status NOT IN ('resolved', 'cancelled')) as open_cases,
    
    -- Active savings count
    (SELECT COUNT(*) FROM savings_accounts WHERE customer_profile_id = c.id AND status = 'active') as active_savings,
    
    -- Active installments count
    (SELECT COUNT(*) FROM installment_contracts WHERE customer_profile_id = c.id AND status IN ('active', 'overdue')) as active_installments
    
FROM customers c
WHERE c.status != 'blocked';


-- ================================================================
-- PART 5: Stored Procedure - Find or Create Customer
-- ================================================================

DELIMITER //

CREATE PROCEDURE sp_find_or_create_customer(
    IN p_tenant_id VARCHAR(50),
    IN p_platform VARCHAR(20),
    IN p_external_user_id VARCHAR(100),
    IN p_display_name VARCHAR(255),
    IN p_avatar_url TEXT,
    OUT p_customer_id INT
)
BEGIN
    DECLARE v_customer_id INT DEFAULT NULL;
    
    -- Try to find by platform ID
    IF p_platform = 'line' THEN
        SELECT id INTO v_customer_id FROM customers 
        WHERE tenant_id = p_tenant_id AND line_user_id = p_external_user_id LIMIT 1;
    ELSEIF p_platform = 'facebook' THEN
        SELECT id INTO v_customer_id FROM customers 
        WHERE tenant_id = p_tenant_id AND facebook_user_id = p_external_user_id LIMIT 1;
    END IF;
    
    -- If not found, create new
    IF v_customer_id IS NULL THEN
        INSERT INTO customers (
            tenant_id,
            customer_no,
            display_name,
            avatar_url,
            source,
            first_contact_at,
            last_contact_at,
            line_user_id,
            line_display_name,
            facebook_user_id,
            facebook_name
        ) VALUES (
            p_tenant_id,
            CONCAT('CUST-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(FLOOR(RAND() * 9999), 4, '0')),
            p_display_name,
            p_avatar_url,
            p_platform,
            NOW(),
            NOW(),
            CASE WHEN p_platform = 'line' THEN p_external_user_id ELSE NULL END,
            CASE WHEN p_platform = 'line' THEN p_display_name ELSE NULL END,
            CASE WHEN p_platform = 'facebook' THEN p_external_user_id ELSE NULL END,
            CASE WHEN p_platform = 'facebook' THEN p_display_name ELSE NULL END
        );
        
        SET v_customer_id = LAST_INSERT_ID();
    ELSE
        -- Update last contact
        UPDATE customers 
        SET last_contact_at = NOW(),
            display_name = COALESCE(p_display_name, display_name),
            avatar_url = COALESCE(p_avatar_url, avatar_url)
        WHERE id = v_customer_id;
    END IF;
    
    SET p_customer_id = v_customer_id;
END //

DELIMITER ;


-- ================================================================
-- PART 6: Trigger - Auto update customer stats
-- ================================================================

DELIMITER //

-- Trigger: Update stats when order is confirmed
CREATE TRIGGER trg_order_update_customer_stats
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
    IF NEW.status = 'confirmed' AND OLD.status != 'confirmed' AND NEW.customer_profile_id IS NOT NULL THEN
        UPDATE customers 
        SET total_orders = total_orders + 1,
            total_spent = total_spent + NEW.total_amount,
            last_purchase_at = NOW()
        WHERE id = NEW.customer_profile_id;
    END IF;
END //

-- Trigger: Update stats when savings verified
CREATE TRIGGER trg_savings_update_customer_stats
AFTER UPDATE ON savings_transactions
FOR EACH ROW
BEGIN
    DECLARE v_customer_profile_id INT;
    
    IF NEW.status = 'verified' AND OLD.status != 'verified' THEN
        SELECT customer_profile_id INTO v_customer_profile_id
        FROM savings_accounts WHERE id = NEW.savings_account_id;
        
        IF v_customer_profile_id IS NOT NULL THEN
            UPDATE customers 
            SET total_savings = total_savings + NEW.amount
            WHERE id = v_customer_profile_id;
        END IF;
    END IF;
END //

DELIMITER ;


-- ================================================================
-- PART 7: Sample Data (Optional - for testing)
-- ================================================================

/*
INSERT INTO customers (tenant_id, customer_no, display_name, full_name, phone, line_user_id, source, status, tier, tags) VALUES
('default', 'CUST-20260108-0001', 'น้องมิ้นท์ 🌸', 'มินตรา สุขใจ', '0891234567', 'Uxxxxxxxxxx', 'line', 'active', 'gold', '["VIP", "Frequent Buyer"]'),
('default', 'CUST-20260108-0002', 'คุณแพท', 'พัทธนันท์ วงศ์ดี', '0899876543', 'Uyyyyyyyyyy', 'line', 'active', 'silver', '["New"]'),
('default', 'CUST-20260108-0003', 'Somchai FB', 'สมชาย มั่งมี', '0812223333', NULL, 'facebook', 'active', 'bronze', NULL);
*/


-- ================================================================
-- Done!
-- ================================================================
-- 
-- หลังจาก Run script นี้:
-- 1. ตาราง customers จะถูกสร้าง
-- 2. ข้อมูลจาก conversations จะถูก migrate มา
-- 3. ตารางอื่นๆ จะมี FK ไปหา customers
-- 4. View และ Stored Procedure พร้อมใช้งาน
-- 
-- ขั้นตอนถัดไป:
-- 1. แก้ CaseEngine.php ให้ call sp_find_or_create_customer
-- 2. แก้ API ให้ return customer profile
-- 3. สร้างหน้า customers.php ใน Admin Panel
-- 
-- ================================================================
