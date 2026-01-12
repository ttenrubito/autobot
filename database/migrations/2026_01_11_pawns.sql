-- Pawns Table (รับจำนำ)
CREATE TABLE IF NOT EXISTS pawns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pawn_no VARCHAR(50) UNIQUE NOT NULL,
    ticket_no VARCHAR(50) UNIQUE NULL,
    customer_id INT NOT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Customer info (cached)
    customer_name VARCHAR(255) NULL,
    customer_phone VARCHAR(50) NULL,
    customer_id_card VARCHAR(20) NULL,
    customer_address TEXT NULL,
    customer_avatar VARCHAR(500) NULL,
    platform ENUM('line', 'facebook', 'web', 'instagram') NULL,
    
    -- Item info
    item_type VARCHAR(100) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_description TEXT NULL,
    item_condition TEXT NULL,
    item_weight DECIMAL(10,4) NULL,
    item_purity VARCHAR(20) NULL,
    item_serial VARCHAR(100) NULL,
    item_images JSON NULL,
    
    -- Valuation
    appraised_value DECIMAL(12,2) NOT NULL,
    loan_amount DECIMAL(12,2) NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL DEFAULT 3,
    interest_type ENUM('monthly', 'yearly', 'daily') NOT NULL DEFAULT 'monthly',
    
    -- Calculated
    accrued_interest DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    
    -- Dates
    pawn_date DATE NOT NULL,
    due_date DATE NOT NULL,
    extension_date DATE NULL,
    redeemed_date DATE NULL,
    forfeited_date DATE NULL,
    
    -- Status
    status ENUM('active', 'redeemed', 'forfeited', 'extended', 'expired', 'sold', 'cancelled') NOT NULL DEFAULT 'active',
    
    -- Storage
    storage_location VARCHAR(100) NULL,
    
    -- Notes
    notes TEXT NULL,
    admin_notes TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_pawn_no (pawn_no),
    INDEX idx_ticket_no (ticket_no),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_pawn_date (pawn_date),
    INDEX idx_due_date (due_date),
    INDEX idx_item_type (item_type),
    CONSTRAINT fk_pawn_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pawn Interest Payments
CREATE TABLE IF NOT EXISTS pawn_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pawn_id INT NOT NULL,
    payment_type ENUM('interest', 'extension', 'redemption', 'partial') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    interest_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    principal_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50) NULL,
    slip_image VARCHAR(500) NULL,
    verified_by INT NULL,
    verified_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pawn (pawn_id),
    INDEX idx_payment_date (payment_date),
    INDEX idx_payment_type (payment_type),
    CONSTRAINT fk_pawn_payment_pawn FOREIGN KEY (pawn_id) REFERENCES pawns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
