-- Add new columns to orders table (MySQL 5.7 compatible)
ALTER TABLE orders ADD COLUMN product_ref_id VARCHAR(100) NULL;
ALTER TABLE orders ADD COLUMN deposit_amount DECIMAL(12,2) NULL;
ALTER TABLE orders ADD COLUMN deposit_percent DECIMAL(5,2) NULL;
ALTER TABLE orders ADD COLUMN remaining_amount DECIMAL(12,2) NULL;
ALTER TABLE orders ADD COLUMN reservation_expires_at TIMESTAMP NULL;
ALTER TABLE orders ADD COLUMN savings_account_id BIGINT UNSIGNED NULL;
