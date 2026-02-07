-- Add pawn_redemption to payment_type ENUM
-- Run this on production database

ALTER TABLE payments MODIFY COLUMN payment_type 
    ENUM('full','installment','deposit','savings','deposit_interest','deposit_savings','pawn_redemption') 
    NOT NULL DEFAULT 'full';
