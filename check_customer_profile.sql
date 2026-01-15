-- Check customer_profiles for platform_user_id = 25403438045932467
SELECT 
    id,
    platform,
    platform_user_id,
    display_name,
    full_name,
    avatar_url,
    profile_pic_url,
    created_at
FROM customer_profiles 
WHERE platform_user_id = '25403438045932467'
   OR platform_user_id LIKE '%25403438045932467%';
