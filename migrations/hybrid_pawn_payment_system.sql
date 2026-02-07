-- =====================================================
-- Hybrid A+ Pawn Payment System Migration
-- วันที่: 2026-01-31
-- จุดประสงค์: รองรับระบบจำนำสินค้าแบรนด์เนมจากร้าน ฮ.เฮง เฮง
-- =====================================================

-- =====================================================
-- PART 1: อัพเดท payments table สำหรับ classification
-- =====================================================

-- 1.1 เพิ่ม classified_as - ระบุว่าสลิปนี้เป็นของอะไร
ALTER TABLE payments 
ADD COLUMN classified_as ENUM('order', 'pawn', 'unknown', 'rejected') DEFAULT 'unknown' 
AFTER status;

-- 1.2 เพิ่ม linked_pawn_payment_id - ถ้าเป็น pawn จะ link ไปที่ pawn_payments
ALTER TABLE payments 
ADD COLUMN linked_pawn_payment_id INT NULL 
AFTER classified_as;

-- 1.3 เพิ่ม match_status - สถานะการ auto-match
ALTER TABLE payments 
ADD COLUMN match_status ENUM('pending', 'auto_matched', 'manual_matched', 'no_match') DEFAULT 'pending' 
AFTER linked_pawn_payment_id;

-- 1.4 เพิ่ม match_attempts - บันทึกผลการพยายาม match (JSON)
-- เก็บข้อมูลว่าลองหาอะไรบ้าง, เจอ candidates อะไร, confidence score
ALTER TABLE payments 
ADD COLUMN match_attempts JSON NULL COMMENT 'Auto-match attempts log: candidates found, scores, reasons'
AFTER match_status;

-- 1.5 เพิ่ม matched_at - เวลาที่ match สำเร็จ
ALTER TABLE payments 
ADD COLUMN matched_at TIMESTAMP NULL 
AFTER match_attempts;

-- 1.6 เพิ่ม matched_by - ใครเป็นคน match (NULL = auto, user_id = manual)
ALTER TABLE payments 
ADD COLUMN matched_by INT NULL COMMENT 'NULL=auto-matched, user_id=manual classification'
AFTER matched_at;

-- 1.7 เพิ่ม classified_by - admin ที่ทำการ classify (for manual classification)
ALTER TABLE payments 
ADD COLUMN classified_by INT NULL COMMENT 'Admin who classified this payment'
AFTER matched_by;

-- 1.8 เพิ่ม classified_at - เวลาที่ classify
ALTER TABLE payments 
ADD COLUMN classified_at TIMESTAMP NULL COMMENT 'When payment was classified'
AFTER classified_by;

-- 1.9 เพิ่ม reject_reason - เหตุผลที่ปฏิเสธ (ถ้า classified_as = rejected)
ALTER TABLE payments 
ADD COLUMN reject_reason VARCHAR(500) NULL COMMENT 'Reason for rejection'
AFTER classified_at;

-- Index สำหรับ query เร็วขึ้น
CREATE INDEX idx_payments_classified_as ON payments(classified_as);
CREATE INDEX idx_payments_match_status ON payments(match_status);
CREATE INDEX idx_payments_linked_pawn ON payments(linked_pawn_payment_id);

-- =====================================================
-- PART 2: อัพเดท pawn_payments table สำหรับ source tracking
-- =====================================================

-- 2.1 เพิ่ม source_payment_id - link กลับไปที่ payments table (slip ต้นทาง)
ALTER TABLE pawn_payments 
ADD COLUMN source_payment_id INT NULL COMMENT 'Original payment slip from payments table'
AFTER id;

-- 2.2 Foreign Key
ALTER TABLE pawn_payments 
ADD CONSTRAINT fk_pawn_payments_source 
FOREIGN KEY (source_payment_id) REFERENCES payments(id) ON DELETE SET NULL;

-- Index
CREATE INDEX idx_pawn_payments_source ON pawn_payments(source_payment_id);

-- =====================================================
-- PART 3: อัพเดท pawns table สำหรับ order linkage
-- =====================================================

-- 3.1 เพิ่ม order_id - link ไปที่ order ที่ลูกค้าเคยซื้อสินค้านี้
ALTER TABLE pawns 
ADD COLUMN order_id INT NULL COMMENT 'FK to orders - original purchase of this item'
AFTER customer_id;

-- 3.2 เพิ่ม product_code - รหัสสินค้าที่นำมาจำนำ
ALTER TABLE pawns 
ADD COLUMN product_code VARCHAR(50) NULL COMMENT 'Product code from order'
AFTER order_id;

-- 3.3 เพิ่ม original_purchase_price - ราคาที่ลูกค้าซื้อไป (อ้างอิง)
ALTER TABLE pawns 
ADD COLUMN original_purchase_price DECIMAL(12,2) NULL COMMENT 'Original price customer paid'
AFTER product_code;

-- 3.4 เพิ่ม expected_interest_amount - ยอดดอกเบี้ยที่ต้องจ่ายรอบนี้ (สำหรับ auto-match)
ALTER TABLE pawns 
ADD COLUMN expected_interest_amount DECIMAL(12,2) NULL COMMENT 'Current expected interest payment for matching'
AFTER interest_rate;

