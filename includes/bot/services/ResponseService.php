<?php
/**
 * ResponseService - Smart response generation with natural language
 * 
 * Features:
 * - Template rendering with data binding
 * - LLM-powered natural language rewriting (optional)
 * - Locked templates for sensitive data (money, accounts)
 * 
 * IMPORTANT RULES:
 * - ข้อมูลเงิน/เลขบัญชี/ยอดผ่อน → ใช้ Template ตรงๆ (ห้าม AI แก้)
 * - บทสนทนาทั่วไป → ให้ AI ช่วยเกลาภาษาได้
 * 
 * @version 1.0
 * @date 2026-01-23
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../Logger.php';

class ResponseService
{
    protected $db;
    protected $llmService;
    
    /**
     * Templates ที่ห้าม AI แก้ไข (มีตัวเลข/ข้อมูลสำคัญ)
     */
    const LOCKED_TEMPLATES = [
        'payment_account',
        'payment_info', 
        'installment_table',
        'installment_detail',
        'order_confirmation',
        'order_summary',
        'pawn_detail',
        'pawn_redemption',
        'savings_balance',
        'savings_detail',
        'price_quote',
        'deposit_confirmation',
    ];

    /**
     * Templates ที่ AI สามารถช่วยเกลาภาษาได้
     */
    const REWRITABLE_TEMPLATES = [
        'greeting',
        'product_found',
        'product_not_found',
        'search_result',
        'help_message',
        'goodbye',
        'thank_you',
        'wait_message',
        'transfer_to_admin',
        'general_response',
    ];

    public function __construct($llmService = null)
    {
        $this->db = \Database::getInstance();
        $this->llmService = $llmService;
    }

    /**
     * Set LLM Service for natural language rewriting
     */
    public function setLlmService($llmService): void
    {
        $this->llmService = $llmService;
    }

    // ==================== MAIN RESPONSE METHOD ====================

    /**
     * Generate response with optional natural language rewriting
     * 
     * @param array $config Bot config with templates
     * @param string $templateKey Template key (e.g., 'greeting', 'payment_info')
     * @param array $data Data to bind to template
     * @param string|null $userMessage Original user message for context
     * @param bool $forceNatural Force natural rewriting even if disabled in config
     * @return string Response message
     */
    public function reply(
        array $config, 
        string $templateKey, 
        array $data = [], 
        ?string $userMessage = null,
        bool $forceNatural = false
    ): string {
        // 1. Render template with data
        $draftMessage = $this->renderTemplate($config, $templateKey, $data);
        
        // 2. Check if this template should be locked (no AI modification)
        if ($this->isLockedTemplate($templateKey)) {
            \Logger::debug("[ResponseService] Using locked template", [
                'template' => $templateKey,
            ]);
            return $draftMessage;
        }

        // 3. Check if natural language rewriting is enabled
        $enableNatural = $forceNatural || ($config['natural_language']['enabled'] ?? false);
        
        if (!$enableNatural || !$this->llmService) {
            return $draftMessage;
        }

        // 4. Check if this template is rewritable
        if (!$this->isRewritableTemplate($templateKey)) {
            return $draftMessage;
        }

        // 5. Let AI rewrite for naturalness
        try {
            return $this->rewriteWithLlm($draftMessage, $userMessage, $config);
        } catch (\Exception $e) {
            \Logger::warning("[ResponseService] LLM rewrite failed, using original", [
                'error' => $e->getMessage(),
            ]);
            return $draftMessage;
        }
    }

    /**
     * Quick reply without natural language processing
     * Use this for time-critical or sensitive responses
     */
    public function quickReply(array $config, string $templateKey, array $data = []): string
    {
        return $this->renderTemplate($config, $templateKey, $data);
    }

    // ==================== TEMPLATE RENDERING ====================

    /**
     * Render template with data binding
     * 
     * Supports:
     * - {{variable}} syntax for simple values
     * - {{#if condition}}...{{/if}} for conditionals
     * - {{#each items}}...{{/each}} for loops
     * 
     * @param array $config Bot config containing templates
     * @param string $templateKey Template key
     * @param array $data Data to bind
     * @return string Rendered message
     */
    public function renderTemplate(array $config, string $templateKey, array $data = []): string
    {
        // Get template from config
        $templates = $config['response_templates'] ?? [];
        $template = $templates[$templateKey] ?? $this->getDefaultTemplate($templateKey);
        
        if (empty($template)) {
            return $this->getDefaultTemplate($templateKey);
        }

        // Simple variable replacement: {{variable}}
        $result = preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            function ($matches) use ($data) {
                $key = $matches[1];
                return $data[$key] ?? $matches[0];
            },
            $template
        );

        // Nested variable replacement: {{object.property}}
        $result = preg_replace_callback(
            '/\{\{(\w+)\.(\w+)\}\}/',
            function ($matches) use ($data) {
                $obj = $matches[1];
                $prop = $matches[2];
                return $data[$obj][$prop] ?? $matches[0];
            },
            $result
        );

        // Format numbers with Thai formatting
        $result = preg_replace_callback(
            '/\{\{format_money:(\w+)\}\}/',
            function ($matches) use ($data) {
                $key = $matches[1];
                $value = $data[$key] ?? 0;
                return '฿' . number_format((float)$value, 0);
            },
            $result
        );

        return $result;
    }

    /**
     * Get default template for common scenarios
     */
    protected function getDefaultTemplate(string $key): string
    {
        $defaults = [
            'greeting' => 'สวัสดีค่ะ มีอะไรให้ช่วยไหมคะ 🙏',
            'product_found' => '🎯 เจอสินค้าแล้วค่ะ',
            'product_not_found' => 'ขออภัยค่ะ ไม่พบสินค้าที่ค้นหา กรุณาลองใหม่อีกครั้งนะคะ',
            'search_result' => 'พบสินค้า {{count}} รายการค่ะ',
            'help_message' => "สามารถพิมพ์:\n• รหัสสินค้า เช่น ROL-DAY-001\n• ชื่อสินค้า เช่น สร้อยทอง\n• เช็คผ่อน / เช็คจำนำ\n• ติดต่อแอดมิน",
            'goodbye' => 'ขอบคุณค่ะ หากมีข้อสงสัยสามารถสอบถามได้เลยนะคะ 🙏',
            'thank_you' => 'ขอบคุณค่ะ 🙏',
            'wait_message' => 'กรุณารอสักครู่นะคะ...',
            'transfer_to_admin' => 'รับทราบค่ะ กำลังประสานงานให้แอดมินติดต่อกลับค่ะ 🙏',
            'general_response' => 'รับทราบค่ะ',
            'payment_account' => "📍 ช่องทางชำระเงิน:\n🏦 {{bank_name}}\nเลขบัญชี: {{account_no}}\nชื่อบัญชี: {{account_name}}",
            'order_confirmation' => "✅ สั่งซื้อสำเร็จ!\n📋 เลขที่: {{order_no}}\n💰 ยอด: {{format_money:total}}",
            'installment_detail' => "📋 รายการผ่อนชำระ:\nสัญญา: {{contract_no}}\nยอดคงเหลือ: {{format_money:remaining}}\nงวดถัดไป: {{next_due_date}}",
            'pawn_detail' => "🏷️ ตั๋วจำนำ #{{ticket_no}}\nยอดไถ่: {{format_money:redeem_amount}}\nหมดอายุ: {{expiry_date}}",
            'savings_balance' => "💰 บัญชีออมทอง #{{account_no}}\nยอดสะสม: {{format_money:balance}}",
        ];

        return $defaults[$key] ?? 'รับทราบค่ะ';
    }

    // ==================== NATURAL LANGUAGE REWRITING ====================

    /**
     * Rewrite message with LLM for natural conversation
     * 
     * @param string $draftMessage Original template-based message
     * @param string|null $userMessage User's original message for context
     * @param array $config Bot config
     * @return string Rewritten message
     */
    protected function rewriteWithLlm(string $draftMessage, ?string $userMessage, array $config): string
    {
        if (!$this->llmService) {
            return $draftMessage;
        }

        // Get persona from config
        $persona = $config['natural_language']['persona'] ?? 'พนักงานร้านจิวเวลรี่ที่เป็นมิตร';
        $tone = $config['natural_language']['tone'] ?? 'สุภาพ เป็นกันเอง';

        // Build prompt
        $prompt = $this->buildRewritePrompt($draftMessage, $userMessage, $persona, $tone);

        // Call LLM
        try {
            $result = $this->llmService->generate($prompt, [
                'max_tokens' => 200,
                'temperature' => 0.7,
            ]);

            $rewritten = trim($result['text'] ?? $result);

            // Validate: Check if critical data is preserved
            if ($this->validateRewrite($draftMessage, $rewritten)) {
                return $rewritten;
            }

            \Logger::warning("[ResponseService] Rewrite validation failed, using original", [
                'original' => $draftMessage,
                'rewritten' => $rewritten,
            ]);
            return $draftMessage;

        } catch (\Exception $e) {
            \Logger::error("[ResponseService] LLM rewrite error", ['error' => $e->getMessage()]);
            return $draftMessage;
        }
    }

    /**
     * Build prompt for LLM rewriting
     */
    protected function buildRewritePrompt(
        string $draftMessage, 
        ?string $userMessage, 
        string $persona, 
        string $tone
    ): string {
        $prompt = "คุณคือ {$persona} กำลังตอบแชทลูกค้า\n";
        $prompt .= "โทน: {$tone}\n\n";
        
        if ($userMessage) {
            $prompt .= "ลูกค้าพิมพ์ว่า: \"{$userMessage}\"\n";
        }
        
        $prompt .= "เราต้องการตอบว่า: \"{$draftMessage}\"\n\n";
        $prompt .= "โจทย์: ช่วยเกลาประโยคให้ดูเป็นธรรมชาติ เข้ากับบริบท\n";
        $prompt .= "ข้อจำกัด:\n";
        $prompt .= "- ห้ามแก้ไขตัวเลข ราคา รหัสสินค้า หรือข้อมูลสำคัญ\n";
        $prompt .= "- ความยาวใกล้เคียงเดิม\n";
        $prompt .= "- ตอบเป็นภาษาไทยเท่านั้น\n";
        $prompt .= "- ตอบแค่ประโยคที่เกลาแล้ว ไม่ต้องอธิบาย\n";
        
        return $prompt;
    }

    /**
     * Validate that LLM didn't change critical data
     */
    protected function validateRewrite(string $original, string $rewritten): bool
    {
        // Extract numbers from original
        preg_match_all('/[\d,]+(?:\.\d+)?/', $original, $origNumbers);
        
        // Check all numbers are preserved in rewrite
        foreach ($origNumbers[0] as $num) {
            if (strpos($rewritten, $num) === false) {
                // Number was changed - reject rewrite
                return false;
            }
        }

        // Extract product codes (e.g., ROL-DAY-001)
        preg_match_all('/[A-Z]{2,5}-[A-Z]{2,5}-\d{3,}/', $original, $origCodes);
        
        foreach ($origCodes[0] as $code) {
            if (stripos($rewritten, $code) === false) {
                return false;
            }
        }

        // Basic length check (shouldn't be drastically different)
        $origLen = mb_strlen($original);
        $rewriteLen = mb_strlen($rewritten);
        
        if ($rewriteLen < $origLen * 0.5 || $rewriteLen > $origLen * 2) {
            return false;
        }

        return true;
    }

    // ==================== TEMPLATE HELPERS ====================

    /**
     * Check if template should be locked (no AI modification)
     */
    protected function isLockedTemplate(string $templateKey): bool
    {
        return in_array($templateKey, self::LOCKED_TEMPLATES, true);
    }

    /**
     * Check if template can be rewritten by AI
     */
    protected function isRewritableTemplate(string $templateKey): bool
    {
        return in_array($templateKey, self::REWRITABLE_TEMPLATES, true);
    }

    // ==================== RESPONSE BUILDERS ====================

    /**
     * Build product found response
     */
    public function productFound(array $config, array $product, ?string $userMessage = null): string
    {
        $data = [
            'code' => $product['code'] ?? '',
            'name' => $product['name'] ?? '',
            'price' => $product['sale_price'] ?? $product['price'] ?? 0,
            'brand' => $product['brand'] ?? '',
        ];

        $template = "🎯 เจอแล้วค่ะ!\n";
        $template .= "📦 {{code}}\n";
        $template .= "📝 {{name}}\n";
        if (!empty($data['brand'])) {
            $template .= "🏷️ {{brand}}\n";
        }
        $template .= "💰 ราคา: {{format_money:price}}";

        // Use custom template if available, but still substitute data
        $configTemplate = $config['response_templates']['product_found'] ?? $template;
        
        return $this->reply($config, 'product_found', $data, $userMessage);
    }

    /**
     * Build order confirmation response (LOCKED - no AI modification)
     */
    public function orderConfirmation(array $config, array $order): string
    {
        $data = [
            'order_no' => $order['order_no'] ?? '',
            'product_name' => $order['product_name'] ?? '',
            'total' => $order['total_amount'] ?? 0,
            'payment_type' => $order['payment_type'] ?? 'full',
        ];

        // Get payment info
        $paymentInfo = $config['payment_info'] ?? [];
        $data['bank_name'] = $paymentInfo['bank_name'] ?? 'ธนาคาร';
        $data['account_no'] = $paymentInfo['account_no'] ?? '-';
        $data['account_name'] = $paymentInfo['account_name'] ?? '-';

        // This is a locked template - will not be rewritten
        return $this->quickReply($config, 'order_confirmation', $data);
    }

    /**
     * Build installment status response (LOCKED)
     */
    public function installmentStatus(array $config, array $installments): string
    {
        if (empty($installments)) {
            return $this->reply($config, 'installment_not_found', []);
        }

        $lines = ['📋 รายการผ่อนชำระของคุณ:', ''];
        $totalDue = 0;

        foreach ($installments as $i => $inst) {
            $num = $i + 1;
            $remaining = (float)($inst['remaining_amount'] ?? $inst['financed_amount'] ?? 0) 
                       - (float)($inst['paid_amount'] ?? 0);
            $totalDue += $remaining;

            $lines[] = "{$num}. " . ($inst['product_name'] ?? 'สินค้า');
            $lines[] = "   สัญญา: " . ($inst['contract_no'] ?? '-');
            $lines[] = "   ยอดคงเหลือ: ฿" . number_format($remaining, 0);
            $lines[] = "   งวดถัดไป: " . ($inst['next_due_date'] ?? '-');
            $lines[] = '';
        }

        $lines[] = "💰 รวมยอดคงเหลือทั้งหมด: ฿" . number_format($totalDue, 0);

        return implode("\n", $lines);
    }

    /**
     * Build greeting with context
     */
    public function greeting(array $config, array $context, ?string $displayName = null): string
    {
        $data = [
            'name' => $displayName ?? 'คุณลูกค้า',
            'shop_name' => $config['shop_name'] ?? 'ร้าน',
        ];

        return $this->reply($config, 'greeting', $data);
    }
}
