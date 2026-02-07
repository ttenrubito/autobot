-- Migration: Add V5 columns to products table
-- Date: 2026-01-28
-- Purpose: Add shop_owner_id for data isolation and fields for vector search

-- Add shop_owner_id column for shop data isolation (following orders/payments pattern)
ALTER TABLE products 
ADD COLUMN shop_owner_id INT UNSIGNED DEFAULT NULL 
COMMENT 'FK to users.id - shop owner' 
AFTER id;

-- Add brand column for product categorization and search
ALTER TABLE products 
ADD COLUMN brand VARCHAR(100) DEFAULT NULL 
COMMENT 'Product brand for filtering'
AFTER product_name;

-- Add tags column for flexible tagging/search
ALTER TABLE products 
ADD COLUMN tags JSON DEFAULT NULL 
COMMENT 'Array of searchable tags'
AFTER metadata;

-- Add vector sync tracking column
ALTER TABLE products 
ADD COLUMN vector_synced_at DATETIME DEFAULT NULL 
COMMENT 'Last synced to vector search DB'
AFTER tags;

-- Add indexes for performance
CREATE INDEX idx_products_shop_owner ON products (shop_owner_id);
CREATE INDEX idx_products_brand ON products (brand);

-- Add foreign key constraint (optional - uncomment if needed)
-- ALTER TABLE products 
-- ADD CONSTRAINT fk_products_shop_owner 
-- FOREIGN KEY (shop_owner_id) REFERENCES users(id) ON DELETE SET NULL;
