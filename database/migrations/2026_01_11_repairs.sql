-- Repairs Table (รับซ่อม)
CREATE TABLE IF NOT EXISTS repairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    repair_no VARCHAR(50) UNIQUE NOT NULL,
    job_no VARCHAR(50) UNIQUE NULL,
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
    item_brand VARCHAR(100) NULL,
    item_model VARCHAR(100) NULL,
    item_serial VARCHAR(100) NULL,
    item_description TEXT NULL,
    item_condition TEXT NULL,
    problem_description TEXT NOT NULL,
    item_images JSON NULL,
    
    -- Diagnosis
    diagnosis TEXT NULL,
    diagnosis_date DATE NULL,
    diagnosed_by INT NULL,
    
    -- Parts & Labor
    parts_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    labor_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    estimated_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    final_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    
    -- Dates
    received_date DATE NOT NULL,
    estimated_completion_date DATE NULL,
    actual_completion_date DATE NULL,
    picked_up_date DATE NULL,
    warranty_until DATE NULL,
    
    -- Status
    status ENUM('received', 'diagnosing', 'quoted', 'approved', 'in_progress', 'completed', 'ready', 'picked_up', 'cancelled', 'unclaimed') NOT NULL DEFAULT 'received',
    
    -- Assignment
    technician_id INT NULL,
    technician_name VARCHAR(255) NULL,
    
    -- Priority
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    
    -- Notes
    notes TEXT NULL,
    internal_notes TEXT NULL,
    customer_notes TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_repair_no (repair_no),
    INDEX idx_job_no (job_no),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_received_date (received_date),
    INDEX idx_technician (technician_id),
    INDEX idx_priority (priority),
    CONSTRAINT fk_repair_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repair Parts Used
CREATE TABLE IF NOT EXISTS repair_parts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    repair_id INT NOT NULL,
    part_name VARCHAR(255) NOT NULL,
    part_code VARCHAR(50) NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_cost DECIMAL(10,2) NOT NULL,
    total_cost DECIMAL(12,2) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_repair (repair_id),
    CONSTRAINT fk_repair_part FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repair Status Log
CREATE TABLE IF NOT EXISTS repair_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    repair_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_repair (repair_id),
    CONSTRAINT fk_repair_log FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
