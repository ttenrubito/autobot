-- Add Mock Payment Data with Slip Images for test1@gmail.com
-- This script adds realistic payment records with slip images for demo purposes

-- Get user ID for test1@gmail.com
SET @test1_user_id = (SELECT id FROM users WHERE email = 'test1@gmail.com' LIMIT 1);

-- Only proceed if user exists
SET @proceed = IF(@test1_user_id IS NOT NULL, 1, 0);

-- Create mock orders if they don't exist
INSERT INTO orders (
    order_no, customer_id, tenant_id, total_amount, 
    payment_type, installment_period, status, source, created_at
)
SELECT 
    'ORD-LINE-TEST-001', @test1_user_id, 'default', 1490.00,
    'full', NULL, 'pending_payment', 'chatbot', NOW() - INTERVAL 2 DAY
WHERE @proceed = 1 AND NOT EXISTS (SELECT 1 FROM orders WHERE order_no = 'ORD-LINE-TEST-001');

INSERT INTO orders (
    order_no, customer_id, tenant_id, total_amount, 
    payment_type, installment_period, status, source, created_at
)
SELECT 
    'ORD-LINE-TEST-002', @test1_user_id, 'default', 1497.00,
    'installment', 3, 'pending_payment', 'chatbot', NOW() - INTERVAL 1 DAY
WHERE @proceed = 1 AND NOT EXISTS (SELECT 1 FROM orders WHERE order_no = 'ORD-LINE-TEST-002');

-- Get order IDs
SET @order1_id = (SELECT id FROM orders WHERE order_no = 'ORD-LINE-TEST-001' LIMIT 1);
SET @order2_id = (SELECT id FROM orders WHERE order_no = 'ORD-LINE-TEST-002' LIMIT 1);

-- Delete existing test payments for clean insertion
DELETE FROM payments 
WHERE customer_id = @test1_user_id 
AND payment_no LIKE 'PAY-LINE-TEST-%';

-- Insert Payment 1: Verified - Full Payment with KBank Slip
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id,
    amount, payment_type, payment_method,
    installment_period, current_period,
    status, slip_image, payment_details,
    verified_at, payment_date, source, created_at
) VALUES (
    'PAY-LINE-TEST-001',
    @order1_id,
    @test1_user_id,
    'default',
    1490.00,
    'full',
    'bank_transfer',
    NULL,
    NULL,
    'verified',
    'slip-kbank.svg',  -- Mock slip image from /public/images/
    JSON_OBJECT(
        'line_user', 'Test User (test1)',
        'line_user_id', 'U_test1_mock_line_id',
        'message_id', 'LINE_MSG_001',
        'conversation_id', 1,
        'bank_name', 'ธนาคารกสิกรไทย',
        'bank_code', 'KBANK',
        'transfer_time', '2025-12-22 14:30:00',
        'ocr_verified', true,
        'ocr_result', JSON_OBJECT(
            'amount', 1490.00,
            'date', '22/12/2025',
            'time', '14:30',
            'confidence', 0.95
        )
    ),
    NOW() - INTERVAL 2 DAY,
    NOW() - INTERVAL 2 DAY,
    'chatbot',
    NOW() - INTERVAL 2 DAY
);

-- Insert Payment 2: Pending - Installment 1/3 with PromptPay Slip
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id,
    amount, payment_type, payment_method,
    installment_period, current_period,
    status, slip_image, payment_details,
    payment_date, source, created_at
) VALUES (
    'PAY-LINE-TEST-002',
    @order2_id,
    @test1_user_id,
    'default',
    499.00,
    'installment',
    'promptpay',
    3,
    1,
    'pending',
    'slip-promptpay.svg',  -- Mock slip image from /public/images/
    JSON_OBJECT(
        'line_user', 'Test User (test1)',
        'line_user_id', 'U_test1_mock_line_id',
        'message_id', 'LINE_MSG_002',
        'conversation_id', 1,
        'payment_method', 'PromptPay',
        'promptpay_ref', 'PP2025122312345',
        'transfer_time', '2025-12-23 16:45:00',
        'ocr_verified', false,
        'verification_status', 'รอตรวจสอบ'
    ),
    NOW() - INTERVAL 12 HOUR,
    'chatbot',
    NOW() - INTERVAL 12 HOUR
);

-- Insert Payment 3: Rejected - Installment 2/3 with SCB Slip
INSERT INTO payments (
    payment_no, order_id, customer_id, tenant_id,
    amount, payment_type, payment_method,
    installment_period, current_period,
    status, slip_image, payment_details,
    rejection_reason, payment_date, source, created_at
) VALUES (
    'PAY-LINE-TEST-003',
    @order2_id,
    @test1_user_id,
    'default',
    499.00,
    'installment',
    'bank_transfer',
    3,
    2,
    'rejected',
    'slip-scb.svg',  -- Mock slip image from /public/images/
    JSON_OBJECT(
        'line_user', 'Test User (test1)',
        'line_user_id', 'U_test1_mock_line_id',
        'message_id', 'LINE_MSG_003',
        'conversation_id', 1,
        'bank_name', 'ธนาคารไทยพาณิชย์',
        'bank_code', 'SCB',
        'transfer_time', '2025-12-24 09:15:00',
        'ocr_verified', false,
        'ocr_result', JSON_OBJECT(
            'amount', 450.00,
            'date', '24/12/2025',
            'time', '09:15',
            'confidence', 0.65
        ),
        'rejection_details', 'ยอดเงินไม่ตรงกับที่ต้องชำระ (ต้องการ 499 บาท แต่โอนมา 450 บาท)'
    ),
    'ยอดเงินไม่ตรงกับที่ต้องชำระ กรุณาอัปโหลดสลิปใหม่หรือติดต่อเจ้าหน้าที่',
    NOW() - INTERVAL 30 MINUTE,
    'chatbot',
    NOW() - INTERVAL 30 MINUTE
);

-- Update order statuses
UPDATE orders SET status = 'paid' WHERE id = @order1_id;
UPDATE orders SET status = 'pending_payment' WHERE id = @order2_id;

-- Verify insertion
SELECT 
    CONCAT('✅ เพิ่มข้อมูล payment สำเร็จ: ', COUNT(*), ' รายการ') AS result
FROM payments 
WHERE customer_id = @test1_user_id 
AND payment_no LIKE 'PAY-LINE-TEST-%';

-- Show summary
SELECT 
    p.payment_no,
    p.amount,
    p.payment_type,
    p.payment_method,
    p.status,
    p.slip_image,
    o.order_no,
    DATE_FORMAT(p.payment_date, '%d/%m/%Y %H:%i') as payment_datetime
FROM payments p
LEFT JOIN orders o ON p.order_id = o.id
WHERE p.customer_id = @test1_user_id 
AND p.payment_no LIKE 'PAY-LINE-TEST-%'
ORDER BY p.created_at DESC;
