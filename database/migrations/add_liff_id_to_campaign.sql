-- ============================================================================
-- Add LIFF ID to existing campaigns for LINE Application System
-- Created: 2026-01-03
-- 
-- Purpose: Update campaigns table with LIFF ID for application form
-- 
-- Usage:
--   mysql -u root -p autobot < database/migrations/add_liff_id_to_campaign.sql
-- 
-- Or manually:
--   UPDATE campaigns SET liff_id = 'YOUR_LIFF_ID' WHERE code = 'CAMPAIGN_CODE';
-- ============================================================================

-- Example: Update LIFF ID for test campaign
-- REPLACE 'YOUR_LIFF_ID_HERE' with your actual LIFF ID from LINE Developers Console
-- LIFF ID format: 1234567890-AbCdEfGh

UPDATE campaigns 
SET liff_id = 'YOUR_LIFF_ID_HERE'
WHERE code = 'TEST2026';

-- If you have multiple campaigns using same LIFF app:
-- UPDATE campaigns SET liff_id = 'YOUR_LIFF_ID_HERE' WHERE is_active = 1;

-- Verify update
SELECT 
    id,
    code,
    name,
    liff_id,
    CASE 
        WHEN liff_id IS NULL OR liff_id = '' THEN '❌ Not configured'
        ELSE '✅ Configured'
    END as liff_status,
    is_active
FROM campaigns
ORDER BY created_at DESC;

-- ============================================================================
-- HOW TO GET LIFF ID:
-- ============================================================================
-- 
-- 1. Go to LINE Developers Console: https://developers.line.biz/console/
-- 2. Select your channel
-- 3. Click "LIFF" tab
-- 4. Click "Add" to create new LIFF app (if not exists)
-- 5. Configure:
--    - Size: Full
--    - Endpoint URL: https://your-domain.com/liff/application-form.html
--    - Scope: profile, openid
--    - Bot link feature: On (Aggressive)
-- 6. Copy LIFF ID (format: 1234567890-AbCdEfGh)
-- 7. Run this SQL with your LIFF ID
-- 
-- ============================================================================
