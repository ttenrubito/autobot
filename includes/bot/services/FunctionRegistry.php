<?php
/**
 * FunctionRegistry - Define function declarations for LLM Function Calling
 * 
 * This provides function schemas that the LLM uses to decide
 * which actions to take based on user input.
 * 
 * @version 1.0
 * @date 2026-02-07
 */

namespace Autobot\Bot\Services;

class FunctionRegistry
{
    /**
     * Get all available function declarations for Gemini
     * 
     * @return array Function declarations in Gemini format
     */
    public static function getDeclarations(): array
    {
        return [
            // ==================== PRODUCT FUNCTIONS ====================
            [
                'name' => 'search_products',
                'description' => 'ค้นหาสินค้าจากคำค้นหา - ใช้ทุกครั้งที่ลูกค้าถามเกี่ยวกับสินค้า เช่น "มี...ไหม", "หา...", "อยากได้...", "ดู...", "ขอดูสินค้า"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword' => [
                            'type' => 'string',
                            'description' => 'คำค้นหาที่ clean แล้ว (ไม่มีคำถาม ไม่มี "ไหม", "มั้ย") เช่น "นาฬิกา สีดำ", "Rolex", "กำไล ข้อมือ", "พระเครื่อง"'
                        ],
                        'category' => [
                            'type' => 'string',
                            'description' => 'หมวดหมู่: watch, jewelry, bag, amulet, accessory (optional)'
                        ],
                        'price_max' => [
                            'type' => 'integer',
                            'description' => 'ราคาสูงสุด เช่น "ไม่เกิน 5 แสน" = 500000, "งบหมื่น" = 10000 (optional)'
                        ],
                        'price_min' => [
                            'type' => 'integer',
                            'description' => 'ราคาต่ำสุด เช่น "ราคาเกินแสน" = 100000 (optional)'
                        ]
                    ],
                    'required' => ['keyword']
                ]
            ],
            