-- 3.5 เพิ่ม next_payment_due - วันครบกำหนดชำระดอกเบี้ยรอบถัดไป
ALTER TABLE pawns 
ADD COLUMN next_payment_due DATE NULL COMMENT 'Next interest payment due date'
AFTER due_date;

-- 3.6 เพิ่ม total_interest_paid - ดอกเบี้ยที่จ่ายไปแล้วทั้งหมด
ALTER TABLE pawns 
ADD COLUMN total_interest_paid DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Total interest paid so far'
AFTER expected_interest_amount;

-- 3.7 เพิ่ม extension_count - จำนวนครั้งที่ต่ออายุ
ALTER TABLE pawns 
ADD COLUMN extension_count INT DEFAULT 0 COMMENT 'Number of extensions'
AFTER total_interest_paid;

-- Foreign Key to orders
ALTER TABLE pawns 
ADD CONSTRAINT fk_pawns_order 
FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL;

-- Indexes
CREATE INDEX idx_pawns_order ON pawns(order_id);
CREATE INDEX idx_pawns_product_code ON pawns(product_code);
CREATE INDEX idx_pawns_next_payment ON pawns(next_payment_due);
CREATE INDEX idx_pawns_expected_interest ON pawns(expected_interest_amount);

-- =====================================================
-- PART 4: สร้าง View สำหรับ Admin Dashboard
-- =====================================================

-- 4.1 View รวมการชำระเงินทั้งหมด (orders + pawns)
CREATE OR REPLACE VIEW v_all_payments AS
SELECT 
    p.id,
    p.payment_no,
    p.amount,
    p.payment_type,
    p.classified_as,
    p.match_status,
    p.status,
    p.slip_image,
    p.payment_date,
    p.created_at,
    p.verified_by,
    p.verified_at,
    p.customer_id,
    p.platform_user_id,
    p.platform,
    p.tenant_id,
    CASE 
        WHEN p.classified_as = 'order' THEN CONCAT('ORD-', p.order_id)
        WHEN p.classified_as = 'pawn' THEN CONCAT('PAWN-', pp.pawn_id)
        ELSE 'UNCLASSIFIED'
    END as reference_no,
    CASE 
        WHEN p.classified_as = 'order' THEN o.product_code
        WHEN p.classified_as = 'pawn' THEN pw.product_code
        ELSE NULL
    END as product_code,
    cp.name as customer_name,
    cp.phone as customer_phone
FROM payments p
LEFT JOIN pawn_payments pp ON p.linked_pawn_payment_id = pp.id
LEFT JOIN pawns pw ON pp.pawn_id = pw.id
LEFT JOIN orders o ON p.order_id = o.id
LEFT JOIN customer_profiles cp ON p.customer_id = cp.id;

-- 4.2 View สำหรับ pending classification
CREATE OR REPLACE VIEW v_pending_classification AS
SELECT 
    p.id,
    p.payment_no,
    p.amount,
    p.slip_image,
    p.payment_date,
    p.created_at,
    p.payment_details,
    p.match_attempts,
    p.customer_id,
    p.platform_user_id,
    cp.name as customer_name,
    cp.phone as customer_phone,
    -- แสดง candidates ที่เป็นไปได้
    (
        SELECT JSON_ARRAYAGG(
            JSON_OBJECT(
                'type', 'order',
                'id', o2.id,
                'product_code', o2.product_code,
                'remaining', o2.remaining_amount,
                'match_score', 
                CASE 
                    WHEN o2.remaining_amount = p.amount THEN 100
                    WHEN ABS(o2.remaining_amount - p.amount) < 10 THEN 90
                    ELSE 50
                END
            )
        )
        FROM orders o2 
        WHERE o2.customer_id = p.customer_id 
        AND o2.status IN ('pending', 'partial')
        AND o2.remaining_amount > 0
        LIMIT 5
    ) as order_candidates,
    (
        SELECT JSON_ARRAYAGG(
            JSON_OBJECT(
                'type', 'pawn',
                'id', pw2.id,
                'product_code', pw2.product_code,
                'expected_interest', pw2.expected_interest_amount,
                'loan_amount', pw2.loan_amount,
                'match_score',
                CASE 
                    WHEN pw2.expected_interest_amount = p.amount THEN 100
                    WHEN pw2.loan_amount = p.amount THEN 95
                    WHEN ABS(pw2.expected_interest_amount - p.amount) < 10 THEN 85
                    ELSE 40
                END
            )
        )
        FROM pawns pw2 
        WHERE pw2.customer_id = p.customer_id 
        AND pw2.status IN ('active', 'overdue')
        LIMIT 5
    ) as pawn_candidates
FROM payments p
LEFT JOIN customer_profiles cp ON p.customer_id = cp.id
WHERE p.match_status IN ('pending', 'no_match')
AND p.status = 'pending'
ORDER BY p.created_at DESC;

-- =====================================================
-- PART 5: Update existing data
-- =====================================================

