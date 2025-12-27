-- Webhook Logs Table
-- Stores all webhook events received from payment providers

CREATE TABLE IF NOT EXISTS webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL COMMENT 'Payment provider (e.g., omise, stripe)',
    event_type VARCHAR(100) NOT NULL COMMENT 'Event type (e.g., charge.complete)',
    payload JSON NOT NULL COMMENT 'Full webhook payload',
    processed BOOLEAN DEFAULT FALSE COMMENT 'Whether webhook has been processed',
    error_message TEXT NULL COMMENT 'Error message if processing failed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_provider (provider),
    INDEX idx_event_type (event_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactions Table (if not exists)
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'THB',
    status VARCHAR(50) NOT NULL COMMENT 'pending, successful, failed, refunded',
    omise_charge_id VARCHAR(255) NULL,
    transaction_type VARCHAR(50) NOT NULL COMMENT 'payment, refund, subscription',
    description TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_omise_charge_id (omise_charge_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
