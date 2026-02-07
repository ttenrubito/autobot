<?php
// filepath: /opt/lampp/htdocs/autobot/includes/bot/RouterV2BoxDesignHandler.php

require_once __DIR__ . '/BotHandlerInterface.php';
require_once __DIR__ . '/RouterV1Handler.php';
require_once __DIR__ . '/../Logger.php';

/**
 * RouterV2BoxDesignHandler
 *
 * Box Design AI Automation chatbot with specialized conversation flow.
 * Extends RouterV1Handler to reuse core infrastructure while implementing
 * Box Design-specific answer-first rules and conversation management.
 *
 * Key Features:
 * - Answer capabilities questions immediately
 * - Provide pricing information proactively
 * - Prevent repetitive bot questions
 * - Capture business context systematically
 *
 * @version 2.0
 * @production-ready
 */
class RouterV2BoxDesignHandler extends RouterV1Handler
{
    /**
     * Handle incoming message with Box Design-specific flow
     */
    public function handleMessage(array $context): array
    {
        $traceId = (string) ($context['trace_id'] ?? '');
        if ($traceId === '') {
            $traceId = bin2hex(random_bytes(8));
            $context['trace_id'] = $traceId;
        }

        $t0 = microtime(true);

        // ✅ ENTRY LOGGING - Critical for production debugging
        Logger::info('[V2_BOXDESIGN_START]', [
            'handler_class' => 'RouterV2BoxDesignHandler',
            'trace_id' => $traceId,
            'channel_id' => $context['channel']['id'] ?? null,
            'bot_profile_id' => $context['bot_profile']['id'] ?? null,
            'bot_profile_name' => $context['bot_profile']['name'] ?? null,
            'external_user_id' => $context['external_user_id'] ?? null,
            'message_type' => $context['message']['message_type'] ?? null,
            'text_preview' => substr($context['message']['text'] ?? '', 0, 100),
        ]);

        try {
            $botProfile = $context['bot_profile'] ?? [];
            $config = $this->decodeJsonArray($botProfile['config'] ?? null);

            // Force Box Design flow key for consistency
            $config['flow'] = $config['flow'] ?? [];
            $config['flow']['key'] = 'boxdesign';

            // Update context with modified config
            $context['bot_profile']['config'] = json_encode($config, JSON_UNESCAPED_UNICODE);

            // Templates
            $templates = $config['response_templates'] ?? [];
            $greeting = $templates['greeting'] ?? 'สวัสดีครับ ผมคือ AI Assistant ของ Box Design มีอะไรให้ช่วยไหมครับ';
            $fallback = $templates['fallback'] ?? 'ขออภัยครับ ช่วยอธิบายเพิ่มเติมได้ไหมครับ';

            // Get message
            $message = $context['message'] ?? [];
            $text = trim((string) ($message['text'] ?? ''));
            $messageType = $message['message_type'] ?? 'text';

            // ✅ DEBUG: Log incoming text for admin command detection
            Logger::info('[V2_BOXDESIGN_TEXT]', [
                'trace_id' => $traceId,
                'text' => $text,
                'text_len' => mb_strlen($text, 'UTF-8'),
                'text_lower' => mb_strtolower($text, 'UTF-8'),
                'text_bytes' => bin2hex($text),
            ]);

            // Get session
            $channel = $context['channel'] ?? [];
            $channelId = $channel['id'] ?? null;
            $externalUserId = $context['external_user_id'] ?? null;

            $session = null;
            $sessionId = null;
            if ($channelId && $externalUserId) {
                $session = $this->findOrCreateSession((int) $channelId, (string) $externalUserId);
                $sessionId = $session['id'] ?? null;
                if ($sessionId) {
                    $context['session_id'] = (int) $sessionId;
                }
            }

            // Load last state
            $lastSlots = [];
            $lastIntent = null;
            if ($session && !empty($session['last_slots_json'])) {
                $decodedSlots = json_decode($session['last_slots_json'], true);
                if (is_array($decodedSlots)) {
                    $lastSlots = $decodedSlots;
                }
            }
            if ($session && !empty($session['last_intent'])) {
                $lastIntent = $session['last_intent'];
            }

            $lastQuestionKey = null;
            if (is_array($lastSlots) && isset($lastSlots['last_question_key'])) {
                $lastQuestionKey = (string) $lastSlots['last_question_key'];
            }

            // =========================================================
            // ✅ ADMIN HANDOFF: Check BEFORE Box Design rules
            // =========================================================
            $isAdmin = false;
            if (is_callable([$this, 'isAdminContext'])) {
                $isAdmin = $this->isAdminContext($context, $message);
            } else {
                // Fallback
                $isAdmin = !empty($context['is_admin']) || !empty($context['user']['is_admin']);
            }

            // Honor webhook-provided admin flag
            if (!$isAdmin && !empty($context['is_admin'])) {
                $isAdmin = true;
            }

            // ✅ Manual admin handoff command (cross-platform fallback)
            // Accept: "admin", "/admin", "#admin" at START of message (case-insensitive)
            // Examples: "admin", "Admin มาตอบ", "/admin test", "#admin here"
            // ✅ CRITICAL: Only works when message is FROM admin (not typed by customer)
            $adminCmdMatched = false;
            if ($isAdmin && $text !== '') {
                $t = mb_strtolower(trim($text), 'UTF-8');
                // Match if message STARTS with admin command (with or without text after)
                if (preg_match('/^(?:\/admin|#admin|admin)(?:\s|$)/u', $t)) {
                    $adminCmdMatched = true;
                    Logger::info('[V2_BOXDESIGN] Admin command pattern matched!', [
                        'trace_id' => $traceId,
                        'text' => $text,
                        'text_lower' => $t,
                        'session_id' => $sessionId,
                        'channel_id' => $channelId,
                        'external_user_id' => $externalUserId,
                    ]);
                }
            }

            if ($adminCmdMatched && $sessionId) {
                Logger::info('[V2_BOXDESIGN] Manual admin command detected', [
                    'trace_id' => $traceId,
                    'session_id' => $sessionId,
                    'channel_id' => $channelId,
                    'text' => $text,
                ]);

                try {
                    $this->db->execute(
                        'UPDATE chat_sessions SET last_admin_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                        [$sessionId]
                    );
                } catch (Exception $e) {
                    Logger::error('[V2_BOXDESIGN] Failed to update admin timestamp: ' . $e->getMessage(), [
                        'trace_id' => $traceId,
                        'session_id' => $sessionId,
                    ]);
                }

                // Store marker
                $this->storeMessage($sessionId, 'system', '[admin_handoff] manual command');

                // Mark as admin to trigger pause
                $isAdmin = true;

                // Do not reply when command is used
                return [
                    'reply_text' => null,
                    'actions' => [],
                    'meta' => [
                        'handler' => 'router_v2_boxdesign',
                        'reason' => 'admin_handoff_manual_command',
                        'trace_id' => $traceId,
                    ]
                ];
            }

            // If admin message, delegate to parent for proper handling
            if ($isAdmin) {
                Logger::info('[V2_BOXDESIGN] Admin message detected - delegating to parent', [
                    'trace_id' => $traceId,
                    'session_id' => $sessionId,
                    'channel_id' => $channelId,
                    'text_preview' => substr($text, 0, 50),
                ]);

                // Let parent handle admin message (will update last_admin_message_at and return null)
                return parent::handleMessage($context);
            }

            // ✅ Check if bot should pause due to recent admin activity (configurable timeout)
            if ($sessionId) {
                $handoffCfg = $config['handoff'] ?? [];
                $pauseSeconds = (int) ($handoffCfg['timeout_seconds'] ?? 7200); // Default 2 hours
                $pauseMinutes = ceil($pauseSeconds / 60);
                $pauseUntil = date('Y-m-d H:i:s', time() - $pauseSeconds);

                $adminRecent = $this->db->queryOne(
                    "SELECT last_admin_message_at FROM chat_sessions 
                     WHERE id = ? AND last_admin_message_at IS NOT NULL AND last_admin_message_at >= ?",
                    [$sessionId, $pauseUntil]
                );

                if ($adminRecent) {
                    Logger::info('[V2_BOXDESIGN] Bot paused - admin handoff active', [
                        'trace_id' => $traceId,
                        'session_id' => $sessionId,
                        'last_admin_at' => $adminRecent['last_admin_message_at'] ?? null,
                        'pause_until' => $pauseUntil,
                    ]);

                    $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
                    Logger::info('[V2_BOXDESIGN_END]', [
                        'trace_id' => $traceId,
                        'elapsed_ms' => $elapsedMs,
                        'reason' => 'admin_handoff_bot_paused',
                    ]);

                    return [
                        'reply_text' => null,
                        'actions' => [],
                        'meta' => [
                            'handler' => 'router_v2_boxdesign',
                            'reason' => 'admin_handoff_bot_paused',
                            'trace_id' => $traceId,
                            'pause_minutes' => $pauseMinutes,
                        ]
                    ];
                }
            }

            // =========================================================
            // � ECHO FILTER: ข้ามข้อความที่เป็น output ของบอทเอง
            // Facebook จะส่ง echo ของข้อความที่บอทตอบกลับมา
            // =========================================================
            if ($text !== '') {
                // Pattern ที่เป็น output บอท: "1) ตอบแชท", "จาก 3 ข้อนี้", "AI ช่วย", "ครับ:"
                $botOutputPatterns = [
                    '/^1\)\s+ตอบแชท/',                       // Quick reply options
                    '/จาก\s*3\s*ข้อนี้/',                     // "เลือกจาก 3 ข้อนี้"
                    '/\d\)\s+[^\d]+\s+\d\)\s+[^\d]+\s+\d\)/', // "1) xxx 2) xxx 3) xxx"
                    '/อยากให้\s+AI\s+ช่วย.*ครับ[?:]?$/u',    // "อยากให้ AI ช่วยร้านขายเสื้อ..."
                    '/ลองเลือก.*อีกครั้ง/u',                 // "ลองเลือกอีกครั้ง"
                    '/หรือจะลองเลือก/u',                     // "หรือจะลองเลือก"
                    '/ไม่เป็นไรครับ/u',                      // Bot response
                    '/เข้าใจครับ.*อยาก/u',                   // "เข้าใจครับว่าอยาก"
                    '/สนใจ.*เป็นพิเศษ.*ครับ[?]?$/u',         // "สนใจ...เป็นพิเศษครับ"
                    '/รบกวนเลือก/u',                         // "รบกวนเลือก"
                ];
                
                foreach ($botOutputPatterns as $pattern) {
                    if (preg_match($pattern, $text)) {
                        Logger::info('[V2_BOXDESIGN_ECHO_FILTER] Skipped bot echo message', [
                            'trace_id' => $traceId,
                            'pattern' => $pattern,
                            'text_preview' => mb_substr($text, 0, 30, 'UTF-8'),
                        ]);
                        
                        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
                        return [
                            'reply_text' => null,
                            'actions' => [],
                            'meta' => [
                                'handler' => 'router_v2_boxdesign',
                                'reason' => 'echo_filter_bot_output',
                                'trace_id' => $traceId,
                                'elapsed_ms' => $elapsedMs,
                            ]
                        ];
                    }
                }
            }

            // =========================================================
            // �🛡️ GATEKEEPER: ป้องกันการตอบข้อความรัวๆ / คำลงท้าย
            // + Message Buffer: เก็บข้อความที่ skip ไว้รวมบริบท
            // =========================================================
            if ($text !== '' && $channelId && $externalUserId) {
                $gatekeeperResult = $this->shouldProcessMessageV2($text, (string)$externalUserId, (int)$channelId, $traceId, $config);
                if (!$gatekeeperResult['should_process']) {
                    // 📝 เก็บข้อความลง buffer แทนที่จะทิ้ง
                    $this->appendToMessageBufferV2($text, (string)$externalUserId, (int)$channelId);
                    
                    Logger::info('[V2_BOXDESIGN_GATEKEEPER] Skipped message - buffered for context', [
                        'trace_id' => $traceId,
                        'reason' => $gatekeeperResult['reason'],
                        'score' => $gatekeeperResult['score'] ?? null,
                        'text_preview' => mb_substr($text, 0, 20, 'UTF-8'),
                    ]);
                    
                    $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
                    return [
                        'reply_text' => null,
                        'actions' => [],
                        'meta' => [
                            'handler' => 'router_v2_boxdesign',
                            'reason' => 'gatekeeper_' . $gatekeeperResult['reason'],
                            'trace_id' => $traceId,
                            'elapsed_ms' => $elapsedMs,
                        ]
                    ];
                }
                
                // ✅ ถ้า process ได้ → ดึง buffer มารวมกับข้อความปัจจุบัน
                $bufferedText = $this->getAndClearMessageBufferV2((string)$externalUserId, (int)$channelId);
                if (!empty($bufferedText)) {
                    $text = $bufferedText . ' ' . $text;
                    Logger::info('[V2_BOXDESIGN_GATEKEEPER] Merged buffered messages', [
                        'trace_id' => $traceId,
                        'merged_text' => mb_substr($text, 0, 50, 'UTF-8'),
                    ]);
                }
            }

            // Meta for response
            $meta = [
                'handler' => 'router_v2_boxdesign',
                'route' => null,
                'trace_id' => $traceId,
            ];

            // Empty text → greeting
            if ($text === '') {
                Logger::info('[V2_BOXDESIGN_RULE]', [
                    'rule' => 'empty_text_greeting',
                    'trace_id' => $traceId,
                ]);

                // Convert ||SPLIT|| to newlines in greeting
                $greetingText = str_replace(['||SPLIT||', '||'], "\n\n", $greeting);
                $greetingText = preg_replace('/\n{3,}/', "\n\n", $greetingText);
                $greetingText = trim($greetingText);

                $meta['reason'] = 'empty_text_use_greeting';
                if ($sessionId && $greetingText !== '') {
                    $this->storeMessage($sessionId, 'assistant', $greetingText);
                }
                $this->logBotReply($context, $greetingText, 'text');
                
                // Track bot reply time for Gatekeeper
                if ($externalUserId && $channelId) {
                    $this->setQuickStateV2('last_bot_reply_time', ['time' => time()], (string)$externalUserId, (int)$channelId, 300);
                }

                $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
                Logger::info('[V2_BOXDESIGN_END]', [
                    'trace_id' => $traceId,
                    'elapsed_ms' => $elapsedMs,
                    'reason' => $meta['reason'],
                ]);

                return ['reply_text' => $greetingText, 'actions' => [], 'meta' => $meta];
            }

            // NOTE: DO NOT store user message here! 
            // V1 has dedupe check that will see this as duplicate.
            // Store will be done by V1 handler when delegated, or by V2 when handling specific rules below.

            $tLower = mb_strtolower($text, 'UTF-8');

            // =========================================================
            // ✅ LLM-FIRST APPROACH: ส่งให้ LLM ตอบเลย
            // ใช้ system prompt + chat history ควบคุม flow
            // LLM จะจัดการ: ถามธุรกิจ → ถามความต้องการ → handoff
            // =========================================================
            Logger::info('[V2_BOXDESIGN_LLM]', [
                'rule' => 'llm_first_approach',
                'trace_id' => $traceId,
                'text' => $text,
            ]);

            // Store user message first
            if ($sessionId && $text !== '') {
                $this->storeMessage($sessionId, 'user', $text);
            }

            // Get LLM response with chat history
            $llmResult = $this->handleWithLlmBoxDesign($text, $config, $context, $sessionId);
            $reply = $llmResult['reply'] ?? null;

            if (!$reply) {
                // Fallback if LLM fails
                $reply = $templates['fallback'] ?? "รับทราบครับ\n\nเดี๋ยวให้แอดมินมาช่วยดูให้นะครับ";
            }

            // Handle ||SPLIT|| markers from LLM - convert to double newline
            // Order matters: match longest pattern first
            $reply = str_replace('||SPLIT||', "\n\n", $reply);
            // Also handle || alone (some LLMs use this)
            $reply = str_replace('||', "\n\n", $reply);
            // Clean up multiple newlines (3+ → 2)
            $reply = preg_replace('/\n{3,}/', "\n\n", $reply);
            $reply = trim($reply);

            $meta['reason'] = $llmResult['reason'] ?? 'llm_response';

            // Store assistant reply
            if ($sessionId && $reply !== '') {
                $this->storeMessage($sessionId, 'assistant', $reply);
            }
            $this->logBotReply($context, $reply, 'text');

            // Track bot reply time for Gatekeeper
            if ($externalUserId && $channelId) {
                $this->setQuickStateV2('last_bot_reply_time', ['time' => time()], (string)$externalUserId, (int)$channelId, 300);
            }

            $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
            Logger::info('[V2_BOXDESIGN_END]', [
                'trace_id' => $traceId,
                'elapsed_ms' => $elapsedMs,
                'reason' => $meta['reason'],
            ]);

            return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];

        } catch (\Throwable $e) {
            Logger::error('[V2_BOXDESIGN_ERROR]', [
                'handler_class' => 'RouterV2BoxDesignHandler',
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace_id' => $traceId ?? null,
            ]);
            throw $e;
        }
    }

    /**
     * Normalize business type answer by removing polite words
     */
    protected function normalizeBusinessTypeAnswer(string $text): string
    {
        $t = trim($text);
        $t = preg_replace('/\s+/', ' ', $t);
        $t = preg_replace('/^(ครับ|ค่ะ|คับ|ค่า)\s*/u', '', $t);
        $t = preg_replace('/\s*(ครับ|ค่ะ|คับ|ค่า)$/u', '', $t);
        return trim($t);
    }

    /**
     * Generate AI suggestion reply based on business type
     */
    protected function generateBusinessTypeReply(string $businessType, array $templates): string
    {
        // Check for template first
        $reply = (string) ($templates['business_type_reply'] ?? '');
        if ($reply !== '') {
            return str_replace('{business_type}', $businessType, $reply);
        }

        // Detect business category and suggest relevant AI solutions
        $btLower = mb_strtolower($businessType, 'UTF-8');
        
        $suggestions = [];
        
        // ร้านค้าออนไลน์ / ขายของ
        if (preg_match('/(ขาย|ร้าน|shop|ออนไลน์|เสื้อผ้า|นาฬิกา|เครื่องประดับ|สินค้า|แฟชั่น|เครื่องสำอาง)/u', $btLower)) {
            $suggestions = [
                '🛒 Chatbot ตอบคำถามสินค้า + ปิดการขายอัตโนมัติ',
                '📦 ระบบจัดการออเดอร์ + แจ้งเตือน LINE',
                '📊 Dashboard สรุปยอดขายรายวัน',
            ];
        }
        // คลินิก / สุขภาพ
        elseif (preg_match('/(คลินิก|หมอ|สุขภาพ|ความงาม|สปา|นวด|ฟัน|ผิว|เสริมความงาม)/u', $btLower)) {
            $suggestions = [
                '📅 ระบบจองคิวออนไลน์ + แจ้งเตือนอัตโนมัติ',
                '💬 Chatbot ตอบคำถามบริการ + ราคา',
                '🗂️ ระบบจัดการประวัติลูกค้า',
            ];
        }
        // ร้านอาหาร / คาเฟ่
        elseif (preg_match('/(อาหาร|ร้านกาแฟ|คาเฟ่|cafe|เครื่องดื่ม|ขนม|เบเกอรี่)/u', $btLower)) {
            $suggestions = [
                '🍽️ ระบบสั่งอาหารผ่าน LINE',
                '📋 เมนูดิจิทัล + จัดการราคา',
                '🚚 เชื่อม Delivery + แจ้งสถานะ',
            ];
        }
        // อสังหา / บ้าน
        elseif (preg_match('/(อสังหา|บ้าน|คอนโด|ที่ดิน|เช่า|นายหน้า|property)/u', $btLower)) {
            $suggestions = [
                '🏠 Chatbot ตอบคำถามโครงการ + นัดเยี่ยมชม',
                '📝 ระบบ Lead Management',
                '📊 รายงานความสนใจลูกค้า',
            ];
        }
        // โรงแรม / ที่พัก
        elseif (preg_match('/(โรงแรม|รีสอร์ท|ที่พัก|hotel|resort|hostel)/u', $btLower)) {
            $suggestions = [
                '🛏️ ระบบจองห้องพัก + ยืนยันอัตโนมัติ',
                '💬 Chatbot ตอบคำถาม 24 ชม.',
                '📅 เชื่อม Google Calendar',
            ];
        }
        // Default
        else {
            $suggestions = [
                '💬 AI Chatbot ตอบลูกค้าอัตโนมัติ 24 ชม.',
                '⚡ Workflow Automation ลดงาน manual',
                '📊 ระบบหลังบ้านจัดการข้อมูล',
            ];
        }

        $reply = "เยี่ยมเลยครับ! 🎉 ธุรกิจ **{$businessType}** เรามีโซลูชัน AI ที่น่าสนใจ:\n\n";
        $reply .= implode("\n", $suggestions);
        $reply .= "\n\n💡 สนใจตัวไหนเป็นพิเศษครับ? หรือพิมพ์ \"ราคา\" เพื่อดูค่าบริการ";

        return $reply;
    }

    // =========================================================
    // 🤖 LLM HANDLER FOR AI AUTOMATION SERVICE (Box Design)
    // =========================================================

    /**
     * Handle message with LLM for AI Automation context
     * Uses Gemini API directly without V1's product/checkout flows
     */
    protected function handleWithLlmBoxDesign(string $text, array $config, array $context, ?int $sessionId): array
    {
        try {
            // Get LLM integration from context (same as V1)
            $integrations = $context['integrations'] ?? [];
            $llmIntegrations = $integrations['llm'] ?? ($integrations['openai'] ?? ($integrations['gemini'] ?? []));
            $integration = $llmIntegrations[0] ?? null;
            
            if (!$integration) {
                Logger::warning('[V2_BOXDESIGN_LLM] No LLM integration found in context');
                return ['reply' => null, 'reason' => 'no_integration'];
            }

            $apiKey = $integration['api_key'] ?? null;
            $intConfig = $this->decodeJsonArray($integration['config'] ?? null);
            
            if (!$apiKey) {
                Logger::warning('[V2_BOXDESIGN_LLM] Missing API key');
                return ['reply' => null, 'reason' => 'missing_api_key'];
            }

            // Get conversation history for context
            $history = [];
            if ($sessionId) {
                $history = $this->getConversationHistory($sessionId, 10);
            }

            // Build system prompt for AI Automation service
            $llmConfig = $config['llm'] ?? [];
            $systemPrompt = trim((string) ($llmConfig['system_prompt'] ?? ''));
            
            if ($systemPrompt === '') {
                $systemPrompt = $this->getDefaultBoxDesignPrompt();
            }

            // Build messages array
            $messages = [];
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
            
            // Add conversation history
            foreach ($history as $msg) {
                $role = ($msg['role'] === 'user') ? 'user' : 'assistant';
                $messages[] = ['role' => $role, 'content' => $msg['content']];
            }
            
            // Add current message
            $messages[] = ['role' => 'user', 'content' => $text];

            // Determine endpoint
            $endpoint = $intConfig['endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
            $model = $intConfig['model'] ?? 'gemini-2.0-flash';
            $isGemini = (stripos($endpoint, 'generativelanguage.googleapis.com') !== false);

            if ($isGemini) {
                $reply = $this->callGeminiApi($apiKey, $messages, $model);
            } else {
                $reply = $this->callOpenAiApi($apiKey, $endpoint, $messages, $model);
            }

            if ($reply) {
                return ['reply' => $reply, 'reason' => 'llm_success'];
            }

            return ['reply' => null, 'reason' => 'llm_empty_response'];

        } catch (\Exception $e) {
            Logger::error('[V2_BOXDESIGN_LLM] Error: ' . $e->getMessage());
            return ['reply' => null, 'reason' => 'llm_error'];
        }
    }

    /**
     * Default system prompt for Box Design AI Automation service
     */
    protected function getDefaultBoxDesignPrompt(): string
    {
        return <<<PROMPT
คุณคือ AI Assistant ของ Box Design บริษัทที่ให้บริการ AI Automation และ Chatbot Development

🎯 บริการหลักของเรา:
1. **AI Chatbot** - สร้างแชทบอทอัจฉริยะสำหรับ LINE, Facebook Messenger
2. **Workflow Automation** - ออกแบบระบบอัตโนมัติเชื่อมต่อ CRM, Google Sheets, ฐานข้อมูล
3. **ระบบหลังบ้าน** - พัฒนาระบบจัดการออเดอร์, สต็อก, ลูกค้า
4. **API Integration** - เชื่อมต่อระบบต่างๆ ให้ทำงานร่วมกัน

💰 ราคาบริการ:
- Chatbot พื้นฐาน: เริ่มต้น 5,000 บาท/เดือน
- Workflow Automation: เริ่มต้น 10,000 บาท/โปรเจค
- ระบบ Custom: ขึ้นอยู่กับขอบเขตงาน (ประเมินฟรี)

📌 กฎการตอบ:
- ตอบสุภาพ เป็นกันเอง ใช้ภาษาไทย
- ตอบกระชับ ไม่เกิน 200 ตัวอักษร
- ถ้าลูกค้าสนใจ → แนะนำให้ติดต่อแอดมินเพื่อคุยรายละเอียด
- ห้ามพูดเรื่องผ่อน/มัดจำ/checkout (เราไม่ใช่ร้านขายสินค้า)
- ถ้าไม่แน่ใจ → บอกว่า "รอแอดมินมาตอบเพิ่มเติมนะครับ"

ลูกค้าถามว่า:
PROMPT;
    }

    /**
     * Call Gemini API
     */
    protected function callGeminiApi(string $apiKey, array $messages, string $model = 'gemini-2.0-flash'): ?string
    {
        // Convert OpenAI format to Gemini format
        $contents = [];
        $systemInstruction = null;
        
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemInstruction = $msg['content'];
                continue;
            }
            $role = ($msg['role'] === 'user') ? 'user' : 'model';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['content']]]
            ];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 500,
            ]
        ];
        
        if ($systemInstruction) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]]
            ];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::error('[V2_BOXDESIGN_GEMINI] API error', [
                'http_code' => $httpCode,
                'response' => substr($response, 0, 500),
            ]);
            return null;
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        
        return $text ? trim($text) : null;
    }

    /**
     * Call OpenAI-compatible API
     */
    protected function callOpenAiApi(string $apiKey, string $endpoint, array $messages, string $model): ?string
    {
        // ✅ max_tokens = 1500 for Thai language (Thai uses 2-4 tokens per character)
        // 500 tokens = ~125-250 Thai chars which is too short and causes truncation
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 1500,
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::error('[V2_BOXDESIGN_OPENAI] API error', [
                'http_code' => $httpCode,
                'response' => substr($response, 0, 500),
            ]);
            return null;
        }

        $data = json_decode($response, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        
        return $text ? trim($text) : null;
    }

    /**
     * Get conversation history from chat_messages table
     */
    protected function getConversationHistory(int $sessionId, int $limit = 10): array
    {
        try {
            $sql = "SELECT role, text as content FROM chat_messages 
                    WHERE session_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT ?";
            $rows = $this->db->query($sql, [$sessionId, $limit]);
            return array_reverse($rows ?: []);
        } catch (\Exception $e) {
            Logger::warning('[V2_BOXDESIGN] getConversationHistory error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get integration by type
     */
    protected function getIntegration(?int $channelId, string $type): ?array
    {
        if (!$channelId) return null;
        
        try {
            // First try channel-specific integration
            $sql = "SELECT * FROM integrations 
                    WHERE channel_id = ? AND type = ? AND is_active = 1 
                    LIMIT 1";
            $row = $this->db->queryOne($sql, [$channelId, $type]);
            
            if ($row) return $row;
            
            // Fallback to user-level integration
            $sql = "SELECT i.* FROM integrations i
                    JOIN customer_channels cc ON cc.user_id = i.user_id
                    WHERE cc.id = ? AND i.type = ? AND i.is_active = 1
                    LIMIT 1";
            return $this->db->queryOne($sql, [$channelId, $type]);
            
        } catch (\Exception $e) {
            Logger::warning('[V2_BOXDESIGN] getIntegration error: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================
    // 🛡️ GATEKEEPER FUNCTIONS (AI Automation Service Context)
    // =========================================================

    /**
     * Score-based Gatekeeper: ตัดสินว่าควรประมวลผลข้อความหรือไม่
     * 
     * คำนวณ "คะแนนความมีสาระ" (Information Density Score):
     * - คะแนน < 3 และเงื่อนไขเข้า → ข้ามข้อความ
     * - คะแนน >= 3 → ประมวลผลตามปกติ
     */
    protected function shouldProcessMessageV2(string $text, string $platformUserId, int $channelId, string $traceId, array $config = []): array
    {
        $text = trim($text);
        $textLen = mb_strlen($text, 'UTF-8');
        
        // 1. Empty → skip
        if ($textLen === 0) {
            return ['should_process' => false, 'reason' => 'empty', 'score' => 0];
        }

        // 1.5 ✅ Quick Reply Whitelist - ตัวเลข 1, 2, 3 หรือ yes/no → ผ่านทันที
        if (preg_match('/^[1-9]$/', $text) || preg_match('/^(ใช่|ไม่|yes|no|ok|โอเค|ได้|ไม่ได้|ตกลง|ยกเลิก|cancel)$/iu', $text)) {
            Logger::debug('[V2_GATEKEEPER] Quick reply whitelist, pass through', [
                'trace_id' => $traceId,
                'text' => $text,
            ]);
            return ['should_process' => true, 'reason' => 'quick_reply', 'score' => 10];
        }

        // 2. คำนวณ Information Density Score
        $score = $this->calculateMessageScoreV2($text);
        
        // 3. ดึงข้อมูล timing context
        $lastReply = $this->getQuickStateV2('last_bot_reply_time', $platformUserId, $channelId);
        $lastReplyTime = $lastReply['time'] ?? 0;
        $timeSinceReply = time() - $lastReplyTime;
        
        $lastUserMsg = $this->getQuickStateV2('last_user_msg', $platformUserId, $channelId);
        $lastMsgTime = $lastUserMsg['time'] ?? 0;
        $timeSinceLastMsg = time() - $lastMsgTime;

        // 4. ตัดสินใจ
        // ถ้าคะแนนต่ำ (< 3) และเพิ่งมีการสนทนาใน 10 วิ → skip
        if ($score < 3 && $timeSinceReply < 10 && $timeSinceReply >= 0) {
            Logger::debug('[V2_GATEKEEPER] Low score + recent reply, skipping', [
                'trace_id' => $traceId,
                'text' => $text,
                'score' => $score,
                'time_since_reply' => $timeSinceReply,
            ]);
            return ['should_process' => false, 'reason' => 'low_score_recent', 'score' => $score];
        }
        
        // ถ้าคะแนนต่ำมาก (< 2) และเพิ่งส่งข้อความมาใน 2 วิ (พิมพ์รัว) → skip
        if ($score < 2 && $timeSinceLastMsg < 2 && $timeSinceLastMsg >= 0) {
            Logger::debug('[V2_GATEKEEPER] Very low score + rapid typing, skipping', [
                'trace_id' => $traceId,
                'text' => $text,
                'score' => $score,
                'time_since_last_msg' => $timeSinceLastMsg,
            ]);
            return ['should_process' => false, 'reason' => 'rapid_low_score', 'score' => $score];
        }

        // 5. บันทึกข้อความล่าสุด
        $this->setQuickStateV2('last_user_msg', [
            'text' => $text,
            'time' => time(),
            'score' => $score,
        ], $platformUserId, $channelId, 60);

        return ['should_process' => true, 'reason' => 'ok', 'score' => $score];
    }

    /**
     * คำนวณ Information Density Score สำหรับ AI Automation context
     * 
     * คะแนน 0-10:
     * - ความยาว (0-3 pts)
     * - มีคำถาม (0-2 pts)
     * - มี keyword ธุรกิจ (0-3 pts)
     * - มีตัวเลข/รหัส (0-2 pts)
     * 
     * ✅ UPDATED: ใช้ Regex แทน foreach loop เพื่อประสิทธิภาพ
     * ✅ UPDATED: เพิ่มคำทักทาย/สอบถาม
     */
    protected function calculateMessageScoreV2(string $text): float
    {
        $score = 0.0;
        $textLen = mb_strlen($text, 'UTF-8');
        
        // Optimize: ทำ lowercase ครั้งเดียว และ trim
        $textLower = mb_strtolower(trim($text), 'UTF-8');

        // 1. ความยาวข้อความ (0-3 คะแนน)
        if ($textLen >= 30) {
            $score += 3.0;
        } elseif ($textLen >= 15) {
            $score += 2.0;
        } elseif ($textLen >= 8) {
            $score += 1.0;
        } elseif ($textLen >= 4) {
            $score += 0.5;
        }

        // 2. มีคำถาม? (0-2 คะแนน)
        if (preg_match('/[?？]|ไหม|มั้ย|หรือ|ยังไง|อย่างไร|เท่าไหร่|กี่|ทำไม/u', $textLower)) {
            $score += 2.0;
        }

        // 3. มี Business/Service keywords? (0-3 คะแนน) - AI Automation context
        // ✅ ใช้ Regex รวมกลุ่มเพื่อความเร็ว + เพิ่มคำทักทาย/สอบถาม
        $regexKeywords = 'บริการ|ราคา|ค่าบริการ|งบ|ประมาณ|' .
                         'chatbot|bot|บอท|ai|automation|workflow|' .
                         'line|facebook|เพจ|เชื่อม|ระบบ|api|' .
                         'crm|sheet|ชีท|database|ฐานข้อมูล|' .
                         'ทำได้|ช่วย|ต้องการ|อยาก|สนใจ|' .
                         'ปรึกษา|ติดต่อ|นัด|โทร|แอดมิน|' .
                         'สอบถาม|ถาม|รบกวน|สวัสดี|ทัก|hello|hi'; // ✅ เพิ่มคำเปิดบทสนทนา

        if (preg_match_all('/(' . $regexKeywords . ')/iu', $textLower, $matches)) {
            // นับจำนวน unique keywords ที่เจอ (Max 3 คะแนน)
            $count = count(array_unique($matches[0]));
            $score += min($count, 3);
        }

        // 4. มีตัวเลข/รหัส? (0-2 คะแนน)
        if (preg_match('/\d{2,}/', $text)) {
            $score += 1.5; // ตัวเลข 2+ หลัก (อาจเป็นงบ, เบอร์โทร)
        }
        if (preg_match('/[A-Z]{2,}/', $text)) {
            $score += 0.5; // รหัส/ตัวย่อ (case-sensitive)
        }
        
        // 4.5 ✅ Quick Reply Bonus - ตัวเลขเดี่ยว 1-9 (เลือกตัวเลือก)
        if (preg_match('/^[1-9]$/', $text)) {
            $score += 5.0; // Boost สูงเพื่อให้ผ่าน threshold
        }

        // 5. Penalty สำหรับคำลงท้ายเดี่ยวๆ (-2 คะแนน)
        $phaticOnly = '/^(ครับ|ค่ะ|คะ|คับ|จ้า|จ๊า|โอเค|ok|okay|k|kk|อืม|อ่า|เค|ขอบคุณ|thx|thanks|ได้|เข้าใจ|รับทราบ)+[!?. ]*$/iu';
        if (preg_match($phaticOnly, $text)) {
            $score -= 2.0;
        }

        // 6. ❌ Penalty คำกำกวม (Vague Words) ที่มักทำให้บอทเอ๋อ
        // คำพวกนี้ถ้ามาเดี่ยวๆ ให้ลบคะแนนหนักๆ
        $vagueWords = '/^(อยาก|ยัง|ดี|ตอ|ตอน|เคร|นะ|จ้ะ|เออ|งั้น|แล้ว|ก็)+[!?. ]*$/iu';
        if (preg_match($vagueWords, $text)) {
            $score -= 3.0;
        }

        return max(0, $score); // ไม่ติดลบ
    }

    /**
     * Get quick state from chat_state table
     */
    protected function getQuickStateV2(string $key, string $platformUserId, int $channelId)
    {
        try {
            $sql = "SELECT value FROM chat_state 
                    WHERE state_key = ? 
                    AND external_user_id = ? 
                    AND channel_id = ?
                    AND expires_at > NOW()";
            
            $row = $this->db->queryOne($sql, [$key, $platformUserId, $channelId]);
            
            if (!$row) {
                return null;
            }

            $value = $row['value'] ?? null;
            $decoded = json_decode($value, true);
            
            return $decoded !== null ? $decoded : $value;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Set quick state to chat_state table
     */
    protected function setQuickStateV2(string $key, $value, string $platformUserId, int $channelId, int $ttlSeconds = 3600): bool
    {
        try {
            $jsonValue = is_array($value) || is_object($value) 
                ? json_encode($value, JSON_UNESCAPED_UNICODE) 
                : (string)$value;

            $sql = "INSERT INTO chat_state (state_key, value, external_user_id, channel_id, expires_at, created_at) 
                    VALUES (?, ?, ?, ?, NOW() + INTERVAL ? SECOND, NOW())
                    ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at)";
            
            $this->db->execute($sql, [$key, $jsonValue, $platformUserId, $channelId, $ttlSeconds]);
            return true;
        } catch (\Exception $e) {
            Logger::warning("[V2_BOXDESIGN] Failed to set state", ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ==================== 📝 MESSAGE BUFFER FUNCTIONS ====================

    /**
     * เก็บข้อความที่ถูก skip ลง buffer เพื่อรวมบริบทในภายหลัง
     * Buffer จะหมดอายุใน 30 วินาที
     */
    protected function appendToMessageBufferV2(string $text, string $platformUserId, int $channelId): void
    {
        $text = trim($text);
        if (empty($text)) return;
        
        // ดึง buffer เดิม
        $existing = $this->getQuickStateV2('msg_buffer', $platformUserId, $channelId);
        $buffer = $existing['messages'] ?? [];
        $bufferTime = $existing['first_msg_time'] ?? time();
        
        // ถ้า buffer เก่าเกิน 30 วิ → เริ่มใหม่
        if ((time() - $bufferTime) > 30) {
            $buffer = [];
            $bufferTime = time();
        }
        
        // เพิ่มข้อความใหม่ (จำกัด 5 ข้อความ)
        $buffer[] = $text;
        if (count($buffer) > 5) {
            $buffer = array_slice($buffer, -5);
        }
        
        $this->setQuickStateV2('msg_buffer', [
            'messages' => $buffer,
            'first_msg_time' => $bufferTime,
            'last_msg_time' => time(),
        ], $platformUserId, $channelId, 60);
    }

    /**
     * ดึง buffer และล้างทิ้ง
     */
    protected function getAndClearMessageBufferV2(string $platformUserId, int $channelId): string
    {
        $existing = $this->getQuickStateV2('msg_buffer', $platformUserId, $channelId);
        
        if (empty($existing['messages'])) {
            return '';
        }
        
        // ล้าง buffer
        try {
            $sql = "DELETE FROM chat_state WHERE state_key = ? AND external_user_id = ? AND channel_id = ?";
            $this->db->execute($sql, ['msg_buffer', $platformUserId, $channelId]);
        } catch (\Exception $e) {
            // ignore
        }
        
        // รวมข้อความ กรอง phatic words ออก
        $messages = $existing['messages'];
        $filtered = array_filter($messages, function($msg) {
            return !preg_match('/^(ครับ|ค่ะ|คะ|คับ|จ้า|โอเค|ok|k)+[!?.\s]*$/iu', $msg);
        });
        
        return implode(' ', $filtered);
    }
}
