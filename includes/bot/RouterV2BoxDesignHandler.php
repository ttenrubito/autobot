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
                $pauseSeconds = (int) ($handoffCfg['timeout_seconds'] ?? 300); // Default 5 min
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

                $meta['reason'] = 'empty_text_use_greeting';
                if ($sessionId && $greeting !== '') {
                    $this->storeMessage($sessionId, 'assistant', $greeting);
                }
                $this->logBotReply($context, $greeting, 'text');

                $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
                Logger::info('[V2_BOXDESIGN_END]', [
                    'trace_id' => $traceId,
                    'elapsed_ms' => $elapsedMs,
                    'reason' => $meta['reason'],
                ]);

                return ['reply_text' => $greeting, 'actions' => [], 'meta' => $meta];
            }

            // Store user message
            if ($sessionId && $text !== '') {
                $this->storeMessage($sessionId, 'user', $text);
            }

            // =========================================================
            // ✅ BOX DESIGN RULE 1: Capabilities Question
            // User asks "ทำอะไรได้บ้าง" → immediate answer
            // =========================================================
            $tLower = mb_strtolower($text, 'UTF-8');
            $lastBotQuestion = is_array($lastSlots) ? (string) ($lastSlots['last_bot_question'] ?? '') : '';

            $isCapabilitiesQ = $this->containsAny($tLower, [
                'ทำอะไรได้บ้าง',
                'ช่วยอะไรได้บ้าง',
                'มีอะไรบ้าง',
                'แนะนำ',
                'ทำได้ไหม',
                'ทำอะไรได้',
                'ทำงานอะไรได้'
            ]);

            if ($isCapabilitiesQ && !empty($templates['capabilities_general'])) {
                Logger::info('[V2_BOXDESIGN_RULE]', [
                    'rule' => 'capabilities_question',
                    'matched' => true,
                    'template_key' => 'capabilities_general',
                    'trace_id' => $traceId,
                ]);

                $reply = (string) $templates['capabilities_general'];

                // Record to prevent loops
                $lastSlots['last_bot_question'] = 'capabilities_general';
                $lastSlots['last_question_key'] = 'capabilities';
                $this->updateSessionState((int) $sessionId, $lastIntent ?: null, $lastSlots);

                $meta['reason'] = 'boxdesign_answer_first_capabilities';
                if ($reply !== '') {
                    $this->storeMessage($sessionId, 'assistant', $reply);
                }
                $this->logBotReply($context, $reply, 'text');

                $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
                Logger::info('[V2_BOXDESIGN_END]', [
                    'trace_id' => $traceId,
                    'elapsed_ms' => $elapsedMs,
                    'reason' => $meta['reason'],
                ]);

                return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
            }

            // =========================================================
            // ✅ BOX DESIGN RULE 2: Pricing Question
            // Detect pricing questions and provide context-aware answers
            // =========================================================
            $isPriceQ = $this->containsAny($tLower, [
                'ราคา',
                'คิดราคายังไง',
                'งบ',
                'เท่าไหร่',
                'คิดเงิน',
                'ค่าบริการ'
            ]);

            if ($isPriceQ) {
                $tplKey = 'pricing_explain';

                // If mentions integration/automation → need scope
                if ($this->containsAny($tLower, ['เชื่อม', 'เชื่อมต่อ', 'อัตโนมัติ', 'workflow', 'ระบบ', 'crm', 'ชีท', 'sheet'])) {
                    $tplKey = 'pricing_need_scope';
                }

                Logger::info('[V2_BOXDESIGN_RULE]', [
                    'rule' => 'pricing_question',
                    'matched' => true,
                    'template_key' => $tplKey,
                    'trace_id' => $traceId,
                ]);

                $reply = (string) ($templates[$tplKey] ?? '');
                if ($reply !== '') {
                    $lastSlots['last_bot_question'] = $tplKey;
                    $lastSlots['last_question_key'] = 'pricing';
                    $this->updateSessionState((int) $sessionId, $lastIntent ?: null, $lastSlots);

                    $meta['reason'] = 'boxdesign_answer_first_pricing';
                    if ($sessionId) {
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    }
                    $this->logBotReply($context, $reply, 'text');

                    $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
                    Logger::info('[V2_BOXDESIGN_END]', [
                        'trace_id' => $traceId,
                        'elapsed_ms' => $elapsedMs,
                        'reason' => $meta['reason'],
                    ]);

                    return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
                }
            }

            // =========================================================
            // ✅ BOX DESIGN RULE 3: Prevent Repeat Questions
            // If about to ask same question → break loop with capabilities
            // =========================================================
            if ($lastBotQuestion !== '' && $lastBotQuestion === 'ask_goal' && !empty($templates['capabilities_general'])) {
                if ($text !== '' && mb_strlen($text, 'UTF-8') <= 20) {
                    Logger::info('[V2_BOXDESIGN_RULE]', [
                        'rule' => 'prevent_repeat_question',
                        'last_question' => $lastBotQuestion,
                        'trace_id' => $traceId,
                    ]);

                    $reply = (string) $templates['capabilities_general'];
                    $lastSlots['last_bot_question'] = 'capabilities_general';
                    $this->updateSessionState((int) $sessionId, $lastIntent ?: null, $lastSlots);

                    $meta['reason'] = 'boxdesign_break_repeat_question';
                    if ($sessionId) {
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    }
                    $this->logBotReply($context, $reply, 'text');

                    $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
                    Logger::info('[V2_BOXDESIGN_END]', [
                        'trace_id' => $traceId,
                        'elapsed_ms' => $elapsedMs,
                        'reason' => $meta['reason'],
                    ]);

                    return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
                }
            }

            // =========================================================
            // ✅ BOX DESIGN RULE 4: Business Type Capture
            // Capture business_type slot after question
            // =========================================================
            if (
                $sessionId
                && $text !== ''
                && $lastQuestionKey === 'business_type'
                && empty($lastSlots['business_type'])
            ) {
                $answer = $this->normalizeBusinessTypeAnswer($text);
                if ($answer !== '') {
                    Logger::info('[V2_BOXDESIGN_RULE]', [
                        'rule' => 'capture_business_type',
                        'business_type' => $answer,
                        'trace_id' => $traceId,
                    ]);

                    $lastSlots['business_type'] = $answer;
                    $lastSlots['last_question_key'] = null;

                    $this->updateSessionState((int) $sessionId, $lastIntent ?: null, $lastSlots);
                }
            }

            // =========================================================
            // ✅ FALLBACK: Delegate to parent RouterV1Handler
            // For all other flows (KB, LLM, backend integration)
            // =========================================================
            Logger::info('[V2_BOXDESIGN_DELEGATE]', [
                'rule' => 'delegate_to_parent',
                'trace_id' => $traceId,
            ]);

            $result = parent::handleMessage($context);

            // Override handler in meta
            if (isset($result['meta']) && is_array($result['meta'])) {
                $result['meta']['handler'] = 'router_v2_boxdesign';
            }

            $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
            Logger::info('[V2_BOXDESIGN_END]', [
                'trace_id' => $traceId,
                'elapsed_ms' => $elapsedMs,
                'reason' => $result['meta']['reason'] ?? 'delegated',
            ]);

            return $result;

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
}
