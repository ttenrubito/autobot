-- Fix for knowledge base matching issues
-- Replace short keywords with longer, more specific ones

-- Update ID 6 (old location entry)
UPDATE customer_knowledge_base 
SET keywords = '["ที่อยู่ร้าน", "สาขาอยู่ไหน", "ติดต่อร้าน", "เบอร์โทรศัพท์", "Line ID"]'
WHERE id = 6;

-- Update ID 19 (new location entry)  
UPDATE customer_knowledge_base
SET keywords = '["ที่อยู่ร้าน", "สาขาอยู่ไหน", "ติดต่อร้าน", "เบอร์โทรศัพท์", "Line ID", "Facebook"]'
WHERE id = 19;

-- Update ID 33 (duplicate entry for user 1)
UPDATE customer_knowledge_base
SET keywords = '["ที่อยู่ร้าน", "สาขาอยู่ไหน", "ติดต่อร้าน", "เบอร์โทรศัพท์", "Line ID", "Facebook"]'
WHERE id = 33;
