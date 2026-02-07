<?php
/**
 * RouterV4Handler - Refactored message router using service-based architecture
 * 
 * This is a clean rewrite of RouterV1Handler using:
 * - IntentService: Intent detection (regex + LLM)
 * - ProductService: Product search
 * - TransactionService: Installment/Pawn/Repair/Savings checks
 * - CheckoutService: Checkout flow
 * - ChatService: Session & message logging
 * - BackendApiService: Centralized API calls
 * - ResponseService: Smart response with natural language
 * - KnowledgeBaseService: FAQ/Policy answer search
 * - AntiSpamService: Spam detection and prevention
 * 
 * @version 4.2
 * @date 2026-01-23
 */

require_once __DIR__ . '/BotHandlerInterface.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Logger.php';
require_once __DIR__ . '/CaseEngine.php';
require_once __DIR__ . '/services/IntentService.php';
require_once __DIR__ . '/services/ProductService.php';
require_once __DIR__ . '/services/TransactionService.php';
require_once __DIR__ . '/services/CheckoutService.php';
require_once __DIR__ . '/services/ChatService.php';
require_once __DIR__ . '/services/BackendApiService.php';
require_once __DIR__ . '/services/ResponseService.php';
require_once __DIR__ . '/services/KnowledgeBaseService.php';
require_once __DIR__ . '/services/AntiSpamService.php';

// Business Services (Service Layer)
require_once __DIR__ . '/../services/PawnService.php';
require_once __DIR__ . '/../services/InstallmentService.php';
require_once __DIR__ . '/../services/OrderService.php';
require_once __DIR__ . '/../services/AddressService.php';
require_once __DIR__ . '/../services/CaseService.php';

use Autobot\Bot\Services\IntentService;
use Autobot\Bot\Services\ProductService;
use Autobot\Bot\Services\TransactionService;
use Autobot\Bot\Services\CheckoutService;
use Autobot\Bot\Services\ChatService;
use Autobot\Bot\Services\BackendApiService;
use Autobot\Bot\Services\ResponseService;
use Autobot\Bot\Services\KnowledgeBaseService;
use Autobot\Bot\Services\AntiSpamService;

// Business Services
use App\Services\PawnService;
use App\Services\InstallmentService;
use App\Services\OrderService;
use App\Services\AddressService;
use App\Services\CaseService;

class RouterV4Handler implements BotHandlerInterface
{
    protected $db;
    protected $intentService;
    protected $productService;
    protected $transactionService;
    protected $checkoutService;
    protected $chatService;
    protected $backendApiService;
    protected $responseService;
    protected $knowledgeBaseService;
    protected $antiSpamService;
    
    // Business Services (Service Layer)
    protected $pawnService;
    protected $installmentService;
    protected $orderService;
    protected $addressService;
    protected $caseService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->intentService = new IntentService();
        $this->productService = new ProductService();
        $this->transactionService = new TransactionService();
        $this->checkoutService = new CheckoutService();
        $this->chatService = new ChatService();
        $this->backendApiService = new BackendApiService();
        $this->responseService = new ResponseService();
        $this->knowledgeBaseService = new KnowledgeBaseService();
        $this->antiSpamService = new AntiSpamService();
        
