-- ============================================================================
-- LINE Application Automation System - Database Migration
-- Created: 2026-01-01
-- Description: Complete database schema for LINE-based application/registration system
-- 
-- Features:
--   - Multi-campaign support
--   - Dynamic form configuration
--   - OCR document processing
--   - Status workflow tracking
--   - Admin assignment
--   - LINE notification history
--   - Audit trail with status_history
--
-- Usage:
--   mysql -u root -p autobot < database/migrations/2026_01_01_create_line_application_system.sql
-- ============================================================================

-- ============================================================================
-- 1. CAMPAIGNS TABLE - แคมเปญการสมัคร
-- ============================================================================

CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default' COMMENT 'Multi-tenant support',
    
    -- Campaign Info
    code VARCHAR(50) UNIQUE NOT NULL COMMENT 'Unique campaign code e.g. LOAN2024Q1',
    name VARCHAR(255) NOT NULL COMMENT 'Campaign display name',
    description TEXT COMMENT 'Campaign description',
    
    -- Form Configuration (Dynamic Form)
    form_config JSON NOT NULL COMMENT 'Dynamic form questions structure
        Example: {
            "questions": [
                {
                    "id": "q1",
                    "type": "text",
                    "label": "ชื่อ-นามสกุล",
                    "required": true,
                    "validation": {"minLength": 2}
                },
                {
                    "id": "q2",
                    "type": "select",
                    "label": "อาชีพ",
                    "options": ["พนักงานบริษัท", "ธุรกิจส่วนตัว", "อื่นๆ"],
                    "required": true
                }
            ]
        }',
    
    required_documents JSON COMMENT 'Required document types
        Example: [
            {"type": "id_card", "label": "บัตรประชาชน", "required": true},
            {"type": "house_registration", "label": "ทะเบียนบ้าน", "required": false},
            {"type": "bank_statement", "label": "Statement 3 เดือน", "required": true}
        ]',
    
    -- OCR Configuration
    ocr_enabled BOOLEAN DEFAULT TRUE COMMENT 'Enable OCR processing',
    ocr_fields JSON COMMENT 'Fields to extract from OCR
        Example: ["id_number", "name_th", "name_en", "birth_date", "address", "expiry_date"]',
    min_ocr_confidence DECIMAL(3,2) DEFAULT 0.70 COMMENT 'Min confidence for auto-approve (0.00-1.00)',
    
    -- Workflow Settings
    auto_approve BOOLEAN DEFAULT FALSE COMMENT 'Auto-approve if OCR confidence high enough',
    auto_approve_confidence DECIMAL(3,2) DEFAULT 0.90 COMMENT 'Confidence threshold for auto-approve',
    require_appointment BOOLEAN DEFAULT FALSE COMMENT 'Require appointment after approval',
    allow_duplicate BOOLEAN DEFAULT FALSE COMMENT 'Allow same LINE user to apply multiple times',
    
    -- LINE Integration
    line_channel_id VARCHAR(255) COMMENT 'LINE Channel ID for this campaign',
    line_rich_menu_id VARCHAR(255) COMMENT 'Rich Menu ID for this campaign',
    liff_id VARCHAR(255) COMMENT 'LIFF ID for application form',
    
    -- Notification Templates
    notification_template_received TEXT COMMENT 'Template: ได้รับใบสมัครแล้ว',
    notification_template_approved TEXT COMMENT 'Template: อนุมัติแล้ว',
    notification_template_rejected TEXT COMMENT 'Template: ปฏิเสธ',
    notification_template_need_docs TEXT COMMENT 'Template: ขอเอกสารเพิ่ม',
    notification_template_appointment TEXT COMMENT 'Template: นัดหมาย',
    
    -- Validity Period
    start_date DATE COMMENT 'Campaign start date',
    end_date DATE COMMENT 'Campaign end date',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Campaign active status',
    max_applications INT DEFAULT NULL COMMENT 'Max number of applications (null = unlimited)',
    
    -- Metadata
    created_by INT COMMENT 'FK to users (admin who created)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_code (code),
    INDEX idx_tenant (tenant_id),
    INDEX idx_active (is_active),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Campaign configuration for LINE applications';

