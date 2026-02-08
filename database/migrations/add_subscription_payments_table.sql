-- Migration: Add subscription_payments table
-- Date: 2025-01-28
-- Purpose: Store subscription payment/renewal records with slip images

-- Check if table exists before creating
CREATE TABLE IF NOT EXISTS subscription_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL COMMENT 'Amount paid in THB',
    slip_url VARCHAR(500) NULL COMMENT 'Public URL of the slip image',
    gcs_path VARCHAR(500) NULL COMMENT 'GCS storage path for the slip',
    status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending' COMMENT 'Payment verification status',
    days_added INT DEFAULT 0 COMMENT 'Number of days added to subscription',
    verified_by INT NULL COMMENT 'Admin user who verified the payment',
    verified_at TIMESTAMP NULL COMMENT 'When the payment was verified',
    rejection_reason VARCHAR(500) NULL COMMENT 'Reason for rejection if status=rejected',
    notes TEXT NULL COMMENT 'Additional notes',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    
    CONSTRAINT fk_subscription_payments_user 
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_payments_verifier 
        FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add comment to table
ALTER TABLE subscription_payments COMMENT = 'Stores subscription renewal payments with slip images for verification';
