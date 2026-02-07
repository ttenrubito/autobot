<?php
/**
 * CheckoutService - Dynamic Config-Driven Checkout Flow
 * 
 * Features:
 * - Config-Driven (no hardcoded values)
 * - Interruption Guard (fixes Sticky Session)
 * - Dynamic Installment Calculation with Dates
 * - Deposit Calculation from Config
 * - Address Collection Flow
 * - Database Recording (orders + installment_contracts + installment_payments)
 * 
 * @version 2.1 (Unified Installment System)
 * @date 2026-01-30
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../Logger.php';
require_once __DIR__ . '/ProductService.php';
require_once __DIR__ . '/ChatService.php';

class CheckoutService
{
    protected $db;
    protected $productService;
    protected $chatService;
    
    const STATE_KEY = 'checkout_state';
    const STATE_TTL = 1800; // 30 minutes

    // Checkout step constants
    const STEP_CONFIRM = 'confirm';              // Asking payment method
    const STEP_ASK_DELIVERY = 'ask_delivery';    // Asking delivery method
    const STEP_ASK_ADDRESS = 'ask_address';      // Collecting address
    const STEP_CONFIRMED = 'confirmed';          // Order created

    public function __construct()
    {
        $this->db = \Database::getInstance();
        $this->productService = new ProductService();
        $this->chatService = new ChatService();
    }

    // ==================== CHECKOUT FLOW ====================

    /**
     * Start checkout flow for a product
     * NOW: Config-Driven with dynamic installment/deposit display
     * 
     * @param array $product Product data
     * @param array $config Bot config
     * @param array $context Request context
     * @param string|null $defaultPaymentType Pre-select payment type (null=ask, 'deposit', 'installment', 'full')
     */
    public function startCheckout(array $product, array $config, array $context, ?string $defaultPaymentType = null): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        if (!$platformUserId || !$channelId) {
            return $this->errorResult('missing_context');
        }

        // Build checkout state
        $state = [
            'step' => self::STEP_CONFIRM,
            'product' => $product,
            'started_at' => time(),
            'platform_user_id' => $platformUserId,
            'channel_id' => $channelId,
        ];

        // ✅ If payment type is pre-selected (e.g., from "จอง" intent)
        if ($defaultPaymentType) {
            $state['payment_type'] = $defaultPaymentType;
            $state['step'] = self::STEP_ASK_DELIVERY; // Skip payment selection
            
            // Save state
            $this->saveCheckoutState($platformUserId, $channelId, $state);
            
            // Build direct deposit/installment message
            $reply = $this->buildPreSelectedPaymentMessage($product, $config, $defaultPaymentType);
            
            return [
                'ok' => true,
                'reply' => $reply,
                'state' => $state,
            ];
        }

        // Save state
        $this->saveCheckoutState($platformUserId, $channelId, $state);

        // Build confirmation message WITH CONFIG-DRIVEN pricing
        $reply = $this->buildPaymentOptionsMessage($product, $config);

        return [
            'ok' => true,
            'reply' => $reply,
            'state' => $state,
        ];
    }

    /**
     * Confirm checkout and create order
     * NOW: Includes installment schedule creation
     */
    public function confirmCheckout(array $config, array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        // Get checkout state
        $state = $this->getCheckoutState($platformUserId, $channelId);
        
        if (!$state || empty($state['product'])) {
            return [
                'ok' => false,
                'reply' => 'ไม่พบรายการที่ต้องการยืนยัน กรุณาเลือกสินค้าใหม่อีกครั้งค่ะ',
            ];
        }

        // Set defaults if not set
        if (empty($state['payment_type'])) {
            $state['payment_type'] = 'full';
        }
        if (empty($state['delivery_method'])) {
            $state['delivery_method'] = 'pickup';
        }

        return $this->processOrder($state, $config, $context);
    }

    /**
     * Cancel checkout
     */
    public function cancelCheckout(array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        // Clear checkout state
        $this->clearCheckoutState($platformUserId, $channelId);

        return [
            'ok' => true,
            'reply' => 'ยกเลิกการสั่งซื้อเรียบร้อยค่ะ 🙏 หากต้องการสินค้าอื่น สามารถค้นหาได้เลยค่ะ',
        ];
    }

    // ==================== CHECKOUT FLOW HANDLING (Config-Driven) ====================

    /**
     * Handle checkout flow based on current step
     * Returns [] (empty) if user is talking off-topic -> Router should pass to IntentService
     * 
     * INTERRUPTION GUARD: Releases user from checkout if:
     * 1. User is asking a question (not answering checkout)
     * 2. User typed a new product code
     * 3. User said cancel keywords
     * 
     * @param string $text User's message
     * @param array $state Current checkout state
     * @param array $config Bot config
     * @param array $context Chat context
     * @return array ['reply' => ..., 'actions' => ...] or [] if off-topic
     */
    public function handleFlow(string $text, array $state, array $config, array $context): array
    {
        $step = $state['step'] ?? '';
        $textLower = mb_strtolower(trim($text), 'UTF-8');
        $platformUserId = $context['platform_user_id'] ?? '';
        $channelId = $context['channel']['id'] ?? 0;

        // ✅ DEBUG: Log incoming state for troubleshooting
        \Logger::info('[CheckoutService] handleFlow called', [
            'step' => $step,
            'text_preview' => mb_substr($text, 0, 50, 'UTF-8'),
            'state_keys' => array_keys($state),
            'platform_user_id' => $platformUserId,
        ]);

        // ==================== INTERRUPTION GUARD (Fix Sticky Session) ====================
        
        // 1. Check if user is ASKING a question (not answering checkout)
        if ($this->isInterruptionQuestion($text)) {
            \Logger::info('[CheckoutService] User asking question, releasing from checkout', ['text' => $text]);
            return []; // Let Router/IntentService handle
        }

        // 2. Check if user typed a new product code
        if ($this->isProductCode($text)) {
            \Logger::info('[CheckoutService] User typed product code, releasing from checkout', ['text' => $text]);
            return []; // Let Router do product lookup
        }

        // 3. Check for cancel keywords
        if ($this->isCancelIntent($text)) {
            $this->clearCheckoutState($platformUserId, $channelId);
            return [
                'reply' => "ยกเลิกการสั่งซื้อเรียบร้อยค่ะ 🙏\n\nหากต้องการสินค้าอื่น สามารถค้นหาได้เลยค่ะ",
                'cleared_checkout' => true,
            ];
        }

        // ==================== STEP-BASED HANDLING ====================

        switch ($step) {
            case self::STEP_CONFIRM:
                return $this->handlePaymentSelection($text, $state, $config, $context);

            case self::STEP_ASK_DELIVERY:
                return $this->handleDeliverySelection($text, $state, $config, $context);

            case self::STEP_ASK_ADDRESS:
                return $this->handleAddressCollection($text, $state, $config, $context);

            case self::STEP_CONFIRMED:
                return $this->handlePostConfirmation($text, $state, $config, $context);

            default:
                // ✅ FIX: Before releasing, check if user sent address data
                // This handles race condition when step wasn't saved properly
                if ($this->looksLikeAddress($text) && !empty($state['delivery_method'])) {
                    \Logger::info('[CheckoutService] Fallback: Detected address in unknown step, routing to address collection', [
                        'step' => $step,
                        'delivery_method' => $state['delivery_method'],
                    ]);
                    // Force to address collection step
                    $state['step'] = self::STEP_ASK_ADDRESS;
                    $this->saveCheckoutState($platformUserId, $channelId, $state);
                    return $this->handleAddressCollection($text, $state, $config, $context);
                }
                
                \Logger::warning('[CheckoutService] Unknown step, releasing', [
                    'step' => $step,
                    'text_preview' => mb_substr($text, 0, 30, 'UTF-8'),
                ]);
                return [];
        }
    }

    /**
     * Check if user is asking a question (interruption)
     * ✅ Exclude payment method keywords - those are payment change requests, not interruptions
     */
    protected function isInterruptionQuestion(string $text): bool
    {
        // ✅ If text contains payment method keywords, it's NOT an interruption
        // It's a request to change payment method (จองก่อนได้ไหม, มัดจำได้ไหม, ผ่อนได้ไหม)
        if (preg_match('/(จอง|มัดจำ|ผ่อน|ออม|โอนเต็ม|จ่ายเต็ม)/ui', $text)) {
            return false;
        }
        
        return (bool)preg_match(
            '/(ได้ไหม|ได้มั้ย|มีไหม|มีมั้ย|แบบไหน|อะไรบ้าง|ยังไง|หรอ|เหรอ|มั้ย|ราคา|เท่าไหร่|กี่บาท|เงื่อนไข|นโยบาย|รายละเอียด)/ui',
            $text
        );
    }

    /**
     * Check if text is a product code
     */
    protected function isProductCode(string $text): bool
    {
        return (bool)preg_match('/^[A-Z]{2,5}[-]?[A-Z0-9]{2,5}[-]?\d{0,5}$/i', trim($text));
    }

    /**
     * Check if user wants to cancel
     * ✅ Extended patterns to prevent user stuck in checkout loop
     */
    protected function isCancelIntent(string $text): bool
    {
        return (bool)preg_match('/(ยกเลิก|ไม่เอา|ไม่เอาแล้ว|พอแล้ว|หยุด|ออก|กลับ|cancel|stop|exit|quit|ไม่ซื้อ|เปลี่ยนใจ|ไม่ต้อง|เริ่มใหม่|รีเซ็ต|reset)/ui', $text);
    }

    /**
     * Handle payment method selection (Step: confirm)
     * NOW: Config-Driven with dynamic installment summary
     */
    protected function handlePaymentSelection(string $text, array $state, array $config, array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? '';
        $channelId = $context['channel']['id'] ?? 0;
        $product = $state['product'] ?? [];

        // Detect payment method from text
        $paymentType = $this->detectPaymentMethod($text);

        if (!$paymentType) {
            // ✅ Check if user sent address info instead of payment selection
            // This happens when user skips payment selection and types address directly
            if ($this->looksLikeAddress($text)) {
                \Logger::info('[CheckoutService] User sent address at payment step, re-prompting', ['text' => $text]);
                
                // Save address to buffer for later use
                $state['address_buffer'] = $text;
                $this->saveCheckoutState($platformUserId, $channelId, $state);
                
                // Re-prompt for payment method
                $productName = $product['name'] ?? $product['title'] ?? 'สินค้า';
                return [
                    'reply' => "ขอบคุณสำหรับข้อมูลที่อยู่ค่ะ 📝\n\nแต่รบกวนเลือกวิธีชำระเงินก่อนนะคะ:\n1️⃣ โอนเต็ม\n2️⃣ ผ่อน\n3️⃣ มัดจำ",
                    'actions' => [
                        ['type' => 'quick_reply', 'items' => [
                            ['label' => '💳 โอนเต็ม', 'text' => '1.โอนเต็ม'],
                            ['label' => '📅 ผ่อน', 'text' => '2.ผ่อน'],
                            ['label' => '💰 มัดจำ', 'text' => '3.มัดจำ'],
                        ]]
                    ]
                ];
            }
            
            // User said something we don't understand - might be off-topic
            \Logger::info('[CheckoutService] Unrecognized payment selection, releasing', ['text' => $text]);
            return [];
        }

        // Update state with payment type
        $state['payment_type'] = $paymentType;
        $state['step'] = self::STEP_ASK_DELIVERY;
        $this->saveCheckoutState($platformUserId, $channelId, $state);

        // ✅ SYNC TO CASE immediately when payment type is selected
        // This ensures cases.php has the payment_type even if user cancels later
        $this->syncPaymentTypeToCase($paymentType, $config, $context);

        // Build payment summary with CONFIG-DRIVEN calculation and ask for delivery
        $replyData = $this->buildPaymentSummaryAndAskDelivery($product, $paymentType, $config);

        return [
            'reply' => $replyData['text'],
            'payment_type' => $paymentType,
            'actions' => $replyData['actions'] ?? [],
            'quickReply' => $replyData['quickReply'] ?? null,
        ];
    }

    /**
     * Handle post-confirmation queries
     */
    protected function handlePostConfirmation(string $text, array $state, array $config, array $context): array
    {
        $orderNo = $state['order_no'] ?? null;
        
        // Check if user sent slip confirmation
        if (preg_match('/(ยืนยัน.*สลิป|ส่งสลิป|โอนแล้ว)/ui', $text)) {
            return [
                'reply' => 'รบกวนส่งรูปสลิปมาได้เลยค่ะ เดี๋ยวตรวจสอบให้นะคะ 📸',
            ];
        }

        if ($orderNo) {
            return [
                'reply' => "ออเดอร์ #{$orderNo} ยืนยันเรียบร้อยแล้วค่ะ ✅\n\nรอรับสลิปการชำระเงินได้เลยนะคะ 🙏",
            ];
        }

        // Release to router
        return [];
    }

    /**
     * Handle delivery method selection (Config-Driven)
     */
    protected function handleDeliverySelection(string $text, array $state, array $config, array $context): array
    {
        $textLower = mb_strtolower($text, 'UTF-8');
        $platformUserId = $context['platform_user_id'] ?? '';
        $channelId = $context['channel']['id'] ?? 0;

        // Cancel check
        if (preg_match('/(ยกเลิก|ไม่เอา|cancel)/ui', $text)) {
            $this->clearCheckoutState($platformUserId, $channelId);
            return ['reply' => 'ยกเลิกเรียบร้อยค่ะ 🙏', 'cleared_checkout' => true];
        }

        // ✅ NEW: Check for payment method CHANGE request (จองก่อนได้ไหม, เปลี่ยนเป็นมัดจำ)
        $newPaymentType = $this->detectPaymentMethodChangeRequest($text);
        if ($newPaymentType) {
            \Logger::info('[CheckoutService] Payment method change during delivery selection', [
                'old_payment' => $state['payment_type'] ?? 'unknown',
                'new_payment' => $newPaymentType,
                'text' => $text,
            ]);
            
            // Update state with new payment type
            $state['payment_type'] = $newPaymentType;
            $this->saveCheckoutState($platformUserId, $channelId, $state);
            
            // Show new payment summary and continue asking for delivery
            return $this->showPaymentChangedAndAskDelivery($state, $config, $context);
        }

        // Pickup at store - also support "1.รับที่ร้าน" format from Quick Reply
        if ($text === '1' || preg_match('/^1\.\s*รับ/u', $text) || preg_match('/(รับ.*ร้าน|รับเอง|pickup|หน้าร้าน)/ui', $text)) {
            $state['delivery_method'] = 'pickup';
            $state['shipping_fee'] = 0;
            $state['step'] = self::STEP_CONFIRMED;
            $this->saveCheckoutState($platformUserId, $channelId, $state);

            // Create order and confirm
            return $this->processOrder($state, $config, $context);
        }

        // EMS delivery (Config-Driven fee) - also support "2.ส่ง EMS" format
        if ($text === '2' || preg_match('/^2\.\s*ส่ง/u', $text) || preg_match('/\bems\b/ui', $text)) {
            $shippingFee = $this->getShippingFee('ems', $config);
            $state['delivery_method'] = 'ems';
            $state['shipping_fee'] = $shippingFee;
            $state['step'] = self::STEP_ASK_ADDRESS;
            $this->saveCheckoutState($platformUserId, $channelId, $state);

            $feeText = $shippingFee > 0 ? " (+{$shippingFee} บาท)" : "";
            return [
                'reply' => "✅ ส่ง EMS{$feeText}\n\nรบกวนแจ้ง ชื่อ-ที่อยู่-เบอร์โทร สำหรับจัดส่งค่ะ 📝",
                'actions' => [
                    ['type' => 'quick_reply', 'items' => [
                        ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก']
                    ]]
                ]
            ];
        }

        // Grab delivery - also support "3.ส่ง Grab" format
        if ($text === '3' || preg_match('/^3\.\s*ส่ง/u', $text) || preg_match('/(grab|แกร็บ|แกรบ)/ui', $text)) {
            $state['delivery_method'] = 'grab';
            $state['shipping_fee'] = 0; // ค่าส่งตามจริง
            $state['step'] = self::STEP_ASK_ADDRESS;
            $this->saveCheckoutState($platformUserId, $channelId, $state);

            return [
                'reply' => "✅ ส่ง Grab (ค่าส่งตามระยะทาง)\n\nรบกวนแจ้ง ชื่อ-ที่อยู่-เบอร์โทร สำหรับจัดส่งค่ะ 📝",
                'actions' => [
                    ['type' => 'quick_reply', 'items' => [
                        ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก']
                    ]]
                ]
            ];
        }

        // Generic "ส่ง" without specifying method
        if (preg_match('/^(ส่ง|จัดส่ง|deliver)$/ui', trim($text))) {
            $emsFee = $this->getShippingFee('ems', $config);
            return [
                'reply' => "ต้องการส่งแบบไหนคะ?\n\n📦 EMS (+{$emsFee} บาท)\n🛵 Grab (ค่าส่งตามระยะทาง)",
                'actions' => [
                    ['type' => 'quick_reply', 'items' => [
                        ['label' => '📦 EMS', 'text' => '2.ส่ง EMS'],
                        ['label' => '🛵 Grab', 'text' => '3.ส่ง Grab'],
                    ]]
                ]
            ];
        }

        // Unrecognized - release
        \Logger::info('[CheckoutService] Unrecognized delivery input, releasing', ['text' => $text]);
        return [];
    }

    /**
     * Get shipping fee from config
     */
    protected function getShippingFee(string $method, array $config): float
    {
        $shippingConfig = $config['policies']['shipping'] ?? $config['shipping'] ?? [];

        return match ($method) {
            'ems' => (float)($shippingConfig['ems'] ?? 150),
            'grab' => (float)($shippingConfig['grab'] ?? 0),
            default => 0,
        };
    }

    /**
     * Handle address collection with buffering (multi-message support)
     * Collects: name, phone, address - validates each, asks for missing
     */
    protected function handleAddressCollection(string $text, array $state, array $config, array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? '';
        $channelId = $context['channel']['id'] ?? 0;

        // Cancel check
        if (preg_match('/(ยกเลิก|ไม่เอา|cancel)/ui', $text)) {
            $this->clearCheckoutState($platformUserId, $channelId);
            return ['reply' => 'ยกเลิกเรียบร้อยค่ะ 🙏', 'cleared_checkout' => true];
        }

        // ==================== ADDRESS BUFFERING ====================
        // Append new text to address buffer (supports multi-message input)
        $addressBuffer = trim((string)($state['address_buffer'] ?? ''));
        if ($addressBuffer !== '') {
            $addressBuffer .= "\n" . $text;
        } else {
            $addressBuffer = $text;
        }
        
        // Save buffer to state
        $state['address_buffer'] = $addressBuffer;
        $this->saveCheckoutState($platformUserId, $channelId, $state);

        \Logger::info('[CheckoutService] Address buffer updated', [
            'buffer' => $addressBuffer,
            'new_text' => $text,
        ]);

        // ==================== VALIDATION ====================
        $validation = $this->validateAddressBuffer($addressBuffer);

        if (!$validation['is_complete']) {
            // Build prompt for missing info
            $missingPrompt = $this->buildMissingInfoPrompt($validation['missing']);
            return ['reply' => $missingPrompt];
        }

        // ==================== DATA IS COMPLETE ====================
        // Parse address from buffer
        $addressData = $this->parseShippingAddress($addressBuffer);

        // Save to state
        $state['shipping_address'] = $addressData;
        $state['step'] = self::STEP_CONFIRMED;
        $state['address_buffer'] = ''; // Clear buffer
        $this->saveCheckoutState($platformUserId, $channelId, $state);

        // Save address to customer_addresses table
        $addressId = $this->saveCustomerAddress($addressData, $context);
        
        \Logger::info('[CheckoutService] Address saved', [
            'address_id' => $addressId,
            'address_data' => $addressData,
        ]);

        // Create order with address
        return $this->processOrder($state, $config, $context);
    }

    /**
     * Check if text looks like an address
     */
    protected function looksLikeAddress(string $text): bool
    {
        if (mb_strlen($text, 'UTF-8') < 15) {
            return false;
        }

        $score = 0;

        // Has phone number
        if (preg_match('/0[689]\d{8}|0[1-5]\d{7}/u', $text)) {
            $score += 3;
        }

        // Has postal code
        if (preg_match('/\d{5}/', $text)) {
            $score += 2;
        }

        // Has province keywords
        if (preg_match('/กรุงเทพ|กทม|นนทบุรี|ปทุมธานี|สมุทรปราการ|ชลบุรี/u', $text)) {
            $score += 2;
        }

        // Has address keywords
        if (preg_match('/อำเภอ|เขต|ตำบล|แขวง|ซอย|ถนน|หมู่|บ้าน/u', $text)) {
            $score += 2;
        }

        return $score >= 2 || mb_strlen($text, 'UTF-8') >= 30;
    }

    /**
     * Validate address buffer for completeness
     * Checks: name, phone, address
     */
    protected function validateAddressBuffer(string $buffer): array
    {
        $missing = [];
        $buffer = trim($buffer);
        $bufferLen = mb_strlen($buffer, 'UTF-8');

        // Check for phone (Thai mobile: 0 followed by 8-9 digits)
        $hasPhone = (bool) preg_match('/0[689]\d{8}|0[1-5]\d{7}/u', $buffer);
        if (!$hasPhone) {
            $missing[] = 'phone';
        }

        // Check for Thai name (at least 2 Thai words or English words)
        $hasName = (bool) preg_match('/[ก-๙]{2,}[\s]+[ก-๙]{2,}/u', $buffer);
        if (!$hasName) {
            // Also accept English names or "คุณ XXX"
            $hasName = (bool) preg_match('/[a-zA-Z]{2,}[\s]+[a-zA-Z]{2,}/u', $buffer);
        }
        if (!$hasName) {
            // Check for "คุณ + name" pattern
            $hasName = (bool) preg_match('/คุณ\s*[ก-๙a-zA-Z]+/u', $buffer);
        }
        if (!$hasName) {
            $missing[] = 'name';
        }

        // Check for address indicators
        $addressIndicators = [
            '/\d+\/\d+/u',                              // House number like 123/45
            '/ถ\\.?|ถนน|road|rd/iu',                    // Road
            '/ซ\\.?|ซอย|soi/iu',                        // Soi
            '/ม\\.?|หมู่/iu',                           // Moo
            '/ต\\.?|ตำบล|แขวง/iu',                      // Subdistrict
            '/อ\\.?|อำเภอ|เขต|เมือง/iu',                // District
            '/จ\\.?|จังหวัด|กรุงเทพ|กทม/iu',            // Province
            '/\b\d{5}\b/',                              // Postal code
        ];

        $addressScore = 0;
        foreach ($addressIndicators as $pattern) {
            if (preg_match($pattern, $buffer)) {
                $addressScore++;
            }
        }

        // Need at least 2 address indicators OR text > 40 chars with numbers
        $hasAddress = $addressScore >= 2 || ($bufferLen > 40 && preg_match('/\d/', $buffer));
        if (!$hasAddress) {
            $missing[] = 'address';
        }

        // ✅ FIX: Phone is ALWAYS required - no fallback for missing phone!
        // Emergency fallback: Only for address/name if buffer is long enough
        // BUT phone must always be present for shipping
        if (!empty($missing) && $bufferLen >= 50 && $hasPhone) {
            // Only accept fallback if we have phone AND (name or address)
            $hasItems = ($hasPhone ? 1 : 0) + ($hasName ? 1 : 0) + ($hasAddress ? 1 : 0);
            if ($hasItems >= 2 || $bufferLen >= 80) {
                \Logger::info('[CheckoutService] Address validation fallback accepted (phone required)', [
                    'buffer_len' => $bufferLen,
                    'has_items' => $hasItems,
                ]);
                $missing = [];
            }
        }

        $isComplete = empty($missing);

        \Logger::info('[CheckoutService] Address validation', [
            'buffer_len' => $bufferLen,
            'has_name' => $hasName,
            'has_phone' => $hasPhone,
            'has_address' => $hasAddress,
            'address_score' => $addressScore,
            'is_complete' => $isComplete,
            'missing' => $missing,
        ]);

        return [
            'is_complete' => $isComplete,
            'missing' => $missing,
            'has_name' => $hasName,
            'has_phone' => $hasPhone,
            'has_address' => $hasAddress,
        ];
    }

    /**
     * Build prompt message for missing address info
     */
    protected function buildMissingInfoPrompt(array $missing): string
    {
        $prompts = [];
        
        if (in_array('name', $missing)) {
            $prompts[] = '👤 ชื่อ-นามสกุลผู้รับ';
        }
        if (in_array('address', $missing)) {
            $prompts[] = '📍 ที่อยู่สำหรับจัดส่ง';
        }
        if (in_array('phone', $missing)) {
            $prompts[] = '📱 เบอร์โทรศัพท์';
        }

        if (empty($prompts)) {
            return "รบกวนแจ้งข้อมูลสำหรับจัดส่งค่ะ 📝";
        }

        $reply = "รบกวนแจ้งข้อมูลเพิ่มเติมค่ะ:\n\n";
        $reply .= implode("\n", $prompts);
        $reply .= "\n\n📝 พิมพ์ต่อได้เลยนะคะ";

        return $reply;
    }

    /**
     * Parse Thai shipping address from text (improved)
     * Extracts: name, phone, address_line1, subdistrict, district, province, postal_code
     */
    protected function parseShippingAddress(string $text): array
    {
        $result = [
            'name' => '',
            'phone' => '',
            'address_line1' => '',
            'address_line2' => '',
            'subdistrict' => '',
            'district' => '',
            'province' => '',
            'postal_code' => '',
        ];

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', trim($text));
        $originalText = $text;

        // ==================== EXTRACT PHONE ====================
        if (preg_match('/(?:0[689]\d{8}|0[1-5]\d{7})/u', $text, $match)) {
            $result['phone'] = $match[0];
            $text = str_replace($match[0], ' ', $text);
        }

        // ==================== EXTRACT POSTAL CODE ====================
        if (preg_match('/\b(\d{5})\b/', $text, $match)) {
            $result['postal_code'] = $match[1];
            $text = str_replace($match[0], ' ', $text);
        }

        // ==================== EXTRACT PROVINCE ====================
        $provincePatterns = [
            'กรุงเทพมหานคร' => 'กรุงเทพฯ',
            'กรุงเทพฯ' => 'กรุงเทพฯ',
            'กรุงเทพ' => 'กรุงเทพฯ',
            'กทม\\.?' => 'กรุงเทพฯ',
        ];
        
        // Also match จ. or จังหวัด patterns
        if (preg_match('/(?:จ\\.?|จังหวัด)\s*([ก-๙]+)/u', $text, $match)) {
            $result['province'] = $match[1];
            $text = str_replace($match[0], ' ', $text);
        } else {
            foreach ($provincePatterns as $pattern => $normalized) {
                if (preg_match('/' . $pattern . '/ui', $text, $match)) {
                    $result['province'] = $normalized;
                    $text = str_replace($match[0], ' ', $text);
                    break;
                }
            }
        }

        // ==================== EXTRACT DISTRICT ====================
        if (preg_match('/(?:อ\\.?|อำเภอ|เขต)\s*([ก-๙]+)/u', $text, $match)) {
            $result['district'] = $match[1];
            $text = str_replace($match[0], ' ', $text);
        }

        // ==================== EXTRACT SUBDISTRICT ====================
        if (preg_match('/(?:ต\\.?|ตำบล|แขวง)\s*([ก-๙]+)/u', $text, $match)) {
            $result['subdistrict'] = $match[1];
            $text = str_replace($match[0], ' ', $text);
        }

        // ==================== EXTRACT NAME ====================
        // Look for Thai name patterns at start of text
        $text = trim(preg_replace('/\s+/', ' ', $text));
        
        // Pattern 1: คุณ XXX or นาย/นาง/นางสาว XXX
        if (preg_match('/^((?:คุณ|นาย|นาง|นางสาว|น\.ส\.|ดร\.|พ\.ญ\.|พ\.ท\.)?\s*[ก-๙a-zA-Z]+(?:\s+[ก-๙a-zA-Z]+)?)/u', $text, $match)) {
            $potentialName = trim($match[1]);
            // Validate it's likely a name (2+ chars, not address keywords)
            if (mb_strlen($potentialName, 'UTF-8') >= 2 && 
                !preg_match('/หมู่|ถนน|ซอย|บ้าน|เลขที่/u', $potentialName)) {
                $result['name'] = $potentialName;
                $text = trim(str_replace($match[0], '', $text));
            }
        }
        
        // Pattern 2: Look for name between newlines
        if (empty($result['name'])) {
            $lines = preg_split('/[\n\r]+/', $originalText);
            foreach ($lines as $line) {
                $line = trim($line);
                // First line that looks like a name (2+ Thai words, no numbers)
                if (preg_match('/^[ก-๙a-zA-Z\s\.]+$/u', $line) && 
                    mb_strlen($line, 'UTF-8') >= 4 &&
                    !preg_match('/\d/', $line) &&
                    !preg_match('/หมู่|ถนน|ซอย|บ้าน|อำเภอ|เขต|ตำบล|แขวง|จังหวัด/u', $line)) {
                    $result['name'] = $line;
                    break;
                }
            }
        }

        // ==================== REMAINING = ADDRESS ====================
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $text = preg_replace('/^[,\s]+|[,\s]+$/', '', $text);
        
        if (!empty($text)) {
            $result['address_line1'] = $text;
        }

        \Logger::info('[CheckoutService] Parsed address', [
            'original' => mb_substr($originalText, 0, 100),
            'parsed' => $result,
        ]);

        return $result;
    }

    /**
     * Save customer address to database
     */
    protected function saveCustomerAddress(array $addressData, array $context): ?int
    {
        try {
            $platformUserId = $context['platform_user_id'] ?? null;
            $platform = $context['platform'] ?? 'line';
            $channelId = $context['channel']['id'] ?? null;
            
            if (!$platformUserId) {
                \Logger::warning('[CheckoutService] Cannot save address - no platform_user_id');
                return null;
            }

            // Get tenant_id from channel (if column exists)
            // Note: tenant_id column may not exist in some deployments
            $tenantId = 'default';

            // Get customer_profile id (chatbot users are in customer_profiles, not users)
            $customer = $this->db->queryOne(
                "SELECT id FROM customer_profiles WHERE platform_user_id = ? AND platform = ? LIMIT 1",
                [$platformUserId, $platform]
            );
            // customer_id can be NULL now (FK was removed) - we use platform_user_id as primary lookup
            $customerId = $customer ? (int)$customer['id'] : null;

            // Prepare data with defaults for NOT NULL columns
            $recipientName = trim($addressData['name'] ?? '') ?: 'ลูกค้า';
            $phone = trim($addressData['phone'] ?? '') ?: '';
            $addressLine1 = trim($addressData['address_line1'] ?? '') ?: '';
            $subdistrict = trim($addressData['subdistrict'] ?? '') ?: '';
            $district = trim($addressData['district'] ?? '') ?: '-'; // NOT NULL
            $province = trim($addressData['province'] ?? '') ?: '-'; // NOT NULL  
            $postalCode = trim($addressData['postal_code'] ?? '') ?: '00000'; // NOT NULL

            // Insert address
            $this->db->execute(
                "INSERT INTO customer_addresses (
                    customer_id, tenant_id, platform, platform_user_id, address_type,
                    recipient_name, phone, address_line1, subdistrict, district, province, postal_code,
                    country, is_default, created_at
                ) VALUES (?, ?, ?, ?, 'shipping', ?, ?, ?, ?, ?, ?, ?, 'Thailand', 1, NOW())",
                [
                    $customerId,
                    $tenantId,
                    $platform,
                    $platformUserId,
                    $recipientName,
                    $phone,
                    $addressLine1,
                    $subdistrict,
                    $district,
                    $province,
                    $postalCode,
                ]
            );

            $addressId = $this->db->lastInsertId();
            
            \Logger::info('[CheckoutService] Address saved to customer_addresses', [
                'address_id' => $addressId,
                'customer_id' => $customerId,
                'platform_user_id' => $platformUserId,
                'recipient_name' => $recipientName,
                'phone' => $phone,
            ]);

            return (int)$addressId;
        } catch (\Exception $e) {
            \Logger::error('[CheckoutService] Failed to save address', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Detect payment method from user's text
     * NOW: Includes numeric selection (1, 2, 3)
     * 
     * @param string $text User's message
     * @return string|null Payment method: 'full', 'installment', 'deposit', or null
     */
    public function detectPaymentMethod(string $text): ?string
    {
        $textLower = mb_strtolower(trim($text), 'UTF-8');
        
        // Installment: ผ่อน, ออม, งวด, installment
        if (preg_match('/(ผ่อน|ออม|งวด|installment|แบ่งจ่าย|ยืนยัน.*ผ่อน)/ui', $text)) {
            return 'installment';
        }
        
        // Deposit: มัดจำ, จอง, deposit
        if (preg_match('/(มัดจำ|จอง|deposit|ยืนยัน.*มัดจำ)/ui', $text)) {
            return 'deposit';
        }
        
        // Full payment: เต็ม, โอน, สด, full, cash
        if (preg_match('/(เต็ม|โอน|สด|full|cash|ยืนยัน.*ชำระ|ยืนยันเลย|ชำระเต็ม)/ui', $text)) {
            return 'full';
        }

        // Numeric selection (1, 2, 3) - also support "1.โอนเต็ม" format from Quick Reply
        if (preg_match('/^1$|^หนึ่ง$|^1\.\s*โอน/u', $textLower)) return 'full';
        if (preg_match('/^2$|^สอง$|^2\.\s*ผ่อน/u', $textLower)) return 'installment';
        if (preg_match('/^3$|^สาม$|^3\.\s*มัดจำ/u', $textLower)) return 'deposit';
        
        // Just "ยืนยัน" or "ตกลง" alone = default to full payment
        if (preg_match('/^(ยืนยัน|ตกลง|ok|โอเค|เอา|รับ|ได้)$/ui', trim($text))) {
            return 'full';
        }
        
        return null;
    }

    /**
     * Detect if user is requesting to CHANGE payment method
     * Used during checkout flow when user says "จองก่อนได้ไหม" or "เปลี่ยนเป็นมัดจำ"
     * 
     * ⚠️ Only triggers when ALREADY in checkout flow (handleDeliverySelection calls this)
     * Pattern is intentionally narrow to avoid false positives
     * 
     * @param string $text User's message
     * @return string|null New payment method: 'full', 'installment', 'deposit', or null if not a change request
     */
    protected function detectPaymentMethodChangeRequest(string $text): ?string
    {
        // ✅ Pattern must be SHORT and specific (max 20 chars between keywords)
        // This avoids matching unrelated sentences like "จองโต๊ะอาหารได้ไหม"
        
        // Pattern: "จอง/มัดจำ + ก่อน/ได้ไหม/ดีกว่า/แทน" (within 15 chars)
        if (preg_match('/(จอง|มัดจำ).{0,15}(ก่อน|ได้ไหม|ได้มั้ย|ดีกว่า|แทน)/ui', $text)) {
            return 'deposit';
        }
        
        // Pattern: "ผ่อน/ออม + ก่อน/ได้ไหม/ดีกว่า/แทน" (within 15 chars)
        if (preg_match('/(ผ่อน|ออม).{0,15}(ก่อน|ได้ไหม|ได้มั้ย|ดีกว่า|แทน)/ui', $text)) {
            return 'installment';
        }
        
        // Pattern: "โอนเต็ม/จ่ายเต็ม + ได้ไหม/ดีกว่า/แทน" (within 10 chars)
        if (preg_match('/(โอนเต็ม|จ่ายเต็ม).{0,10}(ได้|ไหม|ดีกว่า|แทน)/ui', $text)) {
            return 'full';
        }
        
        // Pattern: "เปลี่ยนเป็น/ไป + payment type" (explicit change request)
        if (preg_match('/เปลี่ยน.{0,5}(เป็น|ไป).{0,5}(จอง|มัดจำ)/ui', $text)) {
            return 'deposit';
        }
        if (preg_match('/เปลี่ยน.{0,5}(เป็น|ไป).{0,5}(ผ่อน|ออม)/ui', $text)) {
            return 'installment';
        }
        if (preg_match('/เปลี่ยน.{0,5}(เป็น|ไป).{0,5}(โอน|เต็ม|สด)/ui', $text)) {
            return 'full';
        }
        
        // ✅ Short standalone keywords when in checkout context
        // Only match very short messages (< 20 chars) to avoid false positives
        $textLen = mb_strlen($text, 'UTF-8');
        if ($textLen <= 20) {
            if (preg_match('/^(ขอ)?(จอง|มัดจำ)(ก่อน|ได้|นะ|ค่ะ|ครับ)?$/ui', trim($text))) {
                return 'deposit';
            }
            if (preg_match('/^(ขอ)?(ผ่อน|ออม)(ก่อน|ได้|นะ|ค่ะ|ครับ)?$/ui', trim($text))) {
                return 'installment';
            }
        }
        
        return null;
    }

    /**
     * Show payment changed confirmation and continue asking for delivery
     * 
     * @param array $state Checkout state
     * @param array $config Bot config
     * @param array $context Request context
     * @return array Reply with updated payment info and delivery question
     */
    protected function showPaymentChangedAndAskDelivery(array $state, array $config, array $context): array
    {
        $product = $state['product'] ?? [];
        $paymentType = $state['payment_type'] ?? 'full';
        $productName = $product['name'] ?? $product['title'] ?? 'สินค้า';
        $productPrice = (float)($product['sale_price'] ?? $product['price'] ?? 0);

        $paymentLabels = [
            'full' => 'โอนเต็มจำนวน',
            'installment' => 'ผ่อน 3 งวด',
            'deposit' => 'มัดจำ 10%',
        ];
        $paymentLabel = $paymentLabels[$paymentType] ?? $paymentType;

        $reply = "ได้ค่ะ เปลี่ยนเป็น **{$paymentLabel}** แล้วค่ะ ✅\n\n";
        $reply .= "📦 สินค้า: {$productName}\n";
        $reply .= "💰 ราคา: " . number_format($productPrice) . " บาท\n";

        if ($paymentType === 'deposit') {
            $depositPercent = (float)($config['policies']['deposit']['percent'] ?? 10);
            $depositAmount = round($productPrice * ($depositPercent / 100));
            $holdDays = (int)($config['policies']['deposit']['hold_days'] ?? 14);
            $reply .= "🎯 ยอดมัดจำ: " . number_format($depositAmount) . " บาท ({$depositPercent}%)\n";
            $reply .= "📅 เก็บสินค้าให้: {$holdDays} วัน\n";
        } elseif ($paymentType === 'installment') {
            $feePercent = (float)($config['policies']['installment']['service_fee_percent'] ?? 3);
            $fee = round($productPrice * ($feePercent / 100));
            $periods = (int)($config['policies']['installment']['periods'] ?? 3);
            $perPeriod = ceil($productPrice / $periods);
            $firstPayment = $perPeriod + $fee;
            $reply .= "📝 งวดแรก: " . number_format($firstPayment) . " บาท (รวมค่าธรรมเนียม {$feePercent}%)\n";
        } else {
            $reply .= "💵 ยอดชำระ: " . number_format($productPrice) . " บาท\n";
        }

        $reply .= "\n📦 สะดวกรับสินค้าช่องทางไหนคะ?\n";
        $reply .= "🏢 พิมพ์ 1 = รับที่ร้าน\n";
        $reply .= "📦 พิมพ์ 2 = จัดส่ง EMS";

        return [
            'reply' => $reply,
            'actions' => [
                ['type' => 'quick_reply', 'items' => [
                    ['label' => '🏢 รับที่ร้าน', 'text' => '1.รับที่ร้าน'],
                    ['label' => '📦 ส่ง EMS', 'text' => '2.ส่ง EMS'],
                    ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก']
                ]]
            ]
        ];
    }

    // ==================== CHECKOUT CONFIRMATION (No Auto Order) ====================

    /**
     * Process checkout confirmation - Updates case slots, does NOT create order
     * Order will be created by staff in admin/cases.php
     * 
     * @version 2.1 - Removed auto order creation per business requirement
     */
    protected function processOrder(array $state, array $config, array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? '';
        $channelId = $context['channel']['id'] ?? 0;
        $product = $state['product'] ?? [];
        $paymentType = $state['payment_type'] ?? 'full';
        $deliveryMethod = $state['delivery_method'] ?? 'pickup';
        $shippingFee = (float)($state['shipping_fee'] ?? 0);
        $shippingAddress = $state['shipping_address'] ?? [];

        try {
            // Calculate amounts (for display only)
            $productPrice = (float)($product['sale_price'] ?? $product['price'] ?? 0);
            $totalAmount = $productPrice + $shippingFee;

            // ✅ Update case.slots with checkout info (for staff to use in cases.php)
            $this->syncCheckoutToCase($state, $config, $context);

            // Update checkout state to confirmed (but no order)
            $state['step'] = self::STEP_CONFIRMED;
            $state['order_status'] = 'pending_staff_review'; // Staff needs to create order
            $this->saveCheckoutState($platformUserId, $channelId, $state);

            // Build confirmation message for customer
            $reply = $this->buildCheckoutConfirmationMessage($product, $paymentType, $totalAmount, $deliveryMethod, $shippingAddress, $config);

            \Logger::info("[CheckoutService] Checkout confirmed (pending staff order)", [
                'platform_user_id' => $platformUserId,
                'payment_type' => $paymentType,
                'delivery_method' => $deliveryMethod,
                'total_amount' => $totalAmount,
            ]);

            return [
                'reply' => $reply,
                'order_created' => false, // ❌ No order created - staff will create
                'handoff_to_admin' => true, // ✅ Flag for admin to handle
                'payment_type' => $paymentType,
                'product' => $product,
            ];

        } catch (\Exception $e) {
            \Logger::error("[CheckoutService] Failed to confirm checkout", ['error' => $e->getMessage()]);
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * Sync checkout data to case.slots for staff to use in cases.php
     * This ensures payment_type is available when staff creates order
     */
    protected function syncCheckoutToCase(array $state, array $config, array $context): void
    {
        try {
            require_once __DIR__ . '/../CaseEngine.php';
            
            $caseEngine = new \CaseEngine($config, $context);
            $caseId = $caseEngine->getActiveCaseId();
            
            if ($caseId) {
                $product = $state['product'] ?? [];
                
                // Prepare checkout slots for case
                $checkoutSlots = [
                    'payment_method' => $state['payment_type'] ?? 'full',
                    'payment_type' => $state['payment_type'] ?? 'full', // Alias
                    'delivery_method' => $state['delivery_method'] ?? 'pickup',
                    'shipping_method' => $state['delivery_method'] ?? 'pickup', // Alias
                    'shipping_fee' => $state['shipping_fee'] ?? 0,
                    'shipping_address' => $state['shipping_address'] ?? [],
                    'checkout_step' => 'confirmed',
                    'order_status' => 'pending_staff_review',
                    // Product info (if not already in slots)
                    'product_code' => $product['code'] ?? $product['product_code'] ?? null,
                    'product_name' => $product['name'] ?? $product['title'] ?? null,
                    'product_price' => $product['sale_price'] ?? $product['price'] ?? null,
                    'confirmed_at' => date('Y-m-d H:i:s'),
                ];
                
                $caseEngine->updateCaseSlots($caseId, $checkoutSlots);
                
                \Logger::info("[CheckoutService] Synced checkout to case", [
                    'case_id' => $caseId,
                    'payment_type' => $checkoutSlots['payment_type'],
                    'delivery_method' => $checkoutSlots['delivery_method'],
                ]);
            }
        } catch (\Exception $e) {
            // Don't fail the checkout if case sync fails
            \Logger::warning("[CheckoutService] Failed to sync checkout to case", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Sync payment type to case immediately (when user selects payment method)
     * This ensures cases.php has the payment_type even if user doesn't complete checkout
     */
    protected function syncPaymentTypeToCase(string $paymentType, array $config, array $context): void
    {
        try {
            require_once __DIR__ . '/../CaseEngine.php';
            
            $caseEngine = new \CaseEngine($config, $context);
            $caseId = $caseEngine->getActiveCaseId();
            
            if ($caseId) {
                $caseEngine->updateCaseSlots($caseId, [
                    'payment_method' => $paymentType,
                    'payment_type' => $paymentType, // Alias
                    'payment_selected_at' => date('Y-m-d H:i:s'),
                ]);
                
                \Logger::info("[CheckoutService] Synced payment type to case", [
                    'case_id' => $caseId,
                    'payment_type' => $paymentType,
                ]);
            }
        } catch (\Exception $e) {
            \Logger::warning("[CheckoutService] Failed to sync payment type to case", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Build confirmation message (no order number - just confirmation of intent)
     */
    protected function buildCheckoutConfirmationMessage(
        array $product,
        string $paymentType,
        float $totalAmount,
        string $deliveryMethod,
        array $shippingAddress,
        array $config
    ): string {
        $productName = $product['name'] ?? $product['title'] ?? 'สินค้า';
        $productCode = $product['code'] ?? $product['product_code'] ?? '';
        
        $paymentLabel = match($paymentType) {
            'installment' => 'ผ่อนชำระ',
            'deposit' => 'มัดจำ',
            default => 'ชำระเต็มจำนวน',
        };
        
        $deliveryLabel = match($deliveryMethod) {
            'ems' => 'ส่ง EMS',
            'grab' => 'ส่ง Grab',
            default => 'รับที่ร้าน',
        };
        
        $lines = [];
        $lines[] = "✅ บันทึกข้อมูลเรียบร้อยแล้วค่ะ";
        $lines[] = "";
        $lines[] = "📦 สินค้า: {$productCode} - {$productName}";
        
        // Show payment details based on type
        if ($paymentType === 'deposit') {
            $depositConfig = $config['policies']['deposit'] ?? [];
            $depositPercent = (float)($depositConfig['percent'] ?? 10);
            $depositAmount = round($totalAmount * ($depositPercent / 100));
            $remainingAmount = $totalAmount - $depositAmount;
            $holdDays = (int)($depositConfig['hold_days'] ?? 14);
            
            $lines[] = "💰 ยอดมัดจำ: ฿" . number_format($depositAmount, 0) . " ({$depositPercent}%)";
            $lines[] = "💰 ยอดคงเหลือ: ฿" . number_format($remainingAmount, 0);
            $lines[] = "📅 เก็บสินค้าไว้ให้: {$holdDays} วัน";
        } elseif ($paymentType === 'installment') {
            $installmentConfig = $config['policies']['installment'] ?? [];
            $periods = (int)($installmentConfig['periods'] ?? 3);
            $feePercent = (float)($installmentConfig['service_fee_percent'] ?? 3);
            $fee = round($totalAmount * ($feePercent / 100));
            $firstPayment = floor($totalAmount / $periods) + $fee;
            
            $lines[] = "💰 ยอดรวม: ฿" . number_format($totalAmount, 0);
            $lines[] = "📅 ผ่อน {$periods} งวด (งวดแรก ฿" . number_format($firstPayment, 0) . ")";
        } else {
            $lines[] = "💰 ยอดรวม: ฿" . number_format($totalAmount, 0);
        }
        
        $lines[] = "💳 วิธีชำระ: {$paymentLabel}";
        $lines[] = "🚚 รับสินค้า: {$deliveryLabel}";
        
        if (!empty($shippingAddress)) {
            $addr = $shippingAddress;
            $addrLine = $addr['name'] ?? '';
            if (!empty($addr['phone'])) $addrLine .= " ({$addr['phone']})";
            if ($addrLine) $lines[] = "📍 จัดส่งถึง: " . $addrLine;
        }
        
        $lines[] = "";
        $lines[] = "🙏 รอเจ้าหน้าที่ติดต่อกลับเพื่อยืนยันออเดอร์และแจ้งรายละเอียดการชำระเงินนะคะ";
        
        return implode("\n", $lines);
    }

    /**
     * @deprecated - Order creation moved to admin/cases.php
     * Keeping for reference only
     */
    protected function closeCaseAfterOrder_DEPRECATED(int $orderId, array $config, array $context): void
    {
        // REMOVED - Staff will close case after creating order in cases.php
    }

    /**
     * Create installment contract and payment schedule with DYNAMIC calculation from config
     * 
     * NOW USES: installment_contracts + installment_payments (new unified system)
     * 
     * Logic from config:
     * - periods: Number of installments
     * - service_fee_percent: Fee added to 1st payment
     * - max_days: Total duration (last payment due)
     * 
     * Payment Schedule:
     * - Period 1: Base + Fee, Due TODAY
     * - Period 2: Base, Due +30 days
     * - Period N: Base + Remainder, Due at max_days
     */
    protected function createInstallmentSchedule(int $orderId, float $price, array $config, array $context = []): void
    {
        // Get config values (NO HARDCODE!)
        $installmentConfig = $config['policies']['installment'] ?? [];
        $periods = (int)($installmentConfig['periods'] ?? 3);
        $feePercent = (float)($installmentConfig['service_fee_percent'] ?? 3);
        $maxDays = (int)($installmentConfig['max_days'] ?? 60);

        // Calculate amounts
        $fee = round($price * ($feePercent / 100));
        $financedAmount = $price + $fee;
        $baseAmount = floor($financedAmount / $periods);
        $remainder = $financedAmount - ($baseAmount * $periods);

        // Get order info for contract
        $order = $this->db->queryOne("SELECT * FROM orders WHERE id = ?", [$orderId]);
        if (!$order) {
            \Logger::error("[CheckoutService] Order not found for installment: {$orderId}");
            return;
        }

        // Generate contract number
        $contractNo = 'INS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

        // Calculate due dates
        $today = new \DateTime();
        $firstDueDate = $today->format('Y-m-d');

        // Get customer/platform info from context
        $customerId = $order['customer_id'] ?? $order['user_id'] ?? 0;
        $platformUserId = $context['platform_user_id'] ?? $order['platform_user_id'] ?? null;
        $platform = $context['platform'] ?? $order['platform'] ?? 'facebook';
        $channelId = $context['channel']['id'] ?? $order['channel_id'] ?? null;
        $customerName = $context['customer_name'] ?? $order['customer_name'] ?? '';
        $productName = $order['product_name'] ?? $order['items'] ?? 'สินค้า';

        // 1. Create installment_contracts record
        $this->db->execute(
            "INSERT INTO installment_contracts 
             (contract_no, customer_id, order_id, tenant_id, product_name, product_price,
              customer_name, platform, platform_user_id, channel_id, external_user_id,
              down_payment, financed_amount, total_periods, amount_per_period,
              interest_rate, interest_type, total_interest,
              status, start_date, next_due_date, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW(), NOW())",
            [
                $contractNo,
                $customerId,
                $orderId,
                $order['tenant_id'] ?? 'default',
                $productName,
                $price,
                $customerName,
                $platform,
                $platformUserId,
                $channelId,
                $platformUserId, // external_user_id
                0, // down_payment
                $financedAmount,
                $periods,
                $baseAmount,
                $feePercent,     // interest_rate = fee percent (e.g., 3)
                'flat',          // interest_type = one-time fee
                $fee,            // total_interest = fee amount
                $firstDueDate,
                $firstDueDate
            ]
        );

        $contractId = $this->db->lastInsertId();

        // 2. Create installment_payments for each period
        for ($i = 1; $i <= $periods; $i++) {
            if ($i === 1) {
                // Period 1: Base + remainder (to include rounding), Due Today
                $amount = $baseAmount + $remainder;
                $dueDateStr = $firstDueDate;
            } else {
                // Other Periods: Base, Due +30 days each from period 1
                $amount = $baseAmount;
                $dueDate = (clone $today)->modify('+' . (30 * ($i - 1)) . ' days');
                $dueDateStr = $dueDate->format('Y-m-d');
            }

            // Insert payment schedule
            $this->db->execute(
                "INSERT INTO installment_payments 
                 (contract_id, period_number, amount, paid_amount, due_date, status, created_at, updated_at)
                 VALUES (?, ?, ?, 0, ?, 'pending', NOW(), NOW())",
                [$contractId, $i, $amount, $dueDateStr]
            );
        }

        \Logger::info("[CheckoutService] Installment contract created", [
            'contract_id' => $contractId,
            'contract_no' => $contractNo,
            'order_id' => $orderId,
            'periods' => $periods,
            'financed_amount' => $financedAmount,
            'base_amount' => $baseAmount,
        ]);
    }

    /**
     * Set payment type for checkout
     */
    public function setPaymentType(string $paymentType, array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        $validTypes = ['full', 'installment', 'deposit', 'savings'];
        if (!in_array($paymentType, $validTypes)) {
            $paymentType = 'full';
        }

        $state = $this->getCheckoutState($platformUserId, $channelId);
        if (!$state) {
            return ['ok' => false, 'error' => 'no_checkout_state'];
        }

        $state['payment_type'] = $paymentType;
        $this->saveCheckoutState($platformUserId, $channelId, $state);

        return ['ok' => true, 'payment_type' => $paymentType];
    }

    // ==================== STATE MANAGEMENT ====================

    /**
     * Get checkout state
     */
    public function getCheckoutState(string $platformUserId, int $channelId): ?array
    {
        return $this->chatService->getQuickState(self::STATE_KEY, $platformUserId, $channelId);
    }

    /**
     * Save checkout state
     */
    public function saveCheckoutState(string $platformUserId, int $channelId, array $state): bool
    {
        return $this->chatService->setQuickState(
            self::STATE_KEY, 
            $state, 
            $platformUserId, 
            $channelId, 
            self::STATE_TTL
        );
    }

    /**
     * Clear checkout state
     */
    public function clearCheckoutState(string $platformUserId, int $channelId): bool
    {
        return $this->chatService->deleteQuickState(self::STATE_KEY, $platformUserId, $channelId);
    }

    /**
     * Check if user is in checkout flow
     */
    public function isInCheckout(string $platformUserId, int $channelId): bool
    {
        $state = $this->getCheckoutState($platformUserId, $channelId);
        return !empty($state);
    }

    // ==================== MESSAGE BUILDERS (Config-Driven) ====================

    /**
     * Build payment options message (Config-Driven)
     * NOW: Shows dynamic installment/deposit amounts from config
     */
    protected function buildPaymentOptionsMessage(array $product, array $config): array
    {
        $productName = $product['name'] ?? $product['title'] ?? 'สินค้า';
        $productCode = $product['code'] ?? $product['product_code'] ?? '';
        $price = (float)($product['sale_price'] ?? $product['price'] ?? 0);
        $priceFormatted = number_format($price, 0);
        $image = $product['image'] ?? '';

        // Get config values (NO HARDCODE!)
        $installmentConfig = $config['policies']['installment'] ?? [];
        $depositConfig = $config['policies']['deposit'] ?? [];

        $periods = (int)($installmentConfig['periods'] ?? 3);
        $feePercent = (float)($installmentConfig['service_fee_percent'] ?? 3);
        $depositPercent = (float)($depositConfig['percent'] ?? 10);

        // Calculate preview amounts
        $fee = round($price * ($feePercent / 100));
        $depositAmount = round($price * ($depositPercent / 100));
        $firstInstallment = floor($price / $periods) + $fee;

        // Build text with config-driven values
        $text = "✅ ยืนยันรายการสั่งซื้อ\n\n";
        $text .= "📦 {$productCode}\n";
        $text .= "📝 {$productName}\n";
        $text .= "💰 ราคา: ฿{$priceFormatted}\n\n";
        $text .= "เลือกวิธีชำระเงินค่ะ:\n";
        $text .= "1️⃣ โอนเต็มจำนวน ฿{$priceFormatted}\n";
        $text .= "2️⃣ ผ่อน {$periods} งวด (งวดแรก ฿" . number_format($firstInstallment, 0) . " รวมค่าดำเนินการ {$feePercent}%)\n";
        $text .= "3️⃣ มัดจำ ฿" . number_format($depositAmount, 0) . " ({$depositPercent}%)\n\n";
        $text .= "พิมพ์ตัวเลข 1-3 หรือพิมพ์ตอบได้เลยค่ะ 😊";

        // Build quick replies - text must be descriptive for LINE (shows what user typed)
        $quickReplyItems = [
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '💳 ชำระเต็ม', 'text' => '1.โอนเต็ม']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '📅 ผ่อนชำระ', 'text' => '2.ผ่อน']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '💵 มัดจำ', 'text' => '3.มัดจำ']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '❌ ยกเลิก', 'text' => 'ยกเลิก']],
        ];

        return [
            'type' => 'text',
            'text' => $text,
            // Note: Image removed to avoid duplicate - already shown in product lookup
            'product' => $product,
            'quickReply' => ['items' => $quickReplyItems],
        ];
    }

    /**
     * Build message for pre-selected payment type (e.g., from "จอง" intent)
     * Skips payment selection and goes directly to delivery question
     */
    protected function buildPreSelectedPaymentMessage(array $product, array $config, string $paymentType): array
    {
        $productName = $product['name'] ?? $product['title'] ?? 'สินค้า';
        $productCode = $product['code'] ?? $product['product_code'] ?? '';
        $price = (float)($product['sale_price'] ?? $product['price'] ?? 0);
        $priceFormatted = number_format($price, 0);

        // Get config values
        $depositConfig = $config['policies']['deposit'] ?? [];
        $installmentConfig = $config['policies']['installment'] ?? [];
        
        $text = "✅ รับทราบค่ะ\n\n";
        $text .= "📦 {$productCode}\n";
        $text .= "📝 {$productName}\n";
        $text .= "💰 ราคา: ฿{$priceFormatted}\n\n";

        if ($paymentType === 'deposit') {
            $depositPercent = (float)($depositConfig['percent'] ?? 10);
            $holdDays = (int)($depositConfig['hold_days'] ?? 14);
            $depositAmount = round($price * ($depositPercent / 100));
            
            $text .= "🎯 **มัดจำ {$depositPercent}%**\n";
            $text .= "💵 ยอดมัดจำ: ฿" . number_format($depositAmount, 0) . "\n";
            $text .= "📅 เก็บสินค้าให้: {$holdDays} วัน\n\n";
        } elseif ($paymentType === 'installment') {
            $periods = (int)($installmentConfig['periods'] ?? 3);
            $feePercent = (float)($installmentConfig['service_fee_percent'] ?? 3);
            $fee = round($price * ($feePercent / 100));
            $firstInstallment = floor($price / $periods) + $fee;
            
            $text .= "📅 **ผ่อน {$periods} งวด**\n";
            $text .= "💵 งวดแรก: ฿" . number_format($firstInstallment, 0) . " (รวมค่าธรรมเนียม {$feePercent}%)\n\n";
        } else {
            $text .= "💵 **ชำระเต็มจำนวน**\n";
            $text .= "💰 ยอดชำระ: ฿{$priceFormatted}\n\n";
        }

        $text .= "📦 สะดวกรับสินค้าช่องทางไหนคะ?\n";
        $text .= "🏢 พิมพ์ 1 = รับที่ร้าน\n";
        $text .= "📦 พิมพ์ 2 = จัดส่ง EMS";

        // Quick reply text must be descriptive for LINE
        $quickReplyItems = [
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '🏢 รับที่ร้าน', 'text' => '1.รับที่ร้าน']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '📦 ส่ง EMS', 'text' => '2.ส่ง EMS']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '❌ ยกเลิก', 'text' => 'ยกเลิก']],
        ];

        return [
            'type' => 'text',
            'text' => $text,
            'product' => $product,
            'quickReply' => ['items' => $quickReplyItems],
        ];
    }

    /**
     * Build payment summary with dates and ask for delivery method (Config-Driven)
     * NOW: Returns array with text + quick reply actions
     */
    protected function buildPaymentSummaryAndAskDelivery(array $product, string $paymentType, array $config): array
    {
        $productName = $product['name'] ?? $product['title'] ?? 'สินค้า';
        $productCode = $product['code'] ?? $product['product_code'] ?? '';
        $price = (float)($product['sale_price'] ?? $product['price'] ?? 0);

        // Get config
        $installmentConfig = $config['policies']['installment'] ?? [];
        $depositConfig = $config['policies']['deposit'] ?? [];
        $storeConfig = $config['store'] ?? [];

        $periods = (int)($installmentConfig['periods'] ?? 3);
        $feePercent = (float)($installmentConfig['service_fee_percent'] ?? 3);
        $maxDays = (int)($installmentConfig['max_days'] ?? 60);
        $depositPercent = (float)($depositConfig['percent'] ?? 10);

        $paymentSummary = "";

        switch ($paymentType) {
            case 'installment':
                $fee = round($price * ($feePercent / 100));
                $baseAmount = floor($price / $periods);
                $remainder = $price - ($baseAmount * $periods);

                $paymentSummary = "📝 ตารางผ่อนชำระ (รวมค่าดำเนินการ {$feePercent}%)\n\n";

                $today = new \DateTime();
                for ($i = 1; $i <= $periods; $i++) {
                    if ($i === 1) {
                        $amount = $baseAmount + $fee;
                        $dueDate = $today->format('d/m/Y');
                    } elseif ($i === $periods) {
                        $amount = $baseAmount + $remainder;
                        $dueDate = (clone $today)->modify("+{$maxDays} days")->format('d/m/Y');
                    } else {
                        $amount = $baseAmount;
                        $dueDate = (clone $today)->modify('+' . (30 * ($i - 1)) . ' days')->format('d/m/Y');
                    }
                    $paymentSummary .= "งวดที่ {$i}: ฿" . number_format($amount, 0) . " (กำหนด {$dueDate})\n";
                }
                break;

            case 'deposit':
                $depositAmount = round($price * ($depositPercent / 100));
                $remaining = $price - $depositAmount;
                $holdDays = (int)($depositConfig['hold_days'] ?? 14);

                $paymentSummary = "📝 มัดจำจองสินค้า\n\n";
                $paymentSummary .= "💰 ยอดมัดจำ: ฿" . number_format($depositAmount, 0) . " ({$depositPercent}%)\n";
                $paymentSummary .= "💰 ยอดคงเหลือ: ฿" . number_format($remaining, 0) . "\n";
                $paymentSummary .= "📅 เก็บสินค้าไว้ให้ {$holdDays} วัน\n";
                break;

            default: // full
                $paymentSummary = "📝 ชำระเต็มจำนวน\n\n";
                $paymentSummary .= "💰 ยอดชำระ: ฿" . number_format($price, 0) . "\n";
        }

        // Get store address from config
        $storeAddress = $storeConfig['location'] ?? $storeConfig['address'] ?? 'ร้านค้า';
        $emsFee = $this->getShippingFee('ems', $config);

        // Build combined message (no ||SPLIT|| for cleaner UX)
        $text = "✅ เลือก{$this->getPaymentLabel($paymentType)}\n\n";
        $text .= $paymentSummary;
        $text .= "\n📦 **รับสินค้า:** กรุณาเลือกช่องทางค่ะ";

        // Quick Reply buttons for delivery - text must be descriptive for LINE
        $quickReplyItems = [
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '🏪 รับที่ร้าน', 'text' => '1.รับที่ร้าน']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '📦 EMS +฿' . number_format($emsFee, 0), 'text' => '2.ส่ง EMS']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '🛵 Grab', 'text' => '3.ส่ง Grab']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '❌ ยกเลิก', 'text' => 'ยกเลิก']],
        ];

        return [
            'text' => $text,
            'actions' => [
                ['type' => 'quick_reply', 'items' => [
                    ['label' => '🏪 รับที่ร้าน', 'text' => '1.รับที่ร้าน'],
                    ['label' => '📦 EMS +฿' . number_format($emsFee, 0), 'text' => '2.ส่ง EMS'],
                    ['label' => '🛵 Grab', 'text' => '3.ส่ง Grab'],
                ]]
            ],
            'quickReply' => ['items' => $quickReplyItems],
        ];
    }

    /**
     * Get payment type label in Thai
     */
    protected function getPaymentLabel(string $paymentType): string
    {
        return match ($paymentType) {
            'installment' => 'ผ่อนชำระ',
            'deposit' => 'มัดจำ',
            default => 'ชำระเต็มจำนวน',
        };
    }

    /**
     * Build order confirmation message (Config-Driven)
     * NOW: Includes payment type, delivery, address summary
     */
    protected function buildOrderConfirmationMessage(
        string $orderNo,
        array $product,
        string $paymentType,
        float $totalAmount,
        string $deliveryMethod,
        array $shippingAddress,
        array $config
    ): string {
        $productName = $product['name'] ?? $product['title'] ?? 'สินค้า';
        $productCode = $product['code'] ?? $product['product_code'] ?? '';

        $paymentLabel = match ($paymentType) {
            'installment' => 'ผ่อนชำระ',
            'deposit' => 'มัดจำ',
            default => 'ชำระเต็มจำนวน',
        };

        $deliveryLabel = match ($deliveryMethod) {
            'pickup' => 'รับที่ร้าน',
            'ems' => 'ส่ง EMS',
            'grab' => 'ส่ง Grab',
            default => $deliveryMethod,
        };

        $reply = "🎉 ยืนยันออเดอร์สำเร็จ!\n\n";
        $reply .= "📋 เลขที่: #{$orderNo}\n";
        $reply .= "📦 สินค้า: {$productCode} - {$productName}\n";
        $reply .= "💰 ยอดรวม: ฿" . number_format($totalAmount, 0) . "\n";
        $reply .= "💳 วิธีชำระ: {$paymentLabel}\n";
        $reply .= "🚚 รับสินค้า: {$deliveryLabel}\n";

        if (!empty($shippingAddress['name']) || !empty($shippingAddress['address_line1'])) {
            $addrName = $shippingAddress['name'] ?? '';
            $addrPhone = $shippingAddress['phone'] ?? '';
            $addrLine = $shippingAddress['address_line1'] ?? '';
            $addrDistrict = $shippingAddress['district'] ?? '';
            $addrProvince = $shippingAddress['province'] ?? '';
            $addrPostal = $shippingAddress['postal_code'] ?? '';
            
            $reply .= "📍 ส่งถึง: {$addrName} {$addrPhone}\n";
            $reply .= "   {$addrLine} {$addrDistrict} {$addrProvince} {$addrPostal}\n";
        }

        $reply .= "\n\n";

        // Get payment info from config
        $paymentInfo = $config['payment_info'] ?? [];
        $bankName = $paymentInfo['bank_name'] ?? '';
        $accountNo = $paymentInfo['account_no'] ?? '';
        $accountName = $paymentInfo['account_name'] ?? '';
        $promptpay = $paymentInfo['promptpay'] ?? null;

        if ($bankName && $accountNo) {
            $reply .= "📍 ช่องทางชำระเงิน:\n";
            $reply .= "🏦 {$bankName}\n";
            $reply .= "เลขบัญชี: {$accountNo}\n";
            $reply .= "ชื่อบัญชี: {$accountName}\n";
            if ($promptpay) {
                $reply .= "พร้อมเพย์: {$promptpay}\n";
            }
            $reply .= "\n📸 หลังโอนแล้ว ส่งสลิปมาได้เลยค่ะ 🙏";
        } else {
            $reply .= "รอระบบส่งเลขบัญชีให้ทางแชทค่ะ 🙏\n";
            $reply .= "หลังโอนแล้ว ส่งสลิปมาได้เลยนะคะ 📸";
        }

        return $reply;
    }

    // Legacy method for backward compatibility
    protected function buildConfirmationMessage(array $product, array $config): array
    {
        return $this->buildPaymentOptionsMessage($product, $config);
    }

    // ==================== HELPERS ====================

    /**
     * Get or create customer from context
     * Note: customer_profiles uses platform + platform_user_id as unique key
     */
    protected function getOrCreateCustomer(array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;
        $displayName = $context['display_name'] ?? $context['profile']['displayName'] ?? 'ลูกค้า';
        $tenantId = $context['channel']['tenant_id'] ?? $context['tenant_id'] ?? 'default';
        $platform = $context['platform'] ?? $context['channel']['platform'] ?? 'line';

        if (!$platformUserId || !$channelId) {
            return ['id' => null];
        }

        try {
            // Check existing customer profile
            $sql = "SELECT * FROM customer_profiles
                    WHERE platform = ? 
                    AND platform_user_id = ?
                    LIMIT 1";
            
            $existing = $this->db->queryOne($sql, [$platform, $platformUserId]);
            
            if ($existing && $existing['id']) {
                return $existing;
            }

            // Create new customer profile
            $this->db->execute(
                "INSERT INTO customer_profiles 
                 (tenant_id, platform, platform_user_id, display_name, created_at) 
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), last_active_at = NOW()",
                [$tenantId, $platform, $platformUserId, $displayName]
            );
            
            // Get the inserted/updated record
            $customer = $this->db->queryOne($sql, [$platform, $platformUserId]);

            return $customer ?: ['id' => $this->db->lastInsertId(), 'display_name' => $displayName];
        } catch (\Exception $e) {
            \Logger::warning("[CheckoutService] Failed to create customer", ['error' => $e->getMessage()]);
            return ['id' => null];
        }
    }

    /**
     * Generate order number
     */
    protected function generateOrderNo(int $channelId): string
    {
        $prefix = 'ORD';
        $date = date('Ymd');
        $random = strtoupper(substr(uniqid(), -4));
        return "{$prefix}-{$date}-{$random}";
    }

    /**
     * Get payment options information (for FAQ/inquiry)
     * This is separate from checkout flow - just shows info
     */
    public function getPaymentOptionsInfo(array $config, ?array $checkoutState): string
    {
        $reply = "💳 **วิธีการชำระเงิน**\n\n";
        $reply .= "1️⃣ **ชำระเต็มจำนวน** - โอนครั้งเดียวจบ\n";
        $reply .= "2️⃣ **ผ่อนชำระ** - แบ่งจ่ายสูงสุด 10 งวด\n";
        $reply .= "3️⃣ **มัดจำ** - วางมัดจำก่อน 30%\n";
        $reply .= "4️⃣ **ออมทอง** - ออมสะสมทีละนิด\n";

        // If there's an active checkout, show calculated amounts
        if (!empty($checkoutState['product'])) {
            $product = $checkoutState['product'];
            $price = (float)($product['price'] ?? $product['sale_price'] ?? 0);
            
            if ($price > 0) {
                // Get installment/deposit config
                $installmentCfg = $config['installment'] ?? [];
                $depositCfg = $config['deposit'] ?? [];
                
                $maxPeriods = (int)($installmentCfg['max_periods'] ?? 10);
                $depositPercent = (float)($depositCfg['percent'] ?? 30) / 100;
                
                $installmentAmount = ceil($price / $maxPeriods);
                $depositAmount = ceil($price * $depositPercent);
                
                $reply .= "\n💰 **สินค้าที่เลือก:**\n";
                $reply .= "• ราคาเต็ม: ฿" . number_format($price, 0) . "\n";
                $reply .= "• ผ่อน ~{$maxPeriods} งวด: ฿" . number_format($installmentAmount, 0) . "/งวด\n";
                $reply .= "• มัดจำ " . (int)($depositPercent * 100) . "%: ฿" . number_format($depositAmount, 0) . "\n";
            }
            
            $reply .= "\n✨ พิมพ์ตัวเลข 1-4 เพื่อเลือกวิธีชำระค่ะ";
        } else {
            $reply .= "\n📌 เลือกสินค้าก่อนนะคะ แล้วค่อยเลือกวิธีชำระ";
        }

        return $reply;
    }

    /**
     * Create error result
     */
    protected function errorResult(string $error): array
    {
        return [
            'ok' => false,
            'reply' => 'ขออภัยค่ะ เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง 🙏',
            'error' => $error,
        ];
    }
}
