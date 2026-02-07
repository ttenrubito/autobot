-- Installment Contracts Table (สัญญาผ่อนชำระ)
CREATE TABLE IF NOT EXISTS installment_contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_no VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    order_id INT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Product info
    product_ref_id VARCHAR(100) NULL,
    product_name VARCHAR(255) NOT NULL,
    product_price DECIMAL(12,2) NOT NULL,
    
    -- Customer info
    customer_name VARCHAR(255) NULL,
    customer_phone VARCHAR(50) NULL,
    customer_avatar VARCHAR(500) NULL,
    platform ENUM('line', 'facebook', 'web', 'instagram') NULL,
    
    -- Installment details
    down_payment DECIMAL(12,2) NOT NULL DEFAULT 0,
    financed_amount DECIMAL(12,2) NOT NULL,
    total_periods INT NOT NULL DEFAULT 3 COMMENT '3 งวด ภายใน 60 วัน',
    paid_periods INT NOT NULL DEFAULT 0,
    amount_per_period DECIMAL(12,2) NOT NULL,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    
    -- Status
    status ENUM('pending', 'active', 'overdue', 'completed', 'cancelled', 'defaulted') NOT NULL DEFAULT 'pending',
    
    -- Dates
    start_date DATE NULL,
    next_due_date DATE NULL,
    last_payment_date DATE NULL,
    completed_at TIMESTAMP NULL,
    
    -- Notes
    admin_notes TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_contract_no (contract_no),
    INDEX idx_customer (customer_id),
    INDEX idx_order (order_id),
    INDEX idx_status (status),
    INDEX idx_next_due (next_due_date),
    CONSTRAINT fk_installment_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Installment Payments Table (งวดผ่อน)
CREATE TABLE IF NOT EXISTS installment_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    period_number INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    due_date DATE NOT NULL,
    paid_date DATE NULL,
    status ENUM('pending', 'paid', 'overdue', 'partial') NOT NULL DEFAULT 'pending',
    payment_id INT NULL,
    slip_image VARCHAR(500) NULL,
    verified_by INT NULL,
    verified_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contract (contract_id),
    INDEX idx_period (period_number),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    CONSTRAINT fk_installment_payment_contract FOREIGN KEY (contract_id) REFERENCES installment_contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