-- 5.1 Mark existing verified payments as 'order' classified
UPDATE payments 
SET classified_as = 'order', 
    match_status = 'manual_matched',
    matched_at = verified_at
WHERE status = 'verified' 
AND order_id IS NOT NULL;

-- 5.2 Mark unverified payments as 'unknown'
UPDATE payments 
SET classified_as = 'unknown',
    match_status = 'pending'
WHERE status = 'pending';

-- =====================================================
-- PART 6: Stored Procedure สำหรับ Auto-Match
-- =====================================================

DELIMITER //

CREATE PROCEDURE sp_auto_match_payment(
    IN p_payment_id INT,
    OUT p_matched_type VARCHAR(20),
    OUT p_matched_id INT,
    OUT p_confidence INT
)
BEGIN
    DECLARE v_amount DECIMAL(12,2);
    DECLARE v_customer_id INT;
    DECLARE v_platform_user_id VARCHAR(255);
    DECLARE v_order_id INT DEFAULT NULL;
    DECLARE v_pawn_id INT DEFAULT NULL;
    DECLARE v_order_confidence INT DEFAULT 0;
    DECLARE v_pawn_confidence INT DEFAULT 0;
    DECLARE v_attempts JSON;
    
    -- Get payment details
    SELECT amount, customer_id, platform_user_id 
    INTO v_amount, v_customer_id, v_platform_user_id
    FROM payments WHERE id = p_payment_id;
    
    -- Initialize attempts log
    SET v_attempts = JSON_OBJECT('searched_at', NOW(), 'candidates', JSON_ARRAY());
    
    -- Step 1: Try to match with orders (exact amount match)
    SELECT id INTO v_order_id
    FROM orders 
    WHERE customer_id = v_customer_id
    AND status IN ('pending', 'partial', 'confirmed')
    AND remaining_amount = v_amount
    ORDER BY created_at DESC
    LIMIT 1;
    
    IF v_order_id IS NOT NULL THEN
        SET v_order_confidence = 100;
        SET v_attempts = JSON_SET(v_attempts, '$.order_exact_match', v_order_id);
    ELSE
        -- Step 1b: Try installment amount match
        SELECT o.id INTO v_order_id
        FROM orders o
        WHERE o.customer_id = v_customer_id
        AND o.status IN ('pending', 'partial')
        AND o.installment_amount = v_amount
        ORDER BY o.created_at DESC
        LIMIT 1;
        
        IF v_order_id IS NOT NULL THEN
            SET v_order_confidence = 95;
            SET v_attempts = JSON_SET(v_attempts, '$.order_installment_match', v_order_id);
        END IF;
    END IF;
    
    -- Step 2: Try to match with pawns (interest or loan amount)
    IF v_order_id IS NULL THEN
        -- 2a: Exact interest match
        SELECT id INTO v_pawn_id
        FROM pawns
        WHERE customer_id = v_customer_id
        AND status IN ('active', 'overdue')
        AND expected_interest_amount = v_amount
        ORDER BY next_payment_due ASC
        LIMIT 1;
        
        IF v_pawn_id IS NOT NULL THEN
            SET v_pawn_confidence = 100;
            SET v_attempts = JSON_SET(v_attempts, '$.pawn_interest_match', v_pawn_id);
        ELSE
            -- 2b: Loan amount match (redemption)
            SELECT id INTO v_pawn_id
            FROM pawns
            WHERE customer_id = v_customer_id
            AND status IN ('active', 'overdue')
            AND loan_amount = v_amount
            ORDER BY due_date ASC
            LIMIT 1;
            
            IF v_pawn_id IS NOT NULL THEN
                SET v_pawn_confidence = 90;
                SET v_attempts = JSON_SET(v_attempts, '$.pawn_redemption_match', v_pawn_id);
            END IF;
        END IF;
    END IF;
    
    -- Step 3: Determine best match
    IF v_order_confidence >= v_pawn_confidence AND v_order_id IS NOT NULL THEN
        SET p_matched_type = 'order';
        SET p_matched_id = v_order_id;
        SET p_confidence = v_order_confidence;
        
        -- Update payment
        UPDATE payments 
        SET order_id = v_order_id,
            classified_as = 'order',
            match_status = 'auto_matched',
            match_attempts = v_attempts,
            matched_at = NOW()
        WHERE id = p_payment_id;
        
    ELSEIF v_pawn_id IS NOT NULL THEN
        SET p_matched_type = 'pawn';
        SET p_matched_id = v_pawn_id;
        SET p_confidence = v_pawn_confidence;
        
        -- Update payment (will need manual confirmation to create pawn_payment)
        UPDATE payments 
        SET classified_as = 'pawn',
            match_status = 'auto_matched',
            match_attempts = v_attempts,
            matched_at = NOW()
        WHERE id = p_payment_id;
        
    ELSE
        SET p_matched_type = 'none';
        SET p_matched_id = NULL;
        SET p_confidence = 0;
        
        -- Log no match
        UPDATE payments 
        SET match_status = 'no_match',
            match_attempts = v_attempts
        WHERE id = p_payment_id;
    END IF;
    
END //

DELIMITER ;

-- =====================================================
-- DONE!
-- =====================================================
