-- Add order_number alias column to orders table
-- The schema has order_no but some code uses order_number

-- Option 1: Add order_number as a generated column (virtual alias)
-- This works in MySQL 5.7+
ALTER TABLE orders ADD COLUMN order_number VARCHAR(50) GENERATED ALWAYS AS (order_no) STORED;

-- Add index if needed
ALTER TABLE orders ADD INDEX idx_order_number (order_number);
