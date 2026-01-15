-- Verify platform_user_id columns exist and have data
SELECT 
    'payments' as tbl, 
    COUNT(*) as total, 
    SUM(IF(platform_user_id IS NOT NULL, 1, 0)) as has_platform_user_id
FROM payments
UNION ALL
SELECT 'orders', COUNT(*), SUM(IF(platform_user_id IS NOT NULL, 1, 0)) FROM orders
UNION ALL
SELECT 'repairs', COUNT(*), SUM(IF(platform_user_id IS NOT NULL, 1, 0)) FROM repairs
UNION ALL  
SELECT 'pawns', COUNT(*), SUM(IF(platform_user_id IS NOT NULL, 1, 0)) FROM pawns;
