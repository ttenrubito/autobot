-- Migration: Fix user_id in cases table
-- Date: 2026-01-25
-- Problem: user_id was incorrectly set from customer_profiles.id instead of channel owner

-- Step 1: Show current state (for verification)
SELECT 
    c.id, 
    c.case_no, 
    c.user_id as current_user_id, 
    c.channel_id,
    cc.user_id as correct_user_id,
    cc.channel_name
FROM cases c
LEFT JOIN customer_channels cc ON c.channel_id = cc.id
WHERE c.user_id IS NULL OR c.user_id != COALESCE(cc.user_id, c.user_id)
LIMIT 20;

-- Step 2: Update cases with correct user_id from channel
UPDATE cases c
JOIN customer_channels cc ON c.channel_id = cc.id
SET c.user_id = cc.user_id
WHERE c.user_id IS NULL OR c.user_id != cc.user_id;

-- Step 3: Verify fix
SELECT 
    c.id, 
    c.case_no, 
    c.user_id as fixed_user_id, 
    c.channel_id,
    cc.user_id as channel_user_id,
    CASE WHEN c.user_id = cc.user_id THEN 'OK' ELSE 'MISMATCH' END as status
FROM cases c
LEFT JOIN customer_channels cc ON c.channel_id = cc.id
ORDER BY c.id DESC
LIMIT 20;

SELECT 'Migration completed: cases.user_id fixed based on channel owner' as status;