        // Initialize Business Services (Service Layer)
        $pdo = $this->db->getPdo();
        $this->pawnService = new PawnService($pdo);
        $this->installmentService = new InstallmentService($pdo);
        $this->orderService = new OrderService($pdo);
        $this->addressService = new AddressService($pdo);
        $this->caseService = new CaseService($pdo);
    }

    /**
     * Main message handler
     */
    public function handleMessage(array $context): array
    {
        $traceId = $context['trace_id'] ?? bin2hex(random_bytes(8));
        $context['trace_id'] = $traceId;
        $t0 = microtime(true);

        Logger::info('[ROUTER_V4] start', [
            'trace_id' => $traceId,
            'channel_id' => $context['channel']['id'] ?? null,
            'platform' => $context['platform'] ?? null,
            'external_user_id' => $context['external_user_id'] ?? null,
        ]);

        try {
            // ==================== SETUP ====================
            
            // Load config
            $botProfile = $context['bot_profile'] ?? [];
            $config = $this->decodeConfig($botProfile['config'] ?? null);
            $templates = $config['response_templates'] ?? [];
            
            // Pass config to services that need it
            $this->productService->setConfig($config);
            
            // Extract message
            $message = $context['message'] ?? [];
            $text = trim((string)($message['text'] ?? ''));
            $messageType = $message['message_type'] ?? ($message['type'] ?? 'text');
            $isEcho = (bool)($message['is_echo'] ?? false);

            // Ignore echo messages
            if ($isEcho) {
                return $this->makeResponse(null, 'ignore_echo', $traceId);
            }

            // Channel & user info
            $channel = $context['channel'] ?? [];
            $channelId = (int)($channel['id'] ?? 0);
            $platformUserId = $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null);
            
            if (!$channelId || !$platformUserId) {
                return $this->makeResponse(null, 'missing_context', $traceId);
            }

            // Build enriched context
            $context['platform_user_id'] = $platformUserId;
            $context['channel']['id'] = $channelId;

            // ==================== SESSION ====================
            
            $session = $this->chatService->getOrCreateSession($context);
            $sessionId = $session['id'] ?? null;
            $context['session_id'] = $sessionId;

            // ==================== ADMIN CHECK ====================
            
            $isAdmin = $this->isAdminContext($context, $message);
            
            // Handle admin message - don't reply
            if ($isAdmin) {
                $this->handleAdminMessage($context, $text, $sessionId);
                return $this->makeResponse(null, 'admin_message', $traceId);
            }

            // Check if admin handoff is active
            if ($this->isAdminHandoffActive($sessionId, $config)) {
                // Store message but don't reply
                $this->chatService->logIncomingMessage($context, $text, $messageType);
                return $this->makeResponse(null, 'admin_handoff_active', $traceId);
            }

            // ==================== ANTI-SPAM CHECKS ====================
            
            // Get customer_service_id for anti-spam checks
            $customerServiceId = $this->getCustomerServiceIdFromChannel($channelId);
            $context['customer_service_id'] = $customerServiceId;
            
            // Check for duplicate webhook delivery
            if ($customerServiceId && !empty($text) && $this->antiSpamService->isDuplicateDelivery($customerServiceId, $text)) {
                Logger::info('[ROUTER_V4] Duplicate delivery detected, ignoring', ['trace_id' => $traceId]);
                return $this->makeResponse(null, 'duplicate_delivery', $traceId);
            }

            // Check for repeated spam messages
            if ($customerServiceId && !empty($text) && $this->antiSpamService->isRepeatedMessage($customerServiceId, $text)) {
                Logger::info('[ROUTER_V4] Repeated message spam detected', ['trace_id' => $traceId]);
                $spamAction = $this->antiSpamService->getSpamAction($config);
                
                if ($spamAction['action'] === 'silent') {
                    return $this->makeResponse(null, 'spam_silent', $traceId);
                }
                
                return $this->makeResponse($spamAction['message'], 'spam_warning', $traceId);
            }

            // ==================== � ECHO FILTER ====================
            // ข้ามข้อความที่เป็น output ของบอทเอง (Facebook Echo Events)
            
            if ($messageType === 'text' && !empty($text)) {
                // Pattern ที่เป็น output บอท V4: ราคา, สินค้า, ยืนยัน
                $botOutputPatterns = [
                    '/^สินค้า:.*ราคา:/u',                    // Product listing
                    '/^รายการสั่งซื้อ/u',                    // Order summary
                    '/^ยืนยันการสั่งซื้อ/u',                 // Order confirmation
                    '/^ขอบคุณที่สั่งซื้อ/u',                 // Thank you message
                    '/^กรุณาโอนเงิน/u',                      // Payment instruction
                    '/^ตรวจสอบสลิป.*สำเร็จ/u',               // Slip verification
                    '/ยอดรวม:\s*฿?\d+/u',                    // Total amount
                    '/เลขพัสดุ:/u',                          // Tracking number
                    '/จัดส่งภายใน/u',                        // Shipping info
                ];
                
                foreach ($botOutputPatterns as $pattern) {
                    if (preg_match($pattern, $text)) {
                        Logger::info('[V4_ECHO_FILTER] Skipped bot echo message', [
                            'trace_id' => $traceId,
                            'pattern' => $pattern,
                            'text_preview' => mb_substr($text, 0, 30, 'UTF-8'),
                        ]);
                        return $this->makeResponse(null, 'echo_filter_bot_output', $traceId);
                    }
                }
            }

            // ==================== 🛡️ GATEKEEPER LAYER ====================
            // ป้องกันการตอบข้อความที่ไม่จำเป็น (คำลงท้าย, คำสั้นพิมพ์รัว)
            // + Message Buffer: เก็บข้อความที่ skip ไว้รวมบริบท
            
            if ($messageType === 'text' && !empty($text)) {
                $gatekeeperResult = $this->shouldProcessMessage($text, $platformUserId, $channelId, $traceId, $config);
                if (!$gatekeeperResult['should_process']) {
                    // 📝 เก็บข้อความลง buffer แทนที่จะทิ้ง (ยกเว้น gibberish)
                    if ($gatekeeperResult['reason'] !== 'gibberish') {
                        $this->appendToMessageBuffer($text, $platformUserId, $channelId);
                    }
                    
                    Logger::info('[GATEKEEPER] Skipped message', [
                        'trace_id' => $traceId,
                        'reason' => $gatekeeperResult['reason'],
                        'text_preview' => mb_substr($text, 0, 20, 'UTF-8'),
                    ]);
                    return $this->makeResponse(null, 'gatekeeper_' . $gatekeeperResult['reason'], $traceId);
                }
                
                // ✅ ถ้า process ได้ → ดึง buffer มารวมกับข้อความปัจจุบัน
                $bufferedText = $this->getAndClearMessageBuffer($platformUserId, $channelId);
                if (!empty($bufferedText)) {
                    $text = $bufferedText . ' ' . $text;
                    $context['message']['text'] = $text;
                    Logger::info('[GATEKEEPER] Merged buffered messages', [
                        'trace_id' => $traceId,
                        'merged_text' => mb_substr($text, 0, 50, 'UTF-8'),
                    ]);
                }
            }

            // ==================== IMAGE HANDLING ====================
            
            if ($messageType === 'image') {
                // Facebook format: attachments[0]['payload']['url']
                // LINE format: attachments[0]['url'] or message['image_url']
                $attachment = $message['attachments'][0] ?? null;
                $imageUrl = null;
                
                if ($attachment) {
                    // Try Facebook format first (payload.url)
                    $imageUrl = $attachment['payload']['url'] ?? null;
                    // Fallback to direct url
                    if (!$imageUrl) {
                        $imageUrl = $attachment['url'] ?? null;
                    }
                }
                
                // Also check for direct image_url in message
                if (!$imageUrl) {
                    $imageUrl = $message['image_url'] ?? null;
                }
                
                Logger::info('[ROUTER_V4] Processing image', [
                    'trace_id' => $traceId,
                    'has_attachments' => !empty($message['attachments']),
                    'attachment_count' => count($message['attachments'] ?? []),
                    'image_url_found' => !empty($imageUrl),
                    'image_url_preview' => $imageUrl ? substr($imageUrl, 0, 100) : null,
                ]);
                
                return $this->handleImage($imageUrl, $config, $context, $templates, $traceId);
            }

            // ==================== TEXT HANDLING ====================
            
            if (empty($text)) {
                // Empty message - send greeting
                $greeting = $templates['greeting'] ?? 'สวัสดีค่ะ ยินดีให้บริการค่ะ 😊';
                return $this->makeResponse($greeting, 'greeting', $traceId);
            }

            // Log incoming message
            $msgId = $this->chatService->logIncomingMessage($context, $text, 'text');
            $context['message_id'] = $msgId;

            // ==================== MENU RESET DETECTION ====================
            
            // Clear checkout state when user clicks menu buttons
            if ($this->isMenuResetTrigger($text)) {
                $checkoutState = $this->checkoutService->getCheckoutState($platformUserId, $channelId);
                if (!empty($checkoutState)) {
                    $this->checkoutService->clearCheckoutState($platformUserId, $channelId);
                    Logger::info('[ROUTER_V4] Checkout cleared on menu reset', [
                        'trace_id' => $traceId,
                        'trigger' => $text,
                    ]);
                }
            }

            // ==================== POLICY QUESTION CHECK ====================
            
            // Check if this is a policy/FAQ question before other processing
            if ($this->knowledgeBaseService->isPolicyQuestion($text)) {
                $kbResults = $this->knowledgeBaseService->search($context, $text);
                if (!empty($kbResults) && isset($kbResults[0]['answer'])) {
                    // Found answer in knowledge base
                    $bestMatch = $kbResults[0];
                    $this->chatService->logOutgoingMessage($context, $bestMatch['answer'], 'text');
                    return $this->makeResponse($bestMatch['answer'], 'knowledge_base', $traceId);
                }
            }

            // ==================== EARLY CHECKOUT DETECTION ====================
            
            // Check for interest keywords with product context
            $earlyCheckout = $this->detectEarlyCheckout($text, $context);
            if ($earlyCheckout) {
                return $this->makeResponse($earlyCheckout['reply'], 'early_checkout', $traceId);
            }

            // ==================== CHECKOUT FLOW HANDLING (Sticky Session Trap Fix) ====================
            
            // Get checkout state first
            $checkoutState = $this->checkoutService->getCheckoutState($platformUserId, $channelId);
            $context['checkout_state'] = $checkoutState;
            $context['pending_checkout'] = !empty($checkoutState);
            
            // If user is in checkout flow, let CheckoutService try to handle first
            if (!empty($checkoutState)) {
                Logger::info('[ROUTER_V4] User in checkout flow, trying CheckoutService first', [
                    'trace_id' => $traceId,
                    'checkout_step' => $checkoutState['step'] ?? 'unknown',
                    'text_preview' => mb_substr($text, 0, 30, 'UTF-8'),
                ]);
                
                $checkoutResult = $this->checkoutService->handleFlow($text, $checkoutState, $config, $context);
                
                if (!empty($checkoutResult['reply'])) {
                    // CheckoutService handled successfully
                    Logger::info('[ROUTER_V4] CheckoutService handled the message', [
                        'trace_id' => $traceId,
                        'has_order' => !empty($checkoutResult['order_created']),
                    ]);
                    
                    $replyText = is_array($checkoutResult['reply']) 
                        ? ($checkoutResult['reply']['text'] ?? $checkoutResult['reply'])
                        : $checkoutResult['reply'];
                    $this->chatService->logOutgoingMessage($context, $replyText, 'text');
                    
                    return $this->makeResponse($checkoutResult['reply'], 'checkout_flow', $traceId, $checkoutResult);
                } else {
                    // CheckoutService returned empty -> User is talking off-topic
                    // ✅ FIX: Don't clear if step is 'ask_address' - might be address data
                    $currentStep = $checkoutState['step'] ?? '';
                    if ($currentStep === 'ask_address') {
                        Logger::warning('[ROUTER_V4] CheckoutService returned empty at ask_address step, keeping state', [
                            'trace_id' => $traceId,
                            'text_preview' => mb_substr($text, 0, 50, 'UTF-8'),
                        ]);
                        // Don't clear - let IntentService handle but keep state for retry
                    } else {
                        Logger::info('[ROUTER_V4] User talking off-topic, releasing from checkout', [
                            'trace_id' => $traceId,
                            'step' => $currentStep,
                        ]);
                        $this->checkoutService->clearCheckoutState($platformUserId, $channelId);
                        $context['checkout_state'] = null;
                        $context['pending_checkout'] = false;
                    }
                }
            }

            // ==================== INTENT DETECTION ====================

            // Detect intent
            $intentResult = $this->intentService->detect($text, $config, $context);
            $intent = $intentResult['intent'];
            $confidence = $intentResult['confidence'];
            $params = $intentResult['slots'] ?? $intentResult['params'] ?? [];

            Logger::info('[ROUTER_V4] Intent detected', [
                'trace_id' => $traceId,
                'intent' => $intent,
                'confidence' => $confidence,
                'method' => $intentResult['method'] ?? 'unknown',
                'params' => $params,
            ]);

            // Log intent
            $this->chatService->logIntent($context, $intent, $confidence, $params ?? []);

            // ==================== AUTO-CREATE CASE (if config enabled) ====================
            
            if ($this->shouldAutoCreateCase($config, $intent)) {
                $caseType = $this->detectCaseTypeFromIntent($intent);
                if ($caseType) {
                    $this->createOrUpdateCase($caseType, $params, $config, $context);
                }
            }

            // ==================== ROUTE TO HANDLER ====================
            
            $response = $this->routeIntent($intent, $params, $config, $context, $templates);

            // Log outgoing message
            if (!empty($response['reply'])) {
                $replyText = is_array($response['reply']) 
                    ? ($response['reply']['text'] ?? json_encode($response['reply']))
                    : $response['reply'];
                $this->chatService->logOutgoingMessage($context, $replyText, 'text');
                
                // 🛡️ Track last bot reply time for Gatekeeper (phatic filter)
                $this->chatService->setQuickState('last_bot_reply_time', [
                    'time' => time(),
                    'intent' => $intent,
                ], $platformUserId, $channelId, 300); // 5 min TTL
            }

            // Calculate duration
            $durationMs = (int)((microtime(true) - $t0) * 1000);
            
            Logger::info('[ROUTER_V4] end', [
                'trace_id' => $traceId,
                'intent' => $intent,
                'duration_ms' => $durationMs,
            ]);

            return $this->makeResponse(
                $response['reply'] ?? null,
                $intent,
                $traceId,
                $response
            );

        } catch (Exception $e) {
            Logger::error('[ROUTER_V4] Error', [
                'trace_id' => $traceId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $fallback = $templates['fallback'] ?? 'ขออภัยค่ะ ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้งนะคะ 🙏';
            return $this->makeResponse($fallback, 'error', $traceId, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Route intent to appropriate handler
     */
    protected function routeIntent(string $intent, array $params, array $config, array $context, array $templates): array
    {
        switch ($intent) {
            // ==================== CHECKOUT FLOW ====================
            
            case 'checkout_confirm':
                return $this->handleCheckoutConfirm($config, $context);
                
            case 'checkout_cancel':
                return $this->handleCheckoutCancel($context);
                
            case 'product_interest':
            case 'purchase_intent':  // Same as product_interest
                return $this->handleProductInterest($params, $config, $context);
                
            case 'product_lookup_by_code':
                return $this->handleProductSearch($params, $config, $context);

            // ==================== DEPOSIT/BOOKING FLOW ====================
            
            case 'deposit_flow':
                // User wants to book/deposit but hasn't specified product
                return $this->handleDepositFlowAskProduct($params, $config, $context, $templates);
                
            case 'deposit_new':
                // User wants to deposit for a specific product (has context)
                return $this->handleDepositWithProduct($params, $config, $context);

            // ==================== TRANSACTION CHECKS ====================
            
            case 'installment_check':
                $result = $this->transactionService->checkInstallment($config, $context);
                return ['reply' => $result['message']];
                
            case 'pawn_check':
                $result = $this->transactionService->checkPawn($config, $context);
                return ['reply' => $result['message']];

            case 'pawn_new':
            case 'pawn_inquiry':
                // ✅ เช็คว่าลูกค้าเคยซื้อสินค้าจากร้านหรือไม่
                return $this->handlePawnInquiry($config, $context, $templates);
                
            case 'repair_check':
                $result = $this->transactionService->checkRepair($config, $context);
                return ['reply' => $result['message']];
                
            case 'savings_check':
                $result = $this->transactionService->checkSavings($config, $context);
                return ['reply' => $result['message']];
                
            case 'order_check':
            case 'order_status':
                $orderNo = $params['order_no'] ?? null;
                $result = $this->transactionService->checkOrder($config, $context, $orderNo);
                return ['reply' => $result['message']];

            // ==================== PAYMENT CHANGE ====================
            
            case 'change_payment_method':
                return $this->handleChangePaymentMethod($params, $config, $context);
                
            case 'installment_flow':
                $result = $this->transactionService->checkInstallment($config, $context);
                return ['reply' => $result['message']];

            // ==================== PRODUCT SEARCH ====================
            
            case 'product_availability':  // "มีนาฬิกาสีแดงไหม", "มีสินค้าอะไรบ้าง"
                // ✅ Extract keyword from text and route to product search
                $text = $context['message']['text'] ?? '';
                $keyword = $this->extractProductKeywords($text);
                
                if (!empty($keyword)) {
                    // ✅ Has specific keyword - skip LLM rewrite, search directly
                    return $this->handleProductSearch(['keyword' => $keyword, 'skip_llm_rewrite' => true], $config, $context);
                }
                
                // ✅ NEW: Empty keyword (e.g., "มีไหม", "มีบ้างไหม") - use LLM to get context from chat history
                Logger::info('[ROUTER_V4] product_availability: empty keyword, using LLM context', [
                    'original_text' => $text
                ]);
                return $this->handleProductSearch(['keyword' => $text, 'skip_llm_rewrite' => false], $config, $context);
            
            case 'product_search':
                return $this->handleProductSearch($params, $config, $context);

            case 'browse_products':
                return $this->handleBrowseProducts($config, $context);

            case 'follow_up_info':
                return $this->handleFollowUpInfo($config, $context);

            // ==================== PAYMENT ====================
            
            case 'payment_options':
                // ✅ Refactored: Call CheckoutService instead of local method
                $checkoutState = $context['checkout_state'] ?? null;
                if (!$checkoutState) {
                    $platformUserId = $context['platform_user_id'] ?? null;
                    $channelId = $context['channel']['id'] ?? null;
                    if ($platformUserId && $channelId) {
                        $checkoutState = $this->checkoutService->getCheckoutState($platformUserId, $channelId);
                    }
                }
                $msg = $this->checkoutService->getPaymentOptionsInfo($config, $checkoutState);
                return ['reply' => $msg];

            // ==================== ADMIN HANDOFF ====================
            
            case 'admin_handoff':
                return $this->handleAdminHandoffRequest($config, $context, $templates);

            // ==================== VIDEO CALL / NEGOTIATION / TRADE-IN ====================
            
            case 'request_video_call':
                return $this->handleVideoCallRequest($config, $context, $templates);
                
            case 'price_negotiation':
                return $this->handlePriceNegotiation($config, $context, $templates);
                
            case 'trade_in_inquiry':
                // ✅ Refactored: Call TransactionService instead of local method
                $msg = $this->transactionService->getTradeInPolicy();
                return ['reply' => $msg];
                
            case 'trade_in_calculate':
                // ✅ Refactored: Call TransactionService instead of local method
                $originalPrice = (float)($params['original_price'] ?? 0);
                $result = $this->transactionService->calculateTradeIn($originalPrice);
                return ['reply' => $result['message']];

            // ==================== GREETINGS ====================
            
            case 'greeting':
                $greeting = $templates['greeting'] ?? 'สวัสดีค่ะ ยินดีให้บริการค่ะ 😊';
                return ['reply' => $greeting];
                
            case 'thanks':
                $thanks = $templates['thanks'] ?? 'ยินดีค่ะ ขอบคุณที่อุดหนุนนะคะ 💛';
                return ['reply' => $thanks];

            // ==================== FALLBACK ====================
            
            default:
                return $this->handleFallback($config, $context, $templates);
        }
    }

    // ==================== INTENT HANDLERS ====================

    /**
     * Handle checkout confirmation
     */
    protected function handleCheckoutConfirm(array $config, array $context): array
    {
        // Extract payment type from message
        $text = mb_strtolower($context['message']['text'] ?? '');
        $paymentType = 'full';
        
        if (strpos($text, 'ผ่อน') !== false || strpos($text, 'installment') !== false) {
            $paymentType = 'installment';
        } elseif (strpos($text, 'มัดจำ') !== false || strpos($text, 'deposit') !== false) {
            $paymentType = 'deposit';
        } elseif (strpos($text, 'ออม') !== false || strpos($text, 'savings') !== false) {
            $paymentType = 'savings';
        }

        // Set payment type
        $this->checkoutService->setPaymentType($paymentType, $context);
        
        // Confirm checkout
        $result = $this->checkoutService->confirmCheckout($config, $context);
        
        // Save last order info for payment change requests
        if (!empty($result['order_no'])) {
            $platformUserId = $context['platform_user_id'] ?? '';
            $channelId = $context['channel']['id'] ?? 0;
            $this->chatService->setQuickState('last_order', [
                'order_no' => $result['order_no'],
                'order_id' => $result['order_id'] ?? null,
                'product' => $result['product'] ?? [],
                'payment_type' => $paymentType,
                'created_at' => time(),
            ], $platformUserId, $channelId, 3600); // 1 hour TTL
        }
        
        return ['reply' => $result['reply']];
    }

    /**
     * Handle checkout cancellation
     */
    protected function handleCheckoutCancel(array $context): array
    {
        $result = $this->checkoutService->cancelCheckout($context);
        return ['reply' => $result['reply']];
    }

    /**
     * Handle product interest (start checkout)
     */
    protected function handleProductInterest(array $params, array $config, array $context): array
    {
        // Support both 'code' and 'product_code' keys
        $productCode = $params['code'] ?? $params['product_code'] ?? null;
        $trigger = $params['trigger'] ?? 'general';
        
        // ✅ NEW: Check if selecting from products history (เอาตัวที่ 2 or เอากำไลทอง)
        $productIndex = $params['product_index'] ?? null;
        $productNameQuery = $params['product_name_query'] ?? null;
        $fromHistory = $params['from_history'] ?? false;
        
        if ($fromHistory && $productIndex !== null) {
            return $this->handleProductSelectionFromHistory($productIndex, $config, $context);
        }
        
        // ✅ NEW: Select by product name from history (เอากำไลทอง, สนใจ Rolex)
        if ($fromHistory && $productNameQuery !== null) {
            return $this->handleProductSelectionByName($productNameQuery, $config, $context);
        }

        // If no code provided, check recently viewed
        if (!$productCode && $trigger === 'general') {
            $recentProduct = $this->productService->getRecentlyViewed($context);
            if ($recentProduct) {
                $productCode = $recentProduct['code'];
            }
        }

        if (!$productCode) {
            // ✅ Check if there are products in history to suggest
            $caseEngine = new \CaseEngine($config, $context);
            $caseId = $caseEngine->getActiveCaseId();
            if ($caseId) {
                $history = $caseEngine->getProductsHistory($caseId);
                if (!empty($history)) {
                    $lines = ['สนใจสินค้าตัวไหนคะ? ที่ดูไว้มี:'];
                    foreach ($history as $p) {
                        $idx = $p['idx'] ?? 0;
                        $name = $p['product_name'] ?? 'สินค้า';
                        $price = number_format($p['product_price'] ?? 0, 0);
                        $lines[] = "{$idx}. {$name} - ฿{$price}";
                    }
                    $lines[] = '';
                    $lines[] = 'พิมพ์ "เอาตัวที่ X" หรือพิมพ์ชื่อสินค้าได้เลยค่ะ 😊';
                    return ['reply' => implode("\n", $lines)];
                }
            }
            
            // ✅ NEW: Analyze conversation history to find product interest keyword
            // If customer discussed a product category (พระ, สร้อย, นาฬิกา) and then says "สนใจ",
            // search for products in that category instead of just asking for code
            $categoryKeyword = $this->extractProductCategoryFromConversation($context);
            if ($categoryKeyword) {
                Logger::info('[ROUTER_V4] Extracted product category from conversation', [
                    'keyword' => $categoryKeyword,
                ]);
                // Search products by category keyword and show carousel
                return $this->handleProductSearch(['keyword' => $categoryKeyword, 'from_interest' => true], $config, $context);
            }
            
            return ['reply' => 'สนใจสินค้าตัวไหนคะ? พิมพ์รหัสสินค้าได้เลยค่ะ เช่น "สนใจ A-1234" 😊'];
        }

        // Search for product
        $searchResult = $this->productService->searchByCode($productCode, $config, $context);
        
        if (!$searchResult['ok'] || empty($searchResult['products'])) {
            return ['reply' => "ไม่พบสินค้ารหัส {$productCode} ค่ะ ลองเช็ครหัสอีกครั้งนะคะ หรือพิมพ์ชื่อสินค้าที่สนใจได้เลยค่ะ 😊"];
        }

        $product = $searchResult['products'][0];
        
        // ✅ FIX: Update case with product interest (missing before!)
        $this->createOrUpdateCase(\CaseEngine::CASE_PRODUCT_INQUIRY, [
            'product_code' => $product['code'] ?? $product['product_code'] ?? $productCode,
            'product_name' => $product['name'] ?? $product['title'] ?? null,
            'product_price' => $product['sale_price'] ?? $product['price'] ?? null,
            'product_ref_id' => $product['ref_id'] ?? $product['id'] ?? null,
            'product_image_url' => $product['image'] ?? $product['thumbnail_url'] ?? $product['image_url'] ?? null,
            'trigger' => $trigger,
        ], $config, $context);
        
        // Start checkout flow
        $checkoutResult = $this->checkoutService->startCheckout($product, $config, $context);
        
        return ['reply' => $checkoutResult['reply']];
    }

    /**
     * Handle product selection from history (เอาตัวที่ 2)
     */
    protected function handleProductSelectionFromHistory(int $productIndex, array $config, array $context): array
    {
        $caseEngine = new \CaseEngine($config, $context);
        $caseId = $caseEngine->getActiveCaseId();
        
        if (!$caseId) {
            return ['reply' => 'ยังไม่มีสินค้าที่ดูไว้ค่ะ พิมพ์รหัสสินค้าหรือชื่อสินค้าได้เลยนะคะ 😊'];
        }
        
        $history = $caseEngine->getProductsHistory($caseId);
        
        if (empty($history)) {
            return ['reply' => 'ยังไม่มีสินค้าที่ดูไว้ค่ะ พิมพ์รหัสสินค้าหรือชื่อสินค้าได้เลยนะคะ 😊'];
        }
        
        // Handle special index -1 (ล่าสุด)
        if ($productIndex === -1) {
            $productIndex = count($history);
        }
        
        // Select product from history
        $selectedProduct = $caseEngine->selectProductFromHistory($caseId, $productIndex);
        
        if (!$selectedProduct) {
            $lines = ['ไม่พบรายการที่ ' . $productIndex . ' ค่ะ มีสินค้าที่ดูไว้:'];
            foreach ($history as $p) {
                $idx = $p['idx'] ?? 0;
                $name = $p['product_name'] ?? 'สินค้า';
                $lines[] = "{$idx}. {$name}";
            }
            return ['reply' => implode("\n", $lines)];
        }
        
        // Build product array for checkout
        $product = [
            'ref_id' => $selectedProduct['product_ref_id'],
            'code' => $selectedProduct['product_code'],
            'name' => $selectedProduct['product_name'],
            'title' => $selectedProduct['product_name'],
            'price' => $selectedProduct['product_price'],
            'sale_price' => $selectedProduct['product_price'],
            'thumbnail_url' => $selectedProduct['product_image_url'],
        ];
        
        Logger::info('[ROUTER_V4] Product selected from history', [
            'case_id' => $caseId,
            'product_index' => $productIndex,
            'product_ref_id' => $product['ref_id']
        ]);
        
        // Start checkout flow
        $checkoutResult = $this->checkoutService->startCheckout($product, $config, $context);
        
        return ['reply' => $checkoutResult['reply']];
    }

    /**
     * Handle product selection by name from history (เอากำไลทอง, สนใจ Rolex)
     */
    protected function handleProductSelectionByName(string $productNameQuery, array $config, array $context): array
    {
        $caseEngine = new \CaseEngine($config, $context);
        $caseId = $caseEngine->getActiveCaseId();
        
        if (!$caseId) {
            // No active case - try product search instead
            return $this->handleProductSearch(['keyword' => $productNameQuery], $config, $context);
        }
        
        $history = $caseEngine->getProductsHistory($caseId);
        
        if (empty($history)) {
            // No products in history - try product search
            return $this->handleProductSearch(['keyword' => $productNameQuery], $config, $context);
        }
        
        // Search for matching product in history
        $matchedProduct = null;
        $query = mb_strtolower($productNameQuery);
        
        foreach ($history as $p) {
            $name = mb_strtolower($p['product_name'] ?? '');
            $code = mb_strtolower($p['product_code'] ?? '');
            
            // Check if query matches name or code
            if (mb_strpos($name, $query) !== false || mb_strpos($code, $query) !== false) {
                $matchedProduct = $p;
                break;
            }
        }
        
        if (!$matchedProduct) {
            // Not found in history - suggest from history or do product search
            $lines = ["ไม่พบ \"{$productNameQuery}\" ในรายการที่ดูไว้ค่ะ"];
            $lines[] = "";
            $lines[] = "สินค้าที่ดูไว้มี:";
            foreach ($history as $p) {
                $idx = $p['idx'] ?? 0;
                $name = $p['product_name'] ?? 'สินค้า';
                $lines[] = "{$idx}. {$name}";
            }
            $lines[] = "";
            $lines[] = "พิมพ์ \"เอาตัวที่ X\" หรือพิมพ์รหัสสินค้าใหม่ได้เลยค่ะ 😊";
            return ['reply' => implode("\n", $lines)];
        }
        
        // Build product array for checkout
        $product = [
            'ref_id' => $matchedProduct['product_ref_id'],
            'code' => $matchedProduct['product_code'],
            'name' => $matchedProduct['product_name'],
            'title' => $matchedProduct['product_name'],
            'price' => $matchedProduct['product_price'],
            'sale_price' => $matchedProduct['product_price'],
            'thumbnail_url' => $matchedProduct['product_image_url'],
        ];
        
        Logger::info('[ROUTER_V4] Product selected by name from history', [
            'case_id' => $caseId,
            'query' => $productNameQuery,
            'product_ref_id' => $product['ref_id']
        ]);
        
        // Start checkout flow
        $checkoutResult = $this->checkoutService->startCheckout($product, $config, $context);
        
        return ['reply' => $checkoutResult['reply']];
    }

    /**
     * Handle product search
     * 
     * Now includes:
     * - Context-Aware Query Rewriting (uses chat history for ambiguous queries)
     * - Chit-chat Guardrail (detects greetings/thanks, skips product search)
     */
    protected function handleProductSearch(array $params, array $config, array $context): array
    {
        // Support both 'code' and 'product_code' keys from IntentService
        $code = $params['code'] ?? $params['product_code'] ?? null;
        $keyword = $params['keyword'] ?? null;
        $query = $code ?: $keyword;
        $skipLlmRewrite = $params['skip_llm_rewrite'] ?? false;

        if (!$query) {
            return ['reply' => 'พิมพ์รหัสสินค้า หรือชื่อสินค้าที่สนใจได้เลยค่ะ 😊'];
        }

        // ✅ Step 1: Context-Aware Query Rewriting + Chit-chat Detection
        // Skip if already confirmed as product search (e.g., from product_availability intent)
        if (!$skipLlmRewrite) {
            $rewriteResult = $this->rewriteQueryWithContext($query, $config, $context);
            
            // ✅ Step 2: If chit-chat detected, fallback to LLM general response
            if ($rewriteResult['is_chit_chat'] ?? false) {
                Logger::info('[ROUTER_V4] Chit-chat detected, falling back to LLM', [
                    'original_query' => $query
                ]);
                
                $llmResponse = $this->handleWithLLM($context, $config);
                if ($llmResponse) {
                    return ['reply' => $llmResponse];
                }
                
                // Fallback for greetings
                if (preg_match('/(สวัสดี|หวัดดี|ดีค่ะ|ดีครับ)/u', $query)) {
                    return ['reply' => 'สวัสดีค่ะ 😊 ยินดีต้อนรับเข้าสู่ร้านของเราค่ะ มีอะไรให้ช่วยคะ?'];
                }
                if (preg_match('/(ขอบคุณ|ขอบใจ|ขอบพระคุณ)/u', $query)) {
                    return ['reply' => 'ยินดีค่ะ 🙏 มีอะไรสอบถามเพิ่มเติมได้เลยนะคะ'];
                }
                
                return ['reply' => 'มีอะไรให้ช่วยคะ? พิมพ์รหัสหรือชื่อสินค้าที่สนใจได้เลยนะคะ 😊'];
            }
            
            // ✅ Step 3: Use rewritten query for product search
            $searchQuery = $rewriteResult['rewritten'] ?? $query;
            
            if ($searchQuery !== $query) {
                Logger::info('[ROUTER_V4] Query rewritten for context', [
                    'original' => $query,
                    'rewritten' => $searchQuery
                ]);
            }
        } else {
            // Skip LLM rewrite - use original query directly
            $searchQuery = $query;
            Logger::info('[ROUTER_V4] Skipping LLM rewrite (product_availability intent)', [
                'query' => $query
            ]);
        }

        $result = $this->productService->search($searchQuery, $config, $context);

        if (!$result['ok'] || empty($result['products'])) {
            return ['reply' => 'ยังไม่เจอสินค้าที่ค้นหาค่ะ 🔍 ลองพิมพ์คำค้นอื่น หรือดูสินค้าแนะนำได้เลยนะคะ'];
        }

        $products = $result['products'];

        // Track first product view
        if (!empty($products[0])) {
            $this->productService->trackView($products[0], $context);
            
            // ✅ Create/Update case for product inquiry (like V1)
            // Note: ProductService.formatProduct() returns 'image', not 'thumbnail_url'
            $this->createOrUpdateCase(\CaseEngine::CASE_PRODUCT_INQUIRY, [
                'product_code' => $products[0]['code'] ?? $products[0]['product_code'] ?? null,
                'product_name' => $products[0]['name'] ?? $products[0]['title'] ?? null,
                'product_price' => $products[0]['price'] ?? $products[0]['sale_price'] ?? null,
                'product_ref_id' => $products[0]['ref_id'] ?? $products[0]['id'] ?? null,
                'product_image_url' => $products[0]['image'] ?? $products[0]['thumbnail_url'] ?? $products[0]['image_url'] ?? null,
            ], $config, $context);
        }

        // ✅ CHECK: If there's a pending deposit intent, start checkout directly!
        $platformUserId = $context['platform_user_id'] ?? '';
        $channelId = $context['channel']['id'] ?? 0;
        $pendingIntent = $this->chatService->getQuickState('pending_intent', $platformUserId, $channelId);
        
        if ($pendingIntent && ($pendingIntent['intent'] ?? '') === 'deposit') {
            // Clear pending intent
            $this->chatService->clearQuickState('pending_intent', $platformUserId, $channelId);
            
            $product = $products[0];
            Logger::info('[ROUTER_V4] Auto-starting deposit checkout from pending intent', [
                'product_code' => $product['code'] ?? $product['product_code'] ?? null,
            ]);
            
            // Start checkout with deposit payment type
            $checkoutResult = $this->checkoutService->startCheckout($product, $config, $context, 'deposit');
            return ['reply' => $checkoutResult['reply']];
        }

        $platform = $context['platform'] ?? 'line';
        $product = $products[0];
        $productName = $product['name'] ?? $product['title'] ?? 'สินค้า';
        $productCode = $product['code'] ?? $product['product_code'] ?? '';
        $price = number_format($product['sale_price'] ?? $product['price'] ?? 0, 0);
        $imageUrl = $product['image'] ?? $product['thumbnail_url'] ?? $product['image_url'] ?? null;

        // ✅ For both LINE and Facebook: Use carousel with button "สนใจ + product_code"
        // This ensures clicking button sends "สนใจ {code}" automatically
        if (count($products) === 1) {
            return [
                'reply' => $this->productService->formatAsCarousel([$products[0]]),
                'type' => 'flex'
            ];
        }

        return [
            'reply' => $this->productService->formatAsCarousel($products),
            'type' => 'flex'
        ];
    }

    /**
     * Handle admin handoff request
     */
    protected function handleAdminHandoffRequest(array $config, array $context, array $templates): array
    {
        $handoffCfg = $config['handoff'] ?? [];
        $message = $handoffCfg['message'] ?? $templates['handoff'] ?? 
            'รับทราบค่ะ กำลังแจ้งแอดมินให้นะคะ รอสักครู่นะคะ 🙏';

        // TODO: Trigger notification to admin (LINE Notify, Email, etc.)

        Logger::info('[ROUTER_V4] Admin handoff requested', [
            'channel_id' => $context['channel']['id'] ?? null,
            'platform_user_id' => $context['platform_user_id'] ?? null,
        ]);

        return ['reply' => $message];
    }

    // ==================== DEPOSIT/BOOKING HANDLERS ====================

    /**
     * Handle deposit flow when product is not specified
     * Sets pending_intent so next product search will auto-start deposit checkout
     */
    protected function handleDepositFlowAskProduct(array $params, array $config, array $context, array $templates): array
    {
        $platformUserId = $context['platform_user_id'] ?? '';
        $channelId = $context['channel']['id'] ?? 0;
        
        // ✅ Check if there's a recently viewed product
        $recentProduct = $this->productService->getRecentlyViewed($context);
        
        if ($recentProduct) {
            // Has recent product → start deposit checkout directly
            $product = [
                'ref_id' => $recentProduct['ref_id'] ?? $recentProduct['id'] ?? null,
                'code' => $recentProduct['code'] ?? $recentProduct['product_code'] ?? '',
                'name' => $recentProduct['name'] ?? $recentProduct['title'] ?? 'สินค้า',
                'title' => $recentProduct['name'] ?? $recentProduct['title'] ?? 'สินค้า',
                'price' => $recentProduct['price'] ?? $recentProduct['sale_price'] ?? 0,
                'sale_price' => $recentProduct['sale_price'] ?? $recentProduct['price'] ?? 0,
                'thumbnail_url' => $recentProduct['image'] ?? $recentProduct['thumbnail_url'] ?? null,
            ];
            
            Logger::info('[ROUTER_V4] Deposit with recent product', [
                'product_code' => $product['code'],
            ]);
            
            // Start checkout with deposit payment type
            $checkoutResult = $this->checkoutService->startCheckout($product, $config, $context, 'deposit');
            return ['reply' => $checkoutResult['reply']];
        }
        
        // ✅ No recent product → Set pending intent and ask for product
        $this->chatService->setQuickState('pending_intent', [
            'intent' => 'deposit',
            'created_at' => time(),
        ], $platformUserId, $channelId, 300); // 5 min TTL
        
        $reply = $templates['deposit_flow_ask_product'] 
            ?? "รับทราบค่ะ สนใจจองสินค้านะคะ 🎯||SPLIT||รบกวนบอกชื่อรุ่น/รหัส หรือส่งรูปสินค้าที่ต้องการจองมาให้แอดมินได้เลยค่ะ||SPLIT||แอดมินจะรีบเช็คและคำนวณยอดมัดจำให้นะคะ 😊";
        
        return ['reply' => $reply];
    }

    /**
     * Handle deposit when product is already in context
     */
    protected function handleDepositWithProduct(array $params, array $config, array $context): array
    {
        // Get product from params or recent view
        $productCode = $params['product_code'] ?? $params['code'] ?? null;
        
        if ($productCode) {
            // Search for product
            $searchResult = $this->productService->searchByCode($productCode, $config, $context);
            
            if ($searchResult['ok'] && !empty($searchResult['products'])) {
                $product = $searchResult['products'][0];
                $checkoutResult = $this->checkoutService->startCheckout($product, $config, $context, 'deposit');
                return ['reply' => $checkoutResult['reply']];
            }
        }
        
        // Try recently viewed
        $recentProduct = $this->productService->getRecentlyViewed($context);
        
        if ($recentProduct) {
            $product = [
                'ref_id' => $recentProduct['ref_id'] ?? $recentProduct['id'] ?? null,
                'code' => $recentProduct['code'] ?? $recentProduct['product_code'] ?? '',
                'name' => $recentProduct['name'] ?? $recentProduct['title'] ?? 'สินค้า',
                'title' => $recentProduct['name'] ?? $recentProduct['title'] ?? 'สินค้า',
                'price' => $recentProduct['price'] ?? $recentProduct['sale_price'] ?? 0,
                'sale_price' => $recentProduct['sale_price'] ?? $recentProduct['price'] ?? 0,
                'thumbnail_url' => $recentProduct['image'] ?? $recentProduct['thumbnail_url'] ?? null,
            ];
            
            $checkoutResult = $this->checkoutService->startCheckout($product, $config, $context, 'deposit');
            return ['reply' => $checkoutResult['reply']];
        }
        
        // No product found → ask
        return ['reply' => "สนใจจองสินค้าตัวไหนคะ? บอกชื่อรุ่น/รหัส หรือส่งรูปมาได้เลยนะคะ 😊"];
    }

    /**
     * Handle video call request (ขอดูวิดีโอคอล) → Handover to Admin
     */
    protected function handleVideoCallRequest(array $config, array $context, array $templates): array
    {
        $message = "📹 รับทราบค่ะ ลูกค้าต้องการดูสินค้าผ่าน Video Call\n\n";
        $message .= "กำลังแจ้งแอดมินให้โทรกลับหาลูกค้านะคะ\n";
        $message .= "รอสักครู่นะคะ 🙏";

        Logger::info('[ROUTER_V4] Video call requested', [
            'channel_id' => $context['channel']['id'] ?? null,
            'platform_user_id' => $context['platform_user_id'] ?? null,
        ]);

        // TODO: Trigger notification to admin for video call

        return ['reply' => $message];
    }

    /**
     * Handle price negotiation (ลดได้ไหม, ขอส่วนลด) → Handover to Admin
     * Bot cannot negotiate prices, must handover to admin
     */
    protected function handlePriceNegotiation(array $config, array $context, array $templates): array
    {
        $message = "🙏 เรื่องราคาต้องให้แอดมินช่วยดูให้นะคะ\n\n";
        $message .= "กำลังแจ้งแอดมินให้ตอบกลับลูกค้าเดี๋ยวนี้เลยค่ะ\n";
        $message .= "รอสักครู่นะคะ 💛";

        Logger::info('[ROUTER_V4] Price negotiation - handover to admin', [
            'channel_id' => $context['channel']['id'] ?? null,
            'platform_user_id' => $context['platform_user_id'] ?? null,
        ]);

        // TODO: Trigger notification to admin for price negotiation

        return ['reply' => $message];
    }

    // ==================== TRADE-IN (Moved to TransactionService) ====================
    // handleTradeInInquiry() -> $this->transactionService->getTradeInPolicy()
    // handleTradeInCalculate() -> $this->transactionService->calculateTradeIn()

    /**
     * Handle browse products (list all available products)
     */
    protected function handleBrowseProducts(array $config, array $context): array
    {
        // Get featured/popular products
        $result = $this->productService->search('', $config, $context);
        
        if (!$result['ok'] || empty($result['products'])) {
            // Show categories instead
            $categories = $this->getProductCategories($config);
            if ($categories) {
                return ['reply' => "📦 สินค้าของเรา\n\n" . $categories . "\n\n💬 พิมพ์รหัส หรือประเภทที่สนใจได้เลยค่ะ 😊"];
            }
            return ['reply' => "📦 สนใจดูสินค้าประเภทไหนคะ?\n\n• นาฬิกา\n• เครื่องประดับ\n• ทองคำ\n• เพชร\n• กระเป๋า\n\n💬 พิมพ์ประเภทที่สนใจได้เลยค่ะ 😊"];
        }

        $products = array_slice($result['products'], 0, 5);
        return [
            'reply' => "📦 สินค้าแนะนำ\n\n" . $this->productService->formatAsCarousel($products),
            'type' => 'flex'
        ];
    }

    // ==================== PAYMENT OPTIONS (Moved to CheckoutService) ====================
    // handlePaymentOptions() -> $this->checkoutService->getPaymentOptionsInfo()

    /**
     * Handle follow-up info request (more options/colors/sizes)
     */
    protected function handleFollowUpInfo(array $config, array $context): array
    {
        // Check recently viewed product
        $recentProduct = $this->productService->getRecentlyViewed($context);
        
        if ($recentProduct) {
            $code = $recentProduct['code'] ?? $recentProduct['product_code'] ?? null;
            $category = $recentProduct['category'] ?? null;
            
            // Search for similar products
            $result = $this->productService->searchByKeyword($category ?? 'ทอง', $config, $context);
            
            if ($result['ok'] && count($result['products']) > 1) {
                $products = array_filter($result['products'], function($p) use ($code) {
                    return ($p['product_code'] ?? $p['code'] ?? '') !== $code;
                });
                $products = array_slice(array_values($products), 0, 3);
                
                if (!empty($products)) {
                    return [
                        'reply' => "✨ สินค้าใกล้เคียง\n\n" . $this->productService->formatAsCarousel($products),
                        'type' => 'flex'
                    ];
                }
            }
            
            return ['reply' => "📦 สินค้า {$code} มีแบบเดียวค่ะ\n\nสนใจดูสินค้าอื่นไหมคะ? พิมพ์ประเภทที่สนใจได้เลยค่ะ 😊"];
        }
        
        return ['reply' => "📦 สนใจดูสินค้าประเภทไหนคะ?\n\n💬 พิมพ์รหัสสินค้า หรือประเภทที่สนใจได้เลยค่ะ 😊"];
    }

    /**
     * Get product categories for display
     */
    protected function getProductCategories(array $config): ?string
    {
        $categories = [
            '⌚ นาฬิกา (watch)',
            '💍 แหวน (ring)',
            '📿 สร้อยคอ (necklace)',
            '✨ สร้อยข้อมือ (bracelet)',
            '💎 เพชร (diamond)',
            '🥇 ทองคำ (gold)',
            '👜 กระเป๋า (bag)',
        ];
        
        return implode("\n", $categories);
    }

    /**
     * Handle fallback (unknown intent)
     * 
     * Strategy:
     * 1. ✅ NEW: Try PRODUCT SEARCH first (text may be a product name)
     * 2. Try Knowledge Base search
     * 3. Check store info questions
     * 4. LLM fallback
     * 5. Template fallback
     */
    protected function handleFallback(array $config, array $context, array $templates): array
    {
        $text = $context['message']['text'] ?? '';

        // ==================== PRODUCT SEARCH FIRST ====================
        // ✅ NEW: If text looks like a product name (not a question, not too short),
        // try product search with vector/semantic search first
        if ($this->looksLikeProductQuery($text)) {
            Logger::info('[ROUTER_V4] Fallback: trying product search', ['text' => $text]);
            
            $result = $this->productService->search($text, $config, $context);
            
            if ($result['ok'] && !empty($result['products'])) {
                Logger::info('[ROUTER_V4] Fallback: product found via search!', [
                    'source' => $result['source'] ?? 'unknown',
                    'product_count' => count($result['products']),
                ]);
                
                $products = $result['products'];
                $product = $products[0];
                
                // Track first product view
                $this->productService->trackView($product, $context);
                
                // Create/Update case for product inquiry
                $this->createOrUpdateCase(\CaseEngine::CASE_PRODUCT_INQUIRY, [
                    'product_code' => $product['code'] ?? $product['product_code'] ?? null,
                    'product_name' => $product['name'] ?? $product['title'] ?? null,
                    'product_price' => $product['price'] ?? $product['sale_price'] ?? null,
                    'product_ref_id' => $product['ref_id'] ?? $product['id'] ?? null,
                    'product_image_url' => $product['image'] ?? $product['thumbnail_url'] ?? $product['image_url'] ?? null,
                ], $config, $context);
                
                // Format and return product card
                $platform = $context['platform'] ?? 'line';
                return $this->formatProductSearchResponse($products, $platform, $config);
            }
            
            // ✅ NEW: No products found - give helpful message instead of falling through
            if ($result['ok'] && empty($result['products']) && ($result['source'] ?? '') === 'no_match') {
                Logger::info('[ROUTER_V4] Product search: no matching products', ['text' => $text]);
                
                $noMatchReply = "ขออภัยค่ะ ไม่พบสินค้าที่ตรงกับ \"{$text}\" ค่ะ 🔍\n\n";
                $noMatchReply .= "💡 แนะนำ:\n";
                $noMatchReply .= "• ส่งรูปสินค้าที่สนใจมาได้เลยค่ะ 📸\n";
                $noMatchReply .= "• หรือพิมพ์รหัสสินค้า เช่น P-2026-000001\n";
                $noMatchReply .= "• หรือพิมพ์ \"แอดมิน\" เพื่อสอบถามกับแอดมินโดยตรงค่ะ";
                
                return ['reply' => $noMatchReply];
            }
        }

        // ==================== KNOWLEDGE BASE SEARCH ====================
        
        // Try knowledge base search
        $kbResults = $this->knowledgeBaseService->search($context, $text);
        if (!empty($kbResults) && isset($kbResults[0]['answer'])) {
            $bestMatch = $kbResults[0];
            Logger::info('[ROUTER_V4] KB match found', [
                'score' => $bestMatch['match_score'] ?? 0,
                'entry_id' => $bestMatch['id'] ?? null,
            ]);
            return ['reply' => $bestMatch['answer']];
        }

        // ==================== STORE INFO CHECK ====================
        
        // Check for store info questions
        if ($this->knowledgeBaseService->isStoreInfoQuestion($text)) {
            $storeInfo = $this->getStoreInfo($config);
            if ($storeInfo) {
                return ['reply' => $storeInfo];
            }
        }

        // ==================== LLM FALLBACK ====================
        
        // Try LLM for smart response if enabled
        if ($this->isLlmEnabled($config)) {
            $llmResponse = $this->handleWithLLM($context, $config);
            if ($llmResponse) {
                return ['reply' => $llmResponse];
            }
        }

        // Use fallback template
        $fallback = $templates['fallback'] ?? 'ขออภัยค่ะ ไม่เข้าใจค่ะ 😅 ลองถามใหม่อีกครั้งนะคะ หรือพิมพ์ "แอดมิน" เพื่อติดต่อแอดมินได้เลยค่ะ';
        return ['reply' => $fallback];
    }

    // ==================== 🛡️ GATEKEEPER FUNCTIONS ====================

    /**
     * 🛡️ Gatekeeper V2: Dynamic & Context-Aware Message Processing
     * 
     * ปรับปรุงจาก V1:
     * - ✅ Dynamic thresholds จาก config/conversation state
     * - ✅ Context-aware: รู้ว่าบอทเพิ่งถามอะไร
     * - ✅ Gibberish detection
     * - ✅ Platform-specific timing
     * - ✅ Conversation state awareness
     */
    protected function shouldProcessMessage(string $text, string $platformUserId, int $channelId, string $traceId, array $config = []): array
    {
        $text = trim($text);
        $textLen = mb_strlen($text, 'UTF-8');
        
        // 1. 🗑️ Empty check
        if ($textLen === 0) {
            return ['should_process' => false, 'reason' => 'empty'];
        }

        // 2. 🎯 Get Gatekeeper Config (dynamic from bot config)
        $gatekeeperCfg = $config['gatekeeper'] ?? [];
        $skipThreshold = (float) ($gatekeeperCfg['skip_threshold'] ?? 0.3);
        $replyWindowSec = (int) ($gatekeeperCfg['reply_window_seconds'] ?? 15);
        $rapidTypingSec = (int) ($gatekeeperCfg['rapid_typing_seconds'] ?? 3);
        $enableGibberishCheck = (bool) ($gatekeeperCfg['gibberish_detection'] ?? true);
        
        // Platform-specific adjustments
        $platform = $this->chatService->getQuickState('platform', $platformUserId, $channelId)['value'] ?? 'line';
        if ($platform === 'facebook') {
            // Facebook users tend to type faster
            $rapidTypingSec = max(2, $rapidTypingSec - 1);
        }

        // 3. 🔤 Gibberish Detection (random keyboard spam)
        if ($enableGibberishCheck && $this->isGibberish($text)) {
            Logger::debug('[GATEKEEPER] Gibberish detected, skipping', [
                'trace_id' => $traceId,
                'text' => $text,
            ]);
            return ['should_process' => false, 'reason' => 'gibberish'];
        }

        // 4. 🎯 Context-Aware: Check if bot is EXPECTING specific input
        $awaitingInput = $this->chatService->getQuickState('awaiting_input', $platformUserId, $channelId);
        if (!empty($awaitingInput['type'])) {
            $inputType = $awaitingInput['type'];
            $expiresAt = $awaitingInput['expires_at'] ?? 0;
            
            // ถ้ายังไม่หมดอายุ และ input ตรงกับที่รอ → ผ่านทันที
            if (time() < $expiresAt) {
                $matchesExpected = $this->matchesExpectedInput($text, $inputType);
                if ($matchesExpected) {
                    Logger::debug('[GATEKEEPER] Matches expected input, pass through', [
                        'trace_id' => $traceId,
                        'input_type' => $inputType,
                        'text' => mb_substr($text, 0, 30, 'UTF-8'),
                    ]);
                    return ['should_process' => true, 'reason' => 'expected_input', 'info_score' => 1.0];
                }
            }
        }

        // 5. ✅ Quick Reply Whitelist - ตัวเลข 1-9 หรือคำตอบสั้น → ผ่านทันที
        if (preg_match('/^[1-9]$/', $text) || preg_match('/^(ใช่|ไม่|yes|no|ok|โอเค|ได้|ไม่ได้|ตกลง|ยกเลิก|cancel)$/iu', $text)) {
            Logger::debug('[GATEKEEPER] Quick reply whitelist, pass through', [
                'trace_id' => $traceId,
                'text' => $text,
            ]);
            return ['should_process' => true, 'reason' => 'quick_reply', 'info_score' => 1.0];
        }

        // 6. 📊 Calculate Information Density Score (0.0 - 1.0)
        $infoScore = $this->calculateInfoScore($text, $config);
        
        // 7. ⏱️ Get timing context
        $lastReply = $this->chatService->getQuickState('last_bot_reply_time', $platformUserId, $channelId);
        $lastReplyTime = $lastReply['time'] ?? 0;
        $timeSinceReply = time() - $lastReplyTime;
        
        $lastUserMsg = $this->chatService->getQuickState('last_user_msg', $platformUserId, $channelId);
        $lastMsgTime = $lastUserMsg['time'] ?? 0;
        $timeSinceLastMsg = time() - $lastMsgTime;
        
        // 8. 📈 Dynamic Threshold Adjustment
        // ถ้าบอทเพิ่งถามคำถาม → ลด threshold (ยอมรับคำตอบสั้นมากขึ้น)
        $lastBotAction = $this->chatService->getQuickState('last_bot_action', $platformUserId, $channelId);
        $botAskedQuestion = ($lastBotAction['type'] ?? '') === 'question';
        if ($botAskedQuestion && $timeSinceReply < 60) {
            $skipThreshold = max(0.1, $skipThreshold - 0.15);
        }

        // 9. 🚫 Decision Logic with dynamic thresholds
        if ($infoScore < $skipThreshold) {
            // Case A: บอทเพิ่งตอบไป < replyWindowSec และข้อความไม่มีสาระ
            if ($timeSinceReply < $replyWindowSec && $timeSinceReply >= 0) {
                Logger::debug('[GATEKEEPER] Low info after reply, skipping', [
                    'trace_id' => $traceId,
                    'text' => $text,
                    'info_score' => $infoScore,
                    'threshold' => $skipThreshold,
                    'time_since_reply' => $timeSinceReply,
                ]);
                return ['should_process' => false, 'reason' => 'low_info_after_reply'];
            }
            
            // Case B: พิมพ์รัว < rapidTypingSec และข้อความไม่มีสาระ
            if ($timeSinceLastMsg < $rapidTypingSec && $timeSinceLastMsg >= 0) {
                Logger::debug('[GATEKEEPER] Low info rapid typing, skipping', [
                    'trace_id' => $traceId,
                    'text' => $text,
                    'info_score' => $infoScore,
                    'threshold' => $skipThreshold,
                    'time_since_last' => $timeSinceLastMsg,
                ]);
                return ['should_process' => false, 'reason' => 'low_info_rapid'];
            }
        }

        // 10. บันทึกข้อความล่าสุดไว้เช็คในครั้งต่อไป
        $this->chatService->setQuickState('last_user_msg', [
            'text' => $text,
            'time' => time()
        ], $platformUserId, $channelId, 60);

        return ['should_process' => true, 'reason' => 'ok', 'info_score' => $infoScore];
    }

    /**
     * 🔤 Gibberish Detection - ตรวจจับข้อความไม่มีความหมาย
     * 
     * ตรวจจับ:
     * - Random keyboard: asdfghjkl, qwerty, ฟหกดสา
     * - Repeated characters: กกกกกก, 5555555
     * - Random Unicode: ◕‿◕, ₪₪₪
     */
    protected function isGibberish(string $text): bool
    {
        $text = trim($text);
        $len = mb_strlen($text, 'UTF-8');
        
        // ข้อความสั้นมาก → ไม่เช็ค gibberish (อาจเป็น "ได้", "ok")
        if ($len <= 3) {
            return false;
        }
        
        // 1. 🔁 Repeated single character (กกกกก, 55555)
        if (preg_match('/^(.)\1{4,}$/u', $text)) {
            return true;
        }
        
        // 2. ⌨️ Keyboard row patterns (English)
        $keyboardRows = [
            'qwertyuiop',
            'asdfghjkl',
            'zxcvbnm',
            'qwerty',
            'asdf',
            'zxcv',
        ];
        $lowerText = mb_strtolower($text, 'UTF-8');
        foreach ($keyboardRows as $row) {
            if (strpos($lowerText, $row) !== false) {
                return true;
            }
        }
        
        // 3. ⌨️ Keyboard patterns (Thai) - ฟหกดส, ไำพะ
        $thaiKeyboardRows = [
            'ฟหกดสา',
            'ไำพะ',
            'ฟหกด',
            'กดสา',
        ];
        foreach ($thaiKeyboardRows as $row) {
            if (mb_strpos($text, $row) !== false) {
                return true;
            }
        }
        
        // 4. 📊 High consonant ratio without vowels (Thai gibberish)
        if ($len >= 5) {
            $thaiConsonants = preg_match_all('/[ก-ฮ]/u', $text);
            $thaiVowels = preg_match_all('/[ะาิีึืุูเแโใไำ]/u', $text);
            
            // ถ้ามี consonant ล้วนๆ > 5 ตัว และไม่มี vowel → gibberish
            if ($thaiConsonants >= 5 && $thaiVowels === 0) {
                return true;
            }
        }
        
        // 5. 🔢 Only repeated numbers (แต่ไม่ใช่เบอร์โทร/รหัส)
        if (preg_match('/^(\d)\1{5,}$/', $text)) {
            return true;
        }
        
        return false;
    }

    /**
     * 🎯 Check if text matches expected input type
     */
    protected function matchesExpectedInput(string $text, string $inputType): bool
    {
        switch ($inputType) {
            case 'number':
            case 'quantity':
                return preg_match('/^\d+$/', $text);
                
            case 'yes_no':
            case 'confirm':
                return preg_match('/^(ใช่|ไม่|yes|no|ok|ได้|ไม่ได้|ตกลง|ยกเลิก|cancel|1|2)$/iu', $text);
                
            case 'selection':
                return preg_match('/^[1-9]$/', $text);
                
            case 'phone':
                return preg_match('/^0[0-9]{8,9}$/', preg_replace('/\D/', '', $text));
                
            case 'address':
                return mb_strlen($text, 'UTF-8') >= 10;
                
            case 'name':
                return mb_strlen($text, 'UTF-8') >= 2 && mb_strlen($text, 'UTF-8') <= 100;
                
            case 'product_code':
                return preg_match('/^[A-Z0-9\-]{3,}$/i', $text);
                
            case 'any':
                return mb_strlen(trim($text), 'UTF-8') > 0;
                
            default:
                return false;
        }
    }

    /**
     * 📊 Calculate Information Density Score V2 (0.0 - 1.0)
     * 
     * ปรับปรุง:
     * - ✅ Dynamic keywords จาก config
     * - ✅ Better scoring algorithm
     * - ✅ More patterns
     */
    protected function calculateInfoScore(string $text, array $config = []): float
    {
        $text = trim($text);
        $len = mb_strlen($text, 'UTF-8');
        
        // === Early Exit: Pure filler words ===
        if (preg_match('/^(ครับ|ค่ะ|คะ|คับ|นะคะ|นะครับ|จ้า|จ้ะ|จ๊า|ค่า|เค|โอเค|ok|okay|k|kk|อืม|อ่า|อา|เออ|yes|no|y|n)+[!?.\s]*$/iu', $text)) {
            return 0.0;
        }
        
        // === Early Exit: Single emoji or sticker ===
        if (preg_match('/^[\p{So}\p{Cs}]+$/u', $text) || $text === '[sticker]') {
            return 0.1;
        }
        
        // === Base Score from Length ===
        $lengthScore = min($len / 40, 0.5);
        
        // === Get Custom Keywords from Config ===
        $gatekeeperCfg = $config['gatekeeper'] ?? [];
        $customActionKeywords = $gatekeeperCfg['action_keywords'] ?? [];
        $customProductKeywords = $gatekeeperCfg['product_keywords'] ?? [];
        $customBrandKeywords = $gatekeeperCfg['brand_keywords'] ?? [];
        
        // === Pattern Boosts ===
        $boosts = 0.0;
        $matchedPatterns = [];
        
        // 🏷️ Product code pattern (A-123, GLD-NCK-001)
        if (preg_match('/[A-Z]{2,5}[-]?[A-Z0-9]{2,}/i', $text)) {
            $boosts += 0.6;
            $matchedPatterns[] = 'product_code';
        }
        
        // 🔢 Numbers with 3+ digits (price, phone, quantity)
        if (preg_match('/\d{3,}/', $text)) {
            $boosts += 0.4;
            $matchedPatterns[] = 'number';
        }
        
        // ❓ Question indicators
        if (preg_match('/(ไหม|มั้ย|เท่าไหร่|ยังไง|อะไร|ที่ไหน|เมื่อไหร่|กี่|how|what|where|when|why|\?)/iu', $text)) {
            $boosts += 0.5;
            $matchedPatterns[] = 'question';
        }
        
        // 🛒 Action keywords (built-in + custom)
        $defaultActionKeywords = 'สนใจ|ซื้อ|เอา|ดู|ขอ|จอง|รับ|ต้องการ|อยาก|หา|เช็ค|ตรวจ|ถาม|สอบถาม|สวัสดี|ทัก|hello|hi';
        $actionPattern = $defaultActionKeywords;
        if (!empty($customActionKeywords)) {
            $actionPattern .= '|' . implode('|', array_map('preg_quote', $customActionKeywords));
        }
        if (preg_match('/^(' . $actionPattern . ')/iu', $text)) {
            $boosts += 0.6;
            $matchedPatterns[] = 'action';
        }
        
        // 💰 Business keywords
        if (preg_match('/(ราคา|ผ่อน|โอน|มัดจำ|จ่าย|ชำระ|ส่ง|จัดส่ง|ที่อยู่|price|pay|ship|delivery)/iu', $text)) {
            $boosts += 0.5;
            $matchedPatterns[] = 'business';
        }
        
        // 💎 Product category keywords (built-in + custom)
        $defaultProductKeywords = 'นาฬิกา|แหวน|สร้อย|กำไล|จี้|ต่างหู|เพชร|ทอง|ทองคำ|พลอย|ไข่มุก|เงิน|กระเป๋า|watch|ring|necklace|bracelet|diamond|gold|bag';
        $productPattern = $defaultProductKeywords;
        if (!empty($customProductKeywords)) {
            $productPattern .= '|' . implode('|', array_map('preg_quote', $customProductKeywords));
        }
        if (preg_match('/(' . $productPattern . ')/iu', $text)) {
            $boosts += 0.5;
            $matchedPatterns[] = 'product';
        }
        
        // 🏷️ Brand names (built-in + custom)
        $defaultBrands = 'rolex|omega|patek|cartier|audemars|hublot|iwc|panerai|chanel|hermes|louis vuitton|lv|gucci|dior|bulgari|tiffany|van cleef|chopard';
        $brandPattern = $defaultBrands;
        if (!empty($customBrandKeywords)) {
            $brandPattern .= '|' . implode('|', array_map('preg_quote', $customBrandKeywords));
        }
        if (preg_match('/(' . $brandPattern . ')/iu', $text)) {
            $boosts += 0.5;
            $matchedPatterns[] = 'brand';
        }
        
        // 📍 Address indicators
        if (preg_match('/(เขต|อำเภอ|ตำบล|จังหวัด|ถนน|ซอย|หมู่|บ้านเลขที่|\d+\/\d+)/u', $text)) {
            $boosts += 0.5;
            $matchedPatterns[] = 'address';
        }
        
        // 🔢 Quick Reply - ตัวเลขเดี่ยว 1-9
        if (preg_match('/^[1-9]$/', $text)) {
            $boosts += 0.6;
            $matchedPatterns[] = 'quick_reply';
        }
        
        // 📞 Phone number pattern
        if (preg_match('/0[689][0-9]{7,8}/', preg_replace('/\D/', '', $text))) {
            $boosts += 0.5;
            $matchedPatterns[] = 'phone';
        }
        
        // 💬 Complaint/Urgent keywords
        if (preg_match('/(ด่วน|urgent|รีบ|เร่ง|ปัญหา|problem|ช่วย|help|ติดต่อ|แจ้ง)/iu', $text)) {
            $boosts += 0.6;
            $matchedPatterns[] = 'urgent';
        }
        
        // === Final Score ===
        $finalScore = min($lengthScore + $boosts, 1.0);
        
        // Log matched patterns for debugging
        if (!empty($matchedPatterns)) {
            Logger::debug('[INFO_SCORE] Matched patterns', [
                'text_preview' => mb_substr($text, 0, 30, 'UTF-8'),
                'score' => $finalScore,
                'patterns' => $matchedPatterns,
            ]);
        }
        
        return $finalScore;
    }

    // ==================== 📝 MESSAGE BUFFER FUNCTIONS ====================

    /**
     * เก็บข้อความที่ถูก skip ลง buffer เพื่อรวมบริบทในภายหลัง
     * Buffer จะหมดอายุใน 30 วินาที (ถ้าลูกค้าหยุดพิมพ์นานกว่านั้น = เริ่มใหม่)
     */
    protected function appendToMessageBuffer(string $text, string $platformUserId, int $channelId): void
    {
        $text = trim($text);
        if (empty($text)) return;
        
        // ดึง buffer เดิม
        $existing = $this->chatService->getQuickState('msg_buffer', $platformUserId, $channelId);
        $buffer = $existing['messages'] ?? [];
        $bufferTime = $existing['first_msg_time'] ?? time();
        
        // ถ้า buffer เก่าเกิน 30 วิ → เริ่มใหม่
        if ((time() - $bufferTime) > 30) {
            $buffer = [];
            $bufferTime = time();
        }
        
        // เพิ่มข้อความใหม่ (จำกัด 5 ข้อความ ป้องกัน spam)
        $buffer[] = $text;
        if (count($buffer) > 5) {
            $buffer = array_slice($buffer, -5);
        }
        
        $this->chatService->setQuickState('msg_buffer', [
            'messages' => $buffer,
            'first_msg_time' => $bufferTime,
            'last_msg_time' => time(),
        ], $platformUserId, $channelId, 60);
    }

    /**
     * ดึง buffer และล้างทิ้ง (ใช้ครั้งเดียว)
     * Return: ข้อความรวมกัน คั่นด้วย space
     * 
     * ปรับปรุง: กรองข้อความที่ไม่เกี่ยวกับสินค้าออก (เช่น greeting, general inquiry)
     */
    protected function getAndClearMessageBuffer(string $platformUserId, int $channelId): string
    {
        $existing = $this->chatService->getQuickState('msg_buffer', $platformUserId, $channelId);
        
        if (empty($existing['messages'])) {
            return '';
        }
        
        // ล้าง buffer
        $this->chatService->deleteQuickState('msg_buffer', $platformUserId, $channelId);
        
        // รวมข้อความ กรอง phatic words และ non-product queries ออก
        $messages = $existing['messages'];
        
        // กรองคำที่ไม่เกี่ยวกับสินค้าออก
        $filtered = array_filter($messages, function($msg) {
            $msg = trim($msg);
            if (empty($msg)) return false;
            
            // กรองคำลงท้ายเดี่ยวๆ ออก
            if (preg_match('/^(ครับ|ค่ะ|คะ|คับ|จ้า|โอเค|ok|k)+[!?.\s]*$/iu', $msg)) {
                return false;
            }
            
            // กรอง greeting ออก (สวัสดี, หวัดดี, ดีครับ, hello, hi)
            if (preg_match('/^(สวัสดี|หวัดดี|ดี|ดีครับ|ดีค่ะ|ดีคะ|hello|hi|hey)[\s!]*$/iu', $msg)) {
                return false;
            }
            
            // กรอง general inquiry ที่ไม่มีคำสำคัญ (มีอะไร, มีอะไรบ้าง, ขายอะไร)
            if (preg_match('/^(มีอะไร|ขายอะไร|มีไรบ้าง|มีอะไรขาย|ดูสินค้า)[\s\?!]*$/iu', $msg)) {
                return false;
            }
            
            // กรอง ack messages (ได้เลย, รับทราบ, ok, โอเค)
            if (preg_match('/^(ได้เลย|ได้ครับ|ได้ค่ะ|รับทราบ|เข้าใจ|โอเค|ตกลง|ok|okay)+[!\.\s]*$/iu', $msg)) {
                return false;
            }
            
            // กรอง thanks (ขอบคุณ, แล้วเจอกัน)
            if (preg_match('/^(ขอบคุณ|ขอบใจ|thanks|thank\s*you|แล้วเจอกัน|ไว้เจอกัน|บาย|bye)+[!\.\s]*$/iu', $msg)) {
                return false;
            }
            
            return true;
        });
        
        // ถ้าหลังกรองแล้วไม่เหลืออะไร → return empty
        if (empty($filtered)) {
            return '';
        }
        
        // ดึงเฉพาะส่วนที่เกี่ยวกับสินค้าจากแต่ละข้อความ
        $productRelevantParts = [];
        foreach ($filtered as $msg) {
            $extracted = $this->extractProductKeywords($msg);
            if (!empty($extracted)) {
                $productRelevantParts[] = $extracted;
            }
        }
        
        // ถ้าดึงคำสำคัญได้ → ใช้คำสำคัญ ไม่งั้นใช้ข้อความเต็ม
        if (!empty($productRelevantParts)) {
            return implode(' ', array_unique($productRelevantParts));
        }
        
        return implode(' ', $filtered);
    }
    
    /**
     * ดึงคำสำคัญที่เกี่ยวกับสินค้าจากข้อความ
     * เช่น "อยากได้นาฬิกา rolex" → "นาฬิกา rolex"
     */
    protected function extractProductKeywords(string $text): string
    {
        $text = trim($text);
        
        // ลบคำนำหน้าที่ไม่จำเป็น
        $prefixes = [
            'อยากได้', 'อยากดู', 'ต้องการ', 'สนใจ', 'หา', 'ขอดู', 'ขอ', 'เอา', 
            'มี', 'หรือเปล่า', 'รึเปล่า', 'บ้าง', 'ไหม', 'มั้ย', 'หน่อย',
            'ดู', 'แนะนำ', 'ช่วยหา', 'ช่วยดู'
        ];
        
        // Sort by length descending
        usort($prefixes, function($a, $b) {
            return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
        });
        
        foreach ($prefixes as $prefix) {
            $text = preg_replace('/^' . preg_quote($prefix, '/') . '\s*/u', '', $text);
        }
        
        // ลบคำลงท้าย (ครับ, ค่ะ, ไหม, มั้ย, หน่อย, etc.)
        $suffixes = ['ครับ', 'ค่ะ', 'คะ', 'คับ', 'จ้า', 'นะ', 'ไหม', 'มั้ย', 'หน่อย', 'บ้าง', 'ด้วย'];
        usort($suffixes, function($a, $b) {
            return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
        });
        
        foreach ($suffixes as $suffix) {
            $text = preg_replace('/' . preg_quote($suffix, '/') . '\s*$/u', '', $text);
        }
        
        return trim($text);
    }

    /**
     * Check if text looks like a product query (name, brand, model)
     * rather than a question or greeting
     */
    protected function looksLikeProductQuery(string $text): bool
    {
        $text = trim($text);
        $len = mb_strlen($text);
        
        // Too short or too long - probably not a product name
        if ($len < 3 || $len > 100) {
            return false;
        }
        
        // Skip if it's a question (contains question marks or Thai question words)
        if (preg_match('/[?？]/u', $text)) {
            return false;
        }
        if (preg_match('/^(ทำไม|อะไร|ที่ไหน|เมื่อไหร่|ยังไง|อย่างไร|ไหม|มั้ย|หรือ)/u', $text)) {
            return false;
        }
        
        // Skip common greetings/thanks
        if (preg_match('/^(สวัสดี|ดีค่ะ|ดีครับ|ขอบคุณ|ขอบใจ|hello|hi|hey|thanks)/iu', $text)) {
            return false;
        }
        
        // Skip common service requests (these have their own intents)
        if (preg_match('/(ซ่อม|จำนำ|ผ่อน|แอดมิน|admin|ติดต่อ)/u', $text)) {
            return false;
        }
        
        // ✅ Positive signals: looks like product name/brand
        // Contains brand names, watch terms, jewelry terms
        if (preg_match('/(rolex|omega|patek|cartier|audemars|richard mille|hublot|iwc|panerai|breitling|tag heuer|tissot|seiko|citizen|casio|g-shock|tudor|longines|chopard|bvlgari|bulgari|tiffany|chanel|van cleef|piaget)/iu', $text)) {
            return true;
        }
        
        // Watch/Jewelry model indicators
        if (preg_match('/(submariner|daytona|datejust|gmt|speedmaster|seamaster|nautilus|royal oak|santos|tank|aquanaut|calatrava|oyster|perpetual|chronograph)/iu', $text)) {
            return true;
        }
        
        // ✅ Common nicknames for luxury items (customers often type short names)
        // Rolex: green sub (Hulk), pepsi, batman, root beer, starbucks (นาฬิกาเขียว), panda (Daytona)
        // Bags: kelly, birkin, constance, boyy, king size
        if (preg_match('/(แพม|pam|green sub|hulk|pepsi|batman|root beer|starbuck|starbucks|panda|king size|boyy|kelly|birkin|constance|j12|coke|แบทแมน|เป๊ปซี่|แพนด้า)/iu', $text)) {
            return true;
        }
        
        // Thai product category keywords
        if (preg_match('/(นาฬิกา|แหวน|สร้อย|กำไล|จี้|ต่างหู|เพชร|ทอง|ทองคำ|เงิน|พลอย|ไข่มุก)/u', $text)) {
            return true;
        }
        
        // Mixed alphanumeric that might be product code/name
        // e.g., "Submariner Date Black" or "GMT Master II"
        if (preg_match('/^[A-Za-z0-9\s\-\.]+$/u', $text) && preg_match('/[A-Za-z]/u', $text)) {
            return true;
        }
        
        // Contains both English and Thai (common for product names)
        if (preg_match('/[A-Za-z]/u', $text) && preg_match('/[\x{0E00}-\x{0E7F}]/u', $text)) {
            return true;
        }
        
        return false;
    }

    // ==================== 🎯 GATEKEEPER HELPER FUNCTIONS ====================

    /**
     * 📝 Set awaiting input state - บอทกำลังรอ input เฉพาะ
     * 
     * ใช้เมื่อบอทถามคำถามและต้องการคำตอบเฉพาะ เช่น:
     * - รอเลือกตัวเลข 1-3
     * - รอยืนยัน ใช่/ไม่
     * - รอกรอกที่อยู่
     * 
     * @param string $inputType Type: number, yes_no, selection, phone, address, name, product_code, any
     * @param string $platformUserId 
     * @param int $channelId
     * @param int $ttlSeconds Time to wait (default 120 seconds = 2 minutes)
     */
    protected function setAwaitingInput(string $inputType, string $platformUserId, int $channelId, int $ttlSeconds = 120): void
    {
        $this->chatService->setQuickState('awaiting_input', [
            'type' => $inputType,
            'expires_at' => time() + $ttlSeconds,
            'set_at' => time(),
        ], $platformUserId, $channelId, $ttlSeconds);
    }

    /**
     * 🗑️ Clear awaiting input state
     */
    protected function clearAwaitingInput(string $platformUserId, int $channelId): void
    {
        $this->chatService->deleteQuickState('awaiting_input', $platformUserId, $channelId);
    }

    /**
     * 📝 Set last bot action - บอทเพิ่งทำอะไรไป
     * 
     * ใช้เพื่อให้ gatekeeper รู้ว่าบอทเพิ่งถามคำถามหรือแสดงรายการ
     * 
     * @param string $actionType Type: question, list, confirm, info, greeting
     * @param string $platformUserId
     * @param int $channelId
     * @param array $extra Extra data เช่น question text, list items
     */
    protected function setLastBotAction(string $actionType, string $platformUserId, int $channelId, array $extra = []): void
    {
        $this->chatService->setQuickState('last_bot_action', array_merge([
            'type' => $actionType,
            'time' => time(),
        ], $extra), $platformUserId, $channelId, 300); // Keep for 5 minutes
    }

    /**
     * 📝 Record bot reply time (for gatekeeper timing)
     */
    protected function recordBotReplyTime(string $platformUserId, int $channelId): void
    {
        $this->chatService->setQuickState('last_bot_reply_time', [
            'time' => time(),
        ], $platformUserId, $channelId, 120);
    }

    // ==================== 🔍 PRODUCT SEARCH FUNCTIONS ====================

    /**
     * Format product search response (used by both handleProductSearch and handleFallback)
     */
    protected function formatProductSearchResponse(array $products, string $platform, array $config): array
    {
        if (empty($products)) {
            return ['reply' => 'ยังไม่เจอสินค้าที่ค้นหาค่ะ 🔍 ลองพิมพ์คำค้นอื่น หรือดูสินค้าแนะนำได้เลยนะคะ'];
        }
        
        return [
            'reply' => $this->productService->formatAsCarousel($products),
            'type' => 'flex'
        ];
    }

    // ==================== IMAGE HANDLING ====================

    /**
     * Handle image message
     */
    protected function handleImage(?string $imageUrl, array $config, array $context, array $templates, string $traceId): array
    {
        if (!$imageUrl) {
            return $this->makeResponse('รับรูปไม่ได้ค่ะ 😅 รบกวนลองส่งใหม่อีกครั้งนะคะ', 'image_error', $traceId);
        }

        Logger::info('[ROUTER_V4] handleImage called', [
            'trace_id' => $traceId,
            'image_url' => substr($imageUrl, 0, 100),
            'image_search_enabled' => $this->isBackendEnabled($config, 'image_search'),
            'slip_enabled' => $this->isPaymentSlipEnabled($config),
        ]);

        // Check if user has pending checkout or pending orders → likely a payment slip
        $platformUserId = $context['platform_user_id'] ?? $context['external_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;
        $hasPendingCheckout = false;
        $hasPendingOrder = false;
        
        if ($platformUserId && $channelId) {
            $checkoutState = $this->checkoutService->getCheckoutState($platformUserId, $channelId);
            $hasPendingCheckout = !empty($checkoutState);
            
            // ✅ Also check for pending orders (orders waiting for payment)
            $hasPendingOrder = $this->hasPendingOrderForUser($platformUserId, $channelId);
            
            // ============================================================
            // ✅ CRITICAL FIX: Check Pending Intent (Pawn/Sell) FIRST
            // If customer just asked about ฝากขาย/รับฝาก, this image is for assessment
            // NOT for product search! Without this, pawn images go to product search.
            // ============================================================
            $pendingIntent = $this->chatService->getQuickState('pending_intent', $platformUserId, (int)$channelId);
            $intentName = $pendingIntent['intent'] ?? '';
            
            if ($intentName === 'pawn_assessment') {
                Logger::info('[ROUTER_V4] Image received for Pawn Assessment (pending_intent matched)', [
                    'trace_id' => $traceId,
                    'platform_user_id' => $platformUserId,
                ]);
                
                // Clear state to prevent stale context
                $this->chatService->deleteQuickState('pending_intent', $platformUserId, (int)$channelId);
                
                // ✅ NOW activate handoff - admin needs to review and price this
                $this->activateAdminHandoff($context['session_id'] ?? null, $context, 'pawn_image_received');
                
                // Acknowledge and inform customer that admin will review
                $msg = "ได้รับรูปประเมินราคาแล้วค่ะ 📸\n\n" .
                       "แอดมินกำลังตรวจสอบสภาพและราคาตลาด\n" .
                       "ขั้นตอนนี้อาจใช้เวลา 5-10 นาทีค่ะ\n\n" .
                       "หากมีรูปเพิ่มเติม (ใบรับประกัน/สภาพสินค้า) ส่งมาได้เลยนะคะ 😊";
                
                return $this->makeResponse($msg, 'pawn_image_received', $traceId);
            }
        }

        Logger::info('[ROUTER_V4] Image slip detection check', [
            'trace_id' => $traceId,
            'platform_user_id' => $platformUserId,
            'has_pending_checkout' => $hasPendingCheckout,
            'has_pending_order' => $hasPendingOrder,
        ]);

        // ✅ ALWAYS use Gemini Vision to detect image type FIRST
        // This prevents misclassifying product images as slips when user has pending order
        $llmIntegration = $this->getLlmIntegration($context);
        $visionResult = null;
        $detectedRoute = 'image_generic';
        $geminiApiError = false;
        
        if ($llmIntegration) {
            $visionResult = $this->analyzeImageWithGemini($llmIntegration, $imageUrl, $config);
            $detectedRoute = $visionResult['route'] ?? 'image_generic';
            $geminiApiError = isset($visionResult['error']) && !empty($visionResult['error']);
            
            Logger::info('[ROUTER_V4] Gemini Vision analyzed image', [
                'trace_id' => $traceId,
                'detected_route' => $detectedRoute,
                'has_pending_order' => $hasPendingOrder,
                'api_error' => $geminiApiError,
            ]);
        }

        // ✅ FIX: If Gemini API failed → DON'T assume it's a slip
        // Instead, acknowledge image receipt and let admin handle
        // Previous bug: treated any image as slip when API failed + pending order
        if ($geminiApiError) {
            Logger::info('[ROUTER_V4] Gemini API error → asking for clarification', [
                'trace_id' => $traceId,
                'error' => $visionResult['error'] ?? 'unknown',
                'has_pending_checkout' => $hasPendingCheckout,
                'has_pending_order' => $hasPendingOrder,
            ]);
            
            $clarifyMsg = $templates['image_clarify'] ?? 
                "ได้รับรูปแล้วค่ะ 📷\n\n" .
                "กรุณาระบุว่าส่งรูปเพื่อ:\n" .
                "• ค้นหาสินค้า พิมพ์ \"ค้นหา\"\n" .
                "• แจ้งโอนเงิน พิมพ์ \"สลิป\"\n" .
                "• ส่งรูปประเมินราคา พิมพ์ \"แอดมิน\"\n\n" .
                "หรือพิมพ์บอกรายละเอียดเพิ่มเติมได้เลยค่ะ 😊";
            return $this->makeResponse($clarifyMsg, 'image_clarify', $traceId);
        }

        // ✅ Route based on Gemini Vision detection
        // Also check raw image_type in case confidence was slightly below threshold
        $rawImageType = $visionResult['image_type'] ?? 'image_generic';
        $visionConfidence = $visionResult['confidence'] ?? 0.0;
        
        // ✅ FIX: If Gemini detected payment_proof (even with lower confidence) AND user has pending order → treat as slip
        $isLikelySlip = ($detectedRoute === 'payment_proof' || $detectedRoute === 'slip')
            || ($rawImageType === 'payment_proof' && ($hasPendingCheckout || $hasPendingOrder) && $visionConfidence >= 0.3);
        
        if ($isLikelySlip) {
            Logger::info('[ROUTER_V4] Image confirmed as payment slip by Gemini', [
                'trace_id' => $traceId,
                'route' => $detectedRoute,
                'raw_image_type' => $rawImageType,
                'confidence' => $visionConfidence,
                'has_pending_order' => $hasPendingOrder,
            ]);
            return $this->handlePaymentSlip($imageUrl, $config, $context, $traceId);
        }
        
        // ✅ If Gemini detected it's a product image, do product search (even if has pending order)
        if ($detectedRoute === 'product' || $detectedRoute === 'product_image' || $detectedRoute === 'product_inquiry') {
            Logger::info('[ROUTER_V4] Image detected as product, redirecting to product search', [
                'trace_id' => $traceId,
                'route' => $detectedRoute,
            ]);
            // Fall through to image_search handling below
        }
        
        // ✅ FIX: Always try image search FIRST (even for image_generic)
        // Only fallback to slip if image search fails AND user has pending checkout
        if ($this->isBackendEnabled($config, 'image_search')) {
            $result = $this->productService->searchByImage($imageUrl, $config, $context);
            
            if ($result['ok'] && !empty($result['products'])) {
                Logger::info('[ROUTER_V4] Image search found products', [
                    'trace_id' => $traceId,
                    'product_count' => count($result['products']),
                ]);
                $flexMessage = $this->productService->formatAsCarousel($result['products']);
                // Return flex message in reply_messages, and use altText as fallback reply_text
                return [
                    'reply_text' => $flexMessage['altText'] ?? 'พบสินค้าที่คล้ายกัน',
                    'reply_messages' => [$flexMessage],
                    'actions' => [],
                    'meta' => [
                        'handler' => 'router_v4',
                        'reason' => 'image_search',
                        'trace_id' => $traceId,
                    ]
                ];
            }
            
            // Image analyzed but no products found - check if should fallback to slip
            $detectedDesc = $result['detected_description'] ?? null;
            
            // ✅ FIX (2025-01-31): DON'T auto-fallback to slip anymore!
            // Previous bug: When image_generic + pending order → assumed slip → created wrong payments
            // Now: If product not found, ask user or handoff to admin
            
            // Show what was detected if available
            if ($detectedDesc) {
                $notFoundMsg = $templates['image_product'] ?? 
                    "แอดมินได้รับรูปภาพเรียบร้อยแล้วค่ะ 😊\n\nเดี๋ยวขอเวลาตรวจสอบในสต็อกสักครู่นะคะ";
                $notFoundMsg .= "\n\n🔍 ตรวจพบ: " . mb_substr($detectedDesc, 0, 100);
                
                // If pending order exists, mention it
                if ($hasPendingCheckout || $hasPendingOrder) {
                    $notFoundMsg .= "\n\nหากต้องการส่งสลิป กรุณาพิมพ์ \"สลิป\" หรือ \"แจ้งโอน\" ค่ะ";
                }
                
                return $this->makeResponse($notFoundMsg, 'image_search_no_result', $traceId);
            }
        }

        // ✅ FIX: Only use slip fallback if explicitly detected as payment
        // Don't assume slip just because slip processing is enabled
        // (Moved after image_search to prevent false positives)

        // Default: acknowledge image receipt and offer help
        $imageAck = $templates['image_received'] ?? 
            "ได้รับรูปแล้วค่ะ 📷\n\nหากต้องการส่งสลิป กรุณาเลือกสินค้าก่อนนะคะ\n\nหรือพิมพ์รหัส/ชื่อสินค้าที่สนใจได้เลยค่ะ 😊";
        return $this->makeResponse($imageAck, 'image_received', $traceId);
    }

    /**
     * Handle payment slip image - Uses Gemini Vision + PaymentService
     */
    protected function handlePaymentSlip(string $imageUrl, array $config, array $context, string $traceId): array
    {
        Logger::info('[ROUTER_V4] Payment slip received - starting OCR', [
            'trace_id' => $traceId,
            'channel_id' => $context['channel']['id'] ?? null,
            'image_url_preview' => substr($imageUrl, 0, 100),
        ]);

        // 1. Get LLM integration for Gemini Vision
        $llmIntegration = $this->getLlmIntegration($context);
        $geminiDetails = [];
        $visionMeta = null;
        
        // 2. Analyze image with Gemini Vision
        if ($llmIntegration && $imageUrl) {
            $geminiResult = $this->analyzeImageWithGemini($llmIntegration, $imageUrl, $config);
            
            if (empty($geminiResult['error'])) {
                $geminiDetails = $geminiResult['details'] ?? [];
                $visionMeta = $geminiResult['meta'] ?? null;
                
                Logger::info('[ROUTER_V4] Gemini Vision analysis complete', [
                    'trace_id' => $traceId,
                    'has_details' => !empty($geminiDetails),
                    'amount' => $geminiDetails['amount'] ?? null,
                ]);
            } else {
                Logger::warning('[ROUTER_V4] Gemini Vision failed', [
                    'error' => $geminiResult['error'],
                ]);
            }
        }

        // 3. Build extracted info message
        $slipAmount = $geminiDetails['amount'] ?? null;
        $slipBank = $geminiDetails['bank'] ?? null;
        $slipDate = $geminiDetails['date'] ?? null;
        $slipRef = $geminiDetails['ref'] ?? null;
        $slipSender = $geminiDetails['sender_name'] ?? null;

        $extractedInfo = '';
        if ($slipAmount) {
            $extractedInfo = "📋 ข้อมูลจากสลิป:\n";
            if ($slipAmount) $extractedInfo .= "💰 จำนวนเงิน: {$slipAmount} บาท\n";
            if ($slipBank) $extractedInfo .= "🏦 ธนาคาร: {$slipBank}\n";
            if ($slipDate) $extractedInfo .= "📅 วันที่: {$slipDate}\n";
            if ($slipRef) $extractedInfo .= "🔢 เลขอ้างอิง: {$slipRef}\n";
            if ($slipSender) $extractedInfo .= "👤 ผู้โอน: {$slipSender}\n";
        }

        // 4. Process with PaymentService for proper insert and auto-matching
        try {
            require_once __DIR__ . '/../services/PaymentService.php';
            $paymentService = new \Autobot\Services\PaymentService();

            $paymentResult = $paymentService->processSlipFromChatbot(
                $geminiDetails,
                $context,
                $imageUrl
            );

            Logger::info('[ROUTER_V4] PaymentService result', [
                'trace_id' => $traceId,
                'success' => $paymentResult['success'] ?? false,
                'payment_id' => $paymentResult['payment_id'] ?? null,
                'matched_order' => $paymentResult['matched_order_no'] ?? null,
            ]);

            if ($paymentResult['success']) {
                $paymentNo = $paymentResult['payment_no'] ?? '';
                $matchedOrderNo = $paymentResult['matched_order_no'] ?? null;

                // Update session state to payment_slip_verify
                $platformUserId = $context['platform_user_id'] ?? $context['external_user_id'] ?? '';
                $channelId = $context['channel']['id'] ?? 0;
                
                // Clear checkout state since payment slip has been submitted
                if ($platformUserId && $channelId) {
                    $this->checkoutService->clearCheckoutState($platformUserId, $channelId);
                    
                    // Store payment info for follow-up
                    $this->chatService->setQuickState('last_payment', [
                        'payment_id' => $paymentResult['payment_id'] ?? null,
                        'payment_no' => $paymentNo,
                        'amount' => $slipAmount,
                        'matched_order' => $matchedOrderNo,
                        'submitted_at' => time(),
                    ], $platformUserId, $channelId, 3600);
                }

                if ($matchedOrderNo) {
                    $reply = "ได้รับสลิปแล้วค่ะ 💚\n\n" . $extractedInfo
                        . "\n📋 เลขอ้างอิง: {$paymentNo}"
                        . "\n🛒 ตรงกับออเดอร์: #{$matchedOrderNo}"
                        . "\n\nรอแอดมินตรวจสอบสักครู่นะคะ 😊";
                } else {
                    // No auto-match - try to find pending orders and auto-link
                    $externalUserId = $context['external_user_id'] ?? null;
                    $channelId = $context['channel']['id'] ?? null;
                    $pendingOrders = [];
                    $quickReplyItems = [];
                    $slipAmountFloat = $this->parseAmount($slipAmount);

                    if ($externalUserId) {
                        $pendingOrders = $this->findPendingOrdersForCustomer(
                            (string) $externalUserId,
                            $channelId,
                            null // Don't exclude any amount - we'll match below
                        );
                    }

                    if (count($pendingOrders) > 0) {
                        // ✅ AUTO-SELECT: Find order with matching amount, or use most recent
                        $selectedOrder = null;
                        $matchReason = '';
                        
                        // 1. Try to find order with matching amount (within 1 baht tolerance)
                        if ($slipAmountFloat > 0) {
                            foreach ($pendingOrders as $order) {
                                $orderAmount = (float)($order['balance'] ?? $order['total_amount'] ?? 0);
                                if (abs($orderAmount - $slipAmountFloat) <= 1) {
                                    $selectedOrder = $order;
                                    $matchReason = 'amount_match';
                                    break;
                                }
                            }
                        }
                        
                        // 2. If no amount match, use most recent pending order
                        if (!$selectedOrder) {
                            $selectedOrder = $pendingOrders[0]; // Already sorted by created_at DESC
                            $matchReason = 'most_recent';
                        }
                        
                        $selectedOrderNo = $selectedOrder['order_number'];
                        $productName = mb_substr($selectedOrder['product_name'] ?? 'สินค้า', 0, 30, 'UTF-8');
                        $orderBalance = number_format((float)($selectedOrder['balance'] ?? $selectedOrder['total_amount']), 0);
                        
                        // ✅ Auto-link payment to selected order
                        $this->linkPaymentToOrder($paymentResult['payment_id'], $selectedOrder['id']);
                        
                        Logger::info('[ROUTER_V4] Auto-linked slip to order', [
                            'payment_id' => $paymentResult['payment_id'],
                            'order_id' => $selectedOrder['id'],
                            'order_no' => $selectedOrderNo,
                            'match_reason' => $matchReason,
                            'slip_amount' => $slipAmountFloat,
                            'order_balance' => $orderBalance,
                        ]);

                        $reply = "ได้รับสลิปแล้วค่ะ 💚\n\n" . $extractedInfo
                            . "\n📋 เลขอ้างอิง: {$paymentNo}"
                            . "\n🛒 จับคู่กับออเดอร์: #{$selectedOrderNo}"
                            . "\n📦 สินค้า: {$productName}"
                            . "\n💰 ยอดคงเหลือ: {$orderBalance} บาท";
                        
                        if ($matchReason === 'amount_match') {
                            $reply .= "\n\n✅ ยอดโอนตรงกับยอดค้างชำระ";
                        } else {
                            $reply .= "\n\n⚠️ ระบบจับคู่กับออเดอร์ล่าสุดให้อัตโนมัติ";
                        }
                        
                        $reply .= "\n\nรอแอดมินตรวจสอบสักครู่นะคะ 😊";
                        
                        // Update matched order in result
                        $matchedOrderNo = $selectedOrderNo;
                    } else {
                        // ✅ Hybrid A+: No pending orders - try to match with pawns
                        $activePawns = $this->findActivePawnsForCustomer(
                            (string) $externalUserId,
                            $channelId
                        );
                        
                        if (count($activePawns) > 0 && $slipAmountFloat > 0) {
                            $matchedPawn = null;
                            $pawnMatchReason = '';
                            $pawnPaymentType = 'interest';
                            
                            // 1. Try exact interest match
                            foreach ($activePawns as $pawn) {
                                $expectedInterest = (float)($pawn['expected_interest'] ?? 0);
                                if ($expectedInterest > 0 && abs($expectedInterest - $slipAmountFloat) <= 1) {
                                    $matchedPawn = $pawn;
                                    $pawnMatchReason = 'interest_match';
                                    $pawnPaymentType = 'interest';
                                    break;
                                }
                            }
                            
                            // 2. Try full redemption match
                            if (!$matchedPawn) {
                                foreach ($activePawns as $pawn) {
                                    $fullRedemption = (float)($pawn['full_redemption_amount'] ?? 0);
                                    if ($fullRedemption > 0 && abs($fullRedemption - $slipAmountFloat) <= 10) {
                                        $matchedPawn = $pawn;
                                        $pawnMatchReason = 'redemption_match';
                                        $pawnPaymentType = 'redemption';
                                        break;
                                    }
                                }
                            }
                            
                            // 3. Try loan amount match (redemption without interest)
                            if (!$matchedPawn) {
                                foreach ($activePawns as $pawn) {
                                    $loanAmount = (float)($pawn['loan_amount'] ?? 0);
                                    if ($loanAmount > 0 && abs($loanAmount - $slipAmountFloat) <= 1) {
                                        $matchedPawn = $pawn;
                                        $pawnMatchReason = 'loan_match';
                                        $pawnPaymentType = 'redemption';
                                        break;
                                    }
                                }
                            }
                            
                            if ($matchedPawn) {
                                // Link payment to pawn
                                $this->linkPaymentToPawn(
                                    $paymentResult['payment_id'],
                                    $matchedPawn['id'],
                                    $pawnPaymentType,
                                    $slipAmountFloat
                                );
                                
                                $pawnNo = $matchedPawn['pawn_no'] ?? 'N/A';
                                $itemName = mb_substr($matchedPawn['item_name'] ?? 'สินค้าจำนำ', 0, 30, 'UTF-8');
                                
                                Logger::info('[ROUTER_V4] Auto-linked slip to pawn (Hybrid A+)', [
                                    'payment_id' => $paymentResult['payment_id'],
                                    'pawn_id' => $matchedPawn['id'],
                                    'pawn_no' => $pawnNo,
                                    'match_reason' => $pawnMatchReason,
                                    'payment_type' => $pawnPaymentType,
                                ]);
                                
                                $reply = "ได้รับสลิปแล้วค่ะ 💚\n\n" . $extractedInfo
                                    . "\n📋 เลขอ้างอิง: {$paymentNo}"
                                    . "\n💎 จับคู่กับจำนำ: #{$pawnNo}"
                                    . "\n📦 สินค้า: {$itemName}";
                                
                                if ($pawnPaymentType === 'redemption') {
                                    $reply .= "\n\n🎉 ระบบตรวจพบว่าเป็นการไถ่ถอน!";
                                } else {
                                    $reply .= "\n\n✅ ระบบตรวจพบว่าเป็นการจ่ายดอกเบี้ย";
                                }
                                
                                $reply .= "\n\nรอแอดมินตรวจสอบสักครู่นะคะ 😊";
                            } else {
                                // No pawn match either - try installments
                                $activeInstallments = $this->findActiveInstallmentsForCustomer(
                                    (string) $externalUserId,
                                    $channelId
                                );
                                
                                $matchedInstallment = null;
                                
                                if (count($activeInstallments) > 0 && $slipAmountFloat > 0) {
                                    // Try to match installment payment amount
                                    foreach ($activeInstallments as $inst) {
                                        $expectedPayment = (float)($inst['expected_payment'] ?? $inst['installment_amount'] ?? 0);
                                        if ($expectedPayment > 0 && abs($expectedPayment - $slipAmountFloat) <= 100) {
                                            $matchedInstallment = $inst;
                                            break;
                                        }
                                    }
                                    
                                    if ($matchedInstallment) {
                                        $this->linkPaymentToInstallment(
                                            $paymentResult['payment_id'],
                                            $matchedInstallment['id'],
                                            $slipAmountFloat
                                        );
                                        
                                        $instNo = $matchedInstallment['contract_no'] ?? 'N/A';
                                        $productName = mb_substr($matchedInstallment['product_name'] ?? 'สินค้า', 0, 30, 'UTF-8');
                                        
                                        Logger::info('[ROUTER_V4] Auto-linked slip to installment (Hybrid A+)', [
                                            'payment_id' => $paymentResult['payment_id'],
                                            'installment_id' => $matchedInstallment['id'],
                                            'contract_no' => $instNo,
                                        ]);
                                        
                                        $reply = "ได้รับสลิปแล้วค่ะ 💚\n\n" . $extractedInfo
                                            . "\n📋 เลขอ้างอิง: {$paymentNo}"
                                            . "\n📅 จับคู่กับผ่อนชำระ: #{$instNo}"
                                            . "\n📦 สินค้า: {$productName}"
                                            . "\n\n✅ ระบบตรวจพบว่าเป็นการชำระค่างวด"
                                            . "\n\nรอแอดมินตรวจสอบสักครู่นะคะ 😊";
                                    } else {
                                        // Found pawns but no amount match - summarize what we found
                                        $summary = [];
                                        if (count($activePawns) > 0) $summary[] = "จำนำ " . count($activePawns) . " รายการ";
                                        if (count($activeInstallments) > 0) $summary[] = "ผ่อนชำระ " . count($activeInstallments) . " รายการ";
                                        
                                        $reply = "ได้รับสลิปแล้วค่ะ 💚\n\n" . $extractedInfo
                                            . "\n📋 เลขอ้างอิง: {$paymentNo}"
                                            . "\n\n💡 พบรายการค้างชำระ: " . implode(', ', $summary)
                                            . "\nรอแอดมินตรวจสอบและจัดประเภทนะคะ 😊";
                                    }
                                } else {
                                    // No pawn match and no installment - leave for admin
                                    $reply = "ได้รับสลิปแล้วค่ะ 💚\n\n" . $extractedInfo
                                        . "\n📋 เลขอ้างอิง: {$paymentNo}"
                                        . "\n\n💡 พบรายการจำนำที่กำลังดำเนินการอยู่ " . count($activePawns) . " รายการ"
                                        . "\nรอแอดมินตรวจสอบและจัดประเภทนะคะ 😊";
                                }
                            }
                        } else {
                            // No orders and no pawns - try installments as last resort
                            $activeInstallments = $this->findActiveInstallmentsForCustomer(
                                (string) $externalUserId,
                                $channelId
                            );
                            
                            if (count($activeInstallments) > 0 && $slipAmountFloat > 0) {
                                $matchedInstallment = null;
                                
                                foreach ($activeInstallments as $inst) {
                                    $expectedPayment = (float)($inst['expected_payment'] ?? $inst['installment_amount'] ?? 0);
                                    if ($expectedPayment > 0 && abs($expectedPayment - $slipAmountFloat) <= 100) {
                                        $matchedInstallment = $inst;
                                        break;
                                    }
                                }
                                
                                if ($matchedInstallment) {
                                    $this->linkPaymentToInstallment(
                                        $paymentResult['payment_id'],
                                        $matchedInstallment['id'],
                                        $slipAmountFloat
                                    );
                                    
                                    $instNo = $matchedInstallment['contract_no'] ?? 'N/A';
                                    $productName = mb_substr($matchedInstallment['product_name'] ?? 'สินค้า', 0, 30, 'UTF-8');
                                    
                                    $reply = "ได้รับสลิปแล้วค่ะ 💚\n\n" . $extractedInfo
                                        . "\n📋 เลขอ้างอิง: {$paymentNo}"
                                        . "\n📅 จับคู่กับผ่อนชำระ: #{$instNo}"
                                        . "\n📦 สินค้า: {$productName}"
                                        . "\n\n✅ ระบบตรวจพบว่าเป็นการชำระค่างวด"
                                        . "\n\nรอแอดมินตรวจสอบสักครู่นะคะ 😊";
                                } else {
                                    // Has installments but no amount match
                                    $reply = "ได้รับสลิปแล้วค่ะ 💚\n\n" . $extractedInfo
                                        . "\n📋 เลขอ้างอิง: {$paymentNo}"
                                        . "\n\n💡 พบรายการผ่อนชำระที่กำลังดำเนินการอยู่ " . count($activeInstallments) . " รายการ"
                                        . "\nรอแอดมินตรวจสอบและจัดประเภทนะคะ 😊";
                                }
                            } else {
                                // No orders, pawns, or installments - leave for admin
                                $reply = "ได้รับสลิปแล้วค่ะ 💚\n\n" . $extractedInfo
                                    . "\n📋 เลขอ้างอิง: {$paymentNo}"
                                    . "\n\nรอแอดมินตรวจสอบและจัดประเภทนะคะ 😊";
                            }
                        }
                    }
                }

                return $this->makeResponse($reply, 'slip_saved', $traceId, [
                    'payment_id' => $paymentResult['payment_id'],
                    'payment_no' => $paymentNo,
                    'matched_order' => $matchedOrderNo,
                    'quick_reply_items' => $quickReplyItems ?? [],
                ]);

            } elseif (!empty($paymentResult['is_duplicate'])) {
                $existingPaymentNo = $paymentResult['existing_payment_no'] ?? '';
                return $this->makeResponse(
                    "สลิปนี้เคยส่งมาแล้วค่ะ � (เลขอ้างอิง: {$existingPaymentNo})\n\nรอแอดมินตรวจสอบอยู่นะคะ ไม่ต้องห่วง",
                    'slip_duplicate',
                    $traceId
                );
            } else {
                Logger::error('[ROUTER_V4] PaymentService failed', [
                    'error' => $paymentResult['error'] ?? 'unknown',
                ]);
            }

        } catch (Exception $e) {
            Logger::error('[ROUTER_V4] Payment slip processing error', [
                'trace_id' => $traceId,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback response
        $fallbackReply = "ได้รับสลิปแล้วค่ะ 💚\n\n";
        if ($extractedInfo) {
            $fallbackReply .= $extractedInfo . "\n";
        }
        $fallbackReply .= "รอแอดมินตรวจสอบสักครู่นะคะ 🙏";
        
        return $this->makeResponse($fallbackReply, 'slip_received', $traceId);
    }

    // ==================== LLM HANDLING ====================

    /**
     * Handle message with LLM
     */
    protected function handleWithLLM(array $context, array $config): ?string
    {
        $text = $context['message']['text'] ?? '';
        
        if (empty($text)) {
            return null;
        }

        // Get LLM integration (Gemini) from database
        $llmIntegration = $this->getLlmIntegration($context);
        if (!$llmIntegration) {
            Logger::warning('[ROUTER_V4] No LLM integration available');
            return null;
        }

        $apiKey = $llmIntegration['api_key'] ?? null;
        $cfg = is_string($llmIntegration['config'] ?? null) 
            ? json_decode($llmIntegration['config'], true) 
            : ($llmIntegration['config'] ?? []);
        $endpoint = $cfg['endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

        if (!$apiKey) {
            Logger::warning('[ROUTER_V4] LLM integration missing API key');
            return null;
        }

        // Get conversation history
        $history = $this->chatService->getHistoryForLLM($context, 10);

        // Build system prompt - use config but REMOVE JSON output instructions
        $systemPrompt = $config['llm']['system_prompt'] ?? $this->buildSystemPrompt($config);
        
        // ✅ Remove JSON output instructions from system prompt (causes truncation issues)
        $systemPrompt = preg_replace('/## Output.*$/s', '', $systemPrompt);
        $systemPrompt = preg_replace('/ตอบกลับเป็น JSON.*$/s', '', $systemPrompt);
        $systemPrompt = preg_replace('/\{"reply_text".*$/s', '', $systemPrompt);
        $systemPrompt = preg_replace('/ห้ามมีข้อความอื่นนอกจาก JSON.*$/s', '', $systemPrompt);
        $systemPrompt = trim($systemPrompt);

        // Build Gemini request - ask for plain text response
        $prompt = $systemPrompt;
        $prompt .= "\n\n📌 รูปแบบการตอบ: ตอบเป็นข้อความภาษาไทยธรรมดา สั้น กระชับ 1-3 ประโยค ห้ามใส่ JSON ⚠️สำคัญมาก: ต้องจบประโยคให้สมบูรณ์ทุกครั้ง ลงท้ายด้วย ค่ะ/ครับ/นะคะ";
        
        if ($history) {
            $prompt .= "\n\nประวัติการสนทนา:\n{$history}";
        }
        $prompt .= "\n\nคำถามลูกค้า: {$text}\n\nคำตอบ:";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                // ✅ FIX: Use higher default (1024) and ensure config is properly loaded
                'maxOutputTokens' => (int)($config['llm']['max_tokens'] ?? 1024),
                'temperature' => (float)($config['llm']['temperature'] ?? 0.4),
            ]
        ];
        
        // Log actual config values being used
        Logger::info('[ROUTER_V4] LLM config values', [
            'max_tokens_from_config' => $config['llm']['max_tokens'] ?? 'NOT_SET',
            'max_tokens_used' => $payload['generationConfig']['maxOutputTokens'],
            'temperature' => $payload['generationConfig']['temperature'],
        ]);

        // Call Gemini API with retry for transient errors
        $url = $endpoint . '?key=' . $apiKey;
        $timeout = (int)($config['llm']['timeout_seconds'] ?? 12);
        
        $response = null;
        $httpCode = 0;
        $curlError = '';
        $maxRetries = 1; // 1 retry = 2 attempts total
        
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep(500000); // Wait 0.5s before retry
                Logger::info('[ROUTER_V4] Gemini LLM retry', ['attempt' => $attempt + 1]);
            }
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            // Success or non-retryable error
            if (!$curlError && $httpCode < 500 && $httpCode != 429) {
                break;
            }
        }

        if ($curlError || $httpCode !== 200) {
            Logger::warning('[ROUTER_V4] Gemini LLM call failed', [
                'http_code' => $httpCode,
                'curl_error' => $curlError,
            ]);
            return null;
        }

        $data = json_decode($response, true);
        
        // Extract text from Gemini response
        $candidates = $data['candidates'] ?? [];
        $content = $candidates[0]['content']['parts'][0]['text'] ?? null;
        $finishReason = $candidates[0]['finishReason'] ?? 'UNKNOWN';
        
        // Log finish reason for debugging truncated responses
        Logger::info('[ROUTER_V4] LLM response details', [
            'finish_reason' => $finishReason,
            'content_length' => $content ? mb_strlen($content, 'UTF-8') : 0,
        ]);
        
        if (!$content) {
            Logger::warning('[ROUTER_V4] Gemini returned empty content', [
                'finish_reason' => $finishReason,
            ]);
            return null;
        }
        
        // ✅ FIX: Detect and fix truncated Thai text (ends mid-character or incomplete sentence)
        $content = $this->fixTruncatedThaiText($content);

        // Clean up markdown code blocks if present
        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);

        // Try to parse as JSON if it looks like JSON
        if (preg_match('/^\s*\{/', $content)) {
            $jsonContent = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($jsonContent['reply_text'])) {
                Logger::info('[ROUTER_V4] Parsed LLM JSON response', [
                    'intent' => $jsonContent['intent'] ?? null,
                ]);
                return $jsonContent['reply_text'];
            }
            
            // JSON parse failed - try to extract reply_text with regex
            if (preg_match('/"reply_text"\s*:\s*"([^"]+)/u', $content, $m)) {
                Logger::info('[ROUTER_V4] Extracted reply_text from truncated JSON');
                return $m[1];
            }
            
            // Still JSON-like but can't extract - return null to use fallback
            Logger::warning('[ROUTER_V4] LLM returned invalid/truncated JSON', [
                'content_preview' => mb_substr($content, 0, 100, 'UTF-8'),
            ]);
            return null;
        }

        // Return raw text
        return $content;
    }

    /**
     * Extract product category keyword from recent conversation history
     * When customer discusses a product type (พระ, สร้อย, นาฬิกา) and then says "สนใจ",
     * this method finds what category they were discussing
     */
    protected function extractProductCategoryFromConversation(array $context): ?string
    {
        // Get recent conversation history
        $history = $this->chatService->getConversationHistory($context, 10);
        
        if (empty($history)) {
            return null;
        }
        
        // ✅ Get category keywords from config (configurable, not hardcoded)
        $categoryKeywords = $this->getCategoryKeywordsFromConfig($config);
        
        // Look through history from newest to oldest (user messages only)
        $reversedHistory = array_reverse($history);
        
        foreach ($reversedHistory as $msg) {
            $role = $msg['role'] ?? '';
            $message = mb_strtolower($msg['message'] ?? '', 'UTF-8');
            
            // Skip assistant messages (bot replies)
            if ($role === 'assistant' || $role === 'bot') {
                continue;
            }
            
            // Skip "สนใจ" message itself
            if (preg_match('/^(สนใจ|รับ|เอา|จอง)\s*(ครับ|ค่ะ|นะ|เลย)?$/u', $message)) {
                continue;
            }
            
            // Check for category keywords (longest match first)
            $sortedKeywords = $categoryKeywords;
            uksort($sortedKeywords, function($a, $b) {
                return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
            });
            
            foreach ($sortedKeywords as $keyword => $searchTerm) {
                if (mb_strpos($message, mb_strtolower($keyword, 'UTF-8')) !== false) {
                    Logger::info('[ROUTER_V4] Found product category in conversation', [
                        'matched_keyword' => $keyword,
                        'search_term' => $searchTerm,
                        'original_message' => $msg['message'] ?? '',
                    ]);
                    return $searchTerm;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get category keywords from config or use defaults
     * ✅ Allows customization without code changes
     */
    protected function getCategoryKeywordsFromConfig(array $config): array
    {
        // Check if config has category_keywords
        if (!empty($config['product_search']['category_keywords'])) {
            return $config['product_search']['category_keywords'];
        }
        
        // Default fallback (for backward compatibility)
        return [
            // พระและวัตถุมงคล
            'พระ' => 'พระ',
            'พระเครื่อง' => 'พระ',
            'พระทอง' => 'พระทอง',
            'ตลับพระ' => 'ตลับพระ',
            'พระเลี่ยม' => 'พระเลี่ยม',
            'วัตถุมงคล' => 'พระ',
            
            // สร้อย
            'สร้อย' => 'สร้อย',
            'สร้อยคอ' => 'สร้อยคอ',
            'สร้อยทอง' => 'สร้อยทอง',
            'สร้อยข้อมือ' => 'สร้อยข้อมือ',
            
            // เครื่องประดับ
            'แหวน' => 'แหวน',
            'แหวนเพชร' => 'แหวนเพชร',
            'ต่างหู' => 'ต่างหู',
            'จี้' => 'จี้',
            'เข็มกลัด' => 'เข็มกลัด',
            'กำไล' => 'กำไล',
            'เครื่องประดับ' => 'เครื่องประดับ',
            'เพชร' => 'เพชร',
            'ทอง' => 'ทอง',
            'ทองคำ' => 'ทองคำ',
            
            // นาฬิกา
            'นาฬิกา' => 'นาฬิกา',
            'rolex' => 'rolex',
            'tag heuer' => 'tag heuer',
            'omega' => 'omega',
            'cartier' => 'cartier',
            'patek' => 'patek philippe',
        ];
    }

    /**
     * Fix truncated Thai text from LLM responses
     * Detects and fixes sentences that end abruptly mid-word
     */
    protected function fixTruncatedThaiText(string $text): string
    {
        $text = trim($text);
        
        if (empty($text)) {
            return $text;
        }
        
        // Get the last character
        $lastChar = mb_substr($text, -1, 1, 'UTF-8');
        
        // Thai ending particles and punctuation that indicate complete sentence
        $validEndings = ['ค่ะ', 'ครับ', 'คะ', 'นะ', 'จ้า', 'จ้ะ', 'ค่า', 'นะคะ', 'นะครับ'];
        $validEndChars = ['!', '?', '.', '。', ')', '）', '"', "'", '😊', '🙏', '✨', '💎', '📸', '😍'];
        
        // Check if ends with valid ending
        $lastTwoChars = mb_substr($text, -2, 2, 'UTF-8');
        $lastThreeChars = mb_substr($text, -3, 3, 'UTF-8');
        $lastFourChars = mb_substr($text, -4, 4, 'UTF-8');
        
        // Check for Thai sentence-final particles
        foreach ($validEndings as $ending) {
            if (mb_substr($text, -mb_strlen($ending, 'UTF-8'), null, 'UTF-8') === $ending) {
                return $text; // Properly ended
            }
        }
        
        // Check for valid ending punctuation/emoji
        if (in_array($lastChar, $validEndChars)) {
            return $text; // Properly ended
        }
        
        // Check if ends with emoji (2-4 bytes for most emojis)
        if (preg_match('/[\x{1F600}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]$/u', $text)) {
            return $text; // Ends with emoji
        }
        
        // Detect mid-word truncation: check if last byte suggests incomplete Thai character
        $bytes = unpack('C*', mb_convert_encoding(mb_substr($text, -1, 1, 'UTF-8'), 'UTF-8'));
        $lastByte = end($bytes);
        
        // Thai characters are 3 bytes in UTF-8 (0xE0-0xEF range)
        // If truncated mid-character, we might have issues
        
        // Log warning for potentially truncated text
        Logger::warning('[ROUTER_V4] LLM response appears truncated', [
            'text_length' => mb_strlen($text, 'UTF-8'),
            'last_chars' => mb_substr($text, -10, 10, 'UTF-8'),
            'last_char_hex' => bin2hex(mb_substr($text, -1, 1, 'UTF-8')),
        ]);
        
        // Add ellipsis and polite ending to make it look intentional
        $text .= 'ค่ะ';
        
        return $text;
    }

    /**
     * Build system prompt for LLM
     */
    protected function buildSystemPrompt(array $config): string
    {
        $persona = $config['persona'] ?? [];
        $store = $config['store'] ?? [];

        $name = $persona['name'] ?? 'น้องบอท';
        $role = $persona['role'] ?? 'พนักงานขายออนไลน์';
        $tone = $persona['tone'] ?? 'สุภาพ เป็นกันเอง';
        
        $storeName = $store['name'] ?? 'ร้านค้า';
        $storeDesc = $store['description'] ?? '';

        $prompt = "คุณชื่อ {$name} เป็น {$role} ของ {$storeName}";
        
        if ($storeDesc) {
            $prompt .= " ({$storeDesc})";
        }
        
        $prompt .= "\n\nแนวทางการตอบ:\n";
        $prompt .= "- พูดด้วยน้ำเสียง {$tone}\n";
        $prompt .= "- ตอบสั้น กระชับ ได้ใจความ\n";
        $prompt .= "- ถ้าไม่แน่ใจให้แนะนำติดต่อแอดมิน\n";
        $prompt .= "- ห้ามตอบเรื่องที่ไม่เกี่ยวกับร้าน\n";

        return $prompt;
    }

    // ==================== ADMIN HANDLING ====================

    /**
     * Check if message is from admin
     */
    protected function isAdminContext(array $context, array $message): bool
    {
        // Explicit flag
        if (!empty($context['is_admin'])) {
            return true;
        }

        // Check user role
        if (!empty($context['user']['is_admin'])) {
            return true;
        }

        // Facebook page echo
        if (!empty($message['is_echo'])) {
            return true;
        }

        // Check sender_is_page
        if (!empty($context['sender_is_page'])) {
            return true;
        }

        return false;
    }

    /**
     * Handle admin message
     */
    protected function handleAdminMessage(array $context, string $text, ?int $sessionId): void
    {
        if (!$sessionId) {
            return;
        }

        // Update last admin message timestamp
        try {
            $this->db->execute(
                'UPDATE chat_sessions SET last_admin_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$sessionId]
            );
        } catch (Exception $e) {
            Logger::error('[ROUTER_V4] Failed to update admin timestamp', ['error' => $e->getMessage()]);
        }

        // Store admin message
        if ($text) {
            $this->chatService->logOutgoingMessage($context, "[admin] {$text}", 'text');
        }

        Logger::info('[ROUTER_V4] Admin message handled', [
            'session_id' => $sessionId,
            'text_preview' => substr($text, 0, 50),
        ]);
    }

    /**
     * Check if admin handoff is still active
     */
    protected function isAdminHandoffActive(?int $sessionId, array $config): bool
    {
        if (!$sessionId) {
            return false;
        }

        try {
            $row = $this->db->queryOne(
                'SELECT last_admin_message_at FROM chat_sessions WHERE id = ? LIMIT 1',
                [$sessionId]
            );

            $lastAdminMsg = $row['last_admin_message_at'] ?? null;
            
            if (!$lastAdminMsg) {
                return false;
            }

            $handoffCfg = $config['handoff'] ?? [];
            $timeoutSec = (int)($handoffCfg['timeout_seconds'] ?? 300);
            
            $lastAdminTime = strtotime($lastAdminMsg);
            $elapsed = time() - $lastAdminTime;

            return $elapsed < $timeoutSec;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Activate admin handoff - bot will stop auto-replying for a while
     * Sets last_admin_message_at timestamp to trigger handoff mode
     * 
     * @param int|null $sessionId Session ID
     * @param array $context Message context
     * @param string $reason Reason for handoff (for logging)
     */
    protected function activateAdminHandoff(?int $sessionId, array $context, string $reason = 'manual'): void
    {
        if (!$sessionId) {
            Logger::warning('[ROUTER_V4] Cannot activate handoff - no session_id', ['reason' => $reason]);
            return;
        }

        try {
            $this->db->execute(
                'UPDATE chat_sessions SET last_admin_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$sessionId]
            );
            
            Logger::info('[ROUTER_V4] Admin handoff activated', [
                'session_id' => $sessionId,
                'reason' => $reason,
                'platform_user_id' => $context['platform_user_id'] ?? null,
            ]);
        } catch (Exception $e) {
            Logger::error('[ROUTER_V4] Failed to activate admin handoff', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);
        }
    }

    // ==================== HELPERS ====================

    /**
     * Decode config JSON
     */
    protected function decodeConfig($config): array
    {
        if (is_array($config)) {
            return $config;
        }
        
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return [];
    }

    /**
     * Make standardized response
     */
    protected function makeResponse($reply, string $reason, string $traceId, array $extra = []): array
    {
        $response = [
            'reply_text' => null,
            'reply_messages' => [],
            'actions' => [],
            'meta' => [
                'handler' => 'router_v4',
                'reason' => $reason,
                'trace_id' => $traceId,
            ]
        ];

        if ($reply !== null) {
            if (is_array($reply)) {
                $response['reply_messages'][] = $reply;
                $response['reply_text'] = $reply['text'] ?? null;
                
                // ✅ Extract image_url from reply
                if (!empty($reply['image'])) {
                    $response['image_url'] = $reply['image'];
                    $response['actions'][] = [
                        'type' => 'image',
                        'url' => $reply['image']
                    ];
                }
                
                // ✅ Convert quick_replies (Facebook format) to actions
                if (!empty($reply['quick_replies'])) {
                    $response['actions'][] = [
                        'type' => 'quick_reply',
                        'items' => array_map(function($qr) {
                            return [
                                'label' => $qr['title'] ?? '',
                                'text' => $qr['payload'] ?? $qr['title'] ?? ''
                            ];
                        }, $reply['quick_replies'])
                    ];
                }
                
                // ✅ Convert quickReply (LINE format) to actions as backup
                if (!empty($reply['quickReply']['items']) && empty($reply['quick_replies'])) {
                    $response['actions'][] = [
                        'type' => 'quick_reply',
                        'items' => array_map(function($item) {
                            return [
                                'label' => $item['action']['label'] ?? '',
                                'text' => $item['action']['text'] ?? ''
                            ];
                        }, $reply['quickReply']['items'])
                    ];
                }
            } else {
                $response['reply_text'] = (string)$reply;
            }
        }

        // Merge extra data (but don't override actions we just built)
        foreach ($extra as $key => $value) {
            if ($key === 'meta') {
                $response['meta'] = array_merge($response['meta'], $value);
            } elseif ($key === 'actions' && !empty($value)) {
                // Merge actions instead of override
                $response['actions'] = array_merge($response['actions'], $value);
            } elseif ($key !== 'reply') {
                // Skip 'reply' key to avoid conflict
                $response[$key] = $value;
            }
        }

        return $response;
    }

    /**
     * Check if LLM is enabled
     * Supports both backend_api.endpoints.llm AND llm.enabled config styles
     */
    protected function isLlmEnabled(array $config): bool
    {
        // Style 1: backend_api.endpoints.llm
        if (!empty($config['backend_api']['enabled']) &&
            !empty($config['backend_api']['endpoints']['llm'])) {
            return true;
        }
        
        // Style 2: llm.enabled (production config style)
        if (!empty($config['llm']['enabled'])) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if backend endpoint is enabled
     */
    protected function isBackendEnabled(array $config, string $endpoint): bool
    {
        return !empty($config['backend_api']['enabled']) &&
               !empty($config['backend_api']['endpoints'][$endpoint]);
    }

    /**
     * Check if payment slip processing is enabled
     */
    protected function isPaymentSlipEnabled(array $config): bool
    {
        return !empty($config['features']['payment_slip']) ||
               !empty($config['backend_api']['endpoints']['slip_ocr']);
    }

    /**
     * Check if user has pending orders (unpaid orders within last 7 days)
     * This helps detect when a user sends a payment slip after placing an order
     */
    protected function hasPendingOrderForUser(string $platformUserId, int $channelId): bool
    {
        try {
            // ✅ FIXED: Use platform_user_id directly from orders table
            // Not via customers table which may not have the mapping
            $result = $this->db->queryOne("
                SELECT COUNT(*) as cnt
                FROM orders
                WHERE platform_user_id = ?
                  AND status IN ('pending', 'processing')
                  AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ", [$platformUserId]);
            
            $count = (int)($result['cnt'] ?? 0);
            
            Logger::info('[ROUTER_V4] Checked pending orders for user', [
                'platform_user_id' => $platformUserId,
                'count' => $count,
            ]);
            
            return $count > 0;
        } catch (\Throwable $e) {
            Logger::error('[ROUTER_V4] Error checking pending orders', [
                'error' => $e->getMessage(),
                'platform_user_id' => $platformUserId,
            ]);
            return false;
        }
    }

    // ==================== NEW HELPER METHODS ====================

    /**
     * Check if text triggers menu reset (clears checkout)
     */
    protected function isMenuResetTrigger(string $text): bool
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        
        // Menu button patterns (extended to prevent user stuck in loops)
        $menuPatterns = [
            'ดูสินค้า', 'ดูสินค้าเพิ่มเติม', 'สินค้าอื่น',
            'สอบถาม', 'สอบถามเพิ่มเติม',
            'เริ่มใหม่', 'รีเซ็ต', 'reset',
            'ยกเลิก', 'ไม่เอา', 'ไม่เอาแล้ว', 'เปลี่ยนใจ',
            'กลับเมนู', 'กลับหน้าแรก', 'หน้าแรก',
            // ✅ NEW: Extended cancel patterns to prevent deadlock
            'พอแล้ว', 'หยุด', 'ออก', 'กลับ',
            'cancel', 'stop', 'exit', 'quit',
            'ไม่ต้อง', 'ไม่ซื้อ', 'ไม่สนใจ',
        ];

        foreach ($menuPatterns as $pattern) {
            if ($text === mb_strtolower($pattern, 'UTF-8')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect early checkout triggers (สนใจ/เอา/ซื้อ with product context)
     */
    protected function detectEarlyCheckout(string $text, array $context): ?array
    {
        $text = mb_strtolower(trim($text), 'UTF-8');

        // Interest patterns
        $interestPatterns = [
            '/สนใจ\s*(?:รุ่นนี้|ตัวนี้|เลย|ค่ะ|ครับ)?$/u',
            '/เอา\s*(?:เลย|ตัวนี้|รุ่นนี้)?$/u',
            '/ซื้อ\s*(?:เลย|ค่ะ|ครับ)?$/u',
            '/ต้องการ\s*(?:เลย|ค่ะ|ครับ)?$/u',
        ];

        $isInterestTrigger = false;
        foreach ($interestPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                $isInterestTrigger = true;
                break;
            }
        }

        if (!$isInterestTrigger) {
            return null;
        }

        // Check for recently viewed product
        $recentProduct = $this->productService->getRecentlyViewed($context);
        if (!$recentProduct) {
            return null;
        }

        // Start checkout with recent product
        $config = $context['config'] ?? $this->decodeConfig($context['bot_profile']['config'] ?? null);
        $result = $this->checkoutService->startCheckout($recentProduct, $config, $context);

        return ['reply' => $result['reply']];
    }

    /**
     * Get store info response
     */
    protected function getStoreInfo(array $config): ?string
    {
        $store = $config['store'] ?? [];
        
        if (empty($store)) {
            return null;
        }

        $name = $store['name'] ?? null;
        $address = $store['address'] ?? null;
        $phone = $store['phone'] ?? null;
        $hours = $store['hours'] ?? null;
        $line = $store['line_id'] ?? null;

        if (!$name && !$address && !$phone) {
            return null;
        }

        $info = [];
        if ($name) {
            $info[] = "🏪 {$name}";
        }
        if ($address) {
            $info[] = "📍 {$address}";
        }
        if ($phone) {
            $info[] = "📞 {$phone}";
        }
        if ($hours) {
            $info[] = "🕐 เปิดบริการ: {$hours}";
        }
        if ($line) {
            $info[] = "💬 LINE: {$line}";
        }

        return implode("\n", $info);
    }

    /**
     * Clear checkout state (for external access)
     */
    public function clearCheckoutState(string $platformUserId, int $channelId): void
    {
        $this->checkoutService->clearCheckoutState($platformUserId, $channelId);
    }

    /**
     * Get customer_service_id from channel_id
     * customer_channels links to customer_services via user_id
     */
    protected function getCustomerServiceIdFromChannel(int $channelId): ?int
    {
        try {
            $row = $this->db->queryOne(
                "SELECT cs.id as customer_service_id 
                 FROM customer_channels cc
                 JOIN customer_services cs ON cs.user_id = cc.user_id
                 WHERE cc.id = ? 
                 LIMIT 1",
                [$channelId]
            );
            return $row ? ($row['customer_service_id'] ?? null) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    // ==================== AI HYBRID SEARCH HELPERS ====================

    /**
     * Rewrite ambiguous query using LLM with chat history context
     * 
     * Addresses scenarios like:
     * - User: "มีสีน้ำเงินมั้ย" → Need to know what product they're asking about
     * - User: "ตัวนี้มีเพชรมั้ย" → Need context from previous messages
     * 
     * @param string $query Current user query
     * @param array $config Bot config  
     * @param array $context Chat context with history
     * @return array ['rewritten' => string, 'is_chit_chat' => bool, 'original' => string]
     */
    protected function rewriteQueryWithContext(string $query, array $config, array $context): array
    {
        // Get chat history for context
        $history = $this->chatService->getHistoryForLLM($context, 5);
        
        // If no history or query is already specific (product code), skip rewriting
        if (empty($history) || $this->isProductCode($query)) {
            return [
                'rewritten' => $query,
                'is_chit_chat' => false,
                'original' => $query,
                'source' => 'no_rewrite_needed'
            ];
        }

        $llmIntegration = $this->getLlmIntegration($context);
        if (!$llmIntegration) {
            return [
                'rewritten' => $query,
                'is_chit_chat' => false,
                'original' => $query,
                'source' => 'no_llm_available'
            ];
        }

        $apiKey = $llmIntegration['api_key'] ?? null;
        $cfg = is_string($llmIntegration['config'] ?? null) 
            ? json_decode($llmIntegration['config'], true) 
            : ($llmIntegration['config'] ?? []);
        $endpoint = $cfg['endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

        if (!$apiKey) {
            return [
                'rewritten' => $query,
                'is_chit_chat' => false,
                'original' => $query,
                'source' => 'no_api_key'
            ];
        }

        // Build rewrite prompt
        $prompt = <<<PROMPT
You are a Thai e-commerce chatbot assistant for a luxury second-hand goods store.

Analyze the user's current message in context of the conversation history.

## Task
1. If the message is a PRODUCT SEARCH query (asking about products, colors, features, prices):
   - Rewrite it into a clear product search query in Thai
   - Include context from history (e.g., product type, brand mentioned earlier)
   
2. If the message is CHIT-CHAT (greetings, thanks, general questions not about products):
   - Return NON_PRODUCT_SEARCH

## Conversation History:
{$history}

## Current Message: "{$query}"

## Output Format (JSON only):
{"rewritten": "rewritten search query or original", "is_chit_chat": true/false}

Example outputs:
- If user asks "มีสีน้ำเงินมั้ย" after discussing Rolex watches: {"rewritten": "นาฬิกา Rolex สีน้ำเงิน", "is_chit_chat": false}
- If user says "ขอบคุณครับ": {"rewritten": "ขอบคุณครับ", "is_chit_chat": true}
- If user says "สวัสดีครับ": {"rewritten": "สวัสดีครับ", "is_chit_chat": true}

Respond with JSON only:
PROMPT;

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'maxOutputTokens' => 150,
                'temperature' => 0.1,
            ]
        ];

        $url = $endpoint . '?key=' . $apiKey;
        
        // Retry logic for transient errors
        $response = null;
        $httpCode = 0;
        $curlError = '';
        
        for ($attempt = 0; $attempt <= 1; $attempt++) {
            if ($attempt > 0) {
                usleep(300000); // 0.3s retry delay
            }
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if (!$curlError && $httpCode < 500 && $httpCode != 429) {
                break;
            }
        }

        if ($curlError || $httpCode !== 200) {
            Logger::warning('[ROUTER_V4] Query rewrite LLM call failed', [
                'http_code' => $httpCode,
                'error' => $curlError
            ]);
            return [
                'rewritten' => $query,
                'is_chit_chat' => false,
                'original' => $query,
                'source' => 'llm_error'
            ];
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        // Parse JSON response
        $jsonMatch = preg_match('/\{[^}]+\}/', $text, $matches);
        if ($jsonMatch) {
            $parsed = json_decode($matches[0], true);
            if (is_array($parsed)) {
                $rewritten = $parsed['rewritten'] ?? $query;
                $isChitChat = (bool)($parsed['is_chit_chat'] ?? false);
                
                Logger::info('[ROUTER_V4] Query rewritten by LLM', [
                    'original' => $query,
                    'rewritten' => $rewritten,
                    'is_chit_chat' => $isChitChat
                ]);
                
                return [
                    'rewritten' => $rewritten,
                    'is_chit_chat' => $isChitChat,
                    'original' => $query,
                    'source' => 'llm_rewrite'
                ];
            }
        }

        return [
            'rewritten' => $query,
            'is_chit_chat' => false,
            'original' => $query,
            'source' => 'parse_failed'
        ];
    }

    /**
     * Check if query looks like a product code (skip LLM rewriting)
     */
    protected function isProductCode(string $query): bool
    {
        // Pattern: P-YYYY-NNNNNN, BR-NNNNN, XX-12345, etc.
        return (bool) preg_match('/^[A-Z]{1,3}[-\s]?\d{4,}/i', trim($query));
    }

    // ==================== PAYMENT SLIP HELPER METHODS ====================

    /**
     * Get LLM integration config from database
     */
    protected function getLlmIntegration(array $context): ?array
    {
        try {
            // First try from context (already loaded by gateway)
            $integrations = $context['integrations'] ?? [];
            $llmIntegrations = $integrations['llm'] ?? ($integrations['openai'] ?? ($integrations['gemini'] ?? []));
            if (!empty($llmIntegrations)) {
                return $llmIntegrations[0] ?? null;
            }

            // Fallback: query from database
            $channelId = $context['channel']['id'] ?? null;
            if (!$channelId) {
                return null;
            }

            // Get user_id from channel, then get their LLM integration
            $row = $this->db->queryOne(
                "SELECT ci.* FROM customer_integrations ci
                 JOIN customer_channels cc ON ci.user_id = cc.user_id
                 WHERE cc.id = ? 
                   AND ci.provider IN ('gemini', 'openai', 'llm')
                   AND ci.is_active = 1 
                   AND ci.is_deleted = 0
                 ORDER BY ci.provider = 'gemini' DESC
                 LIMIT 1",
                [$channelId]
            );
            return $row ?: null;
        } catch (\Exception $e) {
            Logger::warning('[ROUTER_V4] Failed to get LLM integration', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Analyze image with Gemini Vision (multimodal)
     */
    protected function analyzeImageWithGemini(?array $llmIntegration, string $imageUrl, array $config): array
    {
        if (!$llmIntegration) {
            return ['error' => 'no_llm_integration', 'route' => 'image_generic', 'meta' => null];
        }

        $apiKey = $llmIntegration['api_key'] ?? null;
        $cfg = is_string($llmIntegration['config'] ?? null) 
            ? json_decode($llmIntegration['config'], true) 
            : ($llmIntegration['config'] ?? []);
        $endpoint = $cfg['endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

        if (!$apiKey) {
            return ['error' => 'missing_api_key', 'route' => 'image_generic', 'meta' => null];
        }

        // Check if this is actually Gemini
        if (stripos($endpoint, 'generativelanguage.googleapis.com') === false) {
            return ['error' => 'not_gemini', 'route' => 'image_generic', 'meta' => null];
        }

        // Download image and convert to base64
        $imageData = @file_get_contents($imageUrl);
        if ($imageData === false) {
            Logger::warning('[ROUTER_V4] Failed to download image for Gemini analysis', ['url' => substr($imageUrl, 0, 100)]);
            return ['error' => 'download_failed', 'route' => 'image_generic', 'meta' => null];
        }

        // Detect mime type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData);
        if (!$mimeType || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            $mimeType = 'image/jpeg';
        }

        $base64Image = base64_encode($imageData);

        // Build analysis prompt - MUST be explicit about nested details structure
        $analysisPrompt = "วิเคราะห์รูปภาพนี้และตอบเป็น JSON:\n\n"
            . "{\n"
            . "  \"image_type\": \"payment_proof\" หรือ \"product_image\" หรือ \"image_generic\",\n"
            . "  \"confidence\": ตัวเลข 0.0-1.0,\n"
            . "  \"details\": {\n"
            . "    // ถ้าเป็นสลิป (payment_proof):\n"
            . "    \"amount\": ตัวเลขจำนวนเงิน,\n"
            . "    \"bank\": \"ชื่อธนาคาร\",\n"
            . "    \"date\": \"วันที่/เวลา\",\n"
            . "    \"ref\": \"เลขอ้างอิง\",\n"
            . "    \"sender_name\": \"ชื่อผู้โอน\",\n"
            . "    \"receiver_name\": \"ชื่อผู้รับ\"\n"
            . "    // ถ้าเป็นสินค้า (product_image):\n"
            . "    \"brand\": \"แบรนด์\",\n"
            . "    \"model\": \"รุ่น\",\n"
            . "    \"category\": \"หมวดหมู่\"\n"
            . "  },\n"
            . "  \"description\": \"คำอธิบายสั้นๆ\"\n"
            . "}\n\n"
            . "สำคัญมาก: ต้องใส่ข้อมูลทั้งหมดใน \"details\" object!\n"
            . "ตอบเป็น JSON อย่างเดียว ไม่ต้องมีข้อความอื่น";

        // Build Gemini multimodal request
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $analysisPrompt],
                        ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Image]]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 2048,
                'responseMimeType' => 'application/json'
            ]
        ];

        $url = $endpoint . (strpos($endpoint, '?') !== false ? '&' : '?') . 'key=' . $apiKey;

        // ✅ Retry logic with exponential backoff for transient errors (503, 429, timeout)
        $maxRetries = 2;
        $resp = null;
        $err = null;
        $status = 0;
        
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Wait before retry: 1s, 2s
                $waitMs = $attempt * 1000;
                Logger::info('[ROUTER_V4] Gemini Vision API retry', [
                    'attempt' => $attempt + 1,
                    'wait_ms' => $waitMs,
                ]);
                usleep($waitMs * 1000); // Convert to microseconds
            }
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Success or non-retryable error
            if (!$err && $status < 500 && $status != 429) {
                break;
            }
            
            // Log retry attempt
            if ($attempt < $maxRetries) {
                Logger::warning('[ROUTER_V4] Gemini Vision API transient error, will retry', [
                    'attempt' => $attempt + 1,
                    'status' => $status,
                    'error' => $err,
                ]);
            }
        }

        if ($err || $status >= 400) {
            Logger::error('[ROUTER_V4] Gemini Vision API error after retries', [
                'error' => $err, 
                'status' => $status,
                'attempts' => $maxRetries + 1,
            ]);
            return ['error' => $err ?: ('http_' . $status), 'route' => 'image_generic', 'meta' => null];
        }

        $data = json_decode($resp, true);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Parse JSON response from Gemini
        $parsed = $this->extractJsonFromText($content);
        if (!is_array($parsed)) {
            return ['error' => 'parse_error', 'route' => 'image_generic', 'meta' => ['raw' => $content]];
        }

        $imageType = $parsed['image_type'] ?? 'image_generic';
        $confidence = (float)($parsed['confidence'] ?? 0.5);
        $details = $parsed['details'] ?? [];
        $description = $parsed['description'] ?? '';
        
        // ✅ FALLBACK: If Gemini put fields at root level instead of inside 'details'
        // This handles inconsistent Gemini responses
        if (empty($details) || !isset($details['amount'])) {
            $slipFields = ['amount', 'bank', 'date', 'ref', 'sender_name', 'receiver_name'];
            $productFields = ['brand', 'model', 'category'];
            $fieldsToCheck = ($imageType === 'payment_proof') ? $slipFields : $productFields;
            
            foreach ($fieldsToCheck as $field) {
                if (isset($parsed[$field]) && !isset($details[$field])) {
                    $details[$field] = $parsed[$field];
                }
            }
            
            if (!empty(array_filter($details))) {
                Logger::info('[ROUTER_V4] Gemini Vision - extracted fields from root level (fallback)', [
                    'image_type' => $imageType,
                    'details_keys' => array_keys($details),
                ]);
            }
        }

        // Map to route
        // ✅ FIX: Lower threshold for payment_proof to 0.4 (slips have clear patterns)
        $route = 'image_generic';
        if ($imageType === 'payment_proof' && $confidence >= 0.4) {
            $route = 'payment_proof';
        } elseif ($imageType === 'product_image' && $confidence >= 0.5) {
            $route = 'product_image';
        }

        return [
            'route' => $route,
            'image_type' => $imageType,
            'confidence' => $confidence,
            'details' => $details,
            'description' => $description,
            'meta' => [
                'provider' => 'gemini',
                'text' => $description,
                'labels' => [$imageType],
                'parsed' => $parsed
            ]
        ];
    }

    /**
     * Extract JSON object from text (Gemini sometimes wraps in markdown)
     */
    protected function extractJsonFromText(string $text): ?array
    {
        // Try direct parse first
        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
            return $parsed;
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $text, $matches)) {
            $parsed = json_decode($matches[1], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        // Try to find JSON object
        if (preg_match('/\{[\s\S]*\}/m', $text, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Find pending orders for a customer (for slip matching)
     * Delegates to OrderService for business logic
     */
    protected function findPendingOrdersForCustomer(string $externalUserId, ?int $channelId, ?float $excludeAmount = null): array
    {
        try {
            // Delegate to OrderService
            $orders = $this->orderService->findPendingOrders($externalUserId);
            
            // Filter out exact amount match (already handled by auto-match)
            if ($excludeAmount && $excludeAmount > 0) {
                $orders = array_filter($orders, function($order) use ($excludeAmount) {
                    $orderAmount = (float)($order['total_amount'] ?? 0);
                    return abs($orderAmount - $excludeAmount) > 1; // Allow 1 baht tolerance
                });
            }

            return array_values($orders);
        } catch (Exception $e) {
            Logger::warning('[ROUTER_V4] Failed to find pending orders', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Parse amount from various formats (string/number with commas)
     */
    protected function parseAmount($amount): float
    {
        if (is_null($amount)) {
            return 0.0;
        }
        if (is_numeric($amount)) {
            return (float)$amount;
        }
        if (is_string($amount)) {
            // Remove commas, baht symbol, spaces
            $cleaned = preg_replace('/[,฿\s]/', '', $amount);
            return (float)$cleaned;
        }
        return 0.0;
    }

    /**
     * Link a payment record to an order (auto-match)
     * Delegates to OrderService for business logic
     */
    protected function linkPaymentToOrder(int $paymentId, int $orderId): bool
    {
        try {
            // Delegate to OrderService
            $result = $this->orderService->linkPaymentToOrder($orderId, $paymentId);
            
            if ($result['success']) {
                Logger::info('[ROUTER_V4] Linked payment to order via OrderService', [
                    'payment_id' => $paymentId,
                    'order_id' => $orderId,
                ]);
                return true;
            }
            
            Logger::warning('[ROUTER_V4] OrderService failed to link payment', [
                'error' => $result['error'] ?? 'Unknown error',
            ]);
            return false;
            
        } catch (\Throwable $e) {
            Logger::error('[ROUTER_V4] Failed to link payment to order', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId,
                'order_id' => $orderId,
            ]);
            return false;
        }
    }

    // ==================== DEPOSIT INQUIRY HANDLER ====================

    /**
     * Handle deposit inquiry (รับฝาก) - เช็คว่าลูกค้ามี orders ที่เคยซื้อจากร้านหรือไม่
     * 
     * บริการ "รับฝาก" (Deposit):
     * - รับเฉพาะลูกค้าประจำ/ลูกค้าที่ซื้อสินค้าจากร้าน "ฮ.เฮง เฮง" เท่านั้น
     * - ต้องมีใบรับประกันตัวจริงมาแสดง
     * - วงเงิน 65-70% ของราคาประเมิน
     * - ดอกเบี้ย 2% ต่อเดือน, ชำระทุก 30 วัน
     * 
     * คำว่า "จำนำ" ในบริบทนี้:
     * - ไม่รับจำนำของจากร้านอื่น (แนะนำไปโรงรับจำนำ)
     * - "หลุดจำนำ" = ผิดนัดชำระ ร้านยึดของ
     */
    protected function handlePawnInquiry(array $config, array $context, array $templates): array
    {
        // ✅ Simplified: แค่ตอบเงื่อนไขบริการรับฝาก แล้ว handoff ให้แอดมินเลย
        
        $reply = $templates['deposit_policy'] ?? 
            "🏆 บริการ \"รับฝาก\" ของทางร้าน ฮ.เฮง เฮง\n\n" .
            "📌 เงื่อนไขการรับฝาก:\n" .
            "• รับเฉพาะลูกค้าที่ซื้อสินค้าจากทางร้านเท่านั้น\n" .
            "• ต้องมี \"ใบรับประกันตัวจริง\" มาแสดงทุกครั้ง\n" .
            "• วงเงิน 65-70% ของราคาประเมิน\n" .
            "• ดอกเบี้ย 2% ต่อเดือน\n" .
            "• ชำระดอกเบี้ยทุก 30 วัน\n" .
            "• สินค้าจะถูกเก็บในตู้นิรภัยอย่างดี\n\n" .
            "⚠️ หมายเหตุ: ทางร้านไม่รับจำนำสินค้าจากร้านอื่นค่ะ\n\n" .
            "📸 ส่งรูปสินค้า+ใบรับประกันมาได้เลยค่ะ แอดมินจะประเมินให้นะคะ 😊";

        // สร้าง case สำหรับ admin follow-up
        try {
            $this->createOrUpdateCase(\CaseEngine::CASE_PAWN, [
                'description' => 'ลูกค้าสอบถามเรื่องรับฝาก/ฝากขาย',
            ], $config, $context);
        } catch (\Exception $e) {
            Logger::warning('[ROUTER_V4] Failed to create pawn case', ['error' => $e->getMessage()]);
        }
        
        // ✅ 2025-01-31: Set pending_intent state so handleImage knows this is pawn assessment
        // Critical fix: Without this, customer's pawn image goes to product search!
        $platformUserId = $context['platform_user_id'] ?? $context['external_user_id'] ?? '';
        $channelId = $context['channel']['id'] ?? 0;
        
        if ($platformUserId && $channelId) {
            $this->chatService->setQuickState('pending_intent', [
                'intent' => 'pawn_assessment',
                'created_at' => time(),
            ], $platformUserId, (int)$channelId, 600); // จำ 10 นาที
            
            Logger::info('[ROUTER_V4] Set pending_intent for pawn_assessment', [
                'platform_user_id' => $platformUserId,
                'channel_id' => $channelId,
            ]);
        }
        
        // ❌ ไม่ handoff ที่นี่ - รอให้ลูกค้าส่งรูปมาก่อน
        // Handoff จะเกิดใน handleImage เมื่อได้รับรูปประเมินราคา
        // ถ้า handoff ตรงนี้ ลูกค้าจะถาม "มีแหวนไหม" ไม่ได้เพราะบอทเงียบ

        return ['reply' => $reply];
    }

    /**
     * Find active installments for a customer (for slip matching)
     * Delegates to InstallmentService for business logic
     * 
     * @param string $externalUserId Platform user ID
     * @param int|null $channelId Channel ID
     * @return array List of active installments
     */
    protected function findActiveInstallmentsForCustomer(string $externalUserId, ?int $channelId): array
    {
        try {
            // Delegate to InstallmentService
            return $this->installmentService->findActiveInstallments($externalUserId);
        } catch (Exception $e) {
            Logger::warning('[ROUTER_V4] Failed to find active installments', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Link a payment record to an installment (Hybrid A+ auto-match)
     * Delegates to InstallmentService for business logic
     * 
     * @param int $paymentId Payment ID from payments table
     * @param int $installmentId Installment ID from installment_contracts table
     * @param float $amount Payment amount (optional, for logging)
     * @return bool Success status
     */
    protected function linkPaymentToInstallment(int $paymentId, int $installmentId, float $amount = 0): bool
    {
        try {
            // Delegate to InstallmentService
            $result = $this->installmentService->linkPaymentToInstallment($installmentId, $paymentId);
            
            if ($result['success']) {
                Logger::info('[ROUTER_V4] Linked payment to installment via InstallmentService', [
                    'payment_id' => $paymentId,
                    'installment_id' => $installmentId,
                    'amount' => $amount,
                ]);
                return true;
            }
            
            Logger::warning('[ROUTER_V4] InstallmentService failed to link payment', [
                'error' => $result['error'] ?? 'Unknown error',
            ]);
            return false;
            
        } catch (Exception $e) {
            Logger::error('[ROUTER_V4] Failed to link payment to installment', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId,
                'installment_id' => $installmentId,
            ]);
            return false;
        }
    }

    /**
     * Find active pawns for a customer (Hybrid A+ - for slip matching)
     * @param string $externalUserId Platform user ID
     * @param int|null $channelId Channel ID
     * @return array List of active/overdue pawns
     */
    protected function findActivePawnsForCustomer(string $externalUserId, ?int $channelId): array
    {
        try {
            // Look up customer_id from platform_user_id
            $customer = $this->db->queryOne("
                SELECT id FROM customer_profiles 
                WHERE platform_user_id = ?
                LIMIT 1
            ", [$externalUserId]);
            
            if (!$customer) {
                return [];
            }
            
            // Get active pawns for this customer (production schema)
            $sql = "SELECT p.id, p.pawn_no, p.item_name, p.item_type, p.item_description,
                           p.loan_amount, p.interest_rate, p.accrued_interest,
                           p.total_due, p.due_date, p.pawn_date, p.status,
                           ROUND(p.loan_amount * p.interest_rate / 100, 2) as expected_interest,
                           p.total_due as full_redemption_amount,
                           DATEDIFF(p.due_date, CURDATE()) as days_until_due
                    FROM pawns p
                    WHERE p.customer_id = ?
                    AND p.status IN ('active', 'extended')
                    ORDER BY p.due_date ASC, p.created_at DESC
                    LIMIT 10";
            
            return $this->db->query($sql, [$customer['id']]);
        } catch (Exception $e) {
            Logger::warning('[ROUTER_V4] Failed to find active pawns', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Link a payment record to a pawn (Hybrid A+ auto-match)
     * Delegates to PawnService for business logic
     * 
     * @param int $paymentId Payment ID from payments table
     * @param int $pawnId Pawn ID from pawns table
     * @param string $paymentType 'interest', 'redemption', 'partial'
     * @param float $amount Payment amount (optional, used for logging)
     * @return bool Success status
     */
    protected function linkPaymentToPawn(int $paymentId, int $pawnId, string $paymentType = 'interest', float $amount = 0): bool
    {
        try {
            // Delegate to PawnService
            $result = $this->pawnService->linkPaymentToPawn($pawnId, $paymentId, $paymentType);
            
            if ($result['success']) {
                Logger::info('[ROUTER_V4] Linked payment to pawn via PawnService', [
                    'payment_id' => $paymentId,
                    'pawn_id' => $pawnId,
                    'payment_type' => $paymentType,
                    'amount' => $amount,
                ]);
                return true;
            }
            
            Logger::warning('[ROUTER_V4] PawnService failed to link payment', [
                'error' => $result['error'] ?? 'Unknown error',
            ]);
            return false;
            
        } catch (\Throwable $e) {
            Logger::error('[ROUTER_V4] Failed to link payment to pawn', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId,
                'pawn_id' => $pawnId,
            ]);
            return false;
        }
    }

    /**
     * Handle change payment method request (after checkout confirm)
     */
    protected function handleChangePaymentMethod(array $params, array $config, array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? '';
        $channelId = $context['channel']['id'] ?? 0;
        $text = mb_strtolower($context['message']['text'] ?? '', 'UTF-8');

        // Get last order from state
        $lastOrder = $this->chatService->getQuickState('last_order', $platformUserId, $channelId);
        
        if (!$lastOrder) {
            return ['reply' => 'ไม่พบออเดอร์ค่ะ 🔍 รบกวนแจ้งเลขออเดอร์มา หรือเลือกสินค้าใหม่ได้เลยนะคะ'];
        }

        $orderNo = $lastOrder['order_no'] ?? '';
        $orderId = $lastOrder['order_id'] ?? null;
        $currentPaymentType = $lastOrder['payment_type'] ?? 'full';
        $product = $lastOrder['product'] ?? [];

        // Detect new payment type from message
        $newPaymentType = 'full';
        if (preg_match('/(โอน|เต็ม|full|สด|cash)/u', $text)) {
            $newPaymentType = 'full';
        } elseif (preg_match('/(ผ่อน|งวด|installment)/u', $text)) {
            $newPaymentType = 'installment';
        } elseif (preg_match('/(มัดจำ|deposit|จอง)/u', $text)) {
            $newPaymentType = 'deposit';
        } elseif (preg_match('/(ออม|savings)/u', $text)) {
            $newPaymentType = 'savings';
        }

        // If same as current, no change needed
        if ($newPaymentType === $currentPaymentType) {
            $typeLabel = $this->getPaymentTypeLabel($currentPaymentType);
            return ['reply' => "ออเดอร์ #{$orderNo} เป็น{$typeLabel}อยู่แล้วค่ะ 😊\n\nอยากเปลี่ยนวิธีอื่นไหมคะ? พิมพ์บอกได้เลยค่ะ เช่น \"เปลี่ยนเป็นผ่อน\" หรือ \"โอนเต็ม\""];
        }

        // Update order payment type
        if ($orderId) {
            try {
                $this->db->execute(
                    "UPDATE orders SET payment_type = ?, updated_at = NOW() WHERE id = ?",
                    [$newPaymentType, $orderId]
                );

                // Update last_order state
                $lastOrder['payment_type'] = $newPaymentType;
                $this->chatService->setQuickState('last_order', $lastOrder, $platformUserId, $channelId, 3600);

                $oldLabel = $this->getPaymentTypeLabel($currentPaymentType);
                $newLabel = $this->getPaymentTypeLabel($newPaymentType);
                $productName = $product['name'] ?? '';

                return ['reply' => "✅ เปลี่ยนวิธีชำระเรียบร้อยค่ะ\n\n📋 ออเดอร์: #{$orderNo}\n📦 สินค้า: {$productName}\n\n• จาก: {$oldLabel}\n• เป็น: {$newLabel}\n\nแอดมินจะติดต่อยืนยันรายละเอียดนะคะ 😊"];

            } catch (Exception $e) {
                Logger::error('[ROUTER_V4] Failed to update payment type', ['error' => $e->getMessage()]);
            }
        }

        return ['reply' => "เปลี่ยนวิธีชำระไม่ได้ค่ะ 😅 รบกวนพิมพ์ \"แอดมิน\" เพื่อติดต่อแอดมินโดยตรงนะคะ"];
    }

    /**
     * Get payment type label in Thai
     */
    protected function getPaymentTypeLabel(string $type): string
    {
        return match($type) {
            'full' => 'ชำระเต็มจำนวน',
            'installment' => 'ผ่อนชำระ',
            'deposit' => 'มัดจำ',
            'savings' => 'ออมทอง',
            default => $type
        };
    }

    // =========================================================
    // ✅ CASE ENGINE INTEGRATION (ported from RouterV1Handler)
    // =========================================================

    /**
     * Create or update case using CaseEngine
     * This mirrors V1's case management for consistency
     */
    protected function createOrUpdateCase(string $caseType, array $slots, array $config, array $context): ?array
    {
        try {
            // Check if case management is enabled
            $caseCfg = $config['case_management'] ?? [];
            if (empty($caseCfg['enabled'])) {
                return null;
            }

            $caseEngine = new \CaseEngine($config, $context);
            $case = $caseEngine->getOrCreateCase($caseType, $slots);

            if ($case) {
                Logger::info('[ROUTER_V4] Case created/updated', [
                    'case_id' => $case['id'] ?? null,
                    'case_no' => $case['case_no'] ?? null,
                    'case_type' => $caseType,
                ]);
            }

            return $case;
        } catch (\Exception $e) {
            Logger::error('[ROUTER_V4] CaseEngine error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Detect case type from intent (mirrors CaseEngine::detectCaseType)
     */
    protected function detectCaseTypeFromIntent(string $intent): ?string
    {
        $map = [
            'product_lookup_by_code' => \CaseEngine::CASE_PRODUCT_INQUIRY,
            'product_search' => \CaseEngine::CASE_PRODUCT_INQUIRY,
            'product_availability' => \CaseEngine::CASE_PRODUCT_INQUIRY,
            'price_inquiry' => \CaseEngine::CASE_PRODUCT_INQUIRY,
            'checkout_confirm' => \CaseEngine::CASE_PAYMENT_FULL,
            'payment_slip_verify' => \CaseEngine::CASE_PAYMENT_FULL,
            'installment_check' => \CaseEngine::CASE_PAYMENT_INSTALLMENT,
            'installment_flow' => \CaseEngine::CASE_PAYMENT_INSTALLMENT,
            'pawn_check' => \CaseEngine::CASE_PAWN,
            'repair_check' => \CaseEngine::CASE_REPAIR,
            'savings_check' => \CaseEngine::CASE_PAYMENT_SAVINGS,
        ];

        return $map[$intent] ?? null;
    }

    /**
     * Check if auto-create case is enabled and intent is relevant
     */
    protected function shouldAutoCreateCase(array $config, string $intent): bool
    {
        // Check if auto-create case is enabled in config
        $enabled = $config['features']['auto_create_case'] ?? $config['auto_create_case'] ?? true;
        
        if (!$enabled) {
            return false;
        }

        // Only create cases for relevant intents (not greetings, thanks, etc.)
        $caseableIntents = [
            'product_lookup_by_code',
            'product_search',
            'product_availability',
            'price_inquiry',
            'checkout_confirm',
            'purchase_intent',
            'product_interest',
            'payment_slip_verify',
            'installment_check',
            'installment_flow',
            'pawn_check',
            'repair_check',
            'savings_check',
            'order_check',
            'order_status',
        ];

        return in_array($intent, $caseableIntents);
    }

    // =========================================================
    // ✅ ADDRESS PARSING (ported from RouterV1Handler)
    // =========================================================

    /**
     * Parse Thai shipping address from text
     * Extracts: name, phone, address_line1, district, province, postal_code
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

        // Clean up text
        $text = preg_replace('/\s+/', ' ', trim($text));
        $text = preg_replace('/\n+/', ' ', $text);

        // Extract phone number (10 digits, starting with 0)
        if (preg_match('/(?:0[689]\d{8}|0[1-5]\d{7})/u', $text, $phoneMatch)) {
            $result['phone'] = $phoneMatch[0];
            $text = str_replace($phoneMatch[0], '', $text);
        }

        // Extract postal code (5 digits)
        if (preg_match('/\b(\d{5})\b/', $text, $postalMatch)) {
            $result['postal_code'] = $postalMatch[1];
            $text = str_replace($postalMatch[0], '', $text);
        }

        // Common Thai provinces
        $provinces = ['กรุงเทพ', 'กรุงเทพฯ', 'กทม', 'นนทบุรี', 'ปทุมธานี', 'สมุทรปราการ', 
                      'ชลบุรี', 'เชียงใหม่', 'ขอนแก่น', 'นครราชสีมา', 'สงขลา', 'ภูเก็ต', 'ระยอง'];
        
        foreach ($provinces as $prov) {
            if (mb_stripos($text, $prov) !== false) {
                $result['province'] = $prov === 'กทม' ? 'กรุงเทพฯ' : $prov;
                $text = str_ireplace($prov, '', $text);
                break;
            }
        }

        // Extract district (อำเภอ/เขต)
        if (preg_match('/(?:อ\.?|อำเภอ|เขต)\s*([ก-๙a-zA-Z]+)/u', $text, $districtMatch)) {
            $result['district'] = $districtMatch[1];
            $text = str_replace($districtMatch[0], '', $text);
        }

        // Extract subdistrict (ตำบล/แขวง)
        if (preg_match('/(?:ต\.?|ตำบล|แขวง)\s*([ก-๙a-zA-Z]+)/u', $text, $subdistMatch)) {
            $result['subdistrict'] = $subdistMatch[1];
            $text = str_replace($subdistMatch[0], '', $text);
        }

        // Clean remaining text
        $text = preg_replace('/\s+/', ' ', trim($text));
        $parts = preg_split('/[,\n\s]{2,}/u', $text, 2);

        if (count($parts) >= 2) {
            $result['name'] = trim($parts[0]);
            $result['address_line1'] = trim($parts[1]);
        } else {
            // Try to extract Thai name (first 2-4 words)
            if (preg_match('/^([ก-๙]+\s+[ก-๙]+(?:\s+[ก-๙]+)?)/u', $text, $nameMatch)) {
                $result['name'] = trim($nameMatch[1]);
                $result['address_line1'] = trim(str_replace($nameMatch[1], '', $text));
            } else {
                $result['address_line1'] = $text;
            }
        }

        // Clean up
        $result['address_line1'] = preg_replace('/^[,\s]+|[,\s]+$/', '', $result['address_line1']);

        return $result;
    }

    /**
     * Check if text looks like a shipping address
     * Delegates to AddressService for pattern matching
     */
    protected function looksLikeAddress(string $text): bool
    {
        return $this->addressService->looksLikeAddress($text);
    }

    /**
     * Save customer address to database
     * Delegates to AddressService for storage
     */
    protected function saveCustomerAddress(array $addressData, array $context): ?int
    {
        try {
            $platformUserId = $context['platform_user_id'] ?? null;
            $platform = $context['platform'] ?? 'line';

            if (!$platformUserId) {
                return null;
            }

            // Get or create customer profile to get user_id
            $customer = $this->db->queryOne(
                "SELECT id, user_id FROM customer_profiles WHERE platform_user_id = ? AND platform = ? LIMIT 1",
                [$platformUserId, $platform]
            );
            $userId = $customer ? (int)($customer['user_id'] ?? $customer['id']) : null;

            if (!$userId) {
                Logger::warning('[ROUTER_V4] Cannot save address: no user_id', [
                    'platform_user_id' => $platformUserId
                ]);
                return null;
            }

            // Map addressData to AddressService format
            $addressPayload = [
                'full_name' => $addressData['name'] ?? '',
                'phone' => $addressData['phone'] ?? '',
                'address_line' => trim(($addressData['address_line1'] ?? '') . ' ' . ($addressData['address_line2'] ?? '')),
                'subdistrict' => $addressData['subdistrict'] ?? '',
                'district' => $addressData['district'] ?? '',
                'province' => $addressData['province'] ?? '',
                'postal_code' => $addressData['postal_code'] ?? '',
                'is_default' => true,
            ];

            // Delegate to AddressService
            $result = $this->addressService->saveAddress($userId, $addressPayload);
            
            if ($result['success']) {
                Logger::info('[ROUTER_V4] Customer address saved via AddressService', [
                    'address_id' => $result['address_id'],
                    'user_id' => $userId,
                    'platform_user_id' => $platformUserId,
                ]);
                return (int)$result['address_id'];
            }
            
            Logger::warning('[ROUTER_V4] AddressService failed', ['error' => $result['error'] ?? 'Unknown']);
            return null;
            
        } catch (\Exception $e) {
            Logger::error('[ROUTER_V4] Failed to save address', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
