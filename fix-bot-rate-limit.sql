-- Fix Bot Profile: Add routing rules for common product brands to bypass Gemini rate limiting
-- This allows direct intent routing without needing LLM for keywords like "Rolex", "นาฬิกา"

UPDATE customer_channels 
SET bot_profile = JSON_SET(
  bot_profile,
  '$.routing_policy.rules',
  JSON_ARRAY(
    -- Existing greeting rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('สวัสดี', 'ดีค่ะ', 'ดีครับ', 'hello', 'hi'),
      'route_to', 'greeting'
    ),
    
    -- **NEW: Direct brand/product routing (bypass LLM)**
    JSON_OBJECT(
      'when_any', JSON_ARRAY(
        'rolex', 'โรเล็กซ์', 'โรเลก', 'นาฬิกา', 'watch', 'watches',
        'omega', 'โอเมก้า', 'audemars', 'ap', 'piguet',
        'แหวน', 'ring', 'เพชร', 'diamond', 'กำไล', 'bracelet',
        'สร้อย', 'necklace', 'ตุ้มหู', 'earring', 'jewelry'
      ),
      'route_to', 'product_availability'
    ),
    
    -- Existing code/SKU lookup rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('sku', 'รหัส', 'โค้ด', 'code', '#', 'serial', 'ซีเรียล'),
      'route_to', 'product_lookup_by_code'
    ),
    
    -- Existing availability rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('มีไหม', 'ยังอยู่ไหม', 'เช็คของ', 'อยู่มั้ย', 'มีของไหม', 'มีรุ่นนี้ไหม'),
      'route_to', 'product_availability'
    ),
    
    -- Existing price rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('ราคา', 'เท่าไหร่', 'ขอราคา', 'ต่อรอง', 'ลดได้ไหม'),
      'route_to', 'price_inquiry'
    ),
    
    -- Existing installment rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('ผ่อน', 'ค่างวด', 'งวด', 'สัญญา', 'ต่อดอก', 'ดอก', 'ปิดยอด', 'ชำระงวด', 'ส่งงวด'),
      'route_to', 'installment_flow'
    ),
    
    -- Existing payment slip rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('โอนแล้ว', 'ส่งสลิป', 'แนบสลิป', 'ชำระแล้ว', 'จ่ายแล้ว', 'โอนเงินแล้ว'),
      'route_to', 'payment_slip_verify'
    ),
    
    -- Existing order status rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('ตามของ', 'พัสดุ', 'เลขพัสดุ', 'สถานะ', 'ส่งของ', 'tracking'),
      'route_to', 'order_status'
    ),
    
    -- Existing authenticity rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('ของแท้ไหม', 'แท้ไหม', 'แท้มั้ย', 'authentic', 'การันตี'),
      'route_to', 'authenticity_inquiry'
    ),
    
    -- Existing pickup rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('นัดรับ', 'รับเอง', 'รับที่ร้าน', 'รับหน้าร้าน', 'เจอได้ไหม'),
      'route_to', 'pickup_inquiry'
    ),
    
    -- Existing shipping rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('ส่งยังไง', 'ค่าส่ง', 'ส่งอะไร', 'ems', 'kerry', 'flash'),
      'route_to', 'shipping_inquiry'
    ),
    
    -- Existing refund rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('คืนเงิน', 'ยกเลิก', 'refund', 'ยกเลิกออเดอร์'),
      'route_to', 'refund_policy'
    ),
    
    -- Existing handoff rule
    JSON_OBJECT(
      'when_any', JSON_ARRAY('ติดต่อแอดมิน', 'คุยกับคน', 'โทร', 'เบอร์', 'แอดมิน'),
      'route_to', 'handoff_request'
    )
  ),
  
  -- Fix backend endpoints (remove .php extensions)
  '$.backend_api.endpoints.product_search', '/api/products/search',
  '$.backend_api.endpoints.product_get', '/api/products/get',
  '$.backend_api.endpoints.order_status', '/api/orders/status',
  '$.backend_api.endpoints.image_search', '/api/searchImage',
  '$.backend_api.endpoints.receipt_get', '/api/getReceipt',
  '$.backend_api.endpoints.installment_payment_upsert', '/api/installment/payment'
)
WHERE channel_id = 4;  -- NAV Store Facebook channel

-- Verify changes
SELECT 
  channel_id,
  JSON PRETTY(JSON_EXTRACT(bot_profile, '$.routing_policy.rules[1]')) AS new_product_rule,
  JSON_EXTRACT(bot_profile, '$.backend_api.endpoints.product_search') AS product_search_endpoint
FROM customer_channels 
WHERE channel_id = 4;
