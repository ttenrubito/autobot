<?php
// filepath: /opt/lampp/htdocs/autobot/includes/bot/RouterV1Handler.php

require_once __DIR__ . '/BotHandlerInterface.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Logger.php';
require_once __DIR__ . '/CaseEngine.php';
require_once __DIR__ . '/../services/CustomerInterestService.php';

class RouterV1Handler implements BotHandlerInterface
{
    /** @var mixed PDO or PDO-like */
    protected $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function handleMessage(array $context): array
    {
        $traceId = (string)($context['trace_id'] ?? '');
        if ($traceId === '') {
            // keep stable even if caller didn't pass it
            $traceId = bin2hex(random_bytes(8));
            $context['trace_id'] = $traceId;
        }

        $t0 = microtime(true);
        Logger::info('[ROUTER_V1] start', [
            'trace_id' => $traceId,
            'channel_id' => $context['channel']['id'] ?? null,
            'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? null),
            'external_user_id' => $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null),
            'bot_profile_id' => $context['bot_profile']['id'] ?? null,
            'bot_profile_name' => $context['bot_profile']['name'] ?? null,
            'message_type' => $context['message']['message_type'] ?? ($context['message']['type'] ?? null),
            'has_text' => !empty($context['message']['text'] ?? ''),
            'text_len' => mb_strlen((string)($context['message']['text'] ?? ''), 'UTF-8'),
            'has_attachments' => !empty($context['message']['attachments'] ?? null),
        ]);

        try {
            $botProfile = $context['bot_profile'] ?? [];
            $config = $this->decodeJsonArray($botProfile['config'] ?? null);

            // Templates
            $templates = $config['response_templates'] ?? [];
            $greeting  = $templates['greeting'] ?? 'สวัสดีค่ะ มีอะไรให้ช่วยไหมคะ';
            $fallback  = $templates['fallback'] ?? 'ขออภัยค่ะ พอแจ้งรายละเอียดเพิ่มเติมได้ไหมคะ';

            // Persona & behavior flags
            $persona      = $config['persona'] ?? [];
            $skills       = $config['skills'] ?? [];
            $handoffCfg   = $config['handoff'] ?? [];
            $bufferingCfg = $config['buffering'] ?? [];

            // Store info (optional config)
            $storeCfg = $config['store'] ?? [];
            // Example config you can add:
            // "store": { "name":"เฮง เฮง เฮง", "description":"ร้านแบรนด์เนมมือสอง", "address":"...", "hours":"...", "contact":"LINE: ... โทร: ..." }

            // Integrations
            $integrations = $context['integrations'] ?? [];
            $googleNlpIntegrations     = $integrations['google_nlp'] ?? [];
            $googleVisionIntegrations  = $integrations['google_vision'] ?? [];
            $llmIntegrations           = $integrations['llm'] ?? ($integrations['openai'] ?? ($integrations['gemini'] ?? []));

            $googleNlp      = $googleNlpIntegrations[0] ?? null;
            $googleVision   = $googleVisionIntegrations[0] ?? null;
            $llmIntegration = $llmIntegrations[0] ?? null;

            Logger::info("RouterV1 - Integrations loaded", [
                'has_google_nlp' => !empty($googleNlp),
                'has_google_vision' => !empty($googleVision),
                'has_llm' => !empty($llmIntegration),
                'llm_provider' => $llmIntegration ? ($llmIntegration['provider'] ?? 'unknown') : null,
                'llm_has_api_key' => !empty($llmIntegration['api_key'] ?? null),
                'trace_id' => $traceId,
            ]);

            // Incoming message
            $message = $context['message'] ?? [];
            $text = trim((string)($message['text'] ?? ''));

            // ✅ DEBUG LOG: Detailed message intake for LINE vs FB comparison
            Logger::info("INCOMING_RAW_SUMMARY", [
                'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? null),
                'channel_id' => $context['channel']['id'] ?? null,
                'external_user_id' => $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null),
                'msg_keys' => array_keys($message),
                'msg_type_field' => $message['message_type'] ?? ($message['type'] ?? null),
                'has_attachments' => !empty($message['attachments']),
                'attachments_shape' => !empty($message['attachments']) ? array_map(function($a){
                    return [
                        'type' => $a['type'] ?? null,
                        'has_url' => !empty($a['url']) || !empty($a['payload']['url']),
                        'mime' => $a['mime_type'] ?? null,
                    ];
                }, (array)$message['attachments']) : [],
                'text_len' => mb_strlen($text, 'UTF-8'),
                'trace_id' => $traceId,
            ]);

            // ✅ ignore echo/system messages
            $isEcho = (bool)($message['is_echo'] ?? false);
            if ($isEcho) {
                Logger::info("RouterV1 - Ignored echo message");
                return ['reply_text' => null, 'actions' => [], 'meta' => ['reason' => 'ignore_echo']];
            }

            // ✅ Extract message type and image URL early
            $messageType = $message['message_type'] ?? ($message['type'] ?? 'text');
            $imageUrl = $message['attachments'][0]['url'] ?? null;

