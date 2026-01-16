-- Migration: Add Shipping and Installment columns to orders table
-- Purpose: Support shipping options and installment/deposit tracking
-- Date: 2026-01-16

-- =========================================================================
-- Shipping Columns
-- =========================================================================

-- Shipping method: pickup, post, grab
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_method VARCHAR(50) DEFAULT 'pickup';

-- Shipping address for delivery orders
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_address TEXT NULL;

-- Shipping fee (can be 0 for free shipping)
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_fee DECIMAL(12,2) DEFAULT 0;

-- Tracking number for shipped orders
ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(100) NULL;

-- =========================================================================
-- Installment/Deposit Columns
-- =========================================================================

-- Installment months (default 3)
ALTER TABLE orders ADD COLUMN IF NOT EXISTS installment_months INT DEFAULT 3;

-- Down payment for installment orders
ALTER TABLE orders ADD COLUMN IF NOT EXISTS down_payment DECIMAL(12,2) DEFAULT 0;

-- Paid amount (for tracking partial payments)
ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(12,2) DEFAULT 0;

-- Payment status: unpaid, partial, paid
ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid', 'partial', 'paid') DEFAULT 'unpaid';

-- Link to installment record
ALTER TABLE orders ADD COLUMN IF NOT EXISTS installment_id INT UNSIGNED NULL;

-- =========================================================================
-- Installments Table (for tracking payment schedules)
-- =========================================================================
CREATE TABLE IF NOT EXISTS installments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installment_no VARCHAR(50) NOT NULL UNIQUE,
    order_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    tenant_id VARCHAR(100) DEFAULT 'default',
    product_name VARCHAR(255) NOT NULL,
    product_code VARCHAR(100) NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    down_payment DECIMAL(12,2) DEFAULT 0,
    remaining_amount DECIMAL(12,2) NOT NULL,
    total_terms INT DEFAULT 3,
    interest_rate DECIMAL(5,2) DEFAULT 3.00, -- 3% per month
    monthly_payment DECIMAL(12,2) NOT NULL,
    total_interest DECIMAL(12,2) DEFAULT 0,
    grand_total DECIMAL(12,2) NOT NULL,
    status ENUM('active', 'completed', 'defaulted', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Installment Payments Table (individual payment records)
-- =========================================================================
CREATE TABLE IF NOT EXISTS installment_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installment_id INT UNSIGNED NOT NULL,
    term_number INT NOT NULL, -- 1, 2, 3
    amount DECIMAL(12,2) NOT NULL,
    due_date DATE NOT NULL,
    paid_date DATE NULL,
    status ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
    payment_method VARCHAR(50) NULL, -- transfer, cash, etc.
    payment_ref VARCHAR(100) NULL, -- receipt or transfer ref
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_installment (installment_id),
    INDEX idx_due_date (due_date),
    INDEX idx_status (status),
    FOREIGN KEY (installment_id) REFERENCES installments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Add Index for Shipping Status
-- =========================================================================
CREATE INDEX IF NOT EXISTS idx_shipping_method ON orders(shipping_method);

SELECT 'Migration completed: Shipping and Installment columns added' AS result;
