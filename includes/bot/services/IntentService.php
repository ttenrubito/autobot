<?php
/**
 * IntentService - Advanced Intent Detection and Routing (Professional Edition)
 *
 * Capabilities:
 * - Hybrid Detection: Uses Fast Regex first, falls back to Smart LLM
 * - Context Awareness: Considers current checkout state and conversation history
 * - Priority Routing: Handles critical intents (e.g., checkout) with higher priority
 * - Normalized Inputs: Handles Thai tones, slang, and mixed language inputs
 *
 * @version 2.1 (Full Implementation)
 * @author AI Specialist
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Logger.php';
require_once __DIR__ . '/BackendApiService.php';

class IntentService
{
    protected $backendApi;

    // Priority Map: Lower number = Higher priority
    const INTENT_PRIORITY = [
        // 1. Critical Transaction Flow
        'checkout_confirm'    => 1,
        'checkout_cancel'     => 2,
        'payment_slip_verify' => 3, // แก้ชื่อให้ตรงกับ Router
        'payment_options'     => 4,
        'shipping_address'    => 5,
        'change_payment_method' => 6, // ✅ NEW: เปลี่ยนวิธีชำระหลัง confirm

        // 2. Admin & Support
        'admin_handoff'       => 10,

        // 3. Specific Business Logic
        'installment_flow'    => 20, // แก้ชื่อให้ตรงกับ Router
        'pawn_new'            => 21,
        'pawn_check'          => 22,
        'pawn_inquiry'        => 23, // ✅ รับฝาก/ฝากขาย (บริการเดียวกัน)
        'repair_new'          => 25,
        'repair_check'        => 26,
        'savings_new'         => 27,
        'savings_check'       => 28,
        'order_status'        => 29, // แก้ชื่อให้ตรงกับ Router

        // 4. Product Interaction
        'purchase_intent'     => 30, // "สนใจชิ้นนี้" (แก้ชื่อให้ตรง Router)
        'product_lookup_by_code' => 31, // "ขอรหัส ABC"
        'product_availability' => 32, // "มีนาฬิกาไหม"
        'price_inquiry'       => 34,

        // 5. General Conversation
        'follow_up_info'      => 40,
        'greeting'            => 50,
        'thanks'              => 51,
        
        // 6. Fallback
        'unknown'             => 100
    ];

    public function __construct()
    {
        // ใช้ require_once เพื่อความชัวร์ หรือ Dependency Injection ก็ได้
        if (class_exists('BackendApiService')) {
            $this->backendApi = new \BackendApiService();
        } else {
            // Fallback for standalone testing
            $this->backendApi = null; 
        }
    }

    /**
     * Main Entry Point: Detect intent from user message
     *
     * @param string $message User's raw message
     * @param array $config Bot configuration
     * @param array $context Chat context (session, history, slots)
     * @param array $lastSlots Previous slots values (optional)
     * @return array Standardized intent result
     */
    public function detect(string $message, array $config, array $context, array $lastSlots = []): array
    {
        $text = $this->normalizeText($message);

        if (empty($text)) {
            return $this->makeResult('unknown', 0, [], 'empty');
        }

        // 1. Fast Regex Detection (Level 1)
        // ส่ง lastSlots เข้าไปเพื่อเช็ค Context (เช่น checkout_step)
        $regexResult = $this->detectByRegex($text, $message, $context, $lastSlots);
        
        // If Regex is very confident (>= 0.9), return immediately to save LLM cost & time
        if ($regexResult['confidence'] >= 0.9) {
            if (class_exists('Logger')) \Logger::info("[IntentService] Regex Hit: {$regexResult['intent']}");
            return $regexResult;
        }

        // 2. Smart LLM Detection (Level 2)
        if ($this->isLlmEnabled($config)) {
            if (class_exists('Logger')) \Logger::info("[IntentService] Fallback to LLM for: $text");
            $llmResult = $this->detectByLLM($message, $config, $context);

            // LLM usually overrides Regex unless Regex was reasonably sure but not perfect
            // We use a threshold here. If LLM is confident, use it.
            if (($llmResult['confidence'] ?? 0) > ($regexResult['confidence'] ?? 0)) {
                return $llmResult;
            }
        }

        // 3. Final Fallback
        return $regexResult;
    }

    /**
     * Regex-based Detection Logic (The Core Intelligence)
     */
    protected function detectByRegex(string $text, string $rawMessage, array $context, array $lastSlots): array
    {
        // --- 1. CONTEXT-AWARE CHECKS (Highest Priority) ---
        
        // Checkout Flow Context (กำลังอยู่ในขั้นตอนการจ่ายเงิน/ขนส่ง)
        if ($this->isInCheckoutFlow($lastSlots)) {
            // Confirm: ตกลง, เอา, โอนเลย, ยืนยัน, OK
            if (preg_match('/^(ยืนยัน|ตกลง|ok|yes|ใช่|เอา(เลย|ครับ|ค่ะ)?|สั่ง(ซื้อ)?|confirm|โอน(เลย|เงิน)?|จ่าย(เงิน|เลย)?)/u', $text)) {
                return $this->makeResult('checkout_confirm', 1.0, [], 'regex_context');
            }
            // Cancel: ไม่เอา, ยกเลิก, เปลี่ยนใจ
            if (preg_match('/^(ยกเลิก|ไม่(เอา|ซื้อ)|no|cancel|เปลี่ยนใจ|พอแค่นี้)/u', $text)) {
                return $this->makeResult('checkout_cancel', 1.0, [], 'regex_context');
            }
            // Payment method inquiry inside checkout (มีแบบไหนบ้าง, ผ่อนหรอ)
            if (preg_match('/(ผ่อน|บัตร|โอน|มัดจำ|แบบไหน|ทางไหน).*(ได้|ไหม|มั้ย|บ้าง|หรอ|เหรอ)/u', $text)) {
                // ให้เป็น payment_options เพื่อให้ CheckoutService ตอบคำถามเรื่องวิธีชำระ
                return $this->makeResult('payment_options', 0.95, [], 'regex_context');
            }
        }

        // Address Context (If bot just asked for address)
        if (($lastSlots['checkout_step'] ?? '') === 'ask_address') {
            // Primitive address check: numbers + province/district keywords
            // ถ้าข้อความยาวพอสมควร และมีคำบ่งชี้ที่อยู่
            if (preg_match('/(\d+.*(เขต|อำเภอ|แขวง|ตำบล|จ\.|จังหวัด|กทม|road|ถนน|ซอย))/u', $text) && mb_strlen($text) > 20) {
                return $this->makeResult('shipping_address', 0.95, ['address_text' => $rawMessage], 'regex_context');
            }
        }

        // --- 2. CRITICAL KEYWORDS (Priority Over General Chat) ---

        // ✅ NEW: Change payment method (เปลี่ยนวิธีชำระ)
        // "เปลี่ยนไปโอน", "เปลี่ยนเป็นผ่อน", "โอนดีกว่า", "ขอเปลี่ยนวิธีจ่าย"
        if (preg_match('/(เปลี่ยน.*(โอน|ผ่อน|มัดจำ|ออม|จ่าย|ชำระ|วิธี)|โอนดีกว่า|ผ่อนดีกว่า|ขอเปลี่ยน.*(วิธี|ชำระ)|จ่ายเต็ม.*แทน|เปลี่ยนใจ.*(โอน|ผ่อน))/u', $text)) {
            return $this->makeResult('change_payment_method', 0.95, [], 'regex');
        }

        // Installment (ผ่อน) - Context-aware routing
        // 🎯 แยกเป็น 2 กรณี:
        //   1. เช็คยอดผ่อนเก่า (ผ่อนเหลือกี่งวด, ยอดผ่อน) → ค้นประวัติ
        //   2. ถามเรื่องผ่อนทั่วไป (ผ่อนได้ไหม, ผ่อนเท่าไหร่) → ข้อมูลวิธีผ่อน/คำนวณงวด
        if (preg_match('/(ผ่อน|ออม|installment|งวด)/u', $text)) {
            // ✅ CASE 1: เช็คยอดผ่อนเก่า - ต้องมีคำบ่งชี้ชัดเจนว่าถามประวัติ
            // "ยอดผ่อน", "ผ่อนเหลือกี่งวด", "เช็คผ่อน", "ผ่อนอยู่", "ปิดยอดผ่อน", "ยอดผ่อนเท่าไหร่"
            if (preg_match('/(ยอด(ผ่อน|ค้าง)|ผ่อน.*(เหลือ|ค้าง|อยู่)|เช็ค(ผ่อน|ยอด|งวด)|ปิดยอด|งวด.*(เหลือ|ถัด|หน้า)|ค้าง.*งวด|สัญญาผ่อน)/u', $text)) {
                return $this->makeResult('installment_check', 0.95, ['action_type' => 'check_balance'], 'regex');
            }
            
            // ✅ CASE 2: ถามข้อมูลทั่วไป - "ผ่อนได้ไหม", "ผ่อนเท่าไหร่", "ผ่อนหรอ"
            // → ตอบเรื่องวิธีผ่อน หรือคำนวณงวด (ถ้ามีสินค้าใน context)
            // Check if has product context
            $hasProductContext = !empty($lastSlots['product_name']) || !empty($lastSlots['product_code']) || !empty($lastSlots['product_ref_id']);
            
            if ($hasProductContext) {
                // มีสินค้าใน context → ตอบข้อมูลผ่อนสำหรับสินค้านั้น
                return $this->makeResult('payment_options', 0.95, ['action_type' => 'installment_info'], 'regex');
            }
            
            // ไม่มีสินค้า → ตอบข้อมูลทั่วไปเรื่องการผ่อน
            return $this->makeResult('payment_options', 0.90, ['action_type' => 'general_info'], 'regex');
        }

        // Deposit/Booking (จอง/มัดจำ) - Context-aware routing
        // ถ้าอยู่ใน checkout flow แล้ว -> เปลี่ยน payment method
        // ถ้ามี product ใน context -> checkout, ถ้าไม่มี -> ask product
        if (preg_match('/(จอง|มัดจำ|วางเงิน|วางมัดจำ)/u', $text)) {
            // Check if has product context from last slots
            $hasProductContext = !empty($lastSlots['product_name']) || !empty($lastSlots['product_code']) || !empty($lastSlots['product_ref_id']);
            $checkoutStep = trim((string) ($lastSlots['checkout_step'] ?? ''));
            $isInCheckoutFlow = in_array($checkoutStep, ['payment_selection', 'ask_delivery', 'ask_address', 'confirm_order', 'payment_selected']);
            
            // ✅ If already in checkout flow and asking for deposit → change payment method
            if ($isInCheckoutFlow && $hasProductContext) {
                return $this->makeResult('change_payment_method', 0.95, ['new_payment_method' => 'deposit'], 'regex_context');
            }
            
            if ($hasProductContext) {
                // Has product context -> proceed to deposit checkout
                return $this->makeResult('deposit_new', 0.95, ['action_type' => 'proceed'], 'regex');
            } else {
                // No product context -> ask for product
                return $this->makeResult('deposit_flow', 0.90, ['action_type' => 'ask_product'], 'regex');
            }
        }

        // Pawn / Deposit / ฝากขาย / รับฝาก (Same service - ลูกค้าเอาของมาฝากแลกเงิน)
        // ฝากขาย = รับฝาก = บริการเดียวกัน (ดอก 2%/เดือน, วงเงิน 65-70%)
        if (preg_match('/(จำนำ|ฝากจำนำ|รับฝาก|ฝากขาย|ขายฝาก|ฝาก.*ช่วยขาย|เอามาฝาก|ไถ่ถอน|ไถ่คืน|consign)/iu', $text)) {
            if (preg_match('/(ต่อดอก|ดอกเบี้ย)/u', $text)) {
                return $this->makeResult('pawn_pay_interest', 0.95, [], 'regex');
            }
            return $this->makeResult('pawn_inquiry', 0.95, [], 'regex'); // Route to pawn/deposit inquiry
        }

        // Admin Handoff (Safety Valve)
        // ✅ FIXED: ยาก must be standalone word (not part of อยาก)
        if (preg_match('/(ติดต่อ|คุย|ขอ).*(แอดมิน|เจ้าหน้าที่|คน|staff|admin)|(งง|ไม่เข้าใจ|(?<![อ])ยาก|ช่วยด้วย)|@admin/u', $text)) {
            return $this->makeResult('admin_handoff', 1.0, [], 'regex');
        }

        // ✅ NEW: Video Call Request (ขอดูวิดีโอคอล, โทรดูของ)
        if (preg_match('/(video|วิดีโอ|วีดีโอ).*(call|คอล|ดู|หน่อย)|(โทร|call).*(ดู|หน่อย|ของ|สินค้า)|ขอดู.*(live|สด|จริง)|face\s*time/iu', $text)) {
            return $this->makeResult('request_video_call', 1.0, [], 'regex');
        }

        // ✅ NEW: Price Negotiation (ลดได้ไหม, ขอส่วนลด) → Handover to Admin
        if (preg_match('/(ลด|ส่วนลด|discount|ต่อ|หั่น).*(ได้|หน่อย|ไหม|มั้ย|นิด|ราคา|price)|ราคา.*(ต่อ|ลด|เยอะ|ถูก).*ได้|(ขอ|ช่วย).*(ลด|ต่อ|ส่วนลด)|แพง.*(ไป|จัง|มาก)/u', $text)) {
            return $this->makeResult('price_negotiation', 0.95, [], 'regex');
        }

        // ✅ NEW: Trade-in Calculate (คำนวณเทิร์น 50000)
        if (preg_match('/(คำนวณ|คิด).*(เทิร์น|เปลี่ยน|คืน).*?(\d[\d,]*)/u', $text, $matches)) {
            $amount = (int) str_replace(',', '', $matches[3]);
            return $this->makeResult('trade_in_calculate', 0.95, ['original_price' => $amount], 'regex');
        }

        // ✅ NEW: Trade-in Inquiry (เทิร์นของเก่า, เปลี่ยนสินค้า, ขายคืน)
        if (preg_match('/(เทิร์น|turn|trade).*(in|ของ|สินค้า)?|(เปลี่ยน|แลก).*(ของเก่า|สินค้าเก่า|เรือนเก่า)|(ขาย|คืน).*(ให้ร้าน|กลับ|ของเก่า)|นำของเก่ามา.*(แลก|เปลี่ยน)/iu', $text)) {
            return $this->makeResult('trade_in_inquiry', 0.95, [], 'regex');
        }

        // ✅ NEW: Return/Exchange Policy Inquiry (คืนสินค้าได้ไหม, เปลี่ยนสินค้าได้ไหม)
        if (preg_match('/(คืน|เปลี่ยน).*(สินค้า|ของ)?.*(ได้|ไหม|มั้ย|ยังไง|อย่างไร)|นโยบาย.*(คืน|เปลี่ยน)|return.*policy/iu', $text)) {
            return $this->makeResult('trade_in_inquiry', 0.9, [], 'regex');
        }

        // Payment Slip (แจ้งโอน)
        if (preg_match('/(โอน|ชำระ|จ่าย).*(แล้ว|เรียบร้อย)|(สลิป|หลักฐาน)/u', $text)) {
            return $this->makeResult('payment_slip_verify', 0.95, [], 'regex');
        }

        // Repair (ซ่อม)
        if (preg_match('/(ซ่อม|ชุบ|ล้าง|repair)/u', $text)) {
            if (preg_match('/(สถานะ|เสร็จ|ยัง)/u', $text)) {
                return $this->makeResult('repair_check', 0.95, [], 'regex');
            }
            return $this->makeResult('repair_new', 0.95, [], 'regex');
        }

        // Order Status
        if (preg_match('/(สถานะ|เช็ค|ดู).*(ออเดอร์|order|คำสั่งซื้อ|ของ|พัสดุ)/u', $text)) {
            return $this->makeResult('order_status', 0.95, [], 'regex');
        }

        // --- 3. PRODUCT INTERACTIONS ---

        // Product Lookup by Code (Specific Format: ABC-1234 or ABC-DEF-001 or ROL-DAY-001)
        // รองรับ GLD-NCK-001, RLX-SUB, ROL-DAY-001, etc.
        // ✅ FIXED: Support 3-part codes like XXX-YYY-NNN
        if (preg_match('/([A-Z]{2,5}[-][A-Z0-9]{2,5}[-][A-Z0-9]{2,5})/i', $text, $matches) ||
            preg_match('/([A-Z]{2,5}[-][A-Z0-9]{2,5})/i', $text, $matches) ||
            preg_match('/([A-Z]{2,5}[0-9]{3,5})/i', $text, $matches)) {
            $code = strtoupper(trim($matches[1]));
            
            // กรอง: รหัสต้องยาวอย่างน้อย 5 ตัวอักษร
            if (strlen($code) >= 5) {
                // Check if "Buy" keywords exist with the code → เข้า checkout เลย
                if (preg_match('/(สนใจ|เอา|ซื้อ|f|cf|รับ|จอง)/u', $text)) {
                    return $this->makeResult('purchase_intent', 1.0, [
                        'product_code' => $code,
                        'trigger' => 'explicit_code'
                    ], 'regex');
                }
                // ✅ พิมพ์รหัสเฉยๆ → แค่ค้นหาแสดงข้อมูล ยังไม่เข้า checkout
                return $this->makeResult('product_lookup_by_code', 0.95, [
                    'code' => $code,
                    'trigger' => 'code_only'
                ], 'regex');
            }
        }

        // Product Interest (Explicit Keywords)
        // "สนใจครับ", "รับชิ้นนี้", "จองค่ะ"
        if (preg_match('/^(สนใจ|รับ|เอา|จอง|cf|f)\s*(ครับ|ค่ะ|จ้า|นะ|เลย)?$/u', $text) || 
            preg_match('/^(สนใจ|รับ|เอา|จอง).*(ชิ้นนี้|เรือนนี้|ตัวนี้|อันนี้)/u', $text)) {
            return $this->makeResult('purchase_intent', 0.95, [], 'regex');
        }

        // ✅ Product Selection from History (เอาตัวที่ 2, รายการที่ 1, อันแรก)
        if (preg_match('/(เอา|สนใจ|รับ|จอง|ซื้อ).*(ตัว|รายการ|อัน|ชิ้น).*?(ที่\s*)?(\d+|แรก|สอง|สาม|สี่|ล่าสุด)/u', $text, $matches)) {
            $idx = $this->parseProductIndex($matches[4] ?? '1');
            return $this->makeResult('purchase_intent', 0.95, [
                'product_index' => $idx,
                'from_history' => true
            ], 'regex');
        }

        // ✅ Product Selection by Name (เอากำไลทอง, สนใจ Rolex, รับสร้อย)
        // จับ "เอา/สนใจ/รับ/จอง + ชื่อสินค้า" แล้วส่งไป match กับ products_history
        if (preg_match('/^(เอา|สนใจ|รับ|จอง|ซื้อ)\s*(.{2,30})$/u', $text, $matches)) {
            $productName = trim($matches[2]);
            // ลบ suffix ที่ไม่จำเป็น
            $productName = preg_replace('/(ครับ|ค่ะ|จ้า|นะ|เลย|ด้วย)$/u', '', $productName);
            $productName = trim($productName);
            
            if (!empty($productName) && mb_strlen($productName) >= 2) {
                return $this->makeResult('purchase_intent', 0.9, [
                    'product_name_query' => $productName,
                    'from_history' => true
                ], 'regex');
            }
        }

        // Price Inquiry
        if (preg_match('/(ราคา|เท่าไหร่|กี่บาท|price)/u', $text)) {
            return $this->makeResult('price_inquiry', 0.9, [], 'regex');
        }

        // ✅ NEW: Browse Products - General inquiry without specific product
        // "สนใจสินค้า", "อยากดูสินค้า", "มีสินค้าอะไรบ้าง", "แนะนำบ้าง", "มีอะไรแนะนำ"
        // ✅ Explicit pattern for "มีสินค้าอะไรบ้าง" and similar
        if (preg_match('/^มี.*(สินค้า|ของ).*(อะไร|บ้าง|ไหม)/u', $text) ||
            preg_match('/^(สนใจ|อยากดู|อยากได้)\s*สินค้า\s*(ครับ|ค่ะ|คะ|จ้า|นะ)?$/u', $text) ||
            preg_match('/(มี|มีอะไร|แนะนำ|อยากดู).*(อะไร|อะไรบ้าง|บ้าง|แนะนำ)\s*(ไหม|มั้ย|คะ|ครับ)?$/u', $text) ||
            preg_match('/(สินค้า|ของ).*(แนะนำ|ยอดนิยม|ขายดี|ใหม่)/u', $text) ||
            preg_match('/^สินค้า\s*(ครับ|ค่ะ|คะ)?$/u', $text)) {
            \Logger::info('[IntentService] Regex Hit: browse_products (general inquiry)');
            return $this->makeResult('browse_products', 0.9, [], 'regex');
        }

        // General Product Search / Availability (มีสินค้าไหม, อยากได้แหวน, มีพระปิดตาไหม)
        // Matches: "มีแหวนไหม", "หานาฬิกา", "อยากได้แหวนเพชร", "มีพระสมเด็จไหม", "หาพระเลี่ยมทอง"
        // ✅ UPDATED: Added พระ, พระเครื่อง, เลี่ยม, ตลับ for amulet searches
        if (preg_match('/(มี|หา|ดู|อยากได้|อยากเอา|เอา|ต้องการ|สนใจ).*(สินค้า|นาฬิกา|แหวน|สร้อย|กำไล|จี้|ต่างหู|เพชร|ทอง|กระเป๋า|พระ|เลี่ยม|ตลับ|ไหม|มั้ย|บ้าง)/u', $text)) {
            \Logger::info('[IntentService] Regex Hit: product_availability');
            return $this->makeResult('product_availability', 0.9, [], 'regex');
        }
        
        // Direct product category mention (แหวนเพชร, นาฬิกา rolex, พระสมเด็จ) without question words
        // This catches cases like just "แหวนเพชรแท้", "นาฬิกา Rolex", "พระหลวงปู่ทวด"
        // ✅ UPDATED: Added พระ, เลี่ยม for amulet products
        // ✅ UPDATED: Added กำไร (common typo for กำไล)
        if (preg_match('/^(นาฬิกา|แหวน|สร้อย|กำไล|กำไร|จี้|ต่างหู|กระเป๋า|พระ|เลี่ยม)/u', $text)) {
            \Logger::info('[IntentService] Regex Hit: product_search (direct category)');
            return $this->makeResult('product_search', 0.85, [], 'regex');
        }

        // Browse Products / Product Inquiry (สอบถามสินค้า, อยากดูของ)
        if (preg_match('/(สอบถาม|ดู|อยากดู|ขอดู).*(สินค้า|ของ|รายการ|catalog)|^สอบถามสินค้า$/u', $text)) {
            return $this->makeResult('browse_products', 0.85, [], 'regex');
        }

        // --- 4. SMALL TALK ---
        if (preg_match('/^(สวัสดี|ดีครับ|ดีค่ะ|hello|hi)/u', $text)) {
            return $this->makeResult('greeting', 0.9, [], 'regex');
        }
        if (preg_match('/^(ขอบคุณ|ขอบใจ|thanks|thx)/u', $text)) {
            return $this->makeResult('thanks', 0.9, [], 'regex');
        }

        // No match found
        return $this->makeResult('unknown', 0.0, [], 'regex');
    }

    /**
     * LLM-based Detection Logic via Backend API
     */
    protected function detectByLLM(string $message, array $config, array $context): array
    {
        if (!$this->backendApi) return $this->makeResult('unknown', 0, [], 'no_api');

        $history = $context['conversation_history'] ?? []; // Array of last few messages
        
        $payload = [
            'message' => $message,
            'history' => $history,
            'allowed_intents' => array_keys(self::INTENT_PRIORITY),
            'context_state' => $context['session_state'] ?? [] // e.g., {'current_product': 'RLX-001'}
        ];

        // Call Backend API (which wraps OpenAI/Gemini)
        $result = $this->backendApi->call($config, 'intent', $payload, $context);

        if (!$result['ok']) {
            if (class_exists('Logger')) \Logger::error("[IntentService] LLM Call Failed: " . ($result['error'] ?? 'Unknown'));
            return $this->makeResult('unknown', 0.0, [], 'llm_error');
        }

        $data = $result['data'] ?? [];
        
        // Validate returned intent
        $intent = $data['intent'] ?? 'unknown';
        if (!array_key_exists($intent, self::INTENT_PRIORITY) && $intent !== 'unknown') {
            // Fallback Mapping (ถ้า LLM ตอบชื่อแปลกๆ มา)
            if (strpos($intent, 'buy') !== false) $intent = 'purchase_intent';
            else if (strpos($intent, 'pay') !== false) $intent = 'payment_slip_verify';
            else $intent = 'unknown'; 
        }

        return $this->makeResult(
            $intent, 
            (float)($data['confidence'] ?? 0.0), 
            $data['slots'] ?? [], 
            'llm',
            $data['reply_text'] ?? null // เก็บข้อความที่ LLM ตอบมาด้วย (ถ้ามี)
        );
    }

    /**
     * Normalize text for consistent regex matching
     */
    protected function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        // Common typo fixes
        $text = str_replace(['คับ', 'คัฟ', 'ka', 'krub', 'krup'], ['ครับ', 'ครับ', 'ค่ะ', 'ครับ', 'ครับ'], $text);
        // Normalize tones/spellings (ไหม, มั้ย, หรอ, เหรอ)
        $text = preg_replace('/(มั้ย|มั๊ย)/u', 'ไหม', $text);
        $text = preg_replace('/(หรอ|เหรอ)/u', 'หรือ', $text);
        $text = preg_replace('/\s+/u', ' ', $text); // Reduce multiple spaces
        return $text;
    }

    /**
     * Check if the user is currently in a transaction flow
     */
    protected function isInCheckoutFlow(array $lastSlots): bool
    {
        $step = $lastSlots['checkout_step'] ?? '';
        return in_array($step, ['ask_payment', 'ask_delivery', 'ask_address', 'confirm_order']);
    }

    /**
     * Helper to check if LLM is configured
     */
    protected function isLlmEnabled(array $config): bool
    {
        return !empty($config['backend_api']['enabled']) && 
               !empty($config['llm']['enabled']);
    }

    /**
     * Parse product index from Thai text
     */
    protected function parseProductIndex(string $text): int
    {
        $map = [
            'แรก' => 1, 'หนึ่ง' => 1,
            'สอง' => 2,
            'สาม' => 3,
            'สี่' => 4,
            'ห้า' => 5,
            'ล่าสุด' => -1, // Special: last item
        ];
        
        if (is_numeric($text)) {
            return (int)$text;
        }
        
        return $map[$text] ?? 1;
    }

    /**
     * Standardized Result Factory
     */
    protected function makeResult(string $intent, float $confidence, array $slots, string $method, ?string $replyText = null): array
    {
        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'slots' => $slots, // Extracted entities (product code, price, date)
            'method' => $method, // 'regex', 'llm', 'regex_context'
            'reply_text' => $replyText, // Optional: direct reply from LLM
            'priority' => self::INTENT_PRIORITY[$intent] ?? 999
        ];
    }

    /**
     * Get routing info for an intent (Optional Helper for Router)
     */
    public function getRoutingInfo(string $intent): array
    {
        $map = [
            'purchase_intent' => 'CheckoutService',
            'checkout_confirm' => 'CheckoutService',
            'installment_flow' => 'CheckoutService',
            'deposit_new' => 'CheckoutService',
            'product_lookup_by_code' => 'ProductService',
            'product_availability' => 'ProductService',
            'payment_slip_verify' => 'PaymentService'
        ];

        return [
            'service' => $map[$intent] ?? 'ResponseService',
            'is_transaction' => isset($map[$intent])
        ];
    }
}