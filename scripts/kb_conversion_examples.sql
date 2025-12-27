-- ============================================================
-- Manual KB Conversion Examples
-- Use these SQL queries to manually convert specific entries
-- ============================================================

-- --------------------------------------------------------
-- Step 1: Find legacy entries that need conversion
-- --------------------------------------------------------

-- Find all entries containing "one piece"
SELECT 
    id, 
    user_id, 
    category, 
    priority, 
    keywords, 
    question,
    SUBSTRING(answer, 1, 100) as answer_preview
FROM customer_knowledge_base
WHERE is_active = 1 
  AND is_deleted = 0
  AND (
      keywords LIKE '%one piece%'
      OR question LIKE '%one piece%'
      OR answer LIKE '%one piece%'
  )
ORDER BY user_id, priority DESC;

-- Find all legacy format entries (check if keywords doesn't contain "mode":"advanced")
SELECT 
    id, 
    user_id, 
    category, 
    keywords,
    question
FROM customer_knowledge_base
WHERE is_active = 1 
  AND is_deleted = 0
  AND keywords NOT LIKE '%"mode"%'
  AND keywords NOT LIKE '%mode%'
ORDER BY user_id, priority DESC;

-- --------------------------------------------------------
-- Step 2: Convert specific entries
-- --------------------------------------------------------

-- Example 1: Convert shop address entry for "One Piece" shop
-- Replace ID 123 with your actual entry ID
-- This requires "ร้าน" and at least one variation of "one piece"
UPDATE customer_knowledge_base
SET keywords = '{
  "mode": "advanced",
  "require_all": ["ร้าน"],
  "require_any": ["one piece", "วันพีซ", "onepiece"],
  "exclude_any": ["ที่อยู่ของฉัน", "ที่อยู่ผม", "ที่อยู่ฉัน", "บ้านฉัน", "บ้านผม", "ของฉัน", "ของผม"],
  "min_query_len": 6
}'
WHERE id = 123;

-- Example 2: Convert product info entry
-- This requires at least one product name variation
UPDATE customer_knowledge_base
SET keywords = '{
  "mode": "advanced",
  "require_all": [],
  "require_any": ["กระเป๋า", "bag", "กระเป๋าสตางค์"],
  "exclude_any": [],
  "min_query_len": 4
}'
WHERE id = 124;

-- Example 3: Convert contact info entry
-- Requires contact-related keywords
UPDATE customer_knowledge_base
SET keywords = '{
  "mode": "advanced",
  "require_all": [],
  "require_any": ["ติดต่อ", "โทร", "เบอร์", "ไลน์", "contact", "phone"],
  "exclude_any": [],
  "min_query_len": 3
}'
WHERE id = 125;

-- Example 4: Convert opening hours entry
-- Requires time/hours-related keywords
UPDATE customer_knowledge_base
SET keywords = '{
  "mode": "advanced",
  "require_all": ["เปิด", "ร้าน"],
  "require_any": ["เวลา", "กี่โมง", "เปิดกี่โมง", "ปิดกี่โมง"],
  "exclude_any": [],
  "min_query_len": 5
}'
WHERE id = 126;

-- --------------------------------------------------------
-- Step 3: Verify the conversion
-- --------------------------------------------------------

-- Check the converted entry
SELECT id, category, keywords, question
FROM customer_knowledge_base
WHERE id = 123;

-- Check all advanced format entries for a user
SELECT 
    id, 
    category, 
    JSON_EXTRACT(keywords, '$.mode') as mode,
    JSON_EXTRACT(keywords, '$.require_all') as require_all,
    JSON_EXTRACT(keywords, '$.require_any') as require_any,
    question
FROM customer_knowledge_base
WHERE user_id = YOUR_USER_ID
  AND is_active = 1
  AND is_deleted = 0
  AND keywords LIKE '%"mode":"advanced"%'
ORDER BY priority DESC;

-- --------------------------------------------------------
-- Step 4: Batch conversion template
-- --------------------------------------------------------

-- If you have multiple entries for the same shop with different info
-- (address, hours, menu, etc.), you can convert them in batch:

-- First, identify all entries for a specific shop
SELECT id, category, question, keywords
FROM customer_knowledge_base
WHERE user_id = YOUR_USER_ID
  AND (question LIKE '%one piece%' OR keywords LIKE '%one piece%')
  AND is_active = 1
  AND is_deleted = 0;

-- Then convert them based on category:

-- Shop address entries (category = 'ที่อยู่' or similar)
UPDATE customer_knowledge_base
SET keywords = '{
  "mode": "advanced",
  "require_all": ["ร้าน"],
  "require_any": ["one piece", "วันพีซ", "onepiece"],
  "exclude_any": ["ที่อยู่ของฉัน", "ที่อยู่ผม", "ที่อยู่ฉัน", "บ้านฉัน", "บ้านผม"],
  "min_query_len": 6
}'
WHERE user_id = YOUR_USER_ID
  AND category = 'ที่อยู่ร้าน'
  AND keywords LIKE '%one piece%'
  AND keywords NOT LIKE '%"mode"%';

-- Opening hours entries
UPDATE customer_knowledge_base
SET keywords = '{
  "mode": "advanced",
  "require_all": ["one piece"],
  "require_any": ["เปิด", "ปิด", "เวลา", "กี่โมง", "hours"],
  "exclude_any": [],
  "min_query_len": 5
}'
WHERE user_id = YOUR_USER_ID
  AND category = 'เวลาทำการ'
  AND keywords LIKE '%one piece%'
  AND keywords NOT LIKE '%"mode"%';

-- --------------------------------------------------------
-- Rollback (if needed)
-- --------------------------------------------------------

-- If you need to revert a conversion, you can restore the legacy format:
-- (Save the old keywords value before converting!)
UPDATE customer_knowledge_base
SET keywords = '["one piece", "วันพีซ"]'
WHERE id = 123;
