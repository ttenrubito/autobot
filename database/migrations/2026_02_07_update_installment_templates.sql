-- =====================================================
-- Update Installment Push Notification Templates
-- Date: 2026-02-07
-- Purpose: Add remaining_amount and improve templates
-- =====================================================

-- Update installment_payment_verified template
-- Adds: paid_amount, total_amount, remaining_amount
UPDATE notification_templates 
SET line_template = '✅ รับชำระงวดที่ {{current_period}}/{{total_periods}} เรียบร้อยแล้วค่ะ\n\n📦 สินค้า: {{product_name}}\n💰 ยอดครั้งนี้: ฿{{amount}}\n📅 วันที่: {{payment_date}}\n\n📊 ชำระแล้ว: ฿{{paid_amount}} / ฿{{total_amount}}\n💵 คงเหลือ: ฿{{remaining_amount}}\n📋 สถานะ: {{paid_periods}}/{{total_periods}} งวด\n{{next_period_info}}\n\nขอบคุณที่ใช้บริการค่ะ 🙏',
    facebook_template = '✅ รับชำระงวดที่ {{current_period}}/{{total_periods}} แล้วค่ะ\n\n💰 ยอด: ฿{{amount}}\n💵 คงเหลือ: ฿{{remaining_amount}}\n📋 {{paid_periods}}/{{total_periods}} งวด\n\nขอบคุณค่ะ 🙏',
    updated_at = NOW()
WHERE template_key = 'installment_payment_verified';

-- Update installment_completed template
-- Adds: total_periods
UPDATE notification_templates 
SET line_template = '🎉 ยินดีด้วยค่ะ! ผ่อนครบทุกงวดแล้ว\n\n📦 สินค้า: {{product_name}}\n💰 ยอดรวม: ฿{{total_paid}}\n✅ ชำระครบ {{total_periods}} งวด\n📅 วันที่ครบ: {{completion_date}}\n\n🎊 ขอบคุณที่ไว้วางใจใช้บริการค่ะ 🙏✨',
    facebook_template = '🎉 ยินดีด้วยค่ะ! ผ่อนครบแล้ว\n\n📦 {{product_name}}\n💰 ยอดรวม: ฿{{total_paid}}\n✅ ครบ {{total_periods}} งวด\n\nขอบคุณค่ะ 🙏✨',
    updated_at = NOW()
WHERE template_key = 'installment_completed';

SELECT 'Templates updated successfully!' as result;
