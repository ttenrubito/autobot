-- ============================================
-- Chatbot Commerce v2.0 - Cases & Savings System
-- Created: 2026-01-06
-- Description: Tables for case/ticket management and savings (ออมสินค้า)
-- Ref: CHATBOT_COMMERCE_SPEC_V2.md
-- ============================================

-- ============================================
-- 1. CASES (Ticket/Case Management for Admin)
-- ============================================
-- Purpose: Track customer requests (product inquiry, payment, installment, savings)
-- Admin can see combined inbox from FB + LINE with status tracking

CREATE TABLE IF NOT EXISTS cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Case identification
    case_no VARCHAR(50) UNIQUE NOT NULL COMMENT 'Format: CASE-YYYYMMDD-XXXXX',
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Case type (maps to 4 use cases)
    case_type ENUM(
        'product_inquiry',      -- Use Case 1: ค้นหา/สอบถามสินค้า
        'payment_full',         -- Use Case 2: ชำระเงินเต็ม
        'payment_installment',  -- Use Case 3: ชำระผ่อน
        'payment_savings',      -- Use Case 4: ออมสินค้า
        'general_inquiry',      -- คำถามทั่วไป
        'complaint',            -- ร้องเรียน
        'other'
    ) NOT NULL DEFAULT 'general_inquiry',
    
    -- Customer & Channel info
    channel_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to channels',
    external_user_id VARCHAR(255) NOT NULL COMMENT 'LINE UID or FB PSID',
    customer_id INT NULL COMMENT 'FK to users if identified',
    platform ENUM('line', 'facebook', 'web', 'instagram') NOT NULL,
    
    -- Session link
    session_id BIGINT UNSIGNED NULL COMMENT 'FK to chat_sessions',
    
    -- Case content
    subject VARCHAR(500) NULL COMMENT 'Auto-generated or admin-set subject',
    description TEXT NULL COMMENT 'Case description/summary',
    
    -- Slots/Data collected by bot (JSON)
    slots JSON COMMENT 'Collected slot data: product_ref, amount, slip_url, etc.',
    
    -- Related entities
    product_ref_id VARCHAR(100) NULL COMMENT 'NPD ref_id if product-related',
    order_id INT NULL COMMENT 'FK to orders if order-related',
    payment_id INT NULL COMMENT 'FK to payments if payment-related',
    savings_account_id BIGINT UNSIGNED NULL COMMENT 'FK to savings_accounts',
    
    -- Case status & workflow
    status ENUM(
        'open',           -- Bot is handling or waiting customer
        'pending_admin',  -- Needs admin review
        'in_progress',    -- Admin is working on it
        'pending_customer', -- Waiting for customer response
        'resolved',       -- Completed
        'cancelled'       -- Cancelled/void
    ) NOT NULL DEFAULT 'open',
    
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    
    -- Assignment
    assigned_to INT NULL COMMENT 'FK to admin user',
    assigned_at TIMESTAMP NULL,
    
    -- Resolution
    resolution_type ENUM(
        'completed',      -- Successfully completed
        'no_response',    -- Customer didn't respond
        'duplicate',      -- Duplicate case
        'invalid',        -- Invalid request
        'other'
    ) NULL,
    resolution_notes TEXT NULL,
    resolved_at TIMESTAMP NULL,
    resolved_by INT NULL COMMENT 'FK to admin user',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_customer_message_at TIMESTAMP NULL,
    last_bot_message_at TIMESTAMP NULL,
    last_admin_message_at TIMESTAMP NULL,
    
    -- Indexes
    INDEX idx_case_no (case_no),
    INDEX idx_tenant (tenant_id),
    INDEX idx_case_type (case_type),
    INDEX idx_channel (channel_id),
    INDEX idx_external_user (external_user_id),
    INDEX idx_customer (customer_id),
    INDEX idx_platform (platform),
    INDEX idx_session (session_id),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_assigned (assigned_to),
    INDEX idx_product_ref (product_ref_id),
    INDEX idx_order (order_id),
    INDEX idx_created (created_at),
    INDEX idx_updated (updated_at),
    
    -- Composite indexes for common queries
    INDEX idx_platform_status (platform, status),
    INDEX idx_status_priority (status, priority, created_at),
    INDEX idx_assigned_status (assigned_to, status)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. CASE ACTIVITIES (Audit Trail)
-- ============================================