-- ============================================================================
-- 2. LINE_APPLICATIONS TABLE - ใบสมัครหลัก
-- ============================================================================

CREATE TABLE IF NOT EXISTS line_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_no VARCHAR(50) UNIQUE NOT NULL COMMENT 'Auto-generated application number e.g. APP20260101001',
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Campaign Info
    campaign_id INT NOT NULL COMMENT 'FK to campaigns',
    campaign_name VARCHAR(255) NOT NULL COMMENT 'Denormalized for easy access',
    
    -- LINE User Info (from LIFF/Webhook)
    line_user_id VARCHAR(255) NOT NULL COMMENT 'LINE userId from LIFF.getProfile()',
    line_display_name VARCHAR(255) COMMENT 'LINE display name',
    line_picture_url VARCHAR(500) COMMENT 'LINE profile picture URL',
    line_profile JSON COMMENT 'Full LINE profile data for reference',
    
    -- Contact Info (from form)
    phone VARCHAR(20) COMMENT 'Phone number from form',
    email VARCHAR(255) COMMENT 'Email from form',
    
    -- Application Data
    form_data JSON NOT NULL COMMENT 'Form answers {q1: answer1, q2: answer2, ...}',
    ocr_results JSON COMMENT 'Consolidated OCR results from all documents
        Example: {
            "id_card": {
                "id_number": {"value": "1234567890123", "confidence": 0.95},
                "name_th": {"value": "นายสมชาย ใจดี", "confidence": 0.92}
            }
        }',
    extracted_data JSON COMMENT 'Structured extracted data (merged from form + OCR)',
    
    -- Status Workflow
    status ENUM(
        'RECEIVED',          -- เพิ่งสร้างใบสมัคร
        'FORM_INCOMPLETE',   -- กรอกฟอร์มไม่ครบ
        'DOC_PENDING',       -- รอ upload เอกสาร
        'OCR_PROCESSING',    -- กำลัง OCR
        'OCR_DONE',          -- OCR เสร็จแล้ว
        'NEED_REVIEW',       -- ต้อง manual review (OCR confidence ต่ำ)
        'APPROVED',          -- อนุมัติแล้ว
        'REJECTED',          -- ปฏิเสธ
        'INCOMPLETE',        -- ขอเอกสาร/ข้อมูลเพิ่ม
        'EXPIRED'            -- หมดอายุ
    ) DEFAULT 'RECEIVED' COMMENT 'Current application status',
    
    substatus VARCHAR(100) COMMENT 'Detailed substatus e.g. "ขอเอกสารเพิ่ม-บัตรประชาชน", "รอยืนยัน OTP"',
    
    -- Admin Assignment
    assigned_to INT DEFAULT NULL COMMENT 'FK to users (admin assigned to review)',
    assigned_at TIMESTAMP NULL COMMENT 'When assigned',
    assigned_by INT DEFAULT NULL COMMENT 'FK to users (admin who assigned)',
    
    -- Appointment
    appointment_datetime DATETIME NULL COMMENT 'Scheduled appointment date/time',
    appointment_location VARCHAR(500) COMMENT 'Appointment location/branch',
    appointment_note TEXT COMMENT 'Appointment notes',
    appointment_confirmed BOOLEAN DEFAULT FALSE COMMENT 'User confirmed appointment',
    appointment_confirmed_at TIMESTAMP NULL,
    
    -- Metadata
    source VARCHAR(50) DEFAULT 'line_liff' COMMENT 'Source: line_liff, line_chat, web_link, admin_manual',
    ip_address VARCHAR(45) COMMENT 'IP address of submitter',
    user_agent TEXT COMMENT 'User agent string',
    
    -- Audit Trail
    status_changed_by INT DEFAULT NULL COMMENT 'FK to users (last person who changed status)',
    status_changed_at TIMESTAMP NULL COMMENT 'Last status change timestamp',
    status_history JSON COMMENT 'Complete status change history
        Example: [
            {
                "from": "RECEIVED",
                "to": "OCR_DONE",
                "changed_by": "system",
                "changed_by_id": null,
                "changed_at": "2026-01-01 10:05:30",
                "reason": "OCR completed with 0.93 confidence"
            }
        ]',
    admin_notes TEXT COMMENT 'Internal admin notes',
    rejection_reason TEXT COMMENT 'Reason for rejection (if status=REJECTED)',
    
    -- Flags
    is_duplicate BOOLEAN DEFAULT FALSE COMMENT 'Detected as duplicate application',
    duplicate_of INT DEFAULT NULL COMMENT 'FK to line_applications (original application)',
    needs_manual_review BOOLEAN DEFAULT FALSE COMMENT 'Requires manual review (low OCR confidence)',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    
    -- Timestamps
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When application was submitted',
    completed_at TIMESTAMP NULL COMMENT 'When reached final status (APPROVED/REJECTED)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (status_changed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (duplicate_of) REFERENCES line_applications(id) ON DELETE SET NULL,
    
    INDEX idx_application_no (application_no),
    INDEX idx_line_user (line_user_id),
    INDEX idx_campaign (campaign_id),
    INDEX idx_status (status),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_created_at (created_at),
    INDEX idx_submitted_at (submitted_at),
    INDEX idx_duplicate (is_duplicate),
    INDEX idx_needs_review (needs_manual_review),
    INDEX idx_tenant (tenant_id),
    UNIQUE INDEX idx_line_user_campaign_unique (line_user_id, campaign_id, is_duplicate) 
        COMMENT 'Prevent duplicate applications (unless allow_duplicate=true)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Main LINE application records';

