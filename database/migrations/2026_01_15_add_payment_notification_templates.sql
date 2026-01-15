-- =====================================================
-- Add Notification Templates for Payment Approval/Rejection
-- Run this on production database
-- =====================================================

-- Create notification_templates table if not exists
CREATE TABLE IF NOT EXISTS notification_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(100) NOT NULL UNIQUE,
    template_name VARCHAR(255) NOT NULL,
    description TEXT,
    line_template TEXT,
    facebook_template TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create push_notifications table if not exists
CREATE TABLE IF NOT EXISTS push_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    platform VARCHAR(20) NOT NULL,
    platform_user_id VARCHAR(255) NOT NULL,
    channel_id INT NULL,
    notification_type VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    message_data JSON,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    next_retry_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    error_message TEXT,
    api_response JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_platform_user (platform, platform_user_id)
);

-- Insert payment notification templates
INSERT INTO notification_templates (template_key, template_name, description, line_template, facebook_template, is_active)
VALUES 
('payment_verified', 'Payment Verified', 'Sent when payment is approved', 
'✅ การชำระเงินได้รับการอนุมัติแล้ว

📋 เลขที่: {{payment_no}}
💰 จำนวน: ฿{{amount}}
📅 วันที่: {{payment_date}}

ขอบคุณที่ใช้บริการค่ะ 🙏',
'✅ การชำระเงินได้รับการอนุมัติแล้ว\n\n📋 เลขที่: {{payment_no}}\n💰 จำนวน: ฿{{amount}}\n\nขอบคุณที่ใช้บริการค่ะ 🙏',
1),

('payment_rejected', 'Payment Rejected', 'Sent when payment is rejected',
'❌ การชำระเงินถูกปฏิเสธ

📋 เลขที่: {{payment_no}}
💰 จำนวน: ฿{{amount}}
📅 วันที่: {{payment_date}}

❗ เหตุผล: {{reason}}

กรุณาติดต่อร้านค้าหากมีข้อสงสัยค่ะ',
'❌ การชำระเงินถูกปฏิเสธ\n\n📋 เลขที่: {{payment_no}}\n💰 จำนวน: ฿{{amount}}\n\n❗ เหตุผล: {{reason}}\n\nกรุณาติดต่อร้านค้าหากมีข้อสงสัยค่ะ',
1)
ON DUPLICATE KEY UPDATE
    line_template = VALUES(line_template),
    facebook_template = VALUES(facebook_template),
    updated_at = NOW();

-- Verify
SELECT * FROM notification_templates WHERE template_key LIKE 'payment%';
