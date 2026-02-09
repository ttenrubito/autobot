<?php
/**
 * FunctionExecutor - Execute function calls from LLM responses
 * 
 * Takes LLM function call decisions and executes the corresponding
 * service methods, returning results back to the LLM.
 * 
 * @version 1.0
 * @date 2026-02-07
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Logger.php';

use Logger;

class FunctionExecutor
{
    protected $productService;
    protected $transactionService;
    protected $checkoutService;
    protected $chatService;
    protected $config;
    protected $context;

    public function __construct(
        ProductService $productService,
        TransactionService $transactionService,
        CheckoutService $checkoutService,
        ChatService $chatService
    ) {
        $this->productService = $productService;
        $this->transactionService = $transactionService;
        $this->checkoutService = $checkoutService;
        $this->chatService = $chatService;
    }

    /**
     * Set config and context for execution
     */
    public function setContext(array $config, array $context): void
    {
        $this->config = $config;
        $this->context = $context;
    }

    /**
     * Execute a function call from LLM
     * 
     * @param string $functionName The function to call
     * @param array $arguments The arguments from LLM
     * @return array Result to send back to LLM
     */
    public function execute(string $functionName, array $arguments): array
    {
        Logger::info('[FUNC_EXECUTOR] Executing function', [
            'function' => $functionName,
            'arguments' => $arguments,
        ]);

        try {
            switch ($functionName) {
                // ==================== PRODUCT FUNCTIONS ====================
                case 'search_products':
                    return $this->executeSearchProducts($arguments);

                case 'get_product_by_code':
                    return $this->executeGetProductByCode($arguments);

                case 'check_product_stock':
                    return $this->executeCheckProductStock($arguments);

                // ==================== ORDER FUNCTIONS ====================
                case 'get_order_status':
                    return $this->executeGetOrderStatus($arguments);

                case 'create_order':
                    return $this->executeCreateOrder($arguments);

                // ==================== TRANSACTION FUNCTIONS ====================
                case 'check_installment':
                    return $this->executeCheckInstallment();

                case 'check_pawn':
                    return $this->executeCheckPawn();

                case 'create_pawn_inquiry':
                    return $this->executeCreatePawnInquiry($arguments);

                // ==================== PAYMENT FUNCTIONS ====================
                case 'get_payment_options':
                    return $this->executeGetPaymentOptions();

                case 'calculate_installment':
                    return $this->executeCalculateInstallment($arguments);

                // ==================== SUPPORT FUNCTIONS ====================
                case 'request_admin_handoff':
                    return $this->executeAdminHandoff($arguments);

                case 'get_store_info':
                    return $this->executeGetStoreInfo($arguments);

                case 'request_video_call':
                    return $this->executeVideoCallRequest($arguments);

                // ==================== GENERAL ====================
                case 'general_response':
                    // This is a direct response, no execution needed
                    $responseText = $arguments['response_text'] ?? '';
                    
                    // If LLM didn't provide response, use meaningful fallback
                    if (empty(trim($responseText))) {
                        $responseText = 'สอบถามรายละเอียดเพิ่มเติมได้นะคะ ยินดีช่วยเหลือค่ะ 😊';
                    }
                    
                    return [
                        'ok' => true,
                        'type' => 'direct_response',
                        'response' => $responseText,
                        'response_type' => $arguments['response_type'] ?? 'other',
                    ];

                default:
                    Logger::warning('[FUNC_EXECUTOR] Unknown function', ['function' => $functionName]);
                    return [
                        'ok' => false,
                        'error' => "Unknown function: {$functionName}",
                    ];
            }
        } catch (\Exception $e) {
            Logger::error('[FUNC_EXECUTOR] Execution error', [
                'function' => $functionName,
                'error' => $e->getMessage(),
            ]);
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ==================== PRODUCT IMPLEMENTATIONS ====================

    protected function executeSearchProducts(array $args): array
    {
        $keyword = $args['keyword'] ?? '';
        $category = $args['category'] ?? null;
        $priceMax = $args['price_max'] ?? null;
        $priceMin = $args['price_min'] ?? null;

        // ✅ NEW: Detect generic keywords → return browse_products instead
        $genericKeywords = ['สินค้า', 'ของ', 'รายการ', 'สินค้าทั้งหมด', 'ทั้งหมด', 'catalog', 'all', ''];
        $cleanKeyword = mb_strtolower(trim($keyword), 'UTF-8');
        
        if (in_array($cleanKeyword, $genericKeywords)) {
            Logger::info('[FUNC_EXECUTOR] Generic keyword detected, returning browse_products', [
                'keyword' => $keyword
            ]);
            return [
                'ok' => true,
                'type' => 'browse_products',
                'message' => 'ลูกค้าต้องการดูหมวดหมู่สินค้า',
            ];
        }

        $result = $this->productService->search($keyword, $this->config, $this->context);

        if ($result['ok'] && !empty($result['products'])) {
            $products = $result['products'];
            
            // Filter by category if specified
            if ($category) {
                $products = array_filter($products, function($p) use ($category) {
                    $productCategory = strtolower($p['category'] ?? $p['type'] ?? '');
                    return stripos($productCategory, $category) !== false;
                });
            }
            
            // Filter by max price if specified
            if ($priceMax) {
                $products = array_filter($products, function($p) use ($priceMax) {
                    $price = (float)($p['price'] ?? $p['sale_price'] ?? 0);
                    return $price <= $priceMax;
                });
            }
            
            // Filter by min price if specified
            if ($priceMin) {
                $products = array_filter($products, function($p) use ($priceMin) {
                    $price = (float)($p['price'] ?? $p['sale_price'] ?? 0);
                    return $price >= $priceMin;
                });
            }
            
            // Re-index
            $products = array_values($products);
            $totalFound = count($products);
            
            if (empty($products)) {
                // Products found but filtered out by price
                $priceHint = '';
                if ($priceMax) {
                    $priceHint = " ไม่เกิน " . number_format($priceMax) . " บาท";
                }
                return [
                    'ok' => true,
                    'type' => 'no_products',
                    'message' => "ไม่พบสินค้า \"{$keyword}\"{$priceHint} ค่ะ ลองปรับราคาหรือคำค้นดูนะคะ 😊",
                ];
            }
            
            // 🚀 Smart Response: ถ้าผลลัพธ์เยอะเกินไป ให้ถามเพิ่ม
            if ($totalFound > 20) {
                // Get unique brands/categories from results for suggestions
                $brands = [];
                $priceRanges = ['under_100k' => 0, '100k_500k' => 0, 'over_500k' => 0];
                
                foreach (array_slice($products, 0, 50) as $p) {
                    // Extract brand
                    $brand = $p['brand'] ?? null;
                    if ($brand) {
                        $brands[$brand] = ($brands[$brand] ?? 0) + 1;
                    }
                    
                    // Count price ranges
                    $price = (float)($p['price'] ?? $p['sale_price'] ?? 0);
                    if ($price < 100000) {
                        $priceRanges['under_100k']++;
                    } elseif ($price <= 500000) {
                        $priceRanges['100k_500k']++;
                    } else {
                        $priceRanges['over_500k']++;
                    }
                }
                
                // Sort brands by count
                arsort($brands);
                $topBrands = array_slice(array_keys($brands), 0, 5);
                
                return [
                    'ok' => true,
                    'type' => 'too_many_results',
                    'keyword' => $keyword,
                    'total_found' => $totalFound,
                    'top_brands' => $topBrands,
                    'price_ranges' => $priceRanges,
                    'sample_products' => array_slice($products, 0, 3), // Show 3 samples
                ];
            }

            return [
                'ok' => true,
                'type' => 'product_list',
                'products' => array_slice($products, 0, 5), // Max 5 products
                'total_found' => $totalFound,
                'keyword' => $keyword,
            ];
        }

        return [
            'ok' => true,
            'type' => 'no_products',
            'message' => "ไม่พบสินค้าที่ตรงกับ \"{$keyword}\" ค่ะ\n\n💡 ลองค้นหาด้วย:\n• รหัสสินค้า เช่น P001, R023\n• ส่งรูปภาพสินค้าที่ต้องการ\n• ชื่อเฉพาะ เช่น แหวนเพชร, สร้อยทอง",
        ];
    }

    protected function executeGetProductByCode(array $args): array
    {
        $code = $args['product_code'] ?? '';
        
        $result = $this->productService->getByCode($code, $this->config, $this->context);
        
        if ($result && !empty($result['product'])) {
            return [
                'ok' => true,
                'type' => 'product_detail',
                'product' => $result['product'],
            ];
        }

        return [
            'ok' => false,
            'type' => 'not_found',
            'message' => "ไม่พบสินค้ารหัส {$code}",
        ];
    }

    protected function executeCheckProductStock(array $args): array
    {
        $productId = $args['product_id'] ?? null;
        $productCode = $args['product_code'] ?? null;

        // Try to get from recent context if not provided
        if (!$productId && !$productCode) {
            $recentProduct = $this->chatService->getQuickState(
                'last_viewed_product',
                $this->context['platform_user_id'] ?? '',
                $this->context['channel']['id'] ?? 0
            );
            $productId = $recentProduct['value']['id'] ?? null;
            $productCode = $recentProduct['value']['code'] ?? null;
        }

        if (!$productId && !$productCode) {
            return [
                'ok' => false,
                'type' => 'need_product',
                'message' => 'ไม่ทราบว่าต้องการเช็คสินค้าชิ้นไหน กรุณาระบุชื่อหรือรหัสสินค้า',
            ];
        }

        // Get product info
        if ($productCode) {
            $result = $this->productService->getByCode($productCode, $this->config, $this->context);
        } else {
            $result = $this->productService->getById($productId, $this->config, $this->context);
        }

        if ($result && !empty($result['product'])) {
            $product = $result['product'];
            $inStock = ($product['quantity'] ?? $product['stock'] ?? 1) > 0;
            $quantity = $product['quantity'] ?? $product['stock'] ?? 1;

            return [
                'ok' => true,
                'type' => 'stock_status',
                'product_name' => $product['name'] ?? $product['title'] ?? 'สินค้า',
                'in_stock' => $inStock,
                'quantity' => $quantity,
                'price' => $product['price'] ?? 0,
            ];
        }

        return [
            'ok' => false,
            'type' => 'not_found',
            'message' => 'ไม่พบข้อมูลสินค้า',
        ];
    }

    // ==================== ORDER IMPLEMENTATIONS ====================

    protected function executeGetOrderStatus(array $args): array
    {
        $orderNo = $args['order_no'] ?? null;

        $result = $this->transactionService->checkOrder($this->config, $this->context, $orderNo);

        return [
            'ok' => true,
            'type' => 'order_status',
            'message' => $result['message'] ?? 'ไม่พบข้อมูลคำสั่งซื้อ',
            'order' => $result['order'] ?? null,
        ];
    }

    protected function executeCreateOrder(array $args): array
    {
        // This should trigger checkout flow, not create order directly
        $productId = $args['product_id'] ?? null;
        $quantity = $args['quantity'] ?? 1;

        if (!$productId) {
            return [
                'ok' => false,
                'type' => 'need_product',
                'message' => 'กรุณาเลือกสินค้าที่ต้องการสั่งซื้อก่อนค่ะ',
            ];
        }

        // Add to cart/checkout
        $platformUserId = $this->context['platform_user_id'] ?? '';
        $channelId = $this->context['channel']['id'] ?? 0;

        $this->checkoutService->setCheckoutState($platformUserId, $channelId, [
            'product_id' => $productId,
            'quantity' => $quantity,
            'step' => 'confirm',
        ]);

        return [
            'ok' => true,
            'type' => 'checkout_started',
            'message' => 'เริ่มกระบวนการสั่งซื้อแล้ว กรุณายืนยันคำสั่งซื้อ',
            'next_action' => 'confirm_order',
        ];
    }

    // ==================== TRANSACTION IMPLEMENTATIONS ====================

    protected function executeCheckInstallment(): array
    {
        $result = $this->transactionService->checkInstallment($this->config, $this->context);

        return [
            'ok' => true,
            'type' => 'installment_status',
            'message' => $result['message'] ?? 'ไม่พบข้อมูลการผ่อนชำระ',
        ];
    }

    protected function executeCheckPawn(): array
    {
        $result = $this->transactionService->checkPawn($this->config, $this->context);

        return [
            'ok' => true,
            'type' => 'pawn_status',
            'message' => $result['message'] ?? 'ไม่พบข้อมูลการจำนำ',
        ];
    }

    protected function executeCreatePawnInquiry(array $args): array
    {
        $itemDescription = $args['item_description'] ?? '';

        return [
            'ok' => true,
            'type' => 'pawn_inquiry',
            'message' => 'รับทราบค่ะ ต้องการฝากขาย/จำนำ กรุณาส่งรูปสินค้าและรายละเอียดมาได้เลยค่ะ แอดมินจะประเมินราคาให้ค่ะ',
            'item_description' => $itemDescription,
            'next_action' => 'send_photo',
        ];
    }

    // ==================== PAYMENT IMPLEMENTATIONS ====================

    protected function executeGetPaymentOptions(): array
    {
        $checkoutState = null;
        $platformUserId = $this->context['platform_user_id'] ?? '';
        $channelId = $this->context['channel']['id'] ?? 0;

        if ($platformUserId && $channelId) {
            $checkoutState = $this->checkoutService->getCheckoutState($platformUserId, $channelId);
        }

        $msg = $this->checkoutService->getPaymentOptionsInfo($this->config, $checkoutState);

        return [
            'ok' => true,
            'type' => 'payment_options',
            'message' => $msg,
        ];
    }

    protected function executeCalculateInstallment(array $args): array
    {
        $price = $args['price'] ?? 0;

        if ($price <= 0) {
            return [
                'ok' => false,
                'type' => 'need_price',
                'message' => 'กรุณาระบุราคาสินค้าที่ต้องการคำนวณค่ะ',
            ];
        }

        // ✅ Use config from shop (not hardcoded!)
        // Default: 3 งวด, ค่าธรรมเนียม 3% งวดแรก, ผ่อนครบรับของ
        $installmentConfig = $this->config['installment'] ?? [];
        $periods = (int)($installmentConfig['periods'] ?? 3);
        $feePercent = (float)($installmentConfig['fee_percent'] ?? 3);
        $deliveryRule = $installmentConfig['delivery_rule'] ?? 'ผ่อนครบรับของ';

        // Calculate: fee added to first period
        $fee = ceil($price * ($feePercent / 100));
        $perPeriod = ceil($price / $periods);
        $firstPeriod = $perPeriod + $fee;
        $remainingPeriods = $perPeriod;
        $total = $firstPeriod + ($remainingPeriods * ($periods - 1));

        return [
            'ok' => true,
            'type' => 'installment_calculation',
            'price' => $price,
            'periods' => $periods,
            'fee_percent' => $feePercent,
            'first_period' => $firstPeriod,
            'remaining_periods' => $remainingPeriods,
            'total' => $total,
            'delivery_rule' => $deliveryRule,
        ];
    }

    // ==================== SUPPORT IMPLEMENTATIONS ====================

    protected function executeAdminHandoff(array $args): array
    {
        $reason = $args['reason'] ?? 'ลูกค้าต้องการคุยกับแอดมิน';

        // Update session for admin handoff
        $sessionId = $this->context['session_id'] ?? null;
        if ($sessionId) {
            $this->chatService->markForAdminHandoff($sessionId, $reason);
        }

        return [
            'ok' => true,
            'type' => 'admin_handoff',
            'message' => 'รับทราบค่ะ ส่งต่อให้แอดมินดูแลแล้วนะคะ รอสักครู่ค่ะ 🙏',
            'reason' => $reason,
        ];
    }

    protected function executeGetStoreInfo(array $args): array
    {
        // Get store info from config
        $store = $this->config['store'] ?? [];
        
        if (empty($store)) {
            return [
                'ok' => true,
                'type' => 'store_info',
                'message' => 'สอบถามข้อมูลร้านค้าเพิ่มเติมได้ทาง LINE หรือโทรติดต่อได้เลยค่ะ 😊',
            ];
        }
        
        $info = [];
        if (!empty($store['name'])) {
            $info[] = "🏪 " . $store['name'];
        }
        if (!empty($store['hours'])) {
            $info[] = "🕐 เปิดบริการ: " . $store['hours'];
        }
        if (!empty($store['address'])) {
            $info[] = "📍 " . $store['address'];
        }
        if (!empty($store['phone'])) {
            $info[] = "📞 " . $store['phone'];
        }
        if (!empty($store['line_id'])) {
            $info[] = "💬 LINE: " . $store['line_id'];
        }
        
        $message = !empty($info) ? implode("\n", $info) : 'ติดต่อร้านค้าผ่านช่องทางแชทได้เลยค่ะ 😊';
        
        return [
            'ok' => true,
            'type' => 'store_info',
            'message' => $message,
        ];
    }

    protected function executeVideoCallRequest(array $args): array
    {
        $productId = $args['product_id'] ?? null;

        return [
            'ok' => true,
            'type' => 'video_call_request',
            'message' => 'รับทราบค่ะ ต้องการดูสินค้าผ่าน Video Call นะคะ กรุณารอแอดมินติดต่อกลับเพื่อนัดหมายเวลาค่ะ 📹',
            'product_id' => $productId,
        ];
    }
}