-- ============================================================================
-- 3. APPLICATION_DOCUMENTS TABLE - เอกสารที่อัปโหลด
-- ============================================================================

CREATE TABLE IF NOT EXISTS application_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL COMMENT 'FK to line_applications',
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Document Info
    document_type VARCHAR(100) NOT NULL COMMENT 'Type: id_card, house_registration, bank_statement, payment_slip, etc.',
    document_label VARCHAR(255) COMMENT 'Display label for user',
    document_side ENUM('front', 'back', 'both', 'single') DEFAULT 'single' COMMENT 'For ID cards with front/back',
    
    -- File Info
    original_filename VARCHAR(500) COMMENT 'Original uploaded filename',
    file_size INT COMMENT 'File size in bytes',
    mime_type VARCHAR(100) COMMENT 'MIME type: image/jpeg, application/pdf, etc.',
    
    -- Storage (Google Cloud Storage)
    storage_provider VARCHAR(50) DEFAULT 'gcs' COMMENT 'Storage provider: gcs, s3, local',
    storage_bucket VARCHAR(255) COMMENT 'GCS bucket name',
    storage_path VARCHAR(1000) NOT NULL COMMENT 'Full storage path: gs://bucket/path/file.jpg',
    signed_url VARCHAR(2000) COMMENT 'Temporary signed URL for admin viewing',
    signed_url_expires_at TIMESTAMP NULL COMMENT 'Signed URL expiration',
    
    -- OCR Processing
    ocr_processed BOOLEAN DEFAULT FALSE COMMENT 'Has OCR been processed',
    ocr_text TEXT COMMENT 'Raw OCR text output',
    ocr_data JSON COMMENT 'Structured OCR results
        Example: {
            "id_number": {"value": "1234567890123", "confidence": 0.95, "bbox": [...]},
            "name_th": {"value": "นายสมชาย ใจดี", "confidence": 0.92, "bbox": [...]}
        }',
    ocr_confidence DECIMAL(5,4) COMMENT 'Average confidence score (0.0000-1.0000)',
    ocr_error TEXT COMMENT 'Error message if OCR failed',
    ocr_provider VARCHAR(50) DEFAULT 'google_vision' COMMENT 'OCR provider: google_vision, google_docai, tesseract',
    ocr_processed_at TIMESTAMP NULL COMMENT 'When OCR was completed',
    
    -- Verification (Admin Review)
    is_verified BOOLEAN DEFAULT FALSE COMMENT 'Admin has verified this document',
    verified_by INT DEFAULT NULL COMMENT 'FK to users (admin who verified)',
    verified_at TIMESTAMP NULL COMMENT 'Verification timestamp',
    verification_note TEXT COMMENT 'Admin verification notes',
    is_rejected BOOLEAN DEFAULT FALSE COMMENT 'Document rejected by admin',
    rejection_reason TEXT COMMENT 'Reason for document rejection',
    
    -- Metadata
    upload_source VARCHAR(50) DEFAULT 'line_liff' COMMENT 'Upload source: line_liff, line_chat, admin_upload, web_form',
    upload_attempt INT DEFAULT 1 COMMENT 'Upload attempt number (for re-uploads)',
    is_supplementary BOOLEAN DEFAULT FALSE COMMENT 'Is this a supplementary upload (after INCOMPLETE status)',
    
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Upload timestamp',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (application_id) REFERENCES line_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_application (application_id),
    INDEX idx_type (document_type),
    INDEX idx_verified (is_verified),
    INDEX idx_ocr_processed (ocr_processed),
    INDEX idx_tenant (tenant_id),
    INDEX idx_uploaded_at (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Uploaded documents with OCR results';

-- ============================================================================
-- 4. APPLICATION_NOTIFICATIONS TABLE - การแจ้งเตือนที่ส่งไป
-- ============================================================================

CREATE TABLE IF NOT EXISTS application_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL COMMENT 'FK to line_applications',
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Notification Info
    notification_type VARCHAR(100) NOT NULL COMMENT 'Type: received, approved, rejected, need_docs, appointment, reminder, status_change',
    message_text TEXT NOT NULL COMMENT 'Actual message sent to user',
    message_data JSON COMMENT 'Additional message data (flex message structure, quick replies, etc.)',
    
    -- LINE Delivery
    line_message_id VARCHAR(255) COMMENT 'LINE message ID from API response',
    line_message_type VARCHAR(50) DEFAULT 'text' COMMENT 'Message type: text, flex, template, imagemap',
    delivery_status ENUM('pending', 'sent', 'failed', 'delivered', 'read') DEFAULT 'pending',
    delivery_error TEXT COMMENT 'Error message if delivery failed',
    delivery_attempts INT DEFAULT 0 COMMENT 'Number of delivery attempts',
    
    -- Recipient
    recipient_line_user_id VARCHAR(255) NOT NULL COMMENT 'LINE userId of recipient',
    
    -- Triggering Info
    triggered_by VARCHAR(50) COMMENT 'What triggered this notification: system, admin_action, webhook',
    triggered_by_user_id INT DEFAULT NULL COMMENT 'FK to users (if triggered by admin)',
    
    -- Metadata
    sent_at TIMESTAMP NULL COMMENT 'When successfully sent',
    read_at TIMESTAMP NULL COMMENT 'When user read (if webhook available)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (application_id) REFERENCES line_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (triggered_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_application (application_id),
    INDEX idx_type (notification_type),
    INDEX idx_status (delivery_status),
    INDEX idx_sent_at (sent_at),
    INDEX idx_recipient (recipient_line_user_id),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='LINE notification history';

-- ============================================================================
-- 5. SAMPLE DATA FOR TESTING
-- ============================================================================

-- Insert a sample campaign
INSERT INTO campaigns (
    code,
    name,
    description,
    form_config,
    required_documents,
    ocr_enabled,
    ocr_fields,
    min_ocr_confidence,
    auto_approve,
    auto_approve_confidence,
    notification_template_received,
    notification_template_approved,
    notification_template_rejected,
    notification_template_need_docs,
    start_date,
    end_date,
    is_active
) VALUES (
    'DEMO2026',
    'แคมเปญทดสอบระบบ 2026',
    'แคมเปญสำหรับทดสอบระบบลงทะเบียนผ่าน LINE',
    JSON_OBJECT(
        'questions', JSON_ARRAY(
            JSON_OBJECT('id', 'q1', 'type', 'text', 'label', 'ชื่อ-นามสกุล', 'required', true),
            JSON_OBJECT('id', 'q2', 'type', 'tel', 'label', 'เบอร์โทรศัพท์', 'required', true),
            JSON_OBJECT('id', 'q3', 'type', 'email', 'label', 'อีเมล', 'required', false),
            JSON_OBJECT('id', 'q4', 'type', 'select', 'label', 'อาชีพ', 
                'options', JSON_ARRAY('พนักงานบริษัท', 'ธุรกิจส่วนตัว', 'รับราชการ', 'อื่นๆ'),
                'required', true
            )
        )
    ),
    JSON_ARRAY(
        JSON_OBJECT('type', 'id_card', 'label', 'บัตรประชาชน', 'required', true),
        JSON_OBJECT('type', 'house_registration', 'label', 'ทะเบียนบ้าน', 'required', false)
    ),
    true,
    JSON_ARRAY('id_number', 'name_th', 'name_en', 'birth_date', 'address', 'expiry_date'),
    0.70,
    false,
    0.90,
    'สวัสดีค่ะ 👋 ได้รับใบสมัครของคุณเรียบร้อยแล้ว\nเลขที่: {application_no}\n\nระบบกำลังตรวจสอบเอกสารของคุณ จะแจ้งผลให้ทราบเร็วๆนี้นะคะ',
    'ยินดีด้วยค่ะ 🎉\n\nใบสมัครของคุณได้รับการอนุมัติแล้ว\nเลขที่: {application_no}\n\nกรุณานำเลขนี้มาในวันนัดหมายค่ะ',
    'ขออภัยค่ะ 😔\n\nใบสมัครของคุณไม่ผ่านการพิจารณา\n\nเหตุผล: {rejection_reason}\n\nหากมีข้อสงสัย กรุณาติดต่อเจ้าหน้าที่ค่ะ',
    'กรุณาส่งเอกสารเพิ่มเติมค่ะ 📄\n\nเอกสารที่ต้องการ:\n{required_documents}\n\nคลิกที่นี่เพื่อส่งเอกสาร:\n{upload_link}',
    CURDATE(),
    DATE_ADD(CURDATE(), INTERVAL 1 YEAR),
    true
);

-- ============================================================================
-- COMPLETION MESSAGE
-- ============================================================================

SELECT '============================================' AS '';
SELECT '✅ LINE Application System Migration Complete!' AS '';
SELECT '============================================' AS '';
SELECT '' AS '';
SELECT 'Tables created:' AS '';
SELECT '  1. campaigns - Campaign configuration' AS '';
SELECT '  2. line_applications - Application records' AS '';
SELECT '  3. application_documents - Uploaded documents' AS '';
SELECT '  4. application_notifications - Notification history' AS '';
SELECT '' AS '';
SELECT 'Sample data:' AS '';
SELECT '  - 1 demo campaign (DEMO2026)' AS '';
SELECT '' AS '';

-- Show table row counts
SELECT 
    'campaigns' AS table_name,
    COUNT(*) AS row_count
FROM campaigns
UNION ALL
SELECT 
    'line_applications' AS table_name,
    COUNT(*) AS row_count
FROM line_applications
UNION ALL
SELECT 
    'application_documents' AS table_name,
    COUNT(*) AS row_count
FROM application_documents
UNION ALL
SELECT 
    'application_notifications' AS table_name,
    COUNT(*) AS row_count
FROM application_notifications;