            [
                'name' => 'get_product_by_code',
                'description' => 'ดึงข้อมูลสินค้าจากรหัสสินค้า เช่น P-2026-000001, SKU-123, ROL-SUB-002',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_code' => [
                            'type' => 'string',
                            'description' => 'รหัสสินค้า'
                        ]
                    ],
                    'required' => ['product_code']
                ]
            ],

            [
                'name' => 'check_product_stock',
                'description' => 'เช็คว่าสินค้ายังมีอยู่ไหม มีกี่ชิ้น ใช้เมื่อลูกค้าถามว่า "ยังมีไหม", "เหลืออยู่ไหม", "อันนั้นมีไหม"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'ID ของสินค้า (จาก context หรือ history)'
                        ],
                        'product_code' => [
                            'type' => 'string',
                            'description' => 'รหัสสินค้า (alternative ถ้าไม่มี product_id)'
                        ]
                    ]
                ]
            ],

            // ==================== ORDER FUNCTIONS ====================
            [
                'name' => 'get_order_status',
                'description' => 'เช็คสถานะคำสั่งซื้อ ใช้เมื่อลูกค้าถาม "สถานะออเดอร์", "ของถึงไหนแล้ว", "ส่งของหรือยัง"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'order_no' => [
                            'type' => 'string',
                            'description' => 'เลขที่คำสั่งซื้อ เช่น ORD-2026-001 (optional - ถ้าไม่มีจะดึงล่าสุด)'
                        ]
                    ]
                ]
            ],

            [
                'name' => 'create_order',
                'description' => 'สร้างคำสั่งซื้อเมื่อลูกค้ายืนยันต้องการสั่ง ใช้เมื่อลูกค้าพูดว่า "สั่งเลย", "เอาอันนี้", "ซื้อ"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'ID ของสินค้าที่จะสั่ง'
                        ],
                        'quantity' => [
                            'type' => 'integer',
                            'description' => 'จำนวนที่สั่ง (default: 1)'
                        ]
                    ],
                    'required' => ['product_id']
                ]
            ],

            // ==================== TRANSACTION FUNCTIONS ====================
            [
                'name' => 'check_installment',
                'description' => 'เช็คยอดผ่อนชำระ ใช้เมื่อลูกค้าถาม "ยอดผ่อน", "ผ่อนเดือนนี้เท่าไหร่", "ค้างผ่อน"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass() // Empty object for no params
                ]
            ],

            [
                'name' => 'check_pawn',
                'description' => 'เช็คยอดจำนำ/รับฝาก ใช้เมื่อลูกค้าถาม "ยอดจำนำ", "ค่าดอกเบี้ย", "วันครบกำหนดไถ่ถอน"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass() // Empty object for no params
                ]
            ],

            [
                'name' => 'create_pawn_inquiry',
                'description' => 'สร้างเคสสอบถามจำนำ ใช้เมื่อลูกค้าพูดว่า "อยากจำนำ", "รับซื้อไหม", "ฝากขาย", "รับฝาก"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'item_description' => [
                            'type' => 'string',
                            'description' => 'รายละเอียดสินค้าที่ต้องการจำนำ'
                        ]
                    ]
                ]
            ],

            // ==================== PAYMENT FUNCTIONS ====================
            [
                'name' => 'get_payment_options',
                'description' => 'แสดงช่องทางการชำระเงิน ใช้เมื่อลูกค้าถาม "โอนยังไง", "เลขบัญชี", "จ่ายยังไง", "QR code"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass() // Empty object for no params
                ]
            ],

            [
                'name' => 'calculate_installment',
                'description' => 'คำนวณยอดผ่อนชำระ (3 งวด +3% งวดแรก ผ่อนครบรับของ) ใช้เมื่อลูกค้าถาม "ผ่อนได้ไหม", "ผ่อนเท่าไหร่", "คำนวณผ่อน"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'price' => [
                            'type' => 'number',
                            'description' => 'ราคาสินค้า'
                        ]
                    ],
                    'required' => ['price']
                ]
            ],

            // ==================== SUPPORT FUNCTIONS ====================
            [
                'name' => 'request_admin_handoff',
                'description' => 'ส่งต่อให้แอดมิน ใช้เมื่อลูกค้าต้องการคุยกับคน พูดว่า "แอดมิน", "คุยกับคน", "ต้องการความช่วยเหลือ"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => [
                            'type' => 'string',
                            'description' => 'เหตุผลที่ต้องการคุยกับแอดมิน'
                        ]
                    ]
                ]
            ],

            [
                'name' => 'get_store_info',
                'description' => 'ข้อมูลร้าน ใช้เมื่อลูกค้าถาม "ร้านอยู่ไหน", "เปิดกี่โมง", "เบอร์โทร", "Line ID"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'info_type' => [
                            'type' => 'string',
                            'description' => 'ประเภทข้อมูลที่ต้องการ: location, hours, contact, all'
                        ]
                    ]
                ]
            ],

            [
                'name' => 'request_video_call',
                'description' => 'นัดหมาย Video Call ดูสินค้า ใช้เมื่อลูกค้าพูดว่า "ขอวีดีโอคอล", "ขอดูของจริง", "โชว์สินค้าได้ไหม"',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'ID สินค้าที่ต้องการดู (optional)'
                        ]
                    ]
                ]
            ],

            // ==================== CHITCHAT / GENERAL ====================
            [
                'name' => 'general_response',
                'description' => 'ตอบคำถามทั่วไปที่ไม่ต้องเรียก API เช่น ทักทาย, ขอบคุณ, chitchat, คำถามที่ตอบได้เลย',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'response_text' => [
                            'type' => 'string',
                            'description' => 'ข้อความตอบกลับภาษาไทย สั้น กระชับ ลงท้ายด้วย ค่ะ/ครับ'
                        ],
                        'response_type' => [
                            'type' => 'string',
                            'description' => 'ประเภท: greeting, thanks, chitchat, clarification, other'
                        ]
                    ],
                    'required' => ['response_text']
                ]
            ]
        ];
    }

    /**
     * Get function names only (for validation)
     */
    public static function getFunctionNames(): array
    {
        return array_map(fn($f) => $f['name'], self::getDeclarations());
    }

    /**
     * Get a specific function declaration by name
     */
    public static function getDeclaration(string $name): ?array
    {
        foreach (self::getDeclarations() as $func) {
            if ($func['name'] === $name) {
                return $func;
            }
        }
        return null;
    }
}
