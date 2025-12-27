-- Fix inconsistent slip_image paths in payments table
-- Remove /autobot prefix and ensure consistent /uploads/slips/ format

UPDATE payments
SET slip_image = REPLACE(slip_image, '/autobot/public/uploads/', '/uploads/')
WHERE slip_image LIKE '/autobot/public/uploads/%';

UPDATE payments
SET slip_image = REPLACE(slip_image, '/public/uploads/', '/uploads/')
WHERE slip_image LIKE '/public/uploads/%';

-- Verify the fix
SELECT 
    id,
    payment_no,
    slip_image,
    CASE
        WHEN slip_image LIKE '/autobot%' THEN '❌ Still has /autobot'
        WHEN slip_image LIKE '/public%' THEN '❌ Still has /public'
        WHEN slip_image LIKE '/uploads/%' THEN '✅ Correct format'
        ELSE '⚠️ Unknown format'
    END AS status
FROM payments
WHERE slip_image IS NOT NULL
ORDER BY created_at DESC;