CREATE TABLE IF NOT EXISTS case_activities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id BIGINT UNSIGNED NOT NULL,
    
    -- Activity type
    activity_type ENUM(
        'created',           -- Case created
        'status_changed',    -- Status changed
        'assigned',          -- Assigned to admin
        'slot_updated',      -- Bot collected new slot data
        'customer_message',  -- Customer sent message
        'bot_message',       -- Bot sent message
        'admin_message',     -- Admin sent message
        'note_added',        -- Admin added internal note
        'resolved',          -- Case resolved
        'reopened',          -- Case reopened
        'merged',            -- Merged with another case
        'linked_order',      -- Linked to order
        'linked_payment'     -- Linked to payment
    ) NOT NULL,
    
    -- Activity data
    description TEXT NULL,
    old_value JSON NULL,
    new_value JSON NULL,
    
    -- Actor
    actor_type ENUM('bot', 'customer', 'admin', 'system') NOT NULL,
    actor_id VARCHAR(255) NULL COMMENT 'User ID or admin ID',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_case (case_id),
    INDEX idx_activity_type (activity_type),
    INDEX idx_actor (actor_type, actor_id),
    INDEX idx_created (created_at),
    
    CONSTRAINT fk_case_activities_case FOREIGN KEY (case_id)
        REFERENCES cases(id) ON DELETE CASCADE
        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. SAVINGS ACCOUNTS (บัญชีออมสินค้า)
-- ============================================
-- Purpose: Track customer savings for a specific product
-- Product is reserved during savings period

