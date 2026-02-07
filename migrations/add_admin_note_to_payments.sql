-- Migration: Add admin_note column to payments table
-- Date: 2026-01-19
-- Description: Required for classify action to store admin notes

ALTER TABLE payments 
ADD COLUMN admin_note TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Admin notes for payment classification';

-- Verify
SELECT 'admin_note added' as status;