            // =========================================================
            // ✅ Session (MUST be created before admin command / handoff)
            // =========================================================
            $channel   = $context['channel'] ?? [];
            $channelId = $channel['id'] ?? null;
            $externalUserId = $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null);

            $session = null;
            $sessionId = null;
            if ($channelId && $externalUserId) {
                $session = $this->findOrCreateSession((int)$channelId, (string)$externalUserId);
                $sessionId = $session['id'] ?? null;
                if ($sessionId) $context['session_id'] = (int)$sessionId;
            }

            // ✅ Admin bypass
            // Defensive: avoid fatal if something accidentally overwrote the method name on this instance.
            $isAdmin = false;
            if (is_callable([$this, 'isAdminContext'])) {
                $isAdmin = $this->isAdminContext($context, $message);
            } else {
                Logger::error('[ROUTER_V1] isAdminContext not callable', [
                    'trace_id' => $traceId,
                    'type' => gettype($this->isAdminContext ?? null),
                ]);
                // fallback best-effort
                $isAdmin = !empty($context['is_admin']) || !empty($context['user']['is_admin']);
            }

            // ✅ Honor webhook-provided admin flag (Facebook is_echo / sender_is_page, LINE whitelist)
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
                    Logger::info('[ADMIN_HANDOFF] Command pattern matched!', [
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
                Logger::info('[ADMIN_HANDOFF] Manual command detected', [
                    'trace_id' => $traceId,
                    'session_id' => $sessionId,
                    'channel_id' => $channelId,
                    'external_user_id' => $externalUserId,
                    'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? null),
                ]);

                try {
                    $this->db->execute(
                        'UPDATE chat_sessions SET last_admin_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                        [$sessionId]
                    );
                } catch (Exception $e) {
                    Logger::error('[ADMIN_HANDOFF] Failed to update timestamp (manual cmd): ' . $e->getMessage(), [
                        'trace_id' => $traceId,
                        'session_id' => $sessionId,
                    ]);
                }

                // Store marker for audit trail
                $this->storeMessage($sessionId, 'system', '[admin_handoff] manual');

                // Treat as admin handoff activation immediately
                $isAdmin = true;

                // Do not reply when command is used
                return [
                    'reply_text' => null,
                    'actions' => [],
                    'meta' => [
                        'handler' => 'router_v1',
                        'reason' => 'admin_handoff_manual_command',
                        'trace_id' => $traceId,
                    ]
                ];
            }

            // Human-like delay (optional)
            $delayMs = (int)($config['llm']['reply_delay_ms'] ?? 0);
            if ($delayMs > 0 && $delayMs <= 5000) {
                usleep($delayMs * 1000);
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

            // Box Design logic moved to RouterV2BoxDesignHandler

            // Meta
            $meta = [
                'handler' => 'router_v1',
                'route' => null,
                'persona' => $persona,
                'skills' => $skills,
                'is_admin' => $isAdmin,
                'message_type' => $messageType,
            ];

            // =========================================================
            // ✅ ADMIN HANDOFF: Update timestamp when admin sends message
            // =========================================================
            if ($isAdmin && $sessionId) {
                Logger::info('[ADMIN_HANDOFF] Admin message detected', [
                    'trace_id' => $traceId,
                    'session_id' => $sessionId,
                    'channel_id' => $channelId,
                    'text_preview' => substr($text, 0, 50),
                ]);

                // Update last admin message timestamp
                try {
                    $this->db->execute(
                        'UPDATE chat_sessions SET last_admin_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                        [$sessionId]
                    );
                    Logger::info('[ADMIN_HANDOFF] Updated last_admin_message_at', ['session_id' => $sessionId]);
                } catch (Exception $e) {
                    Logger::error('[ADMIN_HANDOFF] Failed to update timestamp: ' . $e->getMessage());
                }

                // Store admin message for context (use supported role)
                if ($text !== '') {
                    $this->storeMessage($sessionId, 'system', '[admin] ' . $text);
                }

                // Don't reply to admin messages
                return [
                    'reply_text' => null,
                    'actions' => [],
                    'meta' => [
                        'handler' => 'router_v1',
                        'reason' => 'admin_message_detected',
                        'is_admin' => true,
                        'trace_id' => $traceId,
                    ]
                ];
            }

            // =========================================================
            // ✅ ADMIN HANDOFF: Check if admin is still active (1-hour timeout)
            // =========================================================
            if (!$isAdmin && $sessionId && $session) {
                // NOTE: $session may be stale (it was loaded before we update it in other requests).
                // Always read the latest timestamp for correctness.
                $row = $this->db->queryOne('SELECT last_admin_message_at FROM chat_sessions WHERE id = ? LIMIT 1', [$sessionId]);
                $lastAdminMsg = $row['last_admin_message_at'] ?? null;

                if ($lastAdminMsg !== null) {
                    $adminActiveThreshold = 3600; // 1 hour in seconds
                    $lastAdminTime = strtotime((string)$lastAdminMsg);
                    $currentTime = time();
                    $timeSinceAdmin = $currentTime - $lastAdminTime;

                    if ($lastAdminTime && $timeSinceAdmin < $adminActiveThreshold) {
                        // Admin is still active - pause bot
                        Logger::info('[ADMIN_HANDOFF] Admin still active - bot paused', [
                            'trace_id' => $traceId,
                            'session_id' => $sessionId,
                            'time_since_admin_sec' => $timeSinceAdmin,
                            'threshold_sec' => $adminActiveThreshold,
                            'remaining_sec' => $adminActiveThreshold - $timeSinceAdmin,
                        ]);

                        // Store customer message but don't reply
                        if ($text !== '') {
                            $this->storeMessage($sessionId, 'user', $text);
                        }

                        return [
                            'reply_text' => null,
                            'actions' => [],
                            'meta' => [
                                'handler' => 'router_v1',
                                'reason' => 'admin_handoff_active',
                                'admin_timeout_remaining_sec' => $adminActiveThreshold - $timeSinceAdmin,
                                'trace_id' => $traceId,
                            ]
                        ];
                    }

                    // Timeout expired - clear flag and resume bot
                    Logger::info('[ADMIN_HANDOFF] Timeout expired - resuming bot', [
                        'trace_id' => $traceId,
                        'session_id' => $sessionId,
                        'time_since_admin_sec' => $timeSinceAdmin,
                    ]);

                    try {
                        $this->db->execute(
                            'UPDATE chat_sessions SET last_admin_message_at = NULL WHERE id = ?',
                            [$sessionId]
                        );
                        Logger::info('[ADMIN_HANDOFF] Cleared last_admin_message_at', ['session_id' => $sessionId]);
                    } catch (Exception $e) {
                        Logger::error('[ADMIN_HANDOFF] Failed to clear timestamp: ' . $e->getMessage());
                    }
                }
            }

            // Box Design answer-first rules moved to RouterV2BoxDesignHandler

            // ✅ Image flow must be BEFORE empty-text greeting
            if ($messageType === 'image' || $imageUrl) {
                if ($sessionId) {
                    if ($text !== '') $this->storeMessage($sessionId, 'user', $text);
                    $this->storeMessage($sessionId, 'user', '[image] ' . ($imageUrl ?: ''));
                }

                if (!$imageUrl && !empty($message['attachments'][0]['url'])) {
                    $imageUrl = $message['attachments'][0]['url'];
                }
                if ($imageUrl && empty($message['attachments'])) {
                    $message['attachments'] = [['url' => $imageUrl, 'type' => 'image']];
                }

                return $this->handleImageFlow(
                    $context,
                    $config,
                    $templates,
                    $meta,
                    $sessionId,
                    $googleVision,
                    $llmIntegration,
                    $message
                );
            }

            // ✅ Anti-spam / repeat message behavior (text only)
            $antiSpamCfg = $config['anti_spam'] ?? [];
            $antiSpamEnabled   = (bool)($antiSpamCfg['enabled'] ?? true);
            $repeatThreshold   = (int)($antiSpamCfg['repeat_threshold'] ?? 3);
            $repeatWindowSec   = (int)($antiSpamCfg['window_seconds'] ?? 25);
            $repeatAction      = (string)($antiSpamCfg['action'] ?? 'template'); // template | silent | handoff
            $repeatTemplateKey = (string)($antiSpamCfg['template_key'] ?? 'repeat_detected');
            $repeatDefaultReply = (string)($antiSpamCfg['default_reply']
                ?? 'เหมือนข้อความเดิมเมื่อสักครู่ค่ะ 😊 รบกวนพิมพ์รายละเอียดเพิ่มอีกนิดนะคะ');

            // New: extra safety bypasses to prevent false positives
            $antiSpamMinLen = (int)($antiSpamCfg['min_length'] ?? 0); // optional config
            $antiSpamBypassShortLen = (int)($antiSpamCfg['bypass_short_length'] ?? 3); // default: bypass <= 3 chars

            if ($antiSpamEnabled && !$isAdmin && $sessionId && $messageType === 'text' && $text !== '') {
                $normalized = $this->normalizeTextForRepeat($text);

                // Bypass ultra-short texts and common acknowledgements
                $normalizedLen = mb_strlen($normalized, 'UTF-8');
                $ackSet = [
                    'ok','okay','kk','k','thx','thanks','ty',
                    'ค่ะ','ครับ','คับ','จ้า','ได้','ได้ค่ะ','ได้ครับ',
                    'yes','no','y','n',
                ];

                $shouldBypass = false;
                if ($antiSpamMinLen > 0 && $normalizedLen < $antiSpamMinLen) {
                    $shouldBypass = true;
                }
                if ($normalizedLen > 0 && $normalizedLen <= $antiSpamBypassShortLen) {
                    $shouldBypass = true;
                }
                if (in_array($normalized, $ackSet, true)) {
                    $shouldBypass = true;
                }

                if ($shouldBypass) {
                    Logger::info('Anti-Spam - Bypass', [
                        'trace_id' => $traceId,
                        'normalized' => $normalized,
                        'normalized_len' => $normalizedLen,
                    ]);
                } else {
                    Logger::info("Anti-Spam - Enabled: true, Threshold: {$repeatThreshold}, Window: {$repeatWindowSec}s, Action: {$repeatAction}");
                    Logger::info("Anti-Spam - Normalized text: '{$normalized}'");

                    // Important: require at least 2 identical recent user messages in window
                    // to survive duplicate webhook deliveries and RouterV2→RouterV1 delegation duplicates.
                    $isRepeat = $this->isRepeatedUserMessage($sessionId, $normalized, $repeatThreshold, $repeatWindowSec);
                    Logger::info("Anti-Spam - Repeat detected: " . ($isRepeat ? 'YES' : 'NO'));

                    if ($isRepeat) {
                        $this->storeMessage($sessionId, 'user', $text);

                        $meta['reason'] = 'repeat_detected';
                        $meta['anti_spam'] = [
                            'enabled' => true,
                            'threshold' => $repeatThreshold,
                            'window_seconds' => $repeatWindowSec,
                            'action' => $repeatAction,
                        ];

                        $reply = $templates[$repeatTemplateKey] ?? $repeatDefaultReply;

                        if ($repeatAction === 'silent') {
                            Logger::info("Anti-Spam - Action: SILENT (no reply)");
                            return ['reply_text' => null, 'actions' => [], 'meta' => $meta];
                        }

                        if ($repeatAction === 'handoff') {
                            Logger::info("Anti-Spam - Action: HANDOFF");
                            $meta['actions'][] = ['type' => 'handoff_to_admin', 'reason' => 'repeat_spam'];
                        }

                        if ($reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);

                        return [
                            'reply_text' => $reply,
                            'actions' => $meta['actions'] ?? [],
                            'meta' => $meta,
                        ];
                    }
                }
            }

            // Store user message (after anti-spam check)
            if ($sessionId && $text !== '') {
                $this->storeMessage($sessionId, 'user', $text);
            }

            // Box Design business_type slot capture moved to RouterV2BoxDesignHandler

            // Empty text (no image) => greeting
            if ($text === '') {
                $reply = $greeting;
                $meta['reason'] = 'empty_text_use_greeting';

                if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
                $this->logBotReply($context, $reply, 'text');

                Logger::info('[ROUTER_V1] end', [
                    'trace_id' => $traceId,
                    'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
                    'reason' => $meta['reason'] ?? null,
                    'reply_len' => mb_strlen((string)$reply, 'UTF-8'),
                    'actions_count' => 0,
                ]);

                return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
            }

            // =========================================================
            // ✅ Policy guard: Out-of-scope check
            // =========================================================
            $policy = $this->getPolicy($config);
            if ($text !== '' && $this->isOutOfScopeByPolicy($text, $policy)) {
                $key = (string)($policy['out_of_scope_template_key'] ?? 'out_of_scope');
                $reply = $templates[$key] ?? $fallback;
                $meta['reason'] = 'policy_out_of_scope';
                
                if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
                $this->logBotReply($context, $reply, 'text');
                
                return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
            }

            // =========================================================
            // ✅ Quick answers: Store info (before KB / routing)
            // =========================================================
            if ($this->looksLikeStoreInfoQuestion($text)) {
                $name = trim((string)($storeCfg['name'] ?? ''));
                $desc = trim((string)($storeCfg['description'] ?? ''));
                $contact = trim((string)($storeCfg['contact'] ?? ''));
                $hours = trim((string)($storeCfg['hours'] ?? ''));

                // If you want address to be handled by KB, keep it out here.
                $reply = $templates['store_info']
                    ?? ($name ? "ร้าน{$name}ค่ะ 😊 " : "ยินดีให้ข้อมูลร้านค่ะ 😊 ")
                        . ($desc ? $desc . " " : "")
                        . ($hours ? "เวลาเปิด-ปิด: {$hours} " : "")
                        . ($contact ? "ติดต่อ: {$contact}" : "");

                $reply = trim($reply);
                if ($reply === '') $reply = $fallback;

                $meta['reason'] = 'store_info_quick_answer';
                $meta['route'] = 'store_info';

                if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
                $this->logBotReply($context, $reply, 'text');

                return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
            }

            // =========================================================
            // ✅ Follow-up: ใช้ last_image_url เมื่อ user ถาม "มีไหม/ราคา" หลังส่งรูป
            // =========================================================
            if ($sessionId && !$isAdmin) {
                $follow = $this->tryHandleFollowupFromLastMedia(
                    (int)$sessionId,
                    $lastIntent,
                    $lastSlots,
                    $context,
                    $config,
                    $templates,
                    $text
                );

                if (!empty($follow['handled'])) {
                    $reply = (string)($follow['reply_text'] ?? $fallback);
                    $meta['reason'] = $follow['reason'] ?? 'followup_handled';
                    $meta['route'] = $follow['route'] ?? $meta['route'];
                    if (!empty($follow['meta'])) $meta['followup'] = $follow['meta'];

                    if (!empty($follow['intent'])) {
                        $meta['intent'] = $follow['intent'];
                        $meta['slots']  = $follow['slots'] ?? null;
                        $this->updateSessionState((int)$sessionId, $follow['intent'], $follow['slots'] ?? []);
                    }

                    if ($reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
                }
            }

            // =========================================================
            // ✅ KB FIRST (with KB-only buffering)
            // =========================================================
            $kbQuery = $text;
            if ($sessionId) {
                $kbQuery = $this->buildKbBufferedText((int)$sessionId, $text, $bufferingCfg);
                $meta['kb_buffering'] = [
                    'enabled' => (bool)($bufferingCfg['kb_enabled'] ?? true),
                    'window_seconds' => (int)($bufferingCfg['kb_window_seconds'] ?? 25),
                    'max_messages' => (int)($bufferingCfg['kb_max_messages'] ?? 2),
                    'kb_query' => $kbQuery,
                ];
            }

            $kbResults = $this->searchKnowledgeBase($context, $kbQuery);
            if (!empty($kbResults) && isset($kbResults[0])) {
                $bestMatch = $kbResults[0];
                $reply = (string)($bestMatch['answer'] ?? $fallback);

                $meta['knowledge_base'] = [
                    'matched' => true,
                    'match_type' => $bestMatch['match_type'] ?? 'unknown',
                    'match_score' => $bestMatch['match_score'] ?? 0,
                    'matched_keyword' => $bestMatch['matched_keyword'] ?? null,
                    'category' => $bestMatch['category'] ?? null,
                    'metadata' => $bestMatch['metadata'] ?? [],
                ];
                $meta['reason'] = 'knowledge_base_answer';
                $meta['route']  = $bestMatch['category'] ?? 'knowledge';

                if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
                $this->logBotReply($context, $reply, 'text');

                return [
                    'reply_text' => $reply,
                    'actions' => [],
                    'meta' => $meta,
                ];
            }

            // ✅ KB pending hold (fixed logic)
            if (!$isAdmin && $sessionId) {
                $kbHoldEnabled = (bool)($bufferingCfg['kb_enabled'] ?? true);
                if ($kbHoldEnabled && $this->hasAdvancedKbPending($context, $text)) {
                    $pendingReply = $templates['kb_pending']
                        ?? 'รับทราบค่ะ 😊 พิมพ์รายละเอียดต่อได้เลย เช่น ชื่อสินค้า/รุ่น/รหัส/งบ หรือส่งรูปมาได้เลยค่ะ';

                    $meta['reason'] = 'kb_advanced_pending_hold';

                    if ($sessionId && $pendingReply !== '') {
                        $this->storeMessage($sessionId, 'assistant', '[kb_pending] ' . $pendingReply);
                    }
                    $this->logBotReply($context, $pendingReply, 'text');

                    return ['reply_text' => $pendingReply, 'actions' => [], 'meta' => $meta];
                }
            }

            // =========================================================
            // Routing rules (text only) - after KB
            // =========================================================
            $matchedRoute = null;
            $routingCfg = $config['routing_policy'] ?? [];
            $routing = $routingCfg['rules'] ?? [];
            foreach ($routing as $rule) {
                $keywords = $rule['when_any'] ?? [];
                foreach ($keywords as $kw) {
                    $kw = trim((string)$kw);
                    if ($kw !== '' && mb_stripos($text, $kw, 0, 'UTF-8') !== false) {
                        $matchedRoute = $rule['route_to'] ?? null;
                        break 2;
                    }
                }
            }
            $meta['route'] = $matchedRoute;

            // =========================================================
            // ✅ Quick route -> LLM slots + backend call
            // =========================================================
            if ($matchedRoute) {
                if ($llmIntegration && !empty($config['llm']['enabled'])) {
                    $llm = $this->handleWithLlmIntent($llmIntegration, $config, $context, $text);
                    $intent = $matchedRoute; // force route from keywords
                    $slots  = is_array($llm['slots'] ?? null) ? $llm['slots'] : [];
                    $confidence = $llm['confidence'] ?? null;
                    $nextQuestion = $llm['next_question'] ?? null;

                    // ✅ merge last slots
                    $slots = $this->mergeSlots($lastSlots, $slots);

                    // ✅ rule-based slot rescue (สำคัญ: "สินค้า รหัส xxxx")
                    $slots = $this->rescueSlotsFromText($intent, $slots, $text);

                    // เติม action_type จาก rule ถ้าข้อความมีคำชี้นำ
                    if ($intent === 'installment_flow' && empty($slots['action_type'])) {
                        $slots['action_type'] = $this->detectInstallmentActionTypeFromText($text) ?: null;
                    }

                    // =========================================================
                    // ✅ AUTO-CREATE CASE for keyword-matched route (via API)
                    // =========================================================
                    $caseManagement = $config['case_management'] ?? [];
                    $backendCfg = $config['backend_api'] ?? [];
                    if (!empty($caseManagement['enabled']) && !empty($caseManagement['auto_create_case']) && $intent && !empty($backendCfg['enabled'])) {
                        try {
                            $caseEngine = new CaseEngine($config, $context);
                            $caseType = $caseEngine->detectCaseType($intent, $slots['action_type'] ?? null);
                            
                            if ($caseType) {
                                // Get case_create endpoint from config
                                $caseEndpoint = $backendCfg['endpoints']['case_create'] ?? '/api/bot/cases';
                                $casePayload = [
                                    'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? 'unknown'),
                                    'channel_id' => $channelId,
                                    'external_user_id' => $externalUserId,
                                    'case_type' => $caseType,
                                    'slots' => $slots,
                                    'intent' => $intent,
                                    'message' => $text, // ✅ Include customer message
                                ];
                                
                                // Call API endpoint
                                $caseResp = $this->callBackendJson($backendCfg, $caseEndpoint, $casePayload);
                                
                                if ($caseResp['ok'] && !empty($caseResp['data'])) {
                                    $caseData = $caseResp['data'];
                                    $meta['case'] = [
                                        'id' => $caseData['id'] ?? null,
                                        'case_no' => $caseData['case_no'] ?? null,
                                        'case_type' => $caseType,
                                        'is_new' => $caseData['is_new'] ?? true,
                                    ];
                                    Logger::info('[CASE_API] Case created via route', [
                                        'trace_id' => $traceId,
                                        'intent' => $intent,
                                        'case_type' => $caseType,
                                        'case_id' => $caseData['id'] ?? null,
                                    ]);
                                }
                            }
                        } catch (Throwable $caseEx) {
                            Logger::error('[CASE_API] Failed to create case via route', [
                                'trace_id' => $traceId,
                                'error' => $caseEx->getMessage(),
                            ]);
                        }
                    }

                    // backend step
                    $handled = $this->tryHandleByIntentWithBackend($intent, $slots, $context, $config, $templates, $text, null);

                    $meta['llm_intent'] = $llm['meta'] ?? null;
                    $meta['intent'] = $intent;
                    $meta['slots'] = $slots;
                    if ($confidence !== null) $meta['confidence'] = (float)$confidence;

                    if (!empty($handled['handled'])) {
                        $reply = (string)($handled['reply_text'] ?? $fallback);
                        $meta['reason'] = $handled['reason'] ?? 'route_backend_handled';
                        $meta['backend'] = $handled['meta'] ?? null;

                        // ✅ Backend response - skip hallucination check
                        $backendWasUsed = !empty($meta['backend']);
                        $backendWorked = !empty($config['backend_api']['enabled']);
                        $reply = $this->applyPolicyGuards($reply, $intent, $config, $templates, $backendWorked, $backendWasUsed, $handled['slots'] ?? $slots);

                        if ($sessionId && $intent) {
                            $this->updateSessionState($sessionId, $intent, $handled['slots'] ?? $slots);
                        }
                        if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
                        $this->logBotReply($context, $reply, 'text');

                        return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
                    }

                    // ยังไม่ handled -> ถามต่อ
                    $reply = '';
                    if (!empty($handled['reply_text'])) {
                        $reply = (string)$handled['reply_text'];
                        $meta['reason'] = $handled['reason'] ?? 'route_need_more_info';
                    } elseif ($nextQuestion) {
                        $reply = (string)$nextQuestion;
                        $meta['reason'] = 'route_slot_filling_next_question';
                    } else {
                        $reply = $this->fallbackByIntentTemplate($intent, $templates, $fallback);
                        $meta['reason'] = 'route_fallback_template';
                    }

                    // handoff policy
                    $handoffEnabled = !empty($handoffCfg['enabled']);
                    $handoffThreshold = isset($handoffCfg['when_confidence_below']) ? (float)$handoffCfg['when_confidence_below'] : 0.0;
                    if ($handoffEnabled && $confidence !== null && (float)$confidence < $handoffThreshold) {
                        $meta['actions'][] = ['type' => 'handoff_to_admin', 'reason' => 'low_confidence'];
                    }

                    if ($sessionId && $intent) {
                        $this->updateSessionState($sessionId, $intent, $slots);
                    }

                    // ✅ Apply policy guards - LLM reply (not from backend)
                    $backendEnabled = !empty($config['backend_api']['enabled']);
                    $reply = $this->applyPolicyGuards($reply, $intent, $config, $templates, $backendEnabled, false, $slots);

                    if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    return [
                        'reply_text' => $reply,
                        'actions' => $meta['actions'] ?? [],
                        'meta' => $meta,
                    ];
                }

                // ถ้าไม่มี LLM ก็ใช้ template ตาม intent ไปก่อน
                $reply = $this->fallbackByIntentTemplate($matchedRoute, $templates, $fallback);
                $meta['reason'] = 'matched_route_no_llm';
                if ($sessionId && $matchedRoute) $this->updateSessionState($sessionId, $matchedRoute, $lastSlots);
                if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
                $this->logBotReply($context, $reply, 'text');
                return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
            }

            // =========================================================
            // Default router: LLM intent -> backend -> reply
            // =========================================================
            $reply = '';
            $defaultRouter = $routingCfg['default_router'] ?? 'llm_intent';

            if ($defaultRouter === 'llm_intent' && $llmIntegration && !empty($config['llm']['enabled'])) {
                Logger::info('[ROUTER_V1] llm_intent_start', [
                    'trace_id' => $traceId,
                    'text_preview' => mb_substr($text, 0, 120, 'UTF-8'),
                ]);

                $llmResult = $this->handleWithLlmIntent($llmIntegration, $config, $context, $text);

                Logger::info('[ROUTER_V1] llm_intent_result', [
                    'trace_id' => $traceId,
                    'intent' => $llmResult['intent'] ?? null,
                    'confidence' => $llmResult['confidence'] ?? null,
                    'has_reply_text' => !empty($llmResult['reply_text'] ?? null),
                    'reply_preview' => isset($llmResult['reply_text']) ? mb_substr((string)$llmResult['reply_text'], 0, 120, 'UTF-8') : null,
                    'slots_keys' => (isset($llmResult['slots']) && is_array($llmResult['slots'])) ? array_keys($llmResult['slots']) : null,
                    'next_question_present' => !empty($llmResult['next_question'] ?? null),
                ]);

                $reply = (string)($llmResult['reply_text'] ?? $fallback);
                $intent = $llmResult['intent'] ?? null;
                $slots  = is_array($llmResult['slots'] ?? null) ? $llmResult['slots'] : [];
                $confidence   = $llmResult['confidence'] ?? null;
                $nextQuestion = $llmResult['next_question'] ?? null;

                $meta['llm_intent'] = $llmResult['meta'] ?? null;

                $slots = $this->mergeSlots($lastSlots, $slots);
                $slots = $this->rescueSlotsFromText($intent, $slots, $text);

                if ($intent === 'installment_flow' && empty($slots['action_type'])) {
                    $slots['action_type'] = $this->detectInstallmentActionTypeFromText($text) ?: null;
                }

                // ✅ SMART FALLBACK: Only force product_availability if LLM didn't provide meaningful answer
                // If LLM already answered (has reply_text), respect that
                if (empty($intent) && !empty($slots['product_name'])) {
                    // Check if this is an ordering/purchasing question (not product search)
                    $isOrderingQuestion = 
                        preg_match('/สั่ง|ซื้อ|จอง|ชำระ|วิธี|ยังไง|อย่างไร|ขั้นตอน|payment|order|buy|purchase|how/iu', $text);
                    
                    if ($isOrderingQuestion) {
                        Logger::info("[INTENT_FALLBACK] Ordering question detected - NOT forcing product_availability", [
                            'product_name' => $slots['product_name'],
                            'text' => $text
                        ]);
                    } else {
                        $llmReply = trim((string)$llmResult['reply_text'] ?? '');
                        $isFallbackReply = empty($llmReply) || 
                            strpos($llmReply, 'ช่วยบอก') !== false ||
                            strpos($llmReply, 'รบกวน') !== false;
                        
                        // Only force if LLM gave generic/empty response
                        if ($isFallbackReply) {
                            $intent = 'product_availability';
                            Logger::info("[INTENT_FALLBACK] No intent + generic LLM reply - using product_availability", [
                                'product_name' => $slots['product_name']
                            ]);
                        } else {
                            Logger::info("[INTENT_FALLBACK] LLM provided meaningful answer - NOT forcing product_availability", [
                                'product_name' => $slots['product_name'],
                                'llm_reply_preview' => substr($llmReply, 0, 100)
                            ]);
                        }
                    }
                }

                // =========================================================
                // ✅ KEYWORD-BASED INTENT FALLBACK when LLM fails to detect
                // =========================================================
                if (empty($intent)) {
                    $textLower = mb_strtolower($text, 'UTF-8');
                    
                    // Savings keywords
                    if (preg_match('/ออม|ออมทอง|เปิดออม|สะสม/u', $textLower)) {
                        $intent = 'savings_new';
                        $slots['action_type'] = 'new';
                        Logger::info("[INTENT_FALLBACK] Keyword match: savings_new", ['text' => $text]);
                    }
                    // Installment keywords  
                    elseif (preg_match('/ผ่อน|งวด|ผ่อนชำระ/u', $textLower)) {
                        $intent = 'installment_flow';
                        $slots['action_type'] = 'new';
                        Logger::info("[INTENT_FALLBACK] Keyword match: installment_flow", ['text' => $text]);
                    }
                    // Pawn keywords
                    elseif (preg_match('/จำนำ|ฝากจำนำ|ต่อดอก|ไถ่ถอน/u', $textLower)) {
                        $intent = 'pawn_new';
                        $slots['action_type'] = 'new';
                        Logger::info("[INTENT_FALLBACK] Keyword match: pawn_new", ['text' => $text]);
                    }
                    // Repair keywords
                    elseif (preg_match('/ซ่อม|เซอร์วิส|ส่งซ่อม/u', $textLower)) {
                        $intent = 'repair_new';
                        $slots['action_type'] = 'new';
                        Logger::info("[INTENT_FALLBACK] Keyword match: repair_new", ['text' => $text]);
                    }
                    // Deposit keywords
                    elseif (preg_match('/มัดจำ|วางมัดจำ|กันไว้/u', $textLower)) {
                        $intent = 'deposit_new';
                        $slots['action_type'] = 'new';
                        Logger::info("[INTENT_FALLBACK] Keyword match: deposit_new", ['text' => $text]);
                    }
                    // Product inquiry keywords
                    elseif (preg_match('/สนใจ|ดู|มี.*ไหม|ราคา|แหวน|สร้อย|นาฬิกา|กำไล|ต่างหู/u', $textLower)) {
                        $intent = 'product_availability';
                        Logger::info("[INTENT_FALLBACK] Keyword match: product_availability", ['text' => $text]);
                    }
                }

                $intentConfigMap = $config['intents'] ?? [];
                $intentConfig = ($intent && isset($intentConfigMap[$intent])) ? $intentConfigMap[$intent] : [];
                $missingSlots = $intent ? $this->detectMissingSlots($intent, $intentConfig, $slots) : [];

                $meta['intent'] = $intent;
                $meta['slots'] = $slots;
                $meta['missing_slots'] = $missingSlots;
                if ($confidence !== null) $meta['confidence'] = (float)$confidence;

                // =========================================================
                // ✅ AUTO-CREATE CASE when intent detected (via API)
                // =========================================================
                $caseManagement = $config['case_management'] ?? [];
                $backendCfg = $config['backend_api'] ?? [];
                if (!empty($caseManagement['enabled']) && !empty($caseManagement['auto_create_case']) && $intent && !empty($backendCfg['enabled'])) {
                    try {
                        $caseEngine = new CaseEngine($config, $context);
                        $caseType = $caseEngine->detectCaseType($intent, $slots['action_type'] ?? null);
                        
                        if ($caseType) {
                            // ✅ Get customer_profile_id for linking
                            $customerProfileId = $this->getCustomerProfileId($context['platform'] ?? 'unknown', $externalUserId);
                            
                            // Get case_create endpoint from config
                            $caseEndpoint = $backendCfg['endpoints']['case_create'] ?? '/api/bot/cases';
                            $casePayload = [
                                'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? 'unknown'),
                                'channel_id' => $channelId,
                                'external_user_id' => $externalUserId,
                                'customer_profile_id' => $customerProfileId, // ✅ NEW: Link to customer profile
                                'case_type' => $caseType,
                                'slots' => $slots,
                                'intent' => $intent,
                                'message' => $text, // ✅ Include customer message
                                'session_id' => $sessionId, // ✅ NEW: For chat history
                            ];
                            
                            // Call API endpoint
                            $caseResp = $this->callBackendJson($backendCfg, $caseEndpoint, $casePayload);
                            
                            if ($caseResp['ok'] && !empty($caseResp['data'])) {
                                $caseData = $caseResp['data'];
                                $meta['case'] = [
                                    'id' => $caseData['id'] ?? null,
                                    'case_no' => $caseData['case_no'] ?? null,
                                    'case_type' => $caseType,
                                    'is_new' => $caseData['is_new'] ?? true,
                                ];
                                Logger::info('[CASE_API] Case created via API', [
                                    'trace_id' => $traceId,
                                    'intent' => $intent,
                                    'case_type' => $caseType,
                                    'case_id' => $caseData['id'] ?? null,
                                    'case_no' => $caseData['case_no'] ?? null,
                                ]);
                                
                                // ✅ NEW: Track product interest if product_ref_id present
                                if ($customerProfileId && !empty($slots['product_ref_id'])) {
                                    $this->trackProductInterest($customerProfileId, $slots, [
                                        'channel_id' => $channelId,
                                        'case_id' => $caseData['id'] ?? null,
                                        'message_text' => $text,
                                        'intent' => $intent,
                                    ]);
                                }
                            } else {
                                Logger::warning('[CASE_API] Failed to create case via API', [
                                    'trace_id' => $traceId,
                                    'intent' => $intent,
                                    'case_type' => $caseType,
                                    'response' => $caseResp,
                                ]);
                            }
                        }
                    } catch (Throwable $caseEx) {
                        Logger::error('[CASE_ENGINE] Failed to create case', [
                            'trace_id' => $traceId,
                            'intent' => $intent,
                            'error' => $caseEx->getMessage(),
                        ]);
                    }
                }

                $handled = $this->tryHandleByIntentWithBackend($intent, $slots, $context, $config, $templates, $text, null);

                Logger::info('[ROUTER_V1] backend_attempt', [
                    'trace_id' => $traceId,
                    'intent' => $intent,
                    'handled' => !empty($handled['handled']),
                    'reason' => $handled['reason'] ?? null,
                    'backend_meta_keys' => (isset($handled['meta']) && is_array($handled['meta'])) ? array_keys($handled['meta']) : null,
                ]);

                if (!empty($handled['handled'])) {
                    $reply = (string)($handled['reply_text'] ?? $fallback);
                    $meta['backend'] = $handled['meta'] ?? null;
                    $meta['reason']  = $handled['reason'] ?? 'llm_intent_backend_handled';
                    if (!empty($intent)) $meta['route'] = $intent;

                    // ✅ PRESERVE actions from backend (for product images, etc.)
                    $actionsOut = (isset($handled['actions']) && is_array($handled['actions'])) ? $handled['actions'] : [];

                    if ($sessionId && $intent) {
                        $this->updateSessionState($sessionId, $intent, $handled['slots'] ?? $slots);
                    }

                    if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');
                    return [
                        'reply_text' => $reply,
                        'actions' => $actionsOut,  // ✅ FIXED: Send actions!
                        'meta' => $meta,
                    ];
                }

                // ถ้ายังไม่พร้อม -> ถามต่อ
                if ($intent && !empty($missingSlots) && $nextQuestion) {
                    $reply = (string)$nextQuestion;
                    $meta['reason'] = 'llm_intent_slot_filling';
                } else {
                    if ($sessionId) {
                        $this->updateSessionState($sessionId, $intent, $slots);
                    }
                    $meta['reason'] = 'llm_intent_default';
                }

                // handoff policy
                $handoffEnabled = !empty($handoffCfg['enabled']);
                $handoffThreshold = isset($handoffCfg['when_confidence_below']) ? (float)$handoffCfg['when_confidence_below'] : 0.0;

                if ($handoffEnabled && $confidence !== null && (float)$confidence < $handoffThreshold) {
                    $meta['actions'][] = ['type' => 'handoff_to_admin', 'reason' => 'low_confidence'];
                    if ($reply === '' && $nextQuestion) $reply = (string)$nextQuestion;
                }

                if (!empty($intent)) $meta['route'] = $intent;
                
                // ✅ Apply policy guards - LLM only
                $backendEnabled = !empty($config['backend_api']['enabled']);
                $reply = $this->applyPolicyGuards($reply, $intent, $config, $templates, $backendEnabled, false, $slots);
            } elseif ($llmIntegration && !empty($config['llm']['enabled'])) {
                $llmResult = $this->handleWithLlm($llmIntegration, $config, $context, $text);
                $reply = (string)($llmResult['reply_text'] ?? $fallback);
                $meta['llm'] = $llmResult['meta'] ?? null;
                if (!empty($llmResult['intent'])) $meta['route'] = $llmResult['intent'];
                $meta['reason'] = 'llm_fallback';
                
                // ✅ Apply policy guards - LLM only
                $backendEnabled = !empty($config['backend_api']['enabled']);
                $reply = $this->applyPolicyGuards($reply, $llmResult['intent'] ?? null, $config, $templates, $backendEnabled, false, $llmResult['slots'] ?? []);
            } else {
                if ($googleNlp) {
                    $nlpResult = $this->analyzeTextWithGoogleNlp($googleNlp, $text);
                    $meta['nlp'] = $nlpResult['meta'] ?? null;

                    if (!empty($nlpResult['meta']['suggested_route'])) {
                        $meta['route'] = $nlpResult['meta']['suggested_route'];
                        $meta['reason'] = 'google_nlp_suggested_route';
                        $reply = $fallback;
                    } else {
                        $reply = $fallback;
                        $meta['reason'] = 'fallback_with_google_nlp';
                    }
                } else {
                    $reply = $fallback;
                    $meta['reason'] = 'fallback';
                }
            }

            if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
            $this->logBotReply($context, $reply, 'text');

            Logger::info('[ROUTER_V1] end', [
                'trace_id' => $traceId,
                'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
                'reason' => $meta['reason'] ?? null,
                'route' => $meta['route'] ?? null,
                'intent' => $meta['intent'] ?? null,
                'reply_len' => mb_strlen((string)$reply, 'UTF-8'),
                'actions_count' => isset($meta['actions']) && is_array($meta['actions']) ? count($meta['actions']) : 0,
            ]);

            return [
                'reply_text' => $reply,
                'actions' => $meta['actions'] ?? [],
                'meta' => $meta,
            ];
        } catch (Throwable $e) {
            Logger::error('[ROUTER_V1] exception', [
                'trace_id' => $traceId,
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Fail safe: never crash gateway; return fallback reply
            return [
                'reply_text' => (string)('ขออภัยค่ะ ระบบขัดข้องชั่วคราว รบกวนลองใหม่อีกครั้งนะคะ'),
                'actions' => [],
                'meta' => [
                    'handler' => 'router_v1',
                    'reason' => 'router_exception',
                    'trace_id' => $traceId,
                ],
            ];
        }
    }

    // =========================================================
    // ✅ Follow-up handler from last media
    // =========================================================
    protected function tryHandleFollowupFromLastMedia(
        int $sessionId,
        ?string $lastIntent,
        array $lastSlots,
        array $context,
        array $config,
        array $templates,
        string $text
    ): array {
        $now = time();

        // last image
        $lastImageUrl  = (string)($lastSlots['last_image_url'] ?? '');
        $lastImageKind = (string)($lastSlots['last_image_kind'] ?? ''); // product_image | payment_proof | image_generic
        $lastImageTs   = (string)($lastSlots['last_image_ts'] ?? '');
        $lastImageAge  = $lastImageTs ? ($now - strtotime($lastImageTs)) : 999999;

        // if too old, ignore
        if ($lastImageUrl === '' || $lastImageAge > 600) { // 10 minutes
            return ['handled' => false];
        }

        $tLower = mb_strtolower($text, 'UTF-8');

        // Follow-up product from last image
        $askHave = $this->containsAny($tLower, ["มีไหม","ยังอยู่ไหม","เช็คของ","อยู่มั้ย","มีของไหม","มีรุ่นนี้ไหม","มีมั้ย"]);
        $askPrice = $this->containsAny($tLower, ["ราคา","เท่าไหร่","ขอราคา","ต่อรอง","ลดได้ไหม"]);

        // Follow-up payment from last slip image
        $askPaid = $this->containsAny($tLower, ["โอนแล้ว","ชำระแล้ว","จ่ายแล้ว","ส่งสลิป","แนบสลิป","โอนเงินแล้ว","ตรวจสอบยอด","เช็คยอด","เช็คสลิป"]);

        // ถ้ารูปก่อนหน้าเป็นสลิป แล้ว user พิมพ์โอนแล้ว => ยิง receipt_get ได้เลย
        if ($lastImageKind === 'payment_proof' && $askPaid) {
            $handled = $this->tryHandleByIntentWithBackend(
                'payment_slip_verify',
                [
                    'amount' => null,
                    'time' => null,
                    'sender_name' => null,
                    'payment_ref' => null
                ],
                $context,
                $config,
                $templates,
                $text,
                ['slip_image_url' => $lastImageUrl]
            );

            if (!empty($handled['handled'])) {
                return [
                    'handled' => true,
                    'reply_text' => $handled['reply_text'] ?? null,
                    'reason' => 'followup_last_slip_verify',
                    'route' => 'payment_slip_verify',
                    'intent' => 'payment_slip_verify',
                    'slots' => $handled['slots'] ?? ['slip_image_url' => $lastImageUrl],
                    'meta' => ['age_sec' => $lastImageAge, 'backend' => $handled['meta'] ?? null],
                ];
            }

            // ถ้ายังไม่สำเร็จ ให้ถามข้อมูลขั้นต่ำ
            $reply = $templates['ask_slip_missing']
                ?? 'ได้รับข้อมูลแล้วค่ะ 💳 รบกวนแจ้ง “ยอดโอน/เวลาประมาณ/ชื่อผู้โอน” เพิ่มนิดนึงนะคะ เพื่อให้เช็คได้ไวขึ้นค่ะ';
            return [
                'handled' => true,
                'reply_text' => $reply,
                'reason' => 'followup_last_slip_need_more',
                'route' => 'payment_slip_verify',
                'intent' => 'payment_slip_verify',
                'slots' => ['slip_image_url' => $lastImageUrl],
                'meta' => ['age_sec' => $lastImageAge],
            ];
        }

        // ถ้ารูปก่อนหน้าเป็นสินค้า และ user ถามมีไหม/ราคา => ยิง image_search ได้เลย
        if (($lastImageKind === 'product_image' || $lastImageKind === 'image_generic') && ($askHave || $askPrice)) {
            $backendCfg = $config['backend_api'] ?? [];
            if (empty($backendCfg['enabled'])) return ['handled' => false];

            $endpoints = $backendCfg['endpoints'] ?? [];
            $endpoint = $endpoints['image_search'] ?? ($endpoints['searchImage'] ?? '/api/searchImage');

            $payload = [
                'channel_id' => $context['channel']['id'] ?? null,
                'external_user_id' => $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null),
                'image_url' => $lastImageUrl,
                'vision' => [
                    'labels' => $lastSlots['last_vision_labels'] ?? [],
                    'top_descriptions' => $lastSlots['last_vision_top_descriptions'] ?? [],
                    'text' => $lastSlots['last_vision_text'] ?? '',
                    'web_entities' => $lastSlots['last_vision_web_entities'] ?? [],
                ],
            ];

            $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
            if (!empty($resp['ok'])) {
                $products = $resp['data']['products'] ?? ($resp['data']['items'] ?? ($resp['data']['candidates'] ?? []));
                if (!is_array($products)) $products = [];

                $rendered = $this->renderProductsFromBackend($products, $templates);
                return [
                    'handled' => true,
                    'reply_text' => $rendered['text'],
                    'actions' => $rendered['actions'] ?? [],
                    'reason' => 'followup_last_image_product_search',
                    'route' => 'product_lookup_by_image',
                    'intent' => 'product_lookup_by_image',
                    'slots' => ['image_url' => $lastImageUrl],
                    'meta' => ['age_sec' => $lastImageAge, 'backend' => $resp],
                ];
            }

            $reply = $templates['ask_product_code']
                ?? 'รบกวนส่ง “ชื่อรุ่น/รหัส/ซีเรียล/งบ” เพิ่มนิดนึงค่ะ 😊 เพื่อให้เช็คได้ตรงขึ้นค่ะ';
            return [
                'handled' => true,
                'reply_text' => $reply,
                'reason' => 'followup_last_image_backend_error',
                'route' => 'product_lookup_by_image',
                'intent' => 'product_lookup_by_image',
                'slots' => ['image_url' => $lastImageUrl],
                'meta' => ['age_sec' => $lastImageAge],
            ];
        }

        return ['handled' => false];
    }

    // =========================================================
    // ✅ Slot rescue from raw text (fix LLM misses)
    // =========================================================
    protected function rescueSlotsFromText(?string $intent, array $slots, string $text): array
    {
        $intent = trim((string)$intent);
        if ($intent === '') {
            return $slots;
        }

        // product_code extraction
        if ($intent === 'product_lookup_by_code') {
            $pc = trim((string)($slots['product_code'] ?? ''));
            if ($pc === '') {
                // Examples: "รหัส xxxx", "code: RX-001", "SKU#123"
                if (preg_match('/(?:รหัส|โค้ด|code|sku|serial|ซีเรียล)\s*[:#]?\s*([A-Za-z0-9\-\_\.\/]+)\b/iu', $text, $m)) {
                    $slots['product_code'] = trim($m[1]);
                }
            }
        }

        // product_name extraction (improved to catch plain queries)
        if ($intent === 'product_availability' || $intent === 'price_inquiry') {
            $pn = trim((string)($slots['product_name'] ?? ''));
            if ($pn === '') {
                // Try pattern with question keywords first
                if (preg_match('/(?:มีรุ่นนี้ไหม|มีของไหม|มีไหม|ราคา|เท่าไหร่|สนใจ|มี)\s+(.+?)(?:\s+ไหม|บ้าง|มั้ย)?$/iu', $text, $m)) {
                    $guess = trim($m[1]);
                    if (mb_strlen($guess, 'UTF-8') >= 2) $slots['product_name'] = $guess;
                } 
                // If no keywords, use the entire text as product_name (for queries like "Rolex", "นาฬิกา Rolex")
                else {
                    $guess = trim($text);
                    // Only accept if it's not too short and doesn't look like a generic greeting
                    if (mb_strlen($guess, 'UTF-8') >= 3 && !preg_match('/^(สวัสดี|hello|hi|ดีค่ะ|ดีครับ)$/iu', $guess)) {
                        $slots['product_name'] = $guess;
                    }
                }
            }
        }

        // amount extraction (best-effort)
        if ($intent === 'payment_slip_verify' || $intent === 'installment_flow') {
            $amt = trim((string)($slots['amount'] ?? ''));
            if ($amt === '' && preg_match('/(\d{2,3}(?:,\d{3})*(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)\s*(?:บาท|thb)?/iu', $text, $m)) {
                $slots['amount'] = $this->normalizeAmount($m[1]);
            }
        }

        return $slots;
    }

    // =========================================================
    // ✅ Backend intent handler (REAL API CALLS)
    // =========================================================
    protected function tryHandleByIntentWithBackend(?string $intent, array $slots, array $context, array $config, array $templates, string $rawText, ?array $extra = null): array
    {
        $intent = $intent ? trim($intent) : null;
        if (!$intent) return ['handled' => false];

        $backendCfg = $config['backend_api'] ?? [];
        $toolPolicy = $config['tool_policy'] ?? [];
        $preferBackend = (bool)($toolPolicy['prefer_backend_over_llm'] ?? true);

        if (!$preferBackend || empty($backendCfg['enabled'])) {
            return ['handled' => false, 'reason' => 'backend_disabled_or_not_preferred'];
        }

        $channelId = $context['channel']['id'] ?? null;
        $externalUserId = $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null);

        // Normalize some slots
        if (!empty($slots['customer_phone'])) $slots['customer_phone'] = $this->normalizePhone((string)$slots['customer_phone']);
        if (!empty($slots['amount'])) $slots['amount'] = $this->normalizeAmount((string)$slots['amount']);

        // Helper ask templates
        $askProductCode = $templates['ask_product_code'] ?? $templates['fallback'] ?? 'รบกวนส่งรหัส/รุ่นเพิ่มนิดนึงค่ะ';
        $askInstallment = $templates['ask_installment_id'] ?? 'รบกวนแจ้งเลขสัญญา/ชื่อ-เบอร์/ยอดที่โอน/เวลาโอน เพิ่มนิดนึงค่ะ';
        $askSlipMissing = $templates['ask_slip_missing'] ?? 'รบกวนแจ้งยอด/เวลา/ชื่อผู้โอนเพิ่มนิดนึงค่ะ';

        // Endpoint resolver (supports both old & new keys)
        $ep = function(array $keys) use ($backendCfg) {
            $endpoints = $backendCfg['endpoints'] ?? [];
            foreach ($keys as $k) {
                if (!empty($endpoints[$k]) && is_string($endpoints[$k])) return $endpoints[$k];
            }
            return null;
        };

        // Render helpers
        $renderProductReply = function(array $products) use ($templates) {
            $products = array_values($products);
            if (count($products) <= 0) return $templates['product_not_found'] ?? 'ตอนนี้ยังไม่เจอในระบบค่ะ 😅';

            if (count($products) === 1) {
                $p = $products[0];
                $tpl = $templates['product_found_one'] ?? 'พบสินค้า {{name}} ({{code}}) ราคา {{price}} บาท';
                return $this->renderTemplate($tpl, [
                    'name' => $p['name'] ?? ($p['title'] ?? 'สินค้า'),
                    'code' => $p['sku'] ?? ($p['code'] ?? ($p['product_code'] ?? '')),
                    'price' => $p['price'] ?? ($p['selling_price'] ?? ''),
                    'condition' => $p['condition'] ?? ($p['status'] ?? ''),
                ]);
            }

            $lines = [];
            $i = 1;
            foreach ($products as $p) {
                $name = $p['name'] ?? ($p['title'] ?? 'สินค้า');
                $code = $p['sku'] ?? ($p['code'] ?? ($p['product_code'] ?? ''));
                $price = $p['price'] ?? ($p['selling_price'] ?? '');
                $lines[] = "{$i}) {$name}" . ($code ? " (รหัส {$code})" : "") . ($price !== '' ? " - {$price} บาท" : "");
                $i++;
                if ($i > 5) break;
            }

            $tpl = $templates['product_found_many'] ?? "พบหลายรายการ:\n{{list}}\nพิมพ์เลือกเลข 1-{{n}} ได้เลยค่ะ";
            return $this->renderTemplate($tpl, [
                'list' => implode("\n", $lines),
                'n' => min(count($products), 5)
            ]);
        };

        // -------------------------
        // Intent: product_lookup_by_code
        // -------------------------
        if ($intent === 'product_lookup_by_code') {
            $code = trim((string)($slots['product_code'] ?? ''));
            if ($code === '') {
                return ['handled' => false, 'reply_text' => $askProductCode, 'reason' => 'missing_product_code', 'slots' => $slots];
            }

            $endpoint = $ep(['product_search', 'product_get', 'product_lookup']);
            if (!$endpoint) return ['handled' => false, 'reason' => 'missing_endpoint_product_search'];

            $payload = [
                'q' => $code,
                'product_code' => $code,
                'channel_id' => $channelId,
                'external_user_id' => $externalUserId,
            ];

            $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
            if (!$resp['ok']) {
                return ['handled' => false, 'reply_text' => $fallback, 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
            }

            $products = $resp['data']['products'] ?? ($resp['data']['items'] ?? ($resp['data']['candidates'] ?? []));
            if (!is_array($products)) $products = [];

            $rendered = $this->renderProductsFromBackend($products, $templates);

            return [
                'handled' => true,
                'reply_text' => $rendered['text'],
                'actions' => $rendered['actions'] ?? [],
                'reason' => 'backend_product_lookup_by_code',
                'meta' => $resp,
                'slots' => $slots
            ];
        }

        // -------------------------
        // Intent: product_availability / price_inquiry
        // -------------------------
        if ($intent === 'product_availability' || $intent === 'price_inquiry') {
            $name = trim((string)($slots['product_name'] ?? ''));
            if ($name === '') {
                // Use fallback template instead of non-existent product_availability template
                $tpl = $templates['fallback'] ?? 'ลูกค้าสนใจสินค้าไหมค่ะ 😊 ช่วยอธิบายรายละเอียดเพิ่มอีกนิดทีค่ะ เช่น รุ่น/รหัส';
                return ['handled' => false, 'reply_text' => $tpl, 'reason' => 'missing_product_name', 'slots' => $slots];
            }

            $endpoint = $ep(['product_search']);
            if (!$endpoint) return ['handled' => false, 'reason' => 'missing_endpoint_product_search'];

            // Build payload with attributes from slots
            $payload = [
                'q' => $name,
                'product_name' => $name,
                'channel_id' => $channelId,
                'external_user_id' => $externalUserId,
            ];
            
            // Extract attributes from slots (color, brand, etc.)
            $attributes = [];
            if (!empty($slots['color'])) {
                // Map Thai colors to English
                $colorMap = [
                    'ดำ' => 'black', 'สีดำ' => 'black',
                    'น้ำเงิน' => 'blue', 'สีน้ำเงิน' => 'blue',
                    'เงิน' => 'silver', 'สีเงิน' => 'silver',
                    'ทอง' => 'gold', 'สีทอง' => 'gold',
                    'ขาว' => 'white', 'สีขาว' => 'white',
                    'เขียว' => 'green', 'สีเขียว' => 'green',
                    'แดง' => 'red', 'สีแดง' => 'red',
                    'ชมพู' => 'pink', 'สีชมพู' => 'pink',
                ];
                $colorValue = mb_strtolower(trim($slots['color']), 'UTF-8');
                $attributes['color'] = $colorMap[$colorValue] ?? $colorValue;
            }
            if (!empty($slots['gender'])) {
                $attributes['gender'] = $slots['gender'];
            }
            if (!empty($attributes)) {
                $payload['attributes'] = $attributes;
            }
            
            // Price range from budget slot
            if (!empty($slots['budget'])) {
                $budget = (int)preg_replace('/[^0-9]/', '', $slots['budget']);
                if ($budget > 0) {
                    $payload['max_price'] = $budget;
                }
            }

            $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
            if (!$resp['ok']) {
                return ['handled' => false, 'reply_text' => $fallback, 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
            }

            $products = $resp['data']['products'] ?? ($resp['data']['items'] ?? ($resp['data']['candidates'] ?? []));
            if (!is_array($products)) $products = [];

            $rendered = $this->renderProductsFromBackend($products, $templates);
            
            return [
                'handled' => true,
                'reply_text' => $rendered['text'],
                'actions' => $rendered['actions'] ?? [],
                'reason' => 'backend_product_search',
                'meta' => $resp,
                'slots' => $slots
            ];
        }

        // -------------------------
        // Intent: payment_slip_verify
        // -------------------------
        if ($intent === 'payment_slip_verify') {
            $endpoint = $ep(['receipt_get', 'payment_verify']);
            if (!$endpoint) return ['handled' => false, 'reason' => 'missing_endpoint_payment_verify'];

            $amount = trim((string)($slots['amount'] ?? ''));
            $time   = trim((string)($slots['time'] ?? ''));
            $sender = trim((string)($slots['sender_name'] ?? ''));
            $paymentRef = trim((string)($slots['payment_ref'] ?? ''));
            $bank = trim((string)($slots['bank'] ?? ''));

            $slipImageUrl = $extra['slip_image_url'] ?? null;
            if (!$slipImageUrl) $slipImageUrl = $context['message']['attachments'][0]['url'] ?? null;
            
            $visionText = $extra['vision_text'] ?? null;
            $geminiDetails = $extra['gemini_details'] ?? [];

            if ($amount === '' && $time === '' && $sender === '' && $paymentRef === '' && !$slipImageUrl) {
                return ['handled' => false, 'reply_text' => $askSlipMissing, 'reason' => 'missing_slip_info', 'slots' => $slots];
            }

            // ✅ Build comprehensive payload with customer context for auto-matching
            $payload = [
                'channel_id' => $channelId,
                'external_user_id' => $externalUserId,
                'customer_id' => $context['customer']['id'] ?? null,
                'customer_profile_id' => $context['customer']['profile_id'] ?? ($context['customer_profile_id'] ?? null),
                'customer_name' => $context['customer']['name'] ?? ($context['customer']['display_name'] ?? null),
                'customer_phone' => $context['customer']['phone'] ?? null,
                'customer_platform' => $context['channel']['platform'] ?? null,
                'customer_avatar' => $context['customer']['avatar'] ?? null,
                'amount' => $amount ?: null,
                'time' => $time ?: null,
                'sender_name' => $sender ?: null,
                'payment_ref' => $paymentRef ?: null,
                'bank' => $bank ?: null,
                'slip_image_url' => $slipImageUrl ?: null,
                'vision_text' => $visionText,
                'gemini_details' => $geminiDetails,
                'note' => 'customer_reported_payment_via_chat'
            ];

            $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
            if (!$resp['ok']) {
                return ['handled' => false, 'reply_text' => $templates['payment_verify_pending'] ?? $fallback, 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
            }

            $status = $resp['data']['status'] ?? null;
            $paymentNo = $resp['data']['payment_no'] ?? null;
            $matchedOrderNo = $resp['data']['matched_order_no'] ?? null;
            
            if ($status === 'ok' || $status === 'paid' || $status === 'matched') {
                $tpl = $templates['payment_verify_ok'] ?? 'ตรวจสอบแล้วค่ะ ✅ ยอดเข้าเรียบร้อย ขอบคุณมากนะคะ 🙏';
                return ['handled' => true, 'reply_text' => $tpl, 'reason' => 'backend_payment_ok', 'meta' => $resp, 'slots' => $slots];
            }

            // ✅ Build informative pending message
            $pendingMsg = $templates['payment_verify_pending'] ?? 'ได้รับข้อมูลแล้วค่ะ 😊 บันทึกเข้าระบบให้แล้ว รอเจ้าหน้าที่ตรวจสอบนะคะ';
            if ($paymentNo) {
                $pendingMsg .= "\n📋 เลขอ้างอิง: {$paymentNo}";
            }
            if ($matchedOrderNo) {
                $pendingMsg .= "\n📦 จับคู่กับออเดอร์: #{$matchedOrderNo}";
            }
            
            return ['handled' => true, 'reply_text' => $pendingMsg, 'reason' => 'backend_payment_pending', 'meta' => $resp, 'slots' => $slots];
        }

        // -------------------------
        // Intent: installment_flow
        // -------------------------
        if ($intent === 'installment_flow') {
            $action = trim((string)($slots['action_type'] ?? ''));
            if ($action === '') {
                $tpl = $templates['installment_choose_action']
                    ?? 'ต้องการ “ชำระงวด / ต่อดอก / ปิดยอด” แบบไหนคะ 😊 (พิมพ์คำที่ต้องการได้เลยค่ะ)';
                return ['handled' => false, 'reply_text' => $tpl, 'reason' => 'missing_action_type', 'slots' => $slots];
            }

            $installmentId = trim((string)($slots['installment_id'] ?? ''));
            $phone = trim((string)($slots['customer_phone'] ?? ''));

            $wantSummary = in_array($action, ['summary', 'check', 'status', 'close_check', 'pay_check'], true);

            $endpointGet = $ep(['installment_get']);
            $endpointPay = $ep(['installment_payment_upsert']);

            if ($wantSummary && $endpointGet) {
                if ($installmentId === '' && $phone === '') {
                    return ['handled' => false, 'reply_text' => $askInstallment, 'reason' => 'missing_installment_id_or_phone', 'slots' => $slots];
                }

                $payload = [
                    'channel_id' => $channelId,
                    'external_user_id' => $externalUserId,
                    'installment_id' => $installmentId ?: null,
                    'customer_phone' => $phone ?: null
                ];


                $resp = $this->callBackendJson($backendCfg, $endpointGet, $payload);
                if (!$resp['ok']) {
                    return ['handled' => false, 'reply_text' => $templates['payment_verify_pending'] ?? $fallback, 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
                }


                $dueAmount = $resp['data']['due_amount'] ?? ($resp['data']['balance'] ?? '');
                $nextDue = $resp['data']['next_due_date'] ?? ($resp['data']['next_date'] ?? '');
                $realId = $resp['data']['installment_id'] ?? $installmentId;

                $tpl = $templates['installment_summary'] ?? "สรุปผ่อนให้ค่ะ 😊\nสัญญา: {{installment_id}}\nยอดค้าง: {{due_amount}} บาท\nงวดถัดไป: {{next_due_date}}\nต้องการ “ชำระงวด/ต่อดอก/ปิดยอด” แบบไหนคะ?";
                $reply = $this->renderTemplate($tpl, [
                    'installment_id' => $realId,
                    'due_amount' => $dueAmount,
                    'next_due_date' => $nextDue
                ]);

                $slots['installment_id'] = $realId ?: $slots['installment_id'];

                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_installment_get', 'meta' => $resp, 'slots' => $slots];
            }

            if ($endpointPay) {
                $amount = trim((string)($slots['amount'] ?? ''));
                $time   = trim((string)($slots['time'] ?? ''));
                $sender = trim((string)($slots['sender_name'] ?? ''));

                $slipImageUrl = $extra['slip_image_url'] ?? null;
                if (!$slipImageUrl) $slipImageUrl = $context['message']['attachments'][0]['url'] ?? null;

                if (($installmentId === '' && $phone === '') || $amount === '' || $time === '' || $sender === '') {
                    return ['handled' => false, 'reply_text' => $askInstallment, 'reason' => 'missing_installment_payment_fields', 'slots' => $slots];
                }

                $payload = [
                    'channel_id' => $channelId,
                    'external_user_id' => $externalUserId,
                    'installment_id' => $installmentId ?: null,
                    'customer_phone' => $phone ?: null,
                    'action_type' => $action,
                    'amount' => $amount,
                    'time' => $time,
                    'sender_name' => $sender,
                    'slip_image_url' => $slipImageUrl ?: null,
                    'note' => 'installment_payment_reported_via_chat_pending_staff_review'
                ];

                $resp = $this->callBackendJson($backendCfg, $endpointPay, $payload);
                if (!$resp['ok']) {
                    return ['handled' => false, 'reply_text' => $templates['payment_verify_pending'] ?? $fallback, 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
                }

                $tpl = $templates['installment_payment_pending']
                    ?? 'รับทราบค่ะ ✅ บันทึกข้อมูลการชำระงวดให้แล้วนะคะ ตอนนี้สถานะ “รอตรวจโดยเจ้าหน้าที่” ค่ะ 🙏';
                return ['handled' => true, 'reply_text' => $tpl, 'reason' => 'backend_installment_payment_upsert', 'meta' => $resp, 'slots' => $slots];
            }

            return ['handled' => false, 'reply_text' => $askInstallment, 'reason' => 'no_installment_endpoints', 'slots' => $slots];
        }

        // -------------------------
        // Intent: order_status
        // -------------------------
        if ($intent === 'order_status') {
            $endpoint = $ep(['order_status']);
            if (!$endpoint) return ['handled' => false, 'reason' => 'missing_endpoint_order_status'];

            $orderId = trim((string)($slots['order_id'] ?? ''));
            $phone   = trim((string)($slots['customer_phone'] ?? ''));
            if ($orderId === '' && $phone === '') {
                $tpl = $templates['ask_order_status']
                    ?? 'รบกวนแจ้ง “เลขออเดอร์/ชื่อ-เบอร์โทร” เพื่อเช็คสถานะจัดส่งให้ค่ะ 😊';
                return ['handled' => false, 'reply_text' => $tpl, 'reason' => 'missing_order_id_or_phone', 'slots' => $slots];
            }

            $payload = [
                'channel_id' => $channelId,
                'external_user_id' => $externalUserId,
                'order_id' => $orderId ?: null,
                'customer_phone' => $phone ?: null
            ];

            $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
            if (!$resp['ok']) {
                return ['handled' => false, 'reply_text' => $fallback, 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
            }

            $status = $resp['data']['status'] ?? 'กำลังตรวจสอบ';
            $tracking = $resp['data']['tracking_no'] ?? ($resp['data']['tracking'] ?? '');
            $carrier = $resp['data']['carrier'] ?? '';

            $tpl = $templates['order_status_reply']
                ?? 'เช็คให้แล้วค่ะ ✅ สถานะ: {{status}}' . ($tracking ? "\nเลขพัสดุ: {{tracking}}" : '') . ($carrier ? "\nขนส่ง: {{carrier}}" : '');
            $reply = $this->renderTemplate($tpl, [
                'status' => $status,
                'tracking' => $tracking,
                'carrier' => $carrier
            ]);

            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_order_status', 'meta' => $resp, 'slots' => $slots];
        }

        // -------------------------
        // Intent: savings_new / savings_deposit / savings_inquiry
        // -------------------------
        if (in_array($intent, ['savings_new', 'savings_deposit', 'savings_inquiry'])) {
            $actionType = null;
            if ($intent === 'savings_new') $actionType = 'new';
            elseif ($intent === 'savings_deposit') $actionType = 'deposit';
            elseif ($intent === 'savings_inquiry') $actionType = 'inquiry';
            
            // Get action_type from slots if provided
            if (!empty($slots['action_type'])) {
                $actionType = $slots['action_type'];
            }
            
            $askSavingsProduct = $templates['ask_savings_product'] ?? 'สนใจออมสินค้าตัวไหนคะ? 🎁 ส่งชื่อหรือรหัสสินค้ามาได้เลยค่ะ';
            $askSlipMissing = $templates['ask_slip_missing'] ?? 'รบกวนส่งรูปสลิปการโอนด้วยนะคะ 📷';
            
            // Handle savings_new
            if ($actionType === 'new') {
                $productRefId = trim((string)($slots['product_ref_id'] ?? ''));
                $productName = trim((string)($slots['product_name'] ?? ''));
                
                if ($productRefId === '' && $productName === '') {
                    return ['handled' => false, 'reply_text' => $askSavingsProduct, 'reason' => 'missing_product_for_savings', 'slots' => $slots];
                }
                
                $endpoint = $ep(['savings_create']);
                if (!$endpoint) return ['handled' => false, 'reason' => 'missing_endpoint_savings_create'];
                
                $payload = [
                    'channel_id' => $channelId,
                    'external_user_id' => $externalUserId,
                    'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? 'unknown'),
                    'product_ref_id' => $productRefId ?: null,
                    'product_name' => $productName ?: 'Unknown Product',
                    'product_price' => (float)($slots['product_price'] ?? ($slots['target_amount'] ?? 0))
                ];
                
                $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
                if (!$resp['ok']) {
                    return ['handled' => false, 'reply_text' => $templates['fallback'] ?? 'ขออภัยค่ะ มีปัญหาในการเปิดบัญชีออม', 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
                }
                
                $data = $resp['data'] ?? [];
                $tpl = $templates['savings_created'] ?? "เปิดบัญชีออมให้แล้วค่ะ ✅\nสินค้า: {{product_name}}\nเป้าหมาย: {{target_amount}} บาท\nสินค้ากันไว้ให้แล้วนะคะ 🎯";
                $reply = $this->renderTemplate($tpl, [
                    'product_name' => $data['product_name'] ?? $productName,
                    'target_amount' => number_format((float)($data['target_amount'] ?? 0)),
                    'account_no' => $data['account_no'] ?? ''
                ]);
                
                $slots['savings_id'] = $data['id'] ?? null;
                $slots['savings_account_no'] = $data['account_no'] ?? null;
                
                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_savings_created', 'meta' => $resp, 'slots' => $slots];
            }
            
            // Handle savings_deposit
            if ($actionType === 'deposit') {
                $savingsId = trim((string)($slots['savings_id'] ?? ($slots['savings_account_id'] ?? '')));
                $slipImageUrl = $extra['slip_image_url'] ?? ($context['message']['attachments'][0]['url'] ?? null);
                
                // Try to find savings account if not provided
                if ($savingsId === '') {
                    $existingSavings = $this->db->queryOne(
                        "SELECT id FROM savings_accounts WHERE channel_id = ? AND external_user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1",
                        [$channelId, $externalUserId]
                    );
                    if ($existingSavings) {
                        $savingsId = (string)$existingSavings['id'];
                        $slots['savings_id'] = $savingsId;
                    }
                }
                
                if ($savingsId === '') {
                    return ['handled' => false, 'reply_text' => 'ไม่พบบัญชีออมที่เปิดไว้ค่ะ ต้องการเปิดบัญชีออมใหม่ไหมคะ? 🏦', 'reason' => 'no_savings_account', 'slots' => $slots];
                }
                
                if (!$slipImageUrl) {
                    return ['handled' => false, 'reply_text' => $askSlipMissing, 'reason' => 'missing_slip_image', 'slots' => $slots];
                }
                
                $endpoint = $ep(['savings_deposit']);
                if (!$endpoint) return ['handled' => false, 'reason' => 'missing_endpoint_savings_deposit'];
                
                // Replace {id} placeholder in endpoint
                $endpoint = str_replace('{id}', $savingsId, $endpoint);
                
                $payload = [
                    'amount' => (float)($slots['amount'] ?? 0),
                    'slip_image_url' => $slipImageUrl,
                    'payment_time' => $slots['time'] ?? null,
                    'sender_name' => $slots['sender_name'] ?? null
                ];
                
                $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
                if (!$resp['ok']) {
                    return ['handled' => false, 'reply_text' => $templates['payment_verify_pending'] ?? 'บันทึกยอดฝากให้แล้วค่ะ รอเจ้าหน้าที่ตรวจสอบนะคะ', 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
                }
                
                $tpl = $templates['savings_deposit_pending'] ?? 'ได้รับยอดฝากแล้วค่ะ 💰 รอเจ้าหน้าที่ตรวจสอบนะคะ';
                return ['handled' => true, 'reply_text' => $tpl, 'reason' => 'backend_savings_deposit', 'meta' => $resp, 'slots' => $slots];
            }
            
            // Handle savings_inquiry
            if ($actionType === 'inquiry') {
                $savingsId = trim((string)($slots['savings_id'] ?? ($slots['savings_account_id'] ?? '')));
                
                // Try to find savings account if not provided
                if ($savingsId === '') {
                    $existingSavings = $this->db->queryAll(
                        "SELECT * FROM savings_accounts WHERE channel_id = ? AND external_user_id = ? AND status = 'active' ORDER BY created_at DESC",
                        [$channelId, $externalUserId]
                    );
                    
                    if (empty($existingSavings)) {
                        return ['handled' => true, 'reply_text' => 'ไม่มีบัญชีออมที่กำลังดำเนินการอยู่ค่ะ 📭', 'reason' => 'no_savings_account', 'slots' => $slots];
                    }
                    
                    // Format multiple savings accounts
                    if (count($existingSavings) === 1) {
                        $sa = $existingSavings[0];
                        $current = (float)$sa['current_amount'];
                        $target = (float)$sa['target_amount'];
                        $remaining = $target - $current;
                        $progress = $target > 0 ? round(($current / $target) * 100) : 0;
                        
                        $tpl = $templates['savings_status'] ?? "ยอดออมปัจจุบัน: {{current_amount}} บาท\nเป้าหมาย: {{target_amount}} บาท\nเหลืออีก: {{remaining}} บาท\nความคืบหน้า: {{progress}}% 📊";
                        $reply = $this->renderTemplate($tpl, [
                            'current_amount' => number_format($current),
                            'target_amount' => number_format($target),
                            'remaining' => number_format($remaining),
                            'progress' => $progress
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'savings_inquiry_single', 'slots' => $slots];
                    }
                    
                    // Multiple accounts
                    $lines = [];
                    foreach ($existingSavings as $i => $sa) {
                        $current = (float)$sa['current_amount'];
                        $target = (float)$sa['target_amount'];
                        $progress = $target > 0 ? round(($current / $target) * 100) : 0;
                        $lines[] = ($i + 1) . ") {$sa['product_name']}: " . number_format($current) . "/" . number_format($target) . " บาท ({$progress}%)";
                    }
                    
                    $reply = "บัญชีออมที่มีค่ะ 📋\n" . implode("\n", $lines);
                    return ['handled' => true, 'reply_text' => $reply, 'reason' => 'savings_inquiry_multiple', 'slots' => $slots];
                }
                
                // Get specific savings account
                $endpoint = $ep(['savings_status']);
                if ($endpoint) {
                    $endpoint = str_replace('{id}', $savingsId, $endpoint);
                    $resp = $this->callBackendJson($backendCfg, $endpoint, []);
                    
                    if ($resp['ok'] && !empty($resp['data'])) {
                        $sa = $resp['data'];
                        $current = (float)($sa['current_amount'] ?? 0);
                        $target = (float)($sa['target_amount'] ?? 0);
                        $remaining = $target - $current;
                        $progress = $target > 0 ? round(($current / $target) * 100) : 0;
                        
                        $tpl = $templates['savings_status'] ?? "ยอดออมปัจจุบัน: {{current_amount}} บาท\nเป้าหมาย: {{target_amount}} บาท\nเหลืออีก: {{remaining}} บาท\nความคืบหน้า: {{progress}}% 📊";
                        $reply = $this->renderTemplate($tpl, [
                            'current_amount' => number_format($current),
                            'target_amount' => number_format($target),
                            'remaining' => number_format($remaining),
                            'progress' => $progress
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_savings_status', 'meta' => $resp, 'slots' => $slots];
                    }
                }
                
                return ['handled' => false, 'reply_text' => 'ไม่พบข้อมูลบัญชีออมค่ะ 😅', 'reason' => 'savings_not_found', 'slots' => $slots];
            }
            
            // Default: ask what action they want
            $tpl = $templates['savings_choose_action'] ?? 'ต้องการ "เปิดออมใหม่ / ฝากเงิน / เช็คยอด" แบบไหนคะ 😊';
            return ['handled' => false, 'reply_text' => $tpl, 'reason' => 'missing_savings_action_type', 'slots' => $slots];
        }

        // -------------------------
        // Intent: deposit_new / deposit_payment / deposit_inquiry (มัดจำ)
        // -------------------------
        if (in_array($intent, ['deposit_new', 'deposit_payment', 'deposit_inquiry'])) {
            $actionType = null;
            if ($intent === 'deposit_new') $actionType = 'new';
            elseif ($intent === 'deposit_payment') $actionType = 'pay';
            elseif ($intent === 'deposit_inquiry') $actionType = 'inquiry';
            
            if (!empty($slots['action_type'])) {
                $actionType = $slots['action_type'];
            }
            
            $askProductForDeposit = $templates['ask_product_for_deposit'] ?? 'สนใจมัดจำสินค้าตัวไหนคะ? 🎁 ส่งชื่อหรือรหัสสินค้ามาได้เลยค่ะ';
            $askDepositSlip = $templates['ask_deposit_slip'] ?? 'รบกวนส่งรูปสลิปโอนมัดจำด้วยนะคะ 📷';
            
            // Handle deposit_new
            if ($actionType === 'new') {
                $productRefId = trim((string)($slots['product_ref_id'] ?? ''));
                $productName = trim((string)($slots['product_name'] ?? ''));
                
                if ($productRefId === '' && $productName === '') {
                    return ['handled' => false, 'reply_text' => $askProductForDeposit, 'reason' => 'missing_product_for_deposit', 'slots' => $slots];
                }
                
                $endpoint = $ep(['deposit_create']);
                if (!$endpoint) return ['handled' => false, 'reason' => 'missing_endpoint_deposit_create'];
                
                $payload = [
                    'channel_id' => $channelId,
                    'external_user_id' => $externalUserId,
                    'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? 'unknown'),
                    'product_ref_id' => $productRefId ?: null,
                    'product_name' => $productName ?: null,
                    'product_price' => (float)($slots['product_price'] ?? 0),
                    'deposit_percentage' => 10 // 10% มัดจำ
                ];
                
                $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
                if (!$resp['ok']) {
                    return ['handled' => false, 'reply_text' => $templates['fallback'] ?? 'ขออภัยค่ะ มีปัญหาในการสร้างรายการมัดจำ', 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
                }
                
                $data = $resp['data'] ?? [];
                $tpl = $templates['deposit_created'] ?? "กันสินค้าให้แล้วค่ะ 🎯\nรหัส: {{deposit_no}}\nสินค้า: {{product_name}}\nราคาสินค้า: {{product_price}} บาท\nยอดมัดจำ: {{deposit_amount}} บาท (10%)\n\nโอนได้ที่:\nSCB: 1653014242 (บจก.เพชรวิบวับ)\nแล้วส่งสลิปมาได้เลยนะคะ 💳";
                $reply = $this->renderTemplate($tpl, [
                    'deposit_no' => $data['deposit_no'] ?? '',
                    'product_name' => $data['product_name'] ?? $productName,
                    'product_price' => number_format((float)($data['product_price'] ?? 0)),
                    'deposit_amount' => number_format((float)($data['deposit_amount'] ?? 0))
                ]);
                
                $slots['deposit_id'] = $data['id'] ?? null;
                $slots['deposit_no'] = $data['deposit_no'] ?? null;
                
                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_deposit_created', 'meta' => $resp, 'slots' => $slots];
            }
            
            // Handle deposit_payment
            if ($actionType === 'pay') {
                $depositId = trim((string)($slots['deposit_id'] ?? ''));
                $slipImageUrl = $extra['slip_image_url'] ?? ($context['message']['attachments'][0]['url'] ?? null);
                
                // Try to find deposit if not provided
                if ($depositId === '') {
                    $existingDeposit = $this->db->queryOne(
                        "SELECT id FROM deposits WHERE channel_id = ? AND external_user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1",
                        [$channelId, $externalUserId]
                    );
                    if ($existingDeposit) {
                        $depositId = (string)$existingDeposit['id'];
                        $slots['deposit_id'] = $depositId;
                    }
                }
                
                if ($depositId === '') {
                    return ['handled' => false, 'reply_text' => 'ไม่พบรายการมัดจำที่รอชำระค่ะ ต้องการมัดจำสินค้าใหม่ไหมคะ? 🛍️', 'reason' => 'no_pending_deposit', 'slots' => $slots];
                }
                
                if (!$slipImageUrl) {
                    return ['handled' => false, 'reply_text' => $askDepositSlip, 'reason' => 'missing_deposit_slip', 'slots' => $slots];
                }
                
                $endpoint = $ep(['deposit_pay']);
                if (!$endpoint) return ['handled' => false, 'reason' => 'missing_endpoint_deposit_pay'];
                
                $endpoint = str_replace('{id}', $depositId, $endpoint);
                
                $payload = [
                    'slip_image_url' => $slipImageUrl,
                    'amount' => (float)($slots['amount'] ?? 0),
                    'payment_time' => $slots['time'] ?? null,
                    'sender_name' => $slots['sender_name'] ?? null
                ];
                
                $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
                if (!$resp['ok']) {
                    return ['handled' => false, 'reply_text' => $templates['deposit_payment_pending'] ?? 'บันทึกการโอนมัดจำให้แล้วค่ะ รอเจ้าหน้าที่ตรวจสอบนะคะ 🙏', 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
                }
                
                $tpl = $templates['deposit_payment_received'] ?? 'ได้รับสลิปมัดจำแล้วค่ะ ✅ รอเจ้าหน้าที่ตรวจสอบนะคะ สินค้ากันไว้ให้แล้วค่ะ 🎁';
                return ['handled' => true, 'reply_text' => $tpl, 'reason' => 'backend_deposit_payment', 'meta' => $resp, 'slots' => $slots];
            }
            
            // Handle deposit_inquiry
            if ($actionType === 'inquiry') {
                $depositId = trim((string)($slots['deposit_id'] ?? ''));
                
                if ($depositId === '') {
                    $deposits = $this->db->queryAll(
                        "SELECT * FROM deposits WHERE channel_id = ? AND external_user_id = ? AND status IN ('pending', 'paid') ORDER BY created_at DESC",
                        [$channelId, $externalUserId]
                    );
                    
                    if (empty($deposits)) {
                        return ['handled' => true, 'reply_text' => 'ไม่มีรายการมัดจำที่กำลังดำเนินการอยู่ค่ะ 📭', 'reason' => 'no_deposits', 'slots' => $slots];
                    }
                    
                    if (count($deposits) === 1) {
                        $d = $deposits[0];
                        $tpl = $templates['deposit_status'] ?? "รายการมัดจำ {{deposit_no}}\nสินค้า: {{product_name}}\nยอดมัดจำ: {{deposit_amount}} บาท\nสถานะ: {{status}}\nหมดอายุ: {{expires_at}} 📅";
                        $reply = $this->renderTemplate($tpl, [
                            'deposit_no' => $d['deposit_no'] ?? '',
                            'product_name' => $d['product_name'] ?? '',
                            'deposit_amount' => number_format((float)($d['deposit_amount'] ?? 0)),
                            'status' => $d['status'] === 'pending' ? 'รอชำระ' : ($d['status'] === 'paid' ? 'ชำระแล้ว' : $d['status']),
                            'expires_at' => $d['expires_at'] ?? '-'
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'deposit_inquiry_single', 'slots' => $slots];
                    }
                    
                    $lines = [];
                    foreach ($deposits as $i => $d) {
                        $statusTh = $d['status'] === 'pending' ? 'รอชำระ' : ($d['status'] === 'paid' ? 'ชำระแล้ว' : $d['status']);
                        $lines[] = ($i + 1) . ") {$d['product_name']}: " . number_format((float)($d['deposit_amount'] ?? 0)) . " บ. ({$statusTh})";
                    }
                    
                    $reply = "รายการมัดจำค่ะ 📋\n" . implode("\n", $lines);
                    return ['handled' => true, 'reply_text' => $reply, 'reason' => 'deposit_inquiry_multiple', 'slots' => $slots];
                }
                
                $endpoint = $ep(['deposit_status']);
                if ($endpoint) {
                    $endpoint = str_replace('{id}', $depositId, $endpoint);
                    $resp = $this->callBackendJson($backendCfg, $endpoint, []);
                    
                    if ($resp['ok'] && !empty($resp['data'])) {
                        $d = $resp['data'];
                        $tpl = $templates['deposit_status'] ?? "รายการมัดจำ {{deposit_no}}\nสินค้า: {{product_name}}\nยอดมัดจำ: {{deposit_amount}} บาท\nสถานะ: {{status}} 📅";
                        $reply = $this->renderTemplate($tpl, [
                            'deposit_no' => $d['deposit_no'] ?? '',
                            'product_name' => $d['product_name'] ?? '',
                            'deposit_amount' => number_format((float)($d['deposit_amount'] ?? 0)),
                            'status' => $d['status_display'] ?? $d['status']
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_deposit_status', 'meta' => $resp, 'slots' => $slots];
                    }
                }
                
                return ['handled' => false, 'reply_text' => 'ไม่พบข้อมูลมัดจำค่ะ 😅', 'reason' => 'deposit_not_found', 'slots' => $slots];
            }
            
            $tpl = $templates['deposit_choose_action'] ?? 'ต้องการ "มัดจำใหม่ / ส่งสลิป / เช็คสถานะ" แบบไหนคะ 😊';
            return ['handled' => false, 'reply_text' => $tpl, 'reason' => 'missing_deposit_action_type', 'slots' => $slots];
        }

        // -------------------------
        // Intent: pawn_new / pawn_pay_interest / pawn_redeem / pawn_inquiry (ฝากจำนำ)
        // -------------------------
        if (in_array($intent, ['pawn_new', 'pawn_pay_interest', 'pawn_redeem', 'pawn_inquiry'])) {
            $actionType = null;
            if ($intent === 'pawn_new') $actionType = 'new';
            elseif ($intent === 'pawn_pay_interest') $actionType = 'pay_interest';
            elseif ($intent === 'pawn_redeem') $actionType = 'redeem';
            elseif ($intent === 'pawn_inquiry') $actionType = 'inquiry';
            
            if (!empty($slots['action_type'])) {
                $actionType = $slots['action_type'];
            }
            
            $askPawnItem = $templates['ask_pawn_item'] ?? 'ต้องการจำนำสินค้าชิ้นไหนคะ? 💎 บอกรายละเอียดได้เลยค่ะ';
            $askPawnInterestSlip = $templates['ask_pawn_interest_slip'] ?? 'รบกวนส่งรูปสลิปชำระดอกเบี้ยด้วยนะคะ 📷';
            
            // Handle pawn_new - ต้องส่งต่อแอดมิน (ต้องประเมินของ)
            if ($actionType === 'new') {
                $itemDesc = trim((string)($slots['item_description'] ?? ($slots['product_name'] ?? '')));
                
                if ($itemDesc === '') {
                    return ['handled' => false, 'reply_text' => $askPawnItem, 'reason' => 'missing_pawn_item', 'slots' => $slots];
                }
                
                // Pawn ต้อง handoff to admin เพราะต้องประเมินราคา
                $tpl = $templates['pawn_handoff'] ?? "รับทราบค่ะ ต้องการจำนำ {{item_description}} 💎\nรบกวนส่งรูปสินค้ามาให้ตรวจสอบด้วยนะคะ\nเจ้าหน้าที่จะติดต่อกลับเพื่อประเมินราคาค่ะ ✨";
                $reply = $this->renderTemplate($tpl, [
                    'item_description' => $itemDesc
                ]);
                
                // Create case for admin follow-up
                $this->db->execute(
                    "INSERT INTO cases (channel_id, external_user_id, case_type, status, subject, description, priority) VALUES (?, ?, 'pawn', 'open', ?, ?, 'high')",
                    [$channelId, $externalUserId, "ลูกค้าต้องการจำนำ: {$itemDesc}", $itemDesc]
                );
                
                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'pawn_handoff_to_admin', 'handoff' => true, 'slots' => $slots];
            }
            
            // Handle pawn_pay_interest (ต่อดอก)
            if ($actionType === 'pay_interest') {
                $pawnId = trim((string)($slots['pawn_id'] ?? ''));
                $slipImageUrl = $extra['slip_image_url'] ?? ($context['message']['attachments'][0]['url'] ?? null);
                
                // Try to find active pawn if not provided
                if ($pawnId === '') {
                    $existingPawn = $this->db->queryOne(
                        "SELECT id FROM pawns WHERE channel_id = ? AND external_user_id = ? AND status = 'active' ORDER BY next_interest_due ASC LIMIT 1",
                        [$channelId, $externalUserId]
                    );
                    if ($existingPawn) {
                        $pawnId = (string)$existingPawn['id'];
                        $slots['pawn_id'] = $pawnId;
                    }
                }
                
                if ($pawnId === '') {
                    return ['handled' => false, 'reply_text' => 'ไม่พบรายการจำนำที่ต้องต่อดอกค่ะ 📭', 'reason' => 'no_active_pawn', 'slots' => $slots];
                }
                
                if (!$slipImageUrl) {
                    // Get interest amount first
                    $pawnData = $this->db->queryOne("SELECT * FROM pawns WHERE id = ?", [$pawnId]);
                    if ($pawnData) {
                        $interestAmount = (float)$pawnData['principal_amount'] * ((float)$pawnData['interest_rate_percent'] / 100);
                        $tpl = $templates['pawn_interest_info'] ?? "ยอดดอกเบี้ยที่ต้องชำระ: {{interest_amount}} บาท\n\nโอนได้ที่:\nSCB: 1653014242 (บจก.เพชรวิบวับ)\nแล้วส่งสลิปมาได้เลยนะคะ 💳";
                        $reply = $this->renderTemplate($tpl, [
                            'interest_amount' => number_format($interestAmount)
                        ]);
                        return ['handled' => false, 'reply_text' => $reply, 'reason' => 'awaiting_pawn_slip', 'slots' => $slots];
                    }
                    return ['handled' => false, 'reply_text' => $askPawnInterestSlip, 'reason' => 'missing_pawn_slip', 'slots' => $slots];
                }
                
                $endpoint = $ep(['pawn_pay_interest']);
                if (!$endpoint) return ['handled' => false, 'reason' => 'missing_endpoint_pawn_pay_interest'];
                
                $endpoint = str_replace('{id}', $pawnId, $endpoint);
                
                $payload = [
                    'slip_image_url' => $slipImageUrl,
                    'amount' => (float)($slots['amount'] ?? 0),
                    'payment_time' => $slots['time'] ?? null,
                    'sender_name' => $slots['sender_name'] ?? null
                ];
                
                $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
                if (!$resp['ok']) {
                    return ['handled' => false, 'reply_text' => $templates['pawn_payment_pending'] ?? 'บันทึกการชำระดอกเบี้ยให้แล้วค่ะ รอเจ้าหน้าที่ตรวจสอบนะคะ 🙏', 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
                }
                
                $data = $resp['data'] ?? [];
                $tpl = $templates['pawn_interest_paid'] ?? "ได้รับการชำระดอกเบี้ยแล้วค่ะ ✅\nงวดถัดไปครบกำหนด: {{next_due_date}}\nขอบคุณค่ะ 🙏";
                $reply = $this->renderTemplate($tpl, [
                    'next_due_date' => $data['next_interest_due'] ?? '-'
                ]);
                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_pawn_interest_paid', 'meta' => $resp, 'slots' => $slots];
            }
            
            // Handle pawn_redeem (ไถ่ถอน)
            if ($actionType === 'redeem') {
                $pawnId = trim((string)($slots['pawn_id'] ?? ''));
                
                if ($pawnId === '') {
                    $existingPawn = $this->db->queryOne(
                        "SELECT * FROM pawns WHERE channel_id = ? AND external_user_id = ? AND status = 'active' ORDER BY next_interest_due ASC LIMIT 1",
                        [$channelId, $externalUserId]
                    );
                    if ($existingPawn) {
                        $pawnId = (string)$existingPawn['id'];
                        $slots['pawn_id'] = $pawnId;
                    }
                }
                
                if ($pawnId === '') {
                    return ['handled' => false, 'reply_text' => 'ไม่พบรายการจำนำที่สามารถไถ่ถอนได้ค่ะ 📭', 'reason' => 'no_active_pawn_redeem', 'slots' => $slots];
                }
                
                // Get redemption amount
                $endpoint = $ep(['pawn_status']);
                if ($endpoint) {
                    $endpoint = str_replace('{id}', $pawnId, $endpoint);
                    $resp = $this->callBackendJson($backendCfg, $endpoint, []);
                    
                    if ($resp['ok'] && !empty($resp['data'])) {
                        $p = $resp['data'];
                        $tpl = $templates['pawn_redeem_info'] ?? "ยอดไถ่ถอนทั้งหมด: {{redemption_amount}} บาท\n(เงินต้น {{principal}} + ดอกเบี้ยค้าง {{outstanding_interest}} บาท)\n\nโอนได้ที่:\nSCB: 1653014242 (บจก.เพชรวิบวับ)\nแล้วแจ้งมาได้เลยนะคะ เจ้าหน้าที่จะนัดวันรับของค่ะ 💎";
                        $reply = $this->renderTemplate($tpl, [
                            'redemption_amount' => number_format((float)($p['redemption_amount'] ?? 0)),
                            'principal' => number_format((float)($p['principal_amount'] ?? 0)),
                            'outstanding_interest' => number_format((float)($p['outstanding_interest'] ?? 0))
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'pawn_redeem_info', 'slots' => $slots];
                    }
                }
                
                return ['handled' => false, 'reply_text' => 'ไม่พบข้อมูลจำนำค่ะ กรุณาติดต่อเจ้าหน้าที่ 🙏', 'reason' => 'pawn_not_found', 'slots' => $slots];
            }
            
            // Handle pawn_inquiry
            if ($actionType === 'inquiry') {
                $pawnId = trim((string)($slots['pawn_id'] ?? ''));
                
                if ($pawnId === '') {
                    $pawns = $this->db->queryAll(
                        "SELECT * FROM pawns WHERE channel_id = ? AND external_user_id = ? AND status IN ('active', 'overdue') ORDER BY next_interest_due ASC",
                        [$channelId, $externalUserId]
                    );
                    
                    if (empty($pawns)) {
                        return ['handled' => true, 'reply_text' => 'ไม่มีรายการจำนำที่กำลังดำเนินการอยู่ค่ะ 📭', 'reason' => 'no_pawns', 'slots' => $slots];
                    }
                    
                    if (count($pawns) === 1) {
                        $p = $pawns[0];
                        $tpl = $templates['pawn_status'] ?? "รายการจำนำ {{pawn_no}}\nสินค้า: {{item_description}}\nเงินต้น: {{principal}} บาท\nดอกเบี้ย: {{interest_rate}}%/เดือน\nครบกำหนดต่อดอก: {{next_due}} 📅";
                        $reply = $this->renderTemplate($tpl, [
                            'pawn_no' => $p['pawn_no'] ?? '',
                            'item_description' => $p['item_description'] ?? '',
                            'principal' => number_format((float)($p['principal_amount'] ?? 0)),
                            'interest_rate' => $p['interest_rate_percent'] ?? '2',
                            'next_due' => $p['next_interest_due'] ?? '-'
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'pawn_inquiry_single', 'slots' => $slots];
                    }
                    
                    $lines = [];
                    foreach ($pawns as $i => $p) {
                        $lines[] = ($i + 1) . ") {$p['item_description']}: " . number_format((float)($p['principal_amount'] ?? 0)) . " บ. (ถึง: {$p['next_interest_due']})";
                    }
                    
                    $reply = "รายการจำนำค่ะ 📋\n" . implode("\n", $lines);
                    return ['handled' => true, 'reply_text' => $reply, 'reason' => 'pawn_inquiry_multiple', 'slots' => $slots];
                }
                
                $endpoint = $ep(['pawn_status']);
                if ($endpoint) {
                    $endpoint = str_replace('{id}', $pawnId, $endpoint);
                    $resp = $this->callBackendJson($backendCfg, $endpoint, []);
                    
                    if ($resp['ok'] && !empty($resp['data'])) {
                        $p = $resp['data'];
                        $tpl = $templates['pawn_status'] ?? "รายการจำนำ {{pawn_no}}\nสินค้า: {{item_description}}\nเงินต้น: {{principal}} บาท\nดอกเบี้ย: {{interest_rate}}%/เดือน\nครบกำหนดต่อดอก: {{next_due}} 📅";
                        $reply = $this->renderTemplate($tpl, [
                            'pawn_no' => $p['pawn_no'] ?? '',
                            'item_description' => $p['item_description'] ?? '',
                            'principal' => number_format((float)($p['principal_amount'] ?? 0)),
                            'interest_rate' => $p['interest_rate_percent'] ?? '2',
                            'next_due' => $p['next_interest_due'] ?? '-'
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_pawn_status', 'meta' => $resp, 'slots' => $slots];
                    }
                }
                
                return ['handled' => false, 'reply_text' => 'ไม่พบข้อมูลจำนำค่ะ 😅', 'reason' => 'pawn_not_found', 'slots' => $slots];
            }
            
            $tpl = $templates['pawn_choose_action'] ?? 'ต้องการ "จำนำใหม่ / ต่อดอก / ไถ่ถอน / เช็คสถานะ" แบบไหนคะ 😊';
            return ['handled' => false, 'reply_text' => $tpl, 'reason' => 'missing_pawn_action_type', 'slots' => $slots];
        }

        // -------------------------
        // Intent: repair_new / repair_inquiry (งานซ่อม)
        // -------------------------
        if (in_array($intent, ['repair_new', 'repair_inquiry'])) {
            $actionType = null;
            if ($intent === 'repair_new') $actionType = 'new';
            elseif ($intent === 'repair_inquiry') $actionType = 'inquiry';
            
            if (!empty($slots['action_type'])) {
                $actionType = $slots['action_type'];
            }
            
            $askRepairItem = $templates['ask_repair_item'] ?? 'ต้องการซ่อมสินค้าชิ้นไหนคะ? 🔧 บอกรายละเอียดอาการเสียได้เลยค่ะ';
            
            // Handle repair_new
            if ($actionType === 'new') {
                $itemDesc = trim((string)($slots['item_description'] ?? ($slots['product_name'] ?? '')));
                $issueDesc = trim((string)($slots['issue_description'] ?? ''));
                
                if ($itemDesc === '' && $issueDesc === '') {
                    return ['handled' => false, 'reply_text' => $askRepairItem, 'reason' => 'missing_repair_item', 'slots' => $slots];
                }
                
                $endpoint = $ep(['repair_create']);
                if (!$endpoint) {
                    // Fallback: create case and handoff
                    $this->db->execute(
                        "INSERT INTO cases (channel_id, external_user_id, case_type, status, subject, description, priority) VALUES (?, ?, 'repair', 'open', ?, ?, 'medium')",
                        [$channelId, $externalUserId, "ลูกค้าต้องการซ่อม: {$itemDesc}", "{$itemDesc}\nอาการ: {$issueDesc}"]
                    );
                    
                    $tpl = $templates['repair_handoff'] ?? "รับทราบค่ะ 🔧\nสินค้า: {{item_description}}\nอาการ: {{issue_description}}\n\nเจ้าหน้าที่จะติดต่อกลับเพื่อนัดรับของและประเมินราคาซ่อมค่ะ ✨";
                    $reply = $this->renderTemplate($tpl, [
                        'item_description' => $itemDesc ?: '-',
                        'issue_description' => $issueDesc ?: '-'
                    ]);
                    return ['handled' => true, 'reply_text' => $reply, 'reason' => 'repair_case_created', 'handoff' => true, 'slots' => $slots];
                }
                
                $payload = [
                    'channel_id' => $channelId,
                    'external_user_id' => $externalUserId,
                    'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? 'unknown'),
                    'item_description' => $itemDesc,
                    'issue_description' => $issueDesc
                ];
                
                $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
                if (!$resp['ok']) {
                    return ['handled' => false, 'reply_text' => $templates['fallback'] ?? 'ขออภัยค่ะ มีปัญหาในการสร้างรายการซ่อม', 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
                }
                
                $data = $resp['data'] ?? [];
                $tpl = $templates['repair_created'] ?? "รับเรื่องซ่อมแล้วค่ะ 🔧\nรหัส: {{repair_no}}\nสินค้า: {{item_description}}\n\nเจ้าหน้าที่จะติดต่อกลับเพื่อนัดรับของค่ะ ✨";
                $reply = $this->renderTemplate($tpl, [
                    'repair_no' => $data['repair_no'] ?? '',
                    'item_description' => $data['item_description'] ?? $itemDesc
                ]);
                
                $slots['repair_id'] = $data['id'] ?? null;
                $slots['repair_no'] = $data['repair_no'] ?? null;
                
                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_repair_created', 'meta' => $resp, 'slots' => $slots];
            }
            
            // Handle repair_inquiry
            if ($actionType === 'inquiry') {
                $repairId = trim((string)($slots['repair_id'] ?? ''));
                
                if ($repairId === '') {
                    $repairs = $this->db->queryAll(
                        "SELECT * FROM repairs WHERE channel_id = ? AND external_user_id = ? AND status NOT IN ('completed', 'cancelled') ORDER BY created_at DESC",
                        [$channelId, $externalUserId]
                    );
                    
                    if (empty($repairs)) {
                        return ['handled' => true, 'reply_text' => 'ไม่มีรายการซ่อมที่กำลังดำเนินการอยู่ค่ะ 📭', 'reason' => 'no_repairs', 'slots' => $slots];
                    }
                    
                    if (count($repairs) === 1) {
                        $r = $repairs[0];
                        $statusMap = [
                            'pending' => 'รอรับของ',
                            'received' => 'รับของแล้ว',
                            'diagnosing' => 'กำลังตรวจสอบ',
                            'quoted' => 'รอลูกค้าอนุมัติ',
                            'approved' => 'กำลังซ่อม',
                            'repairing' => 'กำลังซ่อม',
                            'completed' => 'ซ่อมเสร็จ'
                        ];
                        $tpl = $templates['repair_status'] ?? "รายการซ่อม {{repair_no}}\nสินค้า: {{item_description}}\nสถานะ: {{status}} 🔧";
                        $reply = $this->renderTemplate($tpl, [
                            'repair_no' => $r['repair_no'] ?? '',
                            'item_description' => $r['item_description'] ?? '',
                            'status' => $statusMap[$r['status']] ?? $r['status']
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'repair_inquiry_single', 'slots' => $slots];
                    }
                    
                    $lines = [];
                    $statusMap = ['pending' => 'รอรับของ', 'received' => 'รับแล้ว', 'diagnosing' => 'ตรวจสอบ', 'quoted' => 'รออนุมัติ', 'approved' => 'กำลังซ่อม', 'repairing' => 'กำลังซ่อม'];
                    foreach ($repairs as $i => $r) {
                        $lines[] = ($i + 1) . ") {$r['item_description']}: " . ($statusMap[$r['status']] ?? $r['status']);
                    }
                    
                    $reply = "รายการซ่อมค่ะ 📋\n" . implode("\n", $lines);
                    return ['handled' => true, 'reply_text' => $reply, 'reason' => 'repair_inquiry_multiple', 'slots' => $slots];
                }
                
                $endpoint = $ep(['repair_status']);
                if ($endpoint) {
                    $endpoint = str_replace('{id}', $repairId, $endpoint);
                    $resp = $this->callBackendJson($backendCfg, $endpoint, []);
                    
                    if ($resp['ok'] && !empty($resp['data'])) {
                        $r = $resp['data'];
                        $tpl = $templates['repair_status'] ?? "รายการซ่อม {{repair_no}}\nสินค้า: {{item_description}}\nสถานะ: {{status}} 🔧";
                        $reply = $this->renderTemplate($tpl, [
                            'repair_no' => $r['repair_no'] ?? '',
                            'item_description' => $r['item_description'] ?? '',
                            'status' => $r['status_display'] ?? $r['status']
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_repair_status', 'meta' => $resp, 'slots' => $slots];
                    }
                }
                
                return ['handled' => false, 'reply_text' => 'ไม่พบข้อมูลรายการซ่อมค่ะ 😅', 'reason' => 'repair_not_found', 'slots' => $slots];
            }
            
            $tpl = $templates['repair_choose_action'] ?? 'ต้องการ "ส่งซ่อม / เช็คสถานะ" แบบไหนคะ 😊';
            return ['handled' => false, 'reply_text' => $tpl, 'reason' => 'missing_repair_action_type', 'slots' => $slots];
        }

        return ['handled' => false, 'reason' => 'intent_not_supported'];
    }

    protected function fallbackByIntentTemplate(string $intent, array $templates, string $fallback): string
    {
        switch ($intent) {
            case 'product_lookup_by_code':
                return $templates['ask_product_code'] ?? $fallback;
            case 'payment_slip_verify':
                return $templates['ask_slip_missing'] ?? $fallback;
            case 'installment_flow':
                return $templates['ask_installment_id'] ?? $fallback;
            case 'order_status':
                return $templates['ask_order_status'] ?? $fallback;
            case 'product_availability':
                return $templates['product_availability']
                    ?? 'ลูกค้าสนใจเช็คของใช่ไหมคะ 😊 รบกวนส่ง “ชื่อรุ่น/รหัสสินค้า” หรือส่งรูปสินค้ามาได้เลย เดี๋ยวเช็คให้ค่ะ';
            default:
            // Deposit intents
            case 'deposit_new':
                return $templates['ask_product_for_deposit'] ?? 'สนใจมัดจำสินค้าตัวไหนคะ? 🎁';
            case 'deposit_payment':
                return $templates['ask_deposit_slip'] ?? 'รบกวนส่งรูปสลิปโอนมัดจำด้วยนะคะ 📷';
            case 'deposit_inquiry':
                return $templates['deposit_inquiry'] ?? 'ต้องการเช็คสถานะมัดจำใช่ไหมคะ? 📋';
            // Pawn intents
            case 'pawn_new':
                return $templates['ask_pawn_item'] ?? 'ต้องการจำนำสินค้าชิ้นไหนคะ? 💎';
            case 'pawn_pay_interest':
                return $templates['ask_pawn_interest_slip'] ?? 'รบกวนส่งรูปสลิปชำระดอกเบี้ยด้วยนะคะ 📷';
            case 'pawn_redeem':
                return $templates['pawn_redeem'] ?? 'ต้องการไถ่ถอนสินค้าใช่ไหมคะ? 💎';
            case 'pawn_inquiry':
                return $templates['pawn_inquiry'] ?? 'ต้องการเช็คสถานะจำนำใช่ไหมคะ? 📋';
            // Repair intents
            case 'repair_new':
                return $templates['ask_repair_item'] ?? 'ต้องการซ่อมสินค้าชิ้นไหนคะ? 🔧';
            case 'repair_inquiry':
                return $templates['repair_inquiry'] ?? 'ต้องการเช็คสถานะงานซ่อมใช่ไหมคะ? 🔧';
                return $fallback;
        }
    }

    // =========================================================
    // Image flow wrapper (Vision -> Backend)
    // =========================================================
    protected function handleImageFlow(
        array $context,
        array $config,
        array $templates,
        array $meta,
        ?int $sessionId,
        ?array $googleVision,
        ?array $llmIntegration,
        array $message
    ): array {
        // ✅ FIX: Facebook sends URL in payload.url, LINE sends in url
        $attachment = $message['attachments'][0] ?? null;
        $imageUrl = $attachment['url'] 
            ?? ($attachment['payload']['url'] ?? null);

        $detectedRoute = 'image_generic';
        $visionMeta = null;
        $labels = [];
        $visionText = '';
        $geminiDetails = []; // ✅ Store Gemini extracted details

        // ✅ PRIORITY 1: Use Gemini Multimodal if LLM integration is Gemini
        // Gemini 2.5 Flash can analyze images natively without separate Vision API
        $usedGemini = false;
        
        // Debug: Log conditions for Gemini Vision
        Logger::info("handleImageFlow - Gemini Vision check", [
            'has_llmIntegration' => !empty($llmIntegration),
            'has_imageUrl' => !empty($imageUrl),
            'imageUrl_preview' => $imageUrl ? substr($imageUrl, 0, 100) : null
        ]);
        
        if ($llmIntegration && $imageUrl) {
            $llmConfig = $this->decodeJsonArray($llmIntegration['config'] ?? null);
            $llmEndpoint = $llmConfig['endpoint'] ?? '';
            
            Logger::info("handleImageFlow - LLM config", [
                'llmEndpoint' => $llmEndpoint,
                'is_gemini' => stripos($llmEndpoint, 'generativelanguage.googleapis.com') !== false
            ]);
            
            // Check if LLM is Gemini
            if (stripos($llmEndpoint, 'generativelanguage.googleapis.com') !== false) {
                Logger::info("handleImageFlow - Calling analyzeImageWithGemini");
                $geminiResult = $this->analyzeImageWithGemini($llmIntegration, $imageUrl, $config);
                
                if (empty($geminiResult['error'])) {
                    $usedGemini = true;
                    $detectedRoute = $geminiResult['route'] ?? 'image_generic';
                    $visionMeta = $geminiResult['meta'] ?? null;
                    $labels = $visionMeta['labels'] ?? [];
                    $visionText = $geminiResult['description'] ?? '';
                    $geminiDetails = $geminiResult['details'] ?? [];
                    
                    Logger::info("Image analyzed with Gemini Vision", [
                        'route' => $detectedRoute,
                        'confidence' => $geminiResult['confidence'] ?? 0,
                        'has_details' => !empty($geminiDetails)
                    ]);
                } else {
                    Logger::warning("Gemini Vision failed, will try Google Vision", [
                        'error' => $geminiResult['error']
                    ]);
                }
            }
        }

        // ✅ FALLBACK: Use Google Vision API if Gemini not available or failed
        if (!$usedGemini && $googleVision && $imageUrl) {
            $visionResult = $this->analyzeImageWithGoogleVision($googleVision, $imageUrl);
            $visionMeta = $visionResult['meta'] ?? null;

            $labels = $visionMeta['top_descriptions'] ?? [];
            $visionText = (string)($visionMeta['text'] ?? '');
            $labelTextLower = mb_strtolower(implode(' ', (array)$labels), 'UTF-8');
            $visionTextLower = mb_strtolower($visionText, 'UTF-8');

            $vr = $config['vision_routing'] ?? [];
            $productHints = $vr['product_hints_labels'] ?? ($vr['product_hints'] ?? ['watch','bag','shoe','ring','jewelry','phone']);
            $payHintsTh = $vr['payment_hints_text_th'] ?? ($vr['payment_hints'] ?? ['receipt','bill','invoice','payment','slip']);
            $payHintsEn = $vr['payment_hints_text_en'] ?? [];
            $useTextDetection = (bool)($vr['use_text_detection'] ?? true);

            $isPayment = false;
            if ($useTextDetection) {
                if ($this->containsAny($visionTextLower, $payHintsTh) || $this->containsAny($visionTextLower, $payHintsEn)) $isPayment = true;
            }
            if (!$isPayment) {
                if ($this->containsAny($labelTextLower, array_merge($payHintsTh, $payHintsEn))) $isPayment = true;
            }

            if ($isPayment) {
                $detectedRoute = 'payment_proof';
            } elseif ($this->containsAny($labelTextLower, $productHints)) {
                $detectedRoute = 'product_image';
            } else {
                $detectedRoute = 'image_generic';
            }
        }

        $meta['vision'] = $visionMeta;
        $meta['route'] = $detectedRoute;
        $meta['gemini_details'] = $geminiDetails; // ✅ Store extracted details from Gemini

        // ✅ Persist last image context for follow-up (สำคัญ!)
        if ($sessionId && $imageUrl) {
            $slots = [
                'last_image_url' => $imageUrl,
                'last_image_kind' => $detectedRoute, // product_image | payment_proof | image_generic
                'last_image_ts' => date('c'),
                'last_vision_labels' => $visionMeta['labels'] ?? [],
                'last_vision_top_descriptions' => $visionMeta['top_descriptions'] ?? [],
                'last_vision_text' => $visionMeta['text'] ?? '',
                'last_vision_web_entities' => $visionMeta['web_entities'] ?? [],
                'last_gemini_details' => $geminiDetails, // ✅ NEW: Store Gemini extracted data
            ];
            $this->updateSessionState($sessionId, 'last_media', $slots);
        }

        // ✅ Backend config
        $backendCfg = $config['backend_api'] ?? [];
        $endpoints = $backendCfg['endpoints'] ?? [];

        // payment proof => call receipt_get/payment_verify (even if pending)
        if ($detectedRoute === 'payment_proof') {
            $reply = $templates['payment_proof']
                ?? 'ได้รับสลิปเรียบร้อยแล้วค่ะ ขอเวลาเช็คยอดสักครู่นะคะ 💳';

            // ✅ Use Gemini extracted details for payment info
            $slipAmount = $geminiDetails['amount'] ?? null;
            $slipBank = $geminiDetails['bank'] ?? null;
            $slipDate = $geminiDetails['date'] ?? null;
            $slipRef = $geminiDetails['ref'] ?? null;
            $slipSender = $geminiDetails['sender_name'] ?? null;
            $slipReceiver = $geminiDetails['receiver_name'] ?? null;

            // ✅ Build informative reply with extracted data
            if ($slipAmount) {
                $extractedInfo = "📋 ข้อมูลจากสลิป:\n";
                if ($slipAmount) $extractedInfo .= "💰 จำนวนเงิน: {$slipAmount} บาท\n";
                if ($slipBank) $extractedInfo .= "🏦 ธนาคาร: {$slipBank}\n";
                if ($slipDate) $extractedInfo .= "📅 วันที่: {$slipDate}\n";
                if ($slipRef) $extractedInfo .= "🔢 เลขอ้างอิง: {$slipRef}\n";
                if ($slipSender) $extractedInfo .= "👤 ผู้โอน: {$slipSender}\n";
                
                $reply = "ได้รับสลิปเรียบร้อยแล้วค่ะ 💳\n\n" . $extractedInfo . "\nกำลังตรวจสอบยอดให้นะคะ...";
            }

            // ✅ Use PaymentService for proper insert with auto-matching
            $savedPaymentId = null;
            try {
                require_once __DIR__ . '/../services/PaymentService.php';
                $paymentService = new \Autobot\Services\PaymentService();
                
                $paymentResult = $paymentService->processSlipFromChatbot(
                    $geminiDetails, // OCR data from Gemini
                    $context,       // Chat context
                    $imageUrl       // Slip image URL
                );
                
                Logger::info("PaymentService result", $paymentResult);
                
                if ($paymentResult['success']) {
                    $savedPaymentId = $paymentResult['payment_id'];
                    $paymentNo = $paymentResult['payment_no'];
                    $matchedOrderNo = $paymentResult['matched_order_no'] ?? null;
                    
                    $meta['payment_saved'] = true;
                    $meta['payment_id'] = $savedPaymentId;
                    $meta['payment_no'] = $paymentNo;
                    $meta['matched_order_no'] = $matchedOrderNo;
                    $meta['reason'] = 'image_payment_saved';
                    
                    // Build reply with payment info
                    if ($matchedOrderNo) {
                        $reply = "ได้รับสลิปเรียบร้อยแล้วค่ะ 💳\n\n" . $extractedInfo 
                            . "\n📝 เลขที่รายการ: {$paymentNo}"
                            . "\n🛒 ตรงกับออเดอร์: #{$matchedOrderNo}"
                            . "\nรอเจ้าหน้าที่ตรวจสอบนะคะ 😊";
                    } else {
                        $reply = "ได้รับสลิปเรียบร้อยแล้วค่ะ 💳\n\n" . $extractedInfo 
                            . "\n📝 เลขที่รายการ: {$paymentNo}"
                            . "\nรอเจ้าหน้าที่ตรวจสอบและ matching กับออเดอร์นะคะ 😊";
                    }
                    
                } elseif (!empty($paymentResult['is_duplicate'])) {
                    // Duplicate slip
                    $existingPaymentNo = $paymentResult['existing_payment_no'] ?? '';
                    $meta['payment_saved'] = false;
                    $meta['payment_duplicate'] = true;
                    $meta['existing_payment_id'] = $paymentResult['existing_payment_id'];
                    $reply = "สลิปนี้เคยส่งมาแล้วค่ะ 📋 (เลขอ้างอิง: {$existingPaymentNo})\nรอเจ้าหน้าที่ตรวจสอบนะคะ 😊";
                    
                    if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');
                    return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
                    
                } else {
                    // Error
                    $meta['payment_saved'] = false;
                    $meta['payment_error'] = $paymentResult['error'] ?? 'Unknown error';
                    Logger::error("PaymentService failed", $paymentResult);
                }
                
            } catch (\Exception $e) {
                Logger::error("Failed to save payment via PaymentService", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $meta['payment_saved'] = false;
                $meta['payment_error'] = $e->getMessage();
            }

            if (!empty($backendCfg['enabled'])) {
                $endpoint = $endpoints['receipt_get'] ?? ($endpoints['payment_verify'] ?? null);
                if ($endpoint) {
                    $handled = $this->tryHandleByIntentWithBackend(
                        'payment_slip_verify',
                        [
                            'amount' => $slipAmount,
                            'time' => $slipDate,
                            'sender_name' => $slipSender,
                            'payment_ref' => $slipRef,
                            'bank' => $slipBank,
                        ],
                        $context,
                        $config,
                        $templates,
                        $message['text'] ?? '',
                        ['slip_image_url' => $imageUrl, 'vision_text' => $visionText, 'gemini_details' => $geminiDetails]
                    );

                    if (!empty($handled['handled'])) {
                        $reply = (string)($handled['reply_text'] ?? $reply);
                        $meta['backend'] = $handled['meta'] ?? null;
                        $meta['reason'] = 'image_payment_backend';
                    } else {
                        $meta['reason'] = 'image_payment_no_backend';
                    }
                } else {
                    $meta['reason'] = 'image_payment_missing_endpoint';
                }
            } else {
                $meta['reason'] = 'image_payment_template';
            }

            if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
            $this->logBotReply($context, $reply, 'text');
            return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
        }

        // product image => call image_search (searchImage)
        if ($detectedRoute === 'product_image') {
            // ✅ Use Gemini extracted details for product info
            $productBrand = $geminiDetails['brand'] ?? null;
            $productModel = $geminiDetails['model'] ?? null;
            $productDesc = $geminiDetails['description'] ?? $visionText;
            $productCategory = $geminiDetails['category'] ?? null;

            // ✅ Build informative reply with extracted data
            $productInfo = "";
            if ($productBrand || $productModel) {
                $productInfo = "🔍 วิเคราะห์รูปได้:\n";
                if ($productBrand) $productInfo .= "🏷️ แบรนด์: {$productBrand}\n";
                if ($productModel) $productInfo .= "📋 รุ่น: {$productModel}\n";
                if ($productCategory) $productInfo .= "📁 หมวด: {$productCategory}\n";
            }

            $reply = $templates['product_image']
                ?? 'ได้รับรูปสินค้ามาแล้วค่ะ 😊 เดี๋ยวขออนุญาตนำรูปไปวิเคราะห์และเช็คในระบบให้นะคะ';
            
            if ($productInfo) {
                $reply = "ได้รับรูปสินค้าแล้วค่ะ 😊\n\n" . $productInfo . "\nกำลังค้นหาในระบบให้นะคะ...";
            }

            // ✅ FIX: Initialize actionsOut early to prevent undefined variable
            $actionsOut = [];

            if (!empty($backendCfg['enabled'])) {
                $endpoint = $endpoints['image_search'] ?? ($endpoints['searchImage'] ?? null);
                if (!$endpoint) $endpoint = '/api/searchImage';

                $payload = [
                    'channel_id' => $context['channel']['id'] ?? null,
                    'external_user_id' => $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null),
                    'image_url' => $imageUrl,
                    'vision' => [
                        'labels' => $visionMeta['labels'] ?? [],
                        'top_descriptions' => $visionMeta['top_descriptions'] ?? [],
                        'text' => $visionMeta['text'] ?? '',
                        'web_entities' => $visionMeta['web_entities'] ?? [],
                    ],
                    // ✅ NEW: Include Gemini extracted details
                    'gemini_details' => [
                        'brand' => $productBrand,
                        'model' => $productModel,
                        'description' => $productDesc,
                        'category' => $productCategory,
                    ],
                ];

                $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
                $meta['backend'] = $resp;

                if ($resp['ok']) {
                    $products = $resp['data']['products'] ?? ($resp['data']['items'] ?? ($resp['data']['candidates'] ?? []));
                    if (!is_array($products)) $products = [];

                    // ✅ renderProductsFromBackend returns {text, actions}
                    $rendered = $this->renderProductsFromBackend($products, $templates);
                    $reply = (string)($rendered['text'] ?? $reply);
                    $actionsOut = (isset($rendered['actions']) && is_array($rendered['actions'])) ? $rendered['actions'] : [];

                    $meta['reason'] = 'image_product_backend';
                } else {
                    $reply = $templates['ask_product_code']
                        ?? 'รบกวนส่ง "ชื่อรุ่น/รหัส/ซีเรียล/งบ" เพิ่มนิดนึงค่ะ 😊 เพื่อให้เช็คได้ตรงขึ้นค่ะ';
                    $meta['reason'] = 'image_product_backend_error';
                }
            }

            if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
            $this->logBotReply($context, $reply, 'text');
            return ['reply_text' => $reply, 'actions' => $actionsOut, 'meta' => $meta];
        }

        // generic image
        $reply = $templates['image_generic']
            ?? 'ได้รับรูปภาพแล้วค่ะ 🖼️ รบกวนบอกเพิ่มนิดนึงนะคะ ว่าอยากให้ช่วยดูเรื่องอะไรเกี่ยวกับรูปนี้ค่ะ';

        if ($llmIntegration && !empty($config['llm']['enabled'])) {
            $prompt  = "ลูกค้าส่งรูปภาพมาแต่ไม่อธิบาย:\n";
            $prompt .= "URL รูปภาพ: {$imageUrl}\n";
            if ($labels) $prompt .= "Vision: " . implode(', ', $labels) . "\n";
            $prompt .= "ช่วยตอบสุภาพ เป็นกันเอง และถามต่อให้ชัดว่าอยากให้เช็คสต็อก/ถามราคา/ส่งสลิป/สอบถามอื่น ๆ\n";

            $llm = $this->handleWithLlm($llmIntegration, $config, $context, $prompt);
            if (!empty($llm['reply_text'])) $reply = (string)$llm['reply_text'];
            $meta['llm'] = $llm['meta'] ?? null;
            $meta['reason'] = 'image_generic_llm';
        } else {
            $meta['reason'] = 'image_generic_template';
        }

        if ($sessionId && $reply !== '') $this->storeMessage($sessionId, 'assistant', $reply);
        $this->logBotReply($context, $reply, 'text');
        return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
    }

    protected function renderProductsFromBackend(array $products, array $templates): array
    {
        $products = array_values($products);
        $actions = [];
        
        Logger::info("[RENDER_PRODUCTS] Processing products", [
            'count' => count($products)
        ]);
        
        if (count($products) <= 0) {
            return [
                'text' => $templates['product_not_found'] ?? 'ตอนนี้ยังไม่เจอในระบบค่ะ 😅',
                'actions' => []
            ];
        }

        if (count($products) === 1) {
            $p = $products[0];
            $tpl = $templates['product_found_one'] ?? 'พบสินค้า {{name}} ({{code}}) ราคา {{price}} บาท';
            $text = $this->renderTemplate($tpl, [
                'name' => $p['name'] ?? ($p['title'] ?? 'สินค้า'),
                'code' => $p['sku'] ?? ($p['code'] ?? ($p['product_code'] ?? '')),
                'price' => $p['price'] ?? ($p['selling_price'] ?? ''),
                'condition' => $p['condition'] ?? ($p['status'] ?? ''),
            ]);
            
            // Add image if available
            if (!empty($p['image_url'])) {
                $actions[] = [
                    'type' => 'image',
                    'url' => $p['image_url']
                ];
                Logger::info("[RENDER_PRODUCTS] ✅ Added image for single product", [
                    'image_url' => $p['image_url'],
                    'product_name' => $p['name'] ?? 'Unknown'
                ]);
            } else {
                Logger::warning("[RENDER_PRODUCTS] ⚠️ No image_url for product", [
                    'product' => $p
                ]);
            }
            
            Logger::info("[RENDER_PRODUCTS] Returning result", [
                'actions_count' => count($actions),
                'has_images' => count($actions) > 0
            ]);
            
            return ['text' => $text, 'actions' => $actions];
        }

        // Multiple products
        $lines = [];
        $i = 1;
        foreach ($products as $p) {
            $name = $p['name'] ?? ($p['title'] ?? 'สินค้า');
            $code = $p['sku'] ?? ($p['code'] ?? ($p['product_code'] ?? ''));
            $price = $p['price'] ?? ($p['selling_price'] ?? '');
            $lines[] = "{$i}) {$name}" . ($code ? " (รหัส {$code})" : "") . ($price !== '' ? " - {$price} บาท" : "");
            
            // Add image for first 3 products only (to avoid too many images)
            if ($i <= 3 && !empty($p['image_url'])) {
                $actions[] = [
                    'type' => 'image',
                    'url' => $p['image_url']
                ];
                Logger::info("[RENDER_PRODUCTS] ✅ Added image #{$i}", [
                    'image_url' => $p['image_url'],
                    'product_name' => $name
                ]);
            } elseif ($i <= 3) {
                Logger::warning("[RENDER_PRODUCTS] ⚠️ No image_url for product #{$i}", [
                    'product_name' => $name,
                    'sku' => $code
                ]);
            }
            
            $i++;
            if ($i > 5) break;
        }

        $tpl = $templates['product_found_many'] ?? "พบหลายรายการ:\n{{list}}\nพิมพ์เลือกเลข 1-{{n}} ได้เลยค่ะ";
        $text = $this->renderTemplate($tpl, [
            'list' => implode("\n", $lines),
            'n' => min(count($products), 5)
        ]);
        
        Logger::info("[RENDER_PRODUCTS] ✅ Final result", [
            'total_products' => count($products),
            'actions_count' => count($actions),
            'image_urls' => array_map(function($a) { return $a['url'] ?? 'N/A'; }, $actions)
        ]);
        
        return ['text' => $text, 'actions' => $actions];
    }

    protected function detectInstallmentActionTypeFromText(string $text): ?string
    {
        $t = mb_strtolower($text, 'UTF-8');
        if (mb_strpos($t, 'ปิดยอด', 0, 'UTF-8') !== false) return 'close_check';
        if (mb_strpos($t, 'ต่อดอก', 0, 'UTF-8') !== false || mb_strpos($t, 'ต่อ', 0, 'UTF-8') !== false) return 'extend_interest';
        if (mb_strpos($t, 'ชำระ', 0, 'UTF-8') !== false || mb_strpos($t, 'ส่งงวด', 0, 'UTF-8') !== false || mb_strpos($t, 'งวด', 0, 'UTF-8') !== false) return 'pay';
        if (mb_strpos($t, 'เช็ค', 0, 'UTF-8') !== false || mb_strpos($t, 'สรุป', 0, 'UTF-8') !== false) return 'summary';
        return null;
    }

    // =========================================================
    // Backend HTTP helper
    // =========================================================
    protected function callBackendJson(array $backendCfg, string $endpointOrUrl, array $payload): array
    {
        $base = rtrim((string)($backendCfg['base_url'] ?? ''), '/');
        $timeout = (int)($backendCfg['timeout_seconds'] ?? 8);
        $timeout = max(3, min(30, $timeout));

        $url = $endpointOrUrl;
        if (!preg_match('~^https?://~i', $url)) {
            $url = $base . '/' . ltrim($endpointOrUrl, '/');
        }
        
        // Ensure trailing slash for directory endpoints (avoid 404 redirects)
        // Only add slash if: 1) no file extension, 2) no query string, 3) doesn't already end with /
        if (!preg_match('~\.\w+$|\?|/$~', $url)) {
            $url .= '/';
        }

        $headers = ['Content-Type: application/json'];

        // optional auth
        $apiKey = $backendCfg['api_key'] ?? null;
        if ($apiKey) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $startTime = microtime(true);
        $raw = curl_exec($ch);
        $responseTime = (int)((microtime(true) - $startTime) * 1000); // milliseconds
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        // Log backend API call to api_usage_logs
        $this->logBackendApiCall($payload, $endpointOrUrl, $responseTime, $status);

        if ($err) {
            Logger::error("Backend API error: {$err}", ['url' => $url]);
            return ['ok' => false, 'error' => $err, 'status' => 0, 'url' => $url];
        }
        if ($status < 200 || $status >= 300) {
            Logger::warning("Backend API non-2xx: {$status}", ['url' => $url, 'response' => substr($raw, 0, 200)]);
            return ['ok' => false, 'error' => "http_{$status}", 'status' => $status, 'data' => ['raw' => $raw], 'url' => $url];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            Logger::warning("Backend API invalid JSON", ['url' => $url]);
            return ['ok' => true, 'status' => $status, 'data' => ['raw' => $raw], 'url' => $url];
        }

        // Merge API response directly (API already has 'ok'/'success' and 'data' keys)
        // Don't double-wrap it
        // Support both 'ok' and 'success' keys
        $isOk = $data['ok'] ?? $data['success'] ?? false;
        if (isset($data['data'])) {
            // API response format: {"ok": true, "data": {...}} or {"success": true, "data": {...}}
            // Return: {"ok": <from API>, "data": <from API>, "status": <http>, "url": <url>}
            return ['ok' => $isOk, 'data' => $data['data'], 'status' => $status, 'url' => $url];
        }
        
        // Legacy format: API returns data directly
        return ['ok' => true, 'status' => $status, 'data' => $data, 'url' => $url];
    }

    /**
     * Log backend API call to api_usage_logs for usage tracking
     */
    protected function logBackendApiCall(array $payload, string $endpoint, int $responseTime, int $statusCode): void
    {
        try {
            $channelId = $payload['channel_id'] ?? null;
            if (!$channelId) return; // Skip if no channel context

            $this->db->execute(
                "INSERT INTO api_usage_logs 
                 (customer_service_id, api_type, endpoint, request_count, response_time, status_code, cost, created_at)
                 VALUES (?, 'backend', ?, 1, ?, ?, 0, NOW())",
                [
                    $channelId,
                    substr($endpoint, 0, 255),
                    $responseTime,
                    $statusCode
                ]
            );
        } catch (Exception $e) {
            // Don't fail the request if logging fails
            Logger::error('Failed to log backend API call: ' . $e->getMessage());
        }
    }

    // =========================================================
    // Detectors
    // =========================================================
    protected function detectMessageType(array $message): string
    {
        $t = (string)($message['message_type'] ?? ($message['type'] ?? ''));
        $t = trim($t);
        if ($t !== '') return $t;

        $atts = $message['attachments'] ?? [];
        if (is_array($atts)) {
            foreach ($atts as $a) {
                $atype = (string)($a['type'] ?? '');
                $url   = (string)($a['url'] ?? ($a['payload']['url'] ?? ''));
                $mime  = (string)($a['mime_type'] ?? '');

                if ($atype === 'image') return 'image';
                if ($mime !== '' && stripos($mime, 'image/') === 0) return 'image';

                if ($url !== '') {
                    if (preg_match('/\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i', $url)) return 'image';
                }
            }
        }
        return 'text';
    }

    protected function extractFirstImageUrl(array $message): ?string
    {
        $atts = $message['attachments'] ?? [];
        if (!is_array($atts) || empty($atts)) return null;

        foreach ($atts as $a) {
            $url = $a['url'] ?? ($a['payload']['url'] ?? null);
            if ($url && is_string($url)) return $url;
        }
        return null;
    }

    protected function isAdminContext(array $context, array $message): bool
    {
        if (!empty($context['is_admin'])) return true;
        if (!empty($context['user']['is_admin'])) return true;
        if (!empty($context['sender_role']) && $context['sender_role'] === 'admin') return true;
        if (!empty($message['meta']['is_admin'])) return true;

        // New: allow webhook metadata to carry sender_role
        if (!empty($message['meta']['sender_role']) && $message['meta']['sender_role'] === 'admin') return true;

        return false;
    }

    // =========================================================
    // Session helpers
    // =========================================================
    public function findOrCreateSession(int $channelId, string $externalUserId): array
    {
        $row = $this->db->queryOne(
            'SELECT * FROM chat_sessions WHERE channel_id = ? AND external_user_id = ? LIMIT 1',
            [$channelId, $externalUserId]
        );
        if ($row) return $row;

        try {
            $this->db->execute(
                'INSERT INTO chat_sessions (channel_id, external_user_id, created_at, updated_at)
                 VALUES (?, ?, NOW(), NOW())',
                [$channelId, $externalUserId]
            );
        } catch (Exception $e) {
            // ignore race
        }

        $row = $this->db->queryOne(
            'SELECT * FROM chat_sessions WHERE channel_id = ? AND external_user_id = ? LIMIT 1',
            [$channelId, $externalUserId]
        );

        return $row ?: [
            'id' => null,
            'channel_id' => $channelId,
            'external_user_id' => $externalUserId,
            'last_intent' => null,
            'last_slots_json' => null,
            'summary' => null,
        ];
    }

    protected function storeMessage(int $sessionId, string $role, string $text): void
    {
        $text = trim((string)$text);
        if ($text === '') return;

        $text = mb_substr($text, 0, 2000, 'UTF-8');

        $this->db->execute(
            'INSERT INTO chat_messages (session_id, role, text, created_at) VALUES (?, ?, ?, NOW())',
            [$sessionId, $role, $text]
        );
    }

    /**
     * Log bot reply to bot_chat_logs for usage tracking
     * Called before returning responses to track outgoing messages
     */
    protected function logBotReply(array $context, string $replyText, string $messageType = 'text'): void
    {
        if (trim($replyText) === '') return;

        try {
            $channel = $context['channel'] ?? [];
            $channelId = $channel['id'] ?? null;
            // ✅ FIX: Fallback to context['user']['external_user_id'] for LINE compatibility
            $externalUserId = $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null);

            if (!$channelId) return; // Skip if no channel context

            $this->db->execute(
                "INSERT INTO bot_chat_logs 
                 (customer_service_id, platform_user_id, direction, message_type, message_content, created_at)
                 VALUES (?, ?, 'outgoing', ?, ?, NOW())",
                [
                    $channelId,
                    $externalUserId ?? 'unknown',
                    $messageType,
                    mb_substr(trim($replyText), 0, 1000, 'UTF-8') // Limit to 1000 chars
                ]
            );
        } catch (Exception $e) {
            // Don't fail the request if logging fails
            Logger::error('Failed to log bot reply: ' . $e->getMessage());
        }
    }


    // ✅ Merge session slots instead of overwrite (สำคัญมาก)
    protected function updateSessionState(int $sessionId, ?string $intent, ?array $slots): void
    {
        $existing = $this->db->queryOne('SELECT last_slots_json FROM chat_sessions WHERE id = ? LIMIT 1', [$sessionId]);
        $oldSlots = [];
        if (!empty($existing['last_slots_json'])) {
            $tmp = json_decode($existing['last_slots_json'], true);
            if (is_array($tmp)) $oldSlots = $tmp;
        }

        $merged = $this->mergeSlots($oldSlots, $slots ?: []);

        $this->db->execute(
            'UPDATE chat_sessions
             SET last_intent = ?,
                 last_slots_json = ?,
                 updated_at = NOW()
             WHERE id = ?',
            [
                $intent,
                !empty($merged) ? json_encode($merged, JSON_UNESCAPED_UNICODE) : null,
                $sessionId
            ]
        );
    }

    protected function getConversationHistory(int $sessionId, int $limit = 10): array
    {
        $limit = max(1, min(50, (int)$limit));
        $sql = "SELECT role, text, created_at
                FROM chat_messages
                WHERE session_id = ?
                ORDER BY created_at DESC
                LIMIT {$limit}";
        $messages = $this->db->query($sql, [$sessionId]);
        return array_reverse($messages);
    }

    // =========================================================
    // Repeat / anti-spam helpers
    // =========================================================
    protected function normalizeTextForRepeat(string $text): string
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', $t);
        $t = preg_replace('/[[:punct:]]+/u', '', $t);
        return trim($t);
    }

    protected function isRepeatedUserMessage(int $sessionId, string $normalizedText, int $threshold, int $windowSeconds): bool
    {
        $threshold = max(2, min(10, $threshold));
        $windowSeconds = max(5, min(300, $windowSeconds));

        // New: require at least 2 identical recent messages, even if threshold is higher.
        // This prevents false positives when a single message is duplicated by upstream deliveries.
        $limit = max(2, $threshold - 1);

        $sql = "SELECT text, created_at
                FROM chat_messages
                WHERE session_id = ?
                  AND role = 'user'
                  AND created_at >= (NOW() - INTERVAL {$windowSeconds} SECOND)
                ORDER BY created_at DESC
                LIMIT {$limit}";

        $rows = $this->db->query($sql, [$sessionId]);

        if (count($rows) < $limit) return false;

        foreach ($rows as $r) {
            $t = $this->normalizeTextForRepeat((string)($r['text'] ?? ''));
            if ($t !== $normalizedText) return false;
        }
        return true;
    }

    // =========================================================
    // Slot helpers
    // =========================================================
    protected function mergeSlots(array $existingSlots = null, array $newSlots = null): array
    {
        $existingSlots = $existingSlots ?: [];
        $newSlots = $newSlots ?: [];
        foreach ($newSlots as $k => $v) {
            if ($v !== null && $v !== '') $existingSlots[$k] = $v;
        }
        return $existingSlots;
    }

    protected function detectMissingSlots(string $intent, array $intentConfig, array $slots): array
    {
        $required = $intentConfig['slots'] ?? [];
        $missing = [];
        foreach ($required as $slotName) {
            if (!array_key_exists($slotName, $slots) || $slots[$slotName] === null || $slots[$slotName] === '') {
                $missing[] = $slotName;
            }
        }
        return $missing;
    }

    // =========================================================
    // Vision / NLP / LLM
    // =========================================================
    
    /**
     * Split reply text into multiple messages for human-like conversation
     * @param string $text Reply text from LLM (may contain ||SPLIT|| delimiter)
     * @return array Array of message strings
     */
    protected function splitReplyMessages(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        
        // If no delimiter found, return single message
        if (strpos($text, '||SPLIT||') === false) {
            return [$text];
        }
        
        // Split by delimiter and clean up
        $messages = explode('||SPLIT||', $text);
        $cleaned = [];
        
        foreach ($messages as $msg) {
            $msg = trim($msg);
            if ($msg !== '') {
                $cleaned[] = $msg;
            }
        }
        
        // If split resulted in empty array, return original
        return empty($cleaned) ? [$text] : $cleaned;
    }
    
    protected function containsAny(string $haystackLower, array $needles): bool
    {
        foreach ($needles as $n) {
            $n = mb_strtolower(trim((string)$n), 'UTF-8');
            if ($n !== '' && mb_stripos($haystackLower, $n, 0, 'UTF-8') !== false) return true;
        }
        return false;
    }

    /**
     * ✅ Analyze image using Gemini Multimodal (Vision capability)
     * Gemini 2.5 Flash can understand images natively without separate Vision API
     */
    protected function analyzeImageWithGemini(array $llmIntegration, string $imageUrl, array $config): array
    {
        $apiKey = $llmIntegration['api_key'] ?? null;
        $cfg = $this->decodeJsonArray($llmIntegration['config'] ?? null);
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
            Logger::warning("Failed to download image for Gemini analysis", ['url' => $imageUrl]);
            return ['error' => 'download_failed', 'route' => 'image_generic', 'meta' => null];
        }

        // Detect mime type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData);
        if (!$mimeType || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            $mimeType = 'image/jpeg'; // fallback
        }

        $base64Image = base64_encode($imageData);

        // Get vision routing config for context
        $vr = $config['vision_routing'] ?? [];
        $productHints = $vr['product_hints_labels'] ?? ['watch', 'bag', 'shoe', 'ring', 'jewelry', 'phone', 'luxury', 'brand'];
        $payHints = $vr['payment_hints_text_th'] ?? ['สลิป', 'โอนเงิน', 'ชำระเงิน', 'ใบเสร็จ', 'receipt', 'transfer', 'payment'];

        // Build analysis prompt
        $analysisPrompt = "วิเคราะห์รูปภาพนี้และระบุข้อมูลสำคัญ:\n\n"
            . "1. ประเภทรูป (image_type): ระบุเป็น payment_proof | product_image | image_generic\n"
            . "   - payment_proof: สลิปโอนเงิน, ใบเสร็จ, หลักฐานการชำระเงิน\n"
            . "   - product_image: รูปสินค้า, นาฬิกา, กระเป๋า, เครื่องประดับ, สินค้าแบรนด์เนม\n"
            . "   - image_generic: รูปอื่นๆ ที่ไม่ใช่ 2 ประเภทข้างต้น\n\n"
            . "2. ถ้าเป็นสลิป (payment_proof) ดึงข้อมูล:\n"
            . "   - amount: จำนวนเงิน (ตัวเลข)\n"
            . "   - bank: ธนาคาร\n"
            . "   - date: วันที่/เวลา\n"
            . "   - ref: เลขอ้างอิง\n"
            . "   - sender_name: ชื่อผู้โอน\n"
            . "   - receiver_name: ชื่อผู้รับ\n\n"
            . "3. ถ้าเป็นสินค้า (product_image) ดึงข้อมูล:\n"
            . "   - brand: แบรนด์/ยี่ห้อ\n"
            . "   - model: รุ่น/ชื่อสินค้า\n"
            . "   - description: คำอธิบายสินค้าโดยย่อ\n"
            . "   - category: หมวดหมู่ (watch/bag/jewelry/etc)\n\n"
            . "ตอบเป็น JSON อย่างเดียว ไม่ต้องมีข้อความอื่น:\n"
            . "{\n"
            . "  \"image_type\": \"payment_proof\" | \"product_image\" | \"image_generic\",\n"
            . "  \"confidence\": 0.0-1.0,\n"
            . "  \"details\": { ... ข้อมูลที่ดึงได้ ... },\n"
            . "  \"description\": \"คำอธิบายสั้นๆ ของรูป\"\n"
            . "}";

        // Build Gemini multimodal request with inline image
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $analysisPrompt
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64Image
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 2048
            ]
        ];

        $url = $endpoint . (strpos($endpoint, '?') !== false ? '&' : '?') . 'key=' . $apiKey;

        $startTime = microtime(true);
        Logger::info("Gemini Vision API call starting", [
            'endpoint' => $endpoint,
            'image_size' => strlen($imageData),
            'mime_type' => $mimeType
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30,
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Logger::info("Gemini Vision API call completed", [
            'duration_ms' => $duration,
            'status' => $status,
            'has_error' => !empty($err)
        ]);

        if ($err || $status >= 400) {
            Logger::error("Gemini Vision API error", ['error' => $err, 'status' => $status, 'response' => $resp]);
            return ['error' => $err ?: ('http_' . $status), 'route' => 'image_generic', 'meta' => null];
        }

        $data = json_decode($resp, true);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Parse JSON response from Gemini
        $parsed = $this->extractJsonObject($content);
        if (!is_array($parsed)) {
            Logger::warning("Gemini Vision returned non-JSON", ['content' => $content]);
            return ['error' => 'parse_error', 'route' => 'image_generic', 'meta' => ['raw' => $content]];
        }

        $imageType = $parsed['image_type'] ?? 'image_generic';
        $confidence = (float)($parsed['confidence'] ?? 0.5);
        $details = $parsed['details'] ?? [];
        $description = $parsed['description'] ?? '';

        // Map to route
        $route = 'image_generic';
        if ($imageType === 'payment_proof' && $confidence >= 0.6) {
            $route = 'payment_proof';
        } elseif ($imageType === 'product_image' && $confidence >= 0.5) {
            $route = 'product_image';
        }

        Logger::info("Gemini Vision analysis result", [
            'image_type' => $imageType,
            'route' => $route,
            'confidence' => $confidence
        ]);

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
                'top_descriptions' => [$description],
                'parsed' => $parsed
            ]
        ];
    }

    protected function analyzeImageWithGoogleVision(array $integration, string $imageUrl): array
    {
        $apiKey = $integration['api_key'] ?? null;
        $cfg = $this->decodeJsonArray($integration['config'] ?? null);
        $endpoint = $cfg['endpoint'] ?? 'https://vision.googleapis.com/v1/images:annotate';

        if (!$apiKey) {
            return ['reply' => null, 'meta' => ['error' => 'missing_api_key']];
        }

        $useUri = preg_match('~^https?://~i', $imageUrl);
        $imagePayload = [];

        if ($useUri) {
            $imagePayload = ['source' => ['imageUri' => $imageUrl]];
        } else {
            $data = @file_get_contents($imageUrl);
            if ($data === false) {
                return ['reply' => null, 'meta' => ['error' => 'download_failed', 'url' => $imageUrl]];
            }
            $imagePayload = ['content' => base64_encode($data)];
        }

        $features = [
            ['type' => 'LABEL_DETECTION', 'maxResults' => 5],
            ['type' => 'TEXT_DETECTION', 'maxResults' => 5],
            ['type' => 'WEB_DETECTION', 'maxResults' => 3],
        ];

        $payload = ['requests' => [[ 'image' => $imagePayload, 'features' => $features ]]];

        $url = $endpoint . '?key=' . urlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            return ['reply' => null, 'meta' => ['error' => 'vision_http_error', 'status' => $code, 'curl_error' => $err]];
        }

        $json = json_decode($resp, true);
        $entities = $json['responses'][0]['webDetection']['webEntities'] ?? [];

        $suggestedRoute = null;
        foreach ($entities as $ent) {
            $name = $ent['description'] ?? ($ent['name'] ?? '');
            if (mb_stripos($name, 'ผ่อน', 0, 'UTF-8') !== false) { $suggestedRoute = 'installment_flow'; break; }
            if (mb_stripos($name, 'คิว', 0, 'UTF-8') !== false) { $suggestedRoute = 'booking'; break; }
            if (mb_stripos($name, 'ราคา', 0, 'UTF-8') !== false || mb_stripos($name, 'มีไหม', 0, 'UTF-8') !== false) { $suggestedRoute = 'product_availability'; break; }
        }

        return ['reply' => null, 'meta' => ['entities' => $entities, 'suggested_route' => $suggestedRoute]];
    }

    protected function handleWithLlm(array $integration, array $botConfig, array $context, string $text): array
    {
        $apiKey = $integration['api_key'] ?? null;
        $cfg = $this->decodeJsonArray($integration['config'] ?? null);

        if (!$apiKey) {
            return ['reply_text' => null, 'intent' => null, 'meta' => ['error' => 'missing_api_key']];
        }

        $llmCfg  = $botConfig['llm'] ?? [];
        $endpoint = $cfg['endpoint'] ?? 'https://api.openai.com/v1/chat/completions';
        $model    = $cfg['model'] ?? ($llmCfg['model'] ?? 'gpt-4.1-mini');

        $isGemini = (stripos($endpoint, 'generativelanguage.googleapis.com') !== false);

        // Use system_prompt from config (with all the detailed rules)
        $systemPrompt = trim((string)($llmCfg['system_prompt'] ?? ''));
        
        // Only use fallback if config is truly empty
        if ($systemPrompt === '') {
            $systemPrompt = 'คุณคือแอดมินร้านค้าที่ตอบลูกค้าด้วยน้ำเสียงสุภาพ เป็นกันเอง กระชับ และช่วยปิดการขายอย่างสุภาพ ตอบเป็นภาษาไทยเท่านั้น';
        }

        $persona = $botConfig['persona'] ?? [];
        if (!empty($persona)) {
            // Only append persona if not already in system_prompt
            if (stripos($systemPrompt, 'บุคลิก') === false && stripos($systemPrompt, 'persona') === false) {
                $personaParts = [];
                if (!empty($persona['tone'])) $personaParts[] = 'โทนการพูด: ' . $persona['tone'];
                if (!empty($persona['language'])) $personaParts[] = 'ภาษาในการตอบหลัก: ' . $persona['language'];
                if (!empty($persona['max_chars'])) $personaParts[] = 'จำกัดความยาวข้อความตอบไม่เกินประมาณ ' . (int)$persona['max_chars'] . ' ตัวอักษร';
                if ($personaParts) $systemPrompt .= "\n\nข้อกำหนดบุคลิก:\n- " . implode("\n- ", $personaParts);
            }
        }

        // CRITICAL: Add conversation history awareness if not already in prompt
        $hasHistoryRule = (stripos($systemPrompt, 'conversation history') !== false) 
                       || (stripos($systemPrompt, 'ถามซ้ำ') !== false)
                       || (stripos($systemPrompt, 'HISTORY') !== false);
        
        $system = $systemPrompt;
        
        if (!$hasHistoryRule) {
            // Add explicit history awareness rules
            $system .= "\n\n⚠️ CRITICAL RULES:"
                . "\n1. READ conversation history BEFORE responding"
                . "\n2. NEVER ask about business_type if user already mentioned their business"
                . "\n3. NEVER ask about goal if user already stated what they want"  
                . "\n4. NEVER repeat questions - check history first"
                . "\n5. If user complains about repeat questions, acknowledge and move forward";
        }
        
        // ✅ NEW: Add message splitting instructions for human-like multi-message responses
        if (stripos($systemPrompt, 'SPLIT') === false && stripos($systemPrompt, 'แบ่งข้อความ') === false) {
            $system .= "\n\n📨 MESSAGE SPLITTING RULES (ตอบแบบคนจริง):"
                . "\n- หากคำตอบสั้น (< 150 ตัวอักษร): ส่ง 1 ข้อความเดียว"
                . "\n- หากคำตอบยาว (≥ 150 ตัวอักษร): แบ่งเป็น 2-3 ข้อความ โดยใส่ ||SPLIT|| คั่น"
                . "\n\n✅ วิธีแบ่งที่ดี:"
                . "\n- แบ่งที่จุดจบของความคิด/ประโยค"
                . "\n- แต่ละข้อความควรสมบูรณ์ในตัวเอง"
                . "\n- ❌ ห้ามตัดกลางประโยค ห้ามตัดกลางคำ"
                . "\n\nตัวอย่างสั้น (1 ข้อความ):"
                . "\n\"สวัสดีครับ เรามีบริการออกแบบกล่องครับ สนใจแบบไหนครับ?\""
                . "\n\nตัวอย่างยาว (3 ข้อความ):"
                . "\n\"เรามีบริการออกแบบกล่อง 3 ประเภทหลักครับ||SPLIT||1. กล่องลูกฟูก เหมาะสำหรับสินค้าทั่วไป ราคาประหยัด\n2. กล่องแข็ง เหมาะกับสินค้าพรีเมียม\n3. กล่องสกรีน สำหรับแบรนด์ที่ต้องการดีไซน์พิเศษ||SPLIT||ลูกค้าสนใจแบบไหนครับ? หรืออยากให้แนะนำเพิ่มเติมไหมครับ?\"";
        }
        
        // Add intent/slots instructions if not already present
        if (stripos($systemPrompt, 'intent') === false) {
            $system .= "\n\nหน้าที่ของคุณ: สรุป intent+slots จากข้อความลูกค้า (อย่ามั่วข้อมูลจากความรู้ทั่วไป)"
                . "\nintent ตัวอย่าง: product_lookup_by_code | product_availability | price_inquiry | payment_slip_verify | installment_flow | order_status"
                . "\nslots ตัวอย่าง:"
                . "\n- product_code, product_name, amount, time, sender_name, payment_ref, installment_id, customer_phone, order_id, action_type(pay|extend_interest|close_check|summary)\n"
                . "\n\nตอบกลับเป็น JSON เท่านั้น:\n{\n  \"reply_text\": string,\n  \"intent\": string | null,\n  \"slots\": object | null,\n  \"confidence\": number | null,\n  \"next_question\": string | null\n}\nห้ามมีข้อความอื่นนอกจาก JSON.";
        }

        // Build conversation history
        $messages = [];
        $sessionId = $context['session_id'] ?? null;
        if ($sessionId) {
            $historyCfg = $botConfig['conversation_history'] ?? [];
            $historyEnabled = $historyCfg['enabled'] ?? true;
            $maxMessages = (int)($historyCfg['max_messages'] ?? 10);

            if ($historyEnabled) {
                $history = $this->getConversationHistory((int)$sessionId, $maxMessages);
                foreach ($history as $msg) {
                    $messages[] = [
                        'role' => ($msg['role'] === 'user') ? 'user' : 'assistant',
                        'content' => $msg['text'],
                    ];
                }
            }
        }

        $userMessage = "ข้อความล่าสุดจากลูกค้า: " . $text;

        if ($isGemini) {
            $contents = [];
            $contents[] = ['parts' => [['text' => $system]]];

            foreach ($messages as $msg) {
                $contents[] = [
                    'role' => $msg['role'] === 'user' ? 'user' : 'model',
                    'parts' => [['text' => $msg['content']]]
                ];
            }

            $contents[] = ['parts' => [['text' => $userMessage]]];

            $payload = ['contents' => $contents];

            $endpoint .= (strpos($endpoint, '?') !== false ? '&' : '?') . 'key=' . $apiKey;

            $headers = ['Content-Type: application/json'];
        } else {
            $openaiMessages = [['role' => 'system', 'content' => $system]];
            foreach ($messages as $msg) {
                $openaiMessages[] = [
                    'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
                    'content' => $msg['content']
                ];
            }
            $openaiMessages[] = ['role' => 'user', 'content' => $userMessage];

            $payload = [
                'model' => $model,
                'messages' => $openaiMessages,
                'temperature' => (float)($llmCfg['temperature'] ?? 0.6),
                'max_tokens'  => (int)($llmCfg['max_tokens'] ?? 256),
            ];

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ];
        }

        $startTime = microtime(true);
        Logger::info("Gemini/LLM API call starting", [
            'provider' => $isGemini ? 'gemini' : 'openai',
            'endpoint' => $endpoint,
            'has_api_key' => !empty($apiKey),
            'payload_size' => strlen(json_encode($payload))
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => (int)($llmCfg['timeout_seconds'] ?? 10),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Logger::info("Gemini/LLM API call completed", [
            'provider' => $isGemini ? 'gemini' : 'openai',
            'duration_ms' => $duration,
            'status' => $status,
            'has_error' => !empty($err),
            'response_size' => strlen($raw)
        ]);

        if ($err || $status >= 400) {
            return ['reply_text' => null, 'intent' => null, 'meta' => ['error' => $err ?: ('http_' . $status), 'raw' => $raw]];
        }

        $data = json_decode($raw, true);

        if ($isGemini) {
            $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } else {
            $content = $data['choices'][0]['message']['content'] ?? '';
        }

        $parsed = $this->extractJsonObject($content);
        if (!is_array($parsed)) {
            return [
                'reply_text' => $content ?: null,
                'intent' => null,
                'slots' => null,
                'confidence' => null,
                'next_question' => null,
                'meta' => ['raw_response' => $data, 'parse_error' => true, 'provider' => $isGemini ? 'gemini' : 'openai'],
            ];
        }

        return [
            'reply_text' => $parsed['reply_text'] ?? null,
            'intent' => $parsed['intent'] ?? null,
            'slots' => $parsed['slots'] ?? null,
            'confidence' => $parsed['confidence'] ?? null,
            'next_question' => $parsed['next_question'] ?? null,
            'meta' => ['raw_response' => $data, 'parsed' => $parsed, 'provider' => $isGemini ? 'gemini' : 'openai'],
        ];
    }

    protected function handleWithLlmIntent(array $integration, array $botConfig, array $context, string $text): array
    {
        $base = $this->handleWithLlm($integration, $botConfig, $context, $text);
        return [
            'reply_text' => $base['reply_text'] ?? null,
            'intent' => $base['intent'] ?? null,
            'slots' => $base['slots'] ?? null,
            'confidence' => $base['confidence'] ?? null,
            'next_question' => $base['next_question'] ?? null,
            'meta' => $base['meta'] ?? [],
        ];
    }

    // =========================================================
    // Knowledge base
    // =========================================================
    protected function searchKnowledgeBase(array $context, string $query): array
    {
        if ($query === '') return [];

        $tenantUserId = $this->resolveTenantUserId($context);
        if (!$tenantUserId) return [];

        $customerId = $context['customer']['id'] ?? null;
        $channelId  = $context['channel']['id'] ?? null;
        Logger::info("KB Search using tenant_user_id={$tenantUserId}, customer_id=" . ($customerId ?? 'null') . ", channel_id=" . ($channelId ?? 'null'));

        try {
            return $this->searchKnowledgeBaseInternal($tenantUserId, $query, $query);
        } catch (Exception $e) {
            Logger::error("KB search error: " . $e->getMessage());
            return [];
        }
    }

    protected function normalizeTextForKb(string $text): string
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', $t);
        $t = preg_replace('/[[:punct:]]+/u', '', $t);
        return trim($t);
    }

    protected function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    protected function searchKnowledgeBaseInternal(int $userId, string $enhancedQuery, ?string $originalQuery = null): array
    {
        $results = [];
        $originalQuery = $originalQuery ?? $enhancedQuery;

        $queryNorm = $this->normalizeTextForKb($enhancedQuery);
        $origNorm  = $this->normalizeTextForKb($originalQuery);

        if ($queryNorm === '' && $origNorm === '') return [];

        Logger::debug("KB Search: query='$enhancedQuery', normalized='$queryNorm', user_id=$userId");

        $sql = "SELECT * FROM customer_knowledge_base
                WHERE user_id = ?
                  AND is_active = 1
                  AND is_deleted = 0
                ORDER BY priority DESC";
        $allEntries = $this->db->query($sql, [$userId]);

        Logger::debug("KB Search: Found " . count($allEntries) . " total entries to check");

        foreach ($allEntries as $row) {
            $keywords = json_decode($row['keywords'] ?? '[]', true);
            if (!is_array($keywords)) $keywords = [];

            $isAdvanced = isset($keywords['mode']) && $keywords['mode'] === 'advanced';

            Logger::debug("KB Entry #{$row['id']} (priority={$row['priority']}): " .
                ($isAdvanced ? 'ADVANCED' : 'LEGACY') .
                " keywords=" . json_encode($keywords, JSON_UNESCAPED_UNICODE));

            if ($isAdvanced) {
                $matched = $this->matchAdvancedKeywords($queryNorm, $keywords);
                Logger::debug("  → Advanced match result: " . ($matched ? 'MATCHED' : 'NO MATCH'));

                if ($matched) {
                    $row['keywords'] = $keywords;
                    $row['metadata'] = json_decode($row['metadata'] ?? '{}', true) ?: [];
                    $row['matched_keyword'] = 'advanced_rules';
                    $row['match_score'] = 100;
                    $row['match_type'] = 'advanced';
                    $results[] = $row;
                    Logger::info("KB Match: Entry #{$row['id']} (ADVANCED) matched query='$enhancedQuery'");
                }
            } else {
                foreach ($keywords as $keyword) {
                    $kwNorm = $this->normalizeTextForKb((string)$keyword);
                    if ($kwNorm === '') continue;
                    if (mb_strlen($kwNorm, 'UTF-8') < 4) continue;

                    $foundEnhanced = mb_strpos($queryNorm, $kwNorm, 0, 'UTF-8') !== false;
                    $foundOriginal = mb_strpos($origNorm,  $kwNorm, 0, 'UTF-8') !== false;

                    if ($foundEnhanced || $foundOriginal) {
                        $row['keywords'] = $keywords;
                        $row['metadata'] = json_decode($row['metadata'] ?? '{}', true) ?: [];
                        $row['matched_keyword'] = $keyword;
                        $row['match_score'] = 100;
                        $row['match_type'] = 'exact_keyword';
                        $results[] = $row;
                        Logger::info("KB Match: Entry #{$row['id']} (LEGACY) matched keyword='$keyword' in query='$enhancedQuery'");
                        break;
                    }
                }
            }

            if (count($results) >= 5) break;
        }

        Logger::debug("KB Search: Total matches found: " . count($results));

        if (empty($results)) {
            $queryLength = mb_strlen($origNorm, 'UTF-8');
            if ($queryLength >= 6) {
                $term = "%" . $this->escapeLike($origNorm) . "%";
                $sql = "SELECT * FROM customer_knowledge_base
                        WHERE user_id = ?
                          AND is_active = 1
                          AND is_deleted = 0
                          AND (question LIKE ? ESCAPE '\\\\' OR answer LIKE ? ESCAPE '\\\\')
                        ORDER BY priority DESC
                        LIMIT 10";
                $partial = $this->db->query($sql, [$userId, $term, $term]);

                foreach ($partial as $row) {
                    $kw = json_decode($row['keywords'] ?? '[]', true);
                    if (!is_array($kw)) $kw = [];

                    $isAdvanced = isset($kw['mode']) && $kw['mode'] === 'advanced';
                    if ($isAdvanced) {
                        $ok = $this->matchAdvancedKeywords($origNorm, $kw);
                        if (!$ok) {
                            Logger::debug("KB Partial: SKIP advanced entry #{$row['id']} (rules not satisfied)");
                            continue;
                        }
                    }

                    $row['keywords'] = $kw;
                    $row['metadata'] = json_decode($row['metadata'] ?? '{}', true) ?: [];
                    $row['match_score'] = 60;
                    $row['match_type'] = 'partial';
                    $results[] = $row;

                    if (count($results) >= 5) break;
                }
            }
        }

        return $results;
    }

    protected function matchAdvancedKeywords(string $queryNorm, array $rules): bool
    {
        $toList = function ($v): array {
            if ($v === null) return [];
            if (is_string($v)) {
                $v = trim($v);
                return $v === '' ? [] : [$v];
            }
            if (!is_array($v)) return [];
            $out = [];
            foreach ($v as $item) {
                if (is_string($item)) {
                    $item = trim($item);
                    if ($item !== '') $out[] = $item;
                }
            }
            return $out;
        };

        $requireAll = $toList($rules['require_all'] ?? null);
        $requireAny = $toList($rules['require_any'] ?? null);
        $excludeAny = $toList($rules['exclude_any'] ?? null);

        $hasRequireAll = count($requireAll) > 0;
        $hasRequireAny = count($requireAny) > 0;

        if (!$hasRequireAll && !$hasRequireAny) return false;

        if (isset($rules['min_query_len'])) {
            $minLen = (int)$rules['min_query_len'];
            $actualLen = mb_strlen($queryNorm, 'UTF-8');
            if ($actualLen < $minLen) return false;
        }

        foreach ($excludeAny as $exclude) {
            $excludeNorm = $this->normalizeTextForKb($exclude);
            if ($excludeNorm !== '' && mb_strpos($queryNorm, $excludeNorm, 0, 'UTF-8') !== false) {
                return false;
            }
        }

        foreach ($requireAll as $required) {
            $requiredNorm = $this->normalizeTextForKb($required);
            $found = ($requiredNorm !== '' && mb_strpos($queryNorm, $requiredNorm, 0, 'UTF-8') !== false);
            if ($requiredNorm !== '' && !$found) return false;
        }

        if ($hasRequireAny) {
            $foundAny = false;
            foreach ($requireAny as $anyKeyword) {
                $anyNorm = $this->normalizeTextForKb($anyKeyword);
                $found = ($anyNorm !== '' && mb_strpos($queryNorm, $anyNorm, 0, 'UTF-8') !== false);
                if ($found) { $foundAny = true; break; }
            }
            if (!$foundAny) return false;
        }

        return true;
    }

    // ✅ FIXED: pending เฉพาะ "require_all ครบ" และ "สั้นกว่า min_query_len"
    protected function isAdvancedPendingMatch(string $queryNorm, array $rules): bool
    {
        $toList = function ($v): array {
            if ($v === null) return [];
            if (is_string($v)) {
                $v = trim($v);
                return $v === '' ? [] : [$v];
            }
            if (!is_array($v)) return [];
            $out = [];
            foreach ($v as $item) {
                if (is_string($item)) {
                    $item = trim($item);
                    if ($item !== '') $out[] = $item;
                }
            }
            return $out;
        };

        $requireAll = $toList($rules['require_all'] ?? null);
        $excludeAny = $toList($rules['exclude_any'] ?? null);

        if (empty($requireAll)) return false;

        foreach ($excludeAny as $ex) {
            $exNorm = $this->normalizeTextForKb($ex);
            if ($exNorm !== '' && mb_strpos($queryNorm, $exNorm, 0, 'UTF-8') !== false) {
                return false;
            }
        }

        foreach ($requireAll as $r) {
            $rNorm = $this->normalizeTextForKb($r);
            if ($rNorm !== '' && mb_strpos($queryNorm, $rNorm, 0, 'UTF-8') === false) {
                return false;
            }
        }

        if (isset($rules['min_query_len'])) {
            $minLen = (int)$rules['min_query_len'];
            $actual = mb_strlen($queryNorm, 'UTF-8');
            return $actual < $minLen;
        }

        return false;
    }

    protected function hasAdvancedKbPending(array $context, string $query): bool
    {
        $tenantUserId = $this->resolveTenantUserId($context);
        if (!$tenantUserId) return false;

        $qNorm = $this->normalizeTextForKb($query);
        if ($qNorm === '') return false;

        $sql = "SELECT id, keywords
            FROM customer_knowledge_base
            WHERE user_id = ?
              AND is_active = 1
              AND is_deleted = 0
            ORDER BY priority DESC";
        $rows = $this->db->query($sql, [$tenantUserId]);

        foreach ($rows as $row) {
            $kw = json_decode($row['keywords'] ?? '[]', true);
            if (!is_array($kw)) continue;

            $isAdvanced = isset($kw['mode']) && $kw['mode'] === 'advanced';
            if (!$isAdvanced) continue;

            if ($this->matchAdvancedKeywords($qNorm, $kw)) continue;

            if ($this->isAdvancedPendingMatch($qNorm, $kw)) {
                Logger::info("KB Pending: advanced entry #{$row['id']} waiting for more text");
                return true;
            }
        }

        return false;
    }

    // =========================================================
    // KB-only buffering
    // =========================================================
    protected function buildKbBufferedText(int $sessionId, string $currentText, array $bufferingCfg): string
    {
        $enabled = (bool)($bufferingCfg['kb_enabled'] ?? true);
        if (!$enabled) return $currentText;

        $windowSec   = (int)($bufferingCfg['kb_window_seconds'] ?? 25);
        $maxMessages = (int)($bufferingCfg['kb_max_messages'] ?? 2);

        $windowSec   = max(5, min(300, $windowSec));
        $maxMessages = max(2, min(10, $maxMessages));

        $limit = $maxMessages * 4;
        $sql = "SELECT role, text
                FROM chat_messages
                WHERE session_id = ?
                AND created_at >= (NOW() - INTERVAL {$windowSec} SECOND)
                ORDER BY created_at DESC
                LIMIT {$limit}";
        $rows = $this->db->query($sql, [$sessionId]);

        $collected = [];
        $countUser = 0;

        foreach ($rows as $r) {
            $role = (string)($r['role'] ?? '');
            $t = trim((string)($r['text'] ?? ''));

            if ($t === '') continue;
            if (stripos($t, '[image]') === 0) continue;

            if ($role === 'assistant') {
                if (mb_stripos($t, '[kb_pending]') === 0) {
                    continue;
                }
                break;
            }

            if ($role === 'user') {
                if ($t === $currentText) continue;

                $collected[] = $t;
                $countUser++;
                if ($countUser >= ($maxMessages - 1)) break;
            }
        }

        $collected = array_reverse($collected);
        $collected[] = $currentText;

        $merged = trim(preg_replace('/\s+/u', ' ', implode(' ', $collected)));
        return $merged !== '' ? $merged : $currentText;
    }

    // =========================================================
    // Small utils
    // =========================================================
    protected function resolveTenantUserId(array $context): ?int
    {
        $botProfile = $context['bot_profile'] ?? [];
        $channel    = $context['channel'] ?? [];

        $uid =
            ($botProfile['user_id'] ?? null)
            ?: ($channel['user_id'] ?? null)
            ?: ($context['tenant_user_id'] ?? null)
            ?: ($context['user_id'] ?? null);

        if (!$uid) return null;
        return (int)$uid;
    }

    protected function decodeJsonArray(?string $json): array
    {
        if (!$json) return [];
        $tmp = json_decode($json, true);
        return is_array($tmp) ? $tmp : [];
    }

    protected function extractJsonObject(string $content): ?array
    {
        $trimmed = trim($content);
        
        // ✅ Strip markdown code block (```json ... ```) - use DOTALL modifier
        // Handle both real newlines and escaped \n
        $trimmed = str_replace('\\n', "\n", $trimmed); // Convert escaped \n to real newlines
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
            Logger::info("extractJsonObject - stripped markdown", [
                'after_strip_length' => strlen($trimmed),
                'first_100_chars' => substr($trimmed, 0, 100)
            ]);
        }
        
        $jsonStart = strpos($trimmed, '{');
        $jsonEnd   = strrpos($trimmed, '}');
        if ($jsonStart === false || $jsonEnd === false || $jsonEnd <= $jsonStart) {
            Logger::warning("extractJsonObject - no valid JSON braces found", [
                'jsonStart' => $jsonStart,
                'jsonEnd' => $jsonEnd
            ]);
            return null;
        }

        $jsonString = substr($trimmed, $jsonStart, $jsonEnd - $jsonStart + 1);
        $parsed = json_decode($jsonString, true);
        
        if (!is_array($parsed)) {
            Logger::warning("extractJsonObject - json_decode failed", [
                'json_error' => json_last_error_msg(),
                'json_length' => strlen($jsonString),
                'first_100_chars' => substr($jsonString, 0, 100)
            ]);
        }
        
        return is_array($parsed) ? $parsed : null;
    }

    protected function renderTemplate(string $tpl, array $vars): string
    {
        $out = $tpl;
        foreach ($vars as $k => $v) {
            $val = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
            $out = str_replace('{{' . $k . '}}', $val, $out);
        }
        return $out;
    }

    /**
     * Replace single-brace template placeholders like {summary}, {business_type}
     * with actual values from slots
     */
    protected function replaceTe​mplatePlaceholders(string $template, array $slots): string
    {
        if (empty($slots) || strpos($template, '{') === false) {
            return $template;
        }

        $result = $template;

        // Replace each slot value
        foreach ($slots as $key => $value) {
            // Only replace string/number values, skip arrays/objects
            if (is_scalar($value) && $value !== null && $value !== '') {
                $placeholder = '{' . $key . '}';
                $result = str_replace($placeholder, (string)$value, $result);
            }
        }

        return $result;
    }

    protected function normalizePhone(string $s): string
    {
        $s = preg_replace('/[^\d]/', '', $s);
        if (!$s) return '';
        if (strpos($s, '66') === 0 && strlen($s) >= 11) {
            $s = '0' . substr($s, 2, 9);
        }
        return $s;
    }

    protected function normalizeAmount(string $s): string
    {
        $s = str_replace([',', '฿', 'บาท', ' '], '', $s);
        $s = preg_replace('/[^\d\.]/', '', $s);
        return $s;
    }


    // =========================================================
    // Policy-based guardrails
    // =========================================================
    
    /**
     * Get policy configuration from store.policies or top-level policies
     */
    protected function getPolicy(array $config): array
    {
        $store = $config['store'] ?? [];
        $pol = $store['policies'] ?? [];
        // allow also top-level
        if (!is_array($pol) || empty($pol)) {
            $pol = $config['policies'] ?? [];
        }
        return is_array($pol) ? $pol : [];
    }

    /**
     * Check if query contains out-of-scope keywords defined in policy
     */
    protected function isOutOfScopeByPolicy(string $text, array $policy): bool
    {
        $t = mb_strtolower($text, 'UTF-8');
        $keywords = $policy['out_of_scope_keywords'] ?? [];
        if (!is_array($keywords)) return false;

        foreach ($keywords as $kw) {
            $kw = mb_strtolower(trim((string)$kw), 'UTF-8');
            if ($kw !== '' && mb_strpos($t, $kw) !== false) {
                Logger::info("Policy: Out-of-scope keyword matched: '{$kw}' in query: '{$text}'");
                return true;
            }
        }
        return false;
    }

    /**
     * Apply policy guards to prevent hallucination and enforce backend requirements
     */
    protected function applyPolicyGuards(string $reply, ?string $intent, array $config, array $templates, bool $backendEnabled, bool $skipHallucinationCheck = false, array $slots = []): string
    {
        $policy = $this->getPolicy($config);

        // =========================================================
        // 🔒 PRICING POLICY GUARD (Box Design specific)
        // =========================================================
        $pricingPolicy = $config['policies']['pricing'] ?? [];
        if (!empty($pricingPolicy['strict_pricing']) && !empty($pricingPolicy['enabled'])) {
            // Define ONLY allowed pricing numbers from templates
            $allowedPrices = [
                '15,900', '15900', '3,900', '3900',  // Plan 1
                '79,000', '79000',  // Plan 2
                // Plan 3 is "ตามโปรเจกต์" no specific number
            ];
            
            // Detect if reply contains pricing information
            $hasPricingKeywords = (
                mb_stripos($reply, 'ราคา') !== false ||
                mb_stripos($reply, 'บาท') !== false ||
                mb_stripos($reply, 'เดือน') !== false ||
                mb_stripos($reply, 'plan') !== false ||
                mb_stripos($reply, 'แพลน') !== false
            );
            
            if ($hasPricingKeywords) {
                // Extract all numbers from reply (including Thai number format with commas)
                preg_match_all('/[\d,]+/', $reply, $matches);
                $foundNumbers = $matches[0] ?? [];
                
                $hasUnauthorizedPrice = false;
                foreach ($foundNumbers as $num) {
                    // Skip small numbers (likely not prices)
                    $cleanNum = str_replace(',', '', $num);
                    if ($cleanNum < 100) continue;
                    
                    // Check if this number is NOT in allowed list
                    if (!in_array($num, $allowedPrices) && !in_array($cleanNum, $allowedPrices)) {
                        $hasUnauthorizedPrice = true;
                        Logger::info("Pricing Policy: Detected unauthorized price '{$num}' in reply");
                        break;
                    }
                }
                
                // If unauthorized pricing detected, replace with appropriate template
                if ($hasUnauthorizedPrice) {
                    // Determine which template to use based on context
                    $useTemplate = 'pricing_only';  // Default
                    
                    // If reply mentions business/summary, use full template
                    if (mb_stripos($reply, 'สรุป') !== false || mb_stripos($reply, 'ธุรกิจ') !== false) {
                        $useTemplate = 'summary_with_all_plans';
                    }
                    
                    $guardedReply = $templates[$useTemplate] ?? $templates['fallback'] ?? $reply;
                    
                    // ✅ Replace placeholders like {summary}, {business_type}
                    $guardedReply = $this->replaceTe​mplatePlaceholders($guardedReply, $slots);
                    
                    Logger::info("Pricing Policy: Blocked hallucinated pricing - using template '{$useTemplate}'", [
                        'original_reply_preview' => mb_substr($reply, 0, 100),
                        'unauthorized_numbers' => $foundNumbers,
                    ]);
                    
                    return $guardedReply;
                }
            }
        }

        // 1) Hard gate: ถ้า intent ต้องใช้ backend แต่ backend ปิด/ไม่พร้อม => ตอบด้วย template ที่กำหนด
        $require = $policy['require_backend_for_intents'] ?? [];
        if (is_array($require) && $intent && in_array($intent, $require, true) && !$backendEnabled) {
            $k = (string)($policy['no_backend_reply_template_key'] ?? 'no_backend_product_check');
            $guardedReply = $templates[$k] ?? ($templates['fallback'] ?? $reply);
            $guardedReply = $this->replaceTe​mplatePlaceholders($guardedReply, $slots);
            Logger::info("Policy: Intent '{$intent}' requires backend but backend disabled - using template '{$k}'");
            return $guardedReply;
        }

        // 2) Block phrases กันหลุดคำว่า "มีของ/มีหลายแบบ" ตอน backend ไม่พร้อม
        // BUT: ถ้ามาจาก backend จริงๆ (skipHallucinationCheck=true) ให้ผ่าน
        if (!$backendEnabled && !$skipHallucinationCheck) {
            $blocks = $policy['hallucination_block_phrases'] ?? [];
            if (is_array($blocks)) {
                foreach ($blocks as $p) {
                    $p = trim((string)$p);
                    if ($p !== '' && mb_strpos($reply, $p) !== false) {
                        $k = (string)($policy['no_backend_reply_template_key'] ?? 'no_backend_product_check');
                        $guardedReply = $templates[$k] ?? ($templates['fallback'] ?? $reply);
                        $guardedReply = $this->replaceTe​mplatePlaceholders($guardedReply, $slots);
                        Logger::info("Policy: Blocked hallucination phrase '{$p}' in reply - using template '{$k}'");
                        return $guardedReply;
                    }
                }
            }
        }

        return $reply;
    }

    // =========================================================
    // ✅ Store info detector
    // =========================================================
    protected function looksLikeStoreInfoQuestion(string $text): bool
    {
        $t = mb_strtolower($text, 'UTF-8');
        $keys = [
            'ร้านชื่ออะไร','ชื่อร้าน','ชื่อร้านอะไร','ขอรายละเอียดร้าน','รายละเอียดร้าน',
            'ร้านนี้ขายอะไร','ขายอะไร','ร้านทำอะไร','ข้อมูลร้าน','ขอข้อมูลร้าน',
            'contact','ติดต่อร้าน','ช่องทางติดต่อ'
        ];
        foreach ($keys as $k) {
            if (mb_stripos($t, $k, 0, 'UTF-8') !== false) return true;
        }
        return false;
    }

    // =========================================================
    // ✅ Customer Profile ID lookup
    // =========================================================
    protected function getCustomerProfileId(?string $platform, ?string $externalUserId): ?int
    {
        if (empty($platform) || empty($externalUserId)) {
            return null;
        }
        
        try {
            $result = $this->db->queryOne(
                "SELECT id FROM customer_profiles WHERE platform = ? AND platform_user_id = ? LIMIT 1",
                [$platform, $externalUserId]
            );
            return $result ? (int)$result['id'] : null;
        } catch (Throwable $e) {
            Logger::warning('[ROUTER_V1] Failed to get customer profile: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================
    // ✅ Track Product Interest
    // =========================================================
    protected function trackProductInterest(int $customerProfileId, array $slots, array $options = []): void
    {
        try {
            $interestService = new CustomerInterestService();
            
            $productData = [
                'product_ref_id' => $slots['product_ref_id'] ?? null,
                'product_name' => $slots['product_name'] ?? null,
                'product_category' => $slots['product_category'] ?? null,
                'product_price' => $slots['product_price'] ?? null,
            ];
            
            $interestOptions = [
                'channel_id' => $options['channel_id'] ?? null,
                'case_id' => $options['case_id'] ?? null,
                'message_text' => $options['message_text'] ?? null,
                'interest_type' => 'inquired',
                'source' => 'chat',
                'metadata' => [
                    'intent' => $options['intent'] ?? null,
                    'slots' => $slots,
                ],
            ];
            
            $interestService->trackProductInterest($customerProfileId, $productData, $interestOptions);
            
        } catch (Throwable $e) {
            Logger::warning('[ROUTER_V1] Failed to track product interest: ' . $e->getMessage());
        }
    }

    // normalizeBusinessTypeAnswer() moved to RouterV2BoxDesignHandler
}