CREATE TABLE IF NOT EXISTS savings_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Account identification
    account_no VARCHAR(50) UNIQUE NOT NULL COMMENT 'Format: SAV-YYYYMMDD-XXXXX',
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Customer info
    customer_id INT NULL COMMENT 'FK to users if identified',
    channel_id BIGINT UNSIGNED NOT NULL,
    external_user_id VARCHAR(255) NOT NULL,
    platform ENUM('line', 'facebook', 'web', 'instagram') NOT NULL,
    
    -- Product being saved for
    product_ref_id VARCHAR(100) NOT NULL COMMENT 'NPD ref_id',
    product_name VARCHAR(255) NOT NULL,
    product_price DECIMAL(12,2) NOT NULL COMMENT 'Price at time of creation',
    
    -- Savings plan
    target_amount DECIMAL(12,2) NOT NULL COMMENT 'Total amount to save (usually = product_price)',
    current_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Amount saved so far',
    min_deposit_amount DECIMAL(12,2) NULL COMMENT 'Minimum per deposit (optional)',
    
    -- Timeline
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    target_date DATE NULL COMMENT 'Expected completion date (optional)',
    completed_at TIMESTAMP NULL,
    
    -- Status
    status ENUM(
        'active',       -- Currently saving
        'completed',    -- Target reached, pending order creation
        'converted',    -- Converted to order
        'cancelled',    -- Cancelled by customer
        'expired',      -- Expired (admin decision)
        'refunded'      -- Money refunded
    ) NOT NULL DEFAULT 'active',
    
    -- Linked order (when converted)
    order_id INT NULL COMMENT 'FK to orders when savings converted',
    
    -- Case link
    case_id BIGINT UNSIGNED NULL COMMENT 'FK to cases',
    
    -- Admin notes
    admin_notes TEXT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_account_no (account_no),
    INDEX idx_tenant (tenant_id),
    INDEX idx_customer (customer_id),
    INDEX idx_channel (channel_id),
    INDEX idx_external_user (external_user_id),
    INDEX idx_product_ref (product_ref_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_target_date (target_date),
    
    CONSTRAINT fk_savings_customer FOREIGN KEY (customer_id)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_savings_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_savings_case FOREIGN KEY (case_id)
        REFERENCES cases(id) ON DELETE SET NULL
        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. SAVINGS TRANSACTIONS (รายการฝากออม)
-- ============================================

CREATE TABLE IF NOT EXISTS savings_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Transaction identification
    transaction_no VARCHAR(50) UNIQUE NOT NULL COMMENT 'Format: SAVTX-YYYYMMDD-XXXXX',
    savings_account_id BIGINT UNSIGNED NOT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Transaction type
    transaction_type ENUM(
        'deposit',      -- Customer deposits money
        'adjustment',   -- Admin adjustment (+/-)
        'refund',       -- Refund to customer
        'conversion'    -- Convert to order payment
    ) NOT NULL DEFAULT 'deposit',
    
    -- Amount (positive for deposit, negative for refund)
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL COMMENT 'Account balance after this transaction',
    
    -- Payment method (for deposits)
    payment_method ENUM('bank_transfer', 'promptpay', 'credit_card', 'cash', 'other') NULL,
    
    -- Payment evidence
    slip_image_url VARCHAR(500) NULL,
    slip_ocr_data JSON NULL COMMENT 'OCR extracted data from slip',
    
    -- Payment details from OCR
    payment_amount DECIMAL(12,2) NULL COMMENT 'Amount from slip (may differ from recorded)',
    payment_time TIMESTAMP NULL COMMENT 'Transfer time from slip',
    sender_name VARCHAR(255) NULL COMMENT 'Sender name from slip',
    
    -- Status
    status ENUM(
        'pending',      -- Waiting for verification
        'verified',     -- Admin verified
        'rejected',     -- Rejected (fake/invalid)
        'cancelled'     -- Cancelled
    ) NOT NULL DEFAULT 'pending',
    
    -- Verification
    verified_by INT NULL COMMENT 'FK to admin user',
    verified_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    
    -- Notes
    notes TEXT NULL,
    
    -- Case link
    case_id BIGINT UNSIGNED NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_transaction_no (transaction_no),
    INDEX idx_account (savings_account_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_type (transaction_type),
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    
    CONSTRAINT fk_savings_tx_account FOREIGN KEY (savings_account_id)
        REFERENCES savings_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_savings_tx_case FOREIGN KEY (case_id)
        REFERENCES cases(id) ON DELETE SET NULL
        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. ALTER ORDERS TABLE - Add deposit/savings types
-- ============================================

-- Add new payment types
ALTER TABLE orders 
MODIFY COLUMN payment_type ENUM('full', 'installment', 'deposit', 'savings') NOT NULL;

-- Add deposit tracking columns
ALTER TABLE orders
ADD COLUMN IF NOT EXISTS deposit_amount DECIMAL(12,2) NULL DEFAULT NULL 
    COMMENT 'มัดจำ: จำนวนเงินที่จ่ายแล้ว' AFTER installment_months,
ADD COLUMN IF NOT EXISTS deposit_percent DECIMAL(5,2) NULL DEFAULT NULL 
    COMMENT 'มัดจำ: เปอร์เซ็นต์ที่จ่าย' AFTER deposit_amount,
ADD COLUMN IF NOT EXISTS remaining_amount DECIMAL(12,2) NULL DEFAULT NULL 
    COMMENT 'ยอดคงเหลือ' AFTER deposit_percent,
ADD COLUMN IF NOT EXISTS reservation_expires_at TIMESTAMP NULL DEFAULT NULL 
    COMMENT 'สินค้าถูกกันจนถึงวันที่' AFTER remaining_amount,
ADD COLUMN IF NOT EXISTS savings_account_id BIGINT UNSIGNED NULL DEFAULT NULL 
    COMMENT 'FK to savings_accounts' AFTER reservation_expires_at;

-- Add product ref_id column
ALTER TABLE orders
ADD COLUMN IF NOT EXISTS product_ref_id VARCHAR(100) NULL DEFAULT NULL 
    COMMENT 'NPD ref_id' AFTER product_code;

-- Add index for savings_account_id
CREATE INDEX IF NOT EXISTS idx_orders_savings ON orders(savings_account_id);
CREATE INDEX IF NOT EXISTS idx_orders_product_ref ON orders(product_ref_id);
CREATE INDEX IF NOT EXISTS idx_orders_reservation ON orders(reservation_expires_at);

-- ============================================
-- 6. ALTER PAYMENTS TABLE - Support savings deposits
-- ============================================

-- Add new payment types for savings
ALTER TABLE payments 
MODIFY COLUMN payment_type ENUM('full', 'installment', 'deposit', 'savings_deposit') NOT NULL;

-- Add savings transaction link
ALTER TABLE payments
ADD COLUMN IF NOT EXISTS savings_transaction_id BIGINT UNSIGNED NULL DEFAULT NULL 
    COMMENT 'FK to savings_transactions' AFTER current_period;

CREATE INDEX IF NOT EXISTS idx_payments_savings_tx ON payments(savings_transaction_id);

-- ============================================
-- 7. ALTER CHAT_SESSIONS - Add active case tracking
-- ============================================

ALTER TABLE chat_sessions
ADD COLUMN IF NOT EXISTS active_case_id BIGINT UNSIGNED NULL DEFAULT NULL 
    COMMENT 'Current active case for this session' AFTER summary,
ADD COLUMN IF NOT EXISTS active_case_type VARCHAR(50) NULL DEFAULT NULL 
    COMMENT 'Type of active case' AFTER active_case_id;

CREATE INDEX IF NOT EXISTS idx_sessions_active_case ON chat_sessions(active_case_id);

-- ============================================
-- COMPLETION MESSAGE
-- ============================================
SELECT 'Migration 2026_01_06: Cases & Savings tables created successfully!' AS status;

-- Show table status
SELECT 
    'cases' AS table_name, 
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = 'cases') AS created
UNION ALL
SELECT 
    'case_activities' AS table_name,
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = 'case_activities') AS created
UNION ALL
SELECT 
    'savings_accounts' AS table_name,
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = 'savings_accounts') AS created
UNION ALL
SELECT 
    'savings_transactions' AS table_name,
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = 'savings_transactions') AS created;
