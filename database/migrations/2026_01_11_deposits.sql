-- Deposits Table (รับฝากสินค้า)
CREATE TABLE IF NOT EXISTS deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deposit_no VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    tenant_id VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Customer info (cached)
    customer_name VARCHAR(255) NULL,
    customer_phone VARCHAR(50) NULL,
    customer_avatar VARCHAR(500) NULL,
    platform ENUM('line', 'facebook', 'web', 'instagram') NULL,
    
    -- Item info
    item_type VARCHAR(100) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_description TEXT NULL,
    item_condition TEXT NULL,
    item_images JSON NULL,
    
    -- Pricing
    storage_fee_per_day DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_storage_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    
    -- Dates
    deposit_date DATE NOT NULL,
    expected_pickup_date DATE NULL,
    actual_pickup_date DATE NULL,
    
    -- Status
    status ENUM('deposited', 'ready', 'picked_up', 'expired', 'disposed', 'cancelled') NOT NULL DEFAULT 'deposited',
    
    -- Location
    storage_location VARCHAR(100) NULL,
    
    -- Notes
    notes TEXT NULL,
    admin_notes TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_deposit_no (deposit_no),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_deposit_date (deposit_date),
    INDEX idx_item_type (item_type),
    CONSTRAINT fk_deposit_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
