<?php
// filepath: /opt/lampp/htdocs/autobot/includes/bot/RouterV1Handler.php

require_once __DIR__ . '/BotHandlerInterface.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Logger.php';
require_once __DIR__ . '/CaseEngine.php';
require_once __DIR__ . '/../services/CustomerInterestService.php';
require_once __DIR__ . '/../services/ProductSearchService.php';

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
        $traceId = (string) ($context['trace_id'] ?? '');
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
            'text_len' => mb_strlen((string) ($context['message']['text'] ?? ''), 'UTF-8'),
            'has_attachments' => !empty($context['message']['attachments'] ?? null),
        ]);

        try {
            $botProfile = $context['bot_profile'] ?? [];
            $config = $this->decodeJsonArray($botProfile['config'] ?? null);

            // Templates
            $templates = $config['response_templates'] ?? [];
            $greeting = $templates['greeting'] ?? 'สวัสดีค่ะ มีอะไรให้ช่วยไหมคะ';
            $fallback = $templates['fallback'] ?? 'ขออภัยค่ะ พอแจ้งรายละเอียดเพิ่มเติมได้ไหมคะ';

            // Persona & behavior flags
            $persona = $config['persona'] ?? [];
            $skills = $config['skills'] ?? [];
            $handoffCfg = $config['handoff'] ?? [];
            $bufferingCfg = $config['buffering'] ?? [];

            // Store info (optional config)
            $storeCfg = $config['store'] ?? [];
            // Example config you can add:
            // "store": { "name":"เฮง เฮง เฮง", "description":"ร้านแบรนด์เนมมือสอง", "address":"...", "hours":"...", "contact":"LINE: ... โทร: ..." }

            // Integrations
            $integrations = $context['integrations'] ?? [];
            $googleNlpIntegrations = $integrations['google_nlp'] ?? [];
            $googleVisionIntegrations = $integrations['google_vision'] ?? [];
            $llmIntegrations = $integrations['llm'] ?? ($integrations['openai'] ?? ($integrations['gemini'] ?? []));

            $googleNlp = $googleNlpIntegrations[0] ?? null;
            $googleVision = $googleVisionIntegrations[0] ?? null;
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
            $text = trim((string) ($message['text'] ?? ''));

            // ✅ DEBUG LOG: Detailed message intake for LINE vs FB comparison
            Logger::info("INCOMING_RAW_SUMMARY", [
                'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? null),
                'channel_id' => $context['channel']['id'] ?? null,
                'external_user_id' => $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null),
                'msg_keys' => array_keys($message),
                'msg_type_field' => $message['message_type'] ?? ($message['type'] ?? null),
                'has_attachments' => !empty($message['attachments']),
                'attachments_shape' => !empty($message['attachments']) ? array_map(function ($a) {
                    return [
                        'type' => $a['type'] ?? null,
                        'has_url' => !empty($a['url']) || !empty($a['payload']['url']),
                        'mime' => $a['mime_type'] ?? null,
                    ];
                }, (array) $message['attachments']) : [],
                'text_len' => mb_strlen($text, 'UTF-8'),
                'trace_id' => $traceId,
            ]);

            // ✅ ignore echo/system messages
            $isEcho = (bool) ($message['is_echo'] ?? false);
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
            $channel = $context['channel'] ?? [];
            $channelId = $channel['id'] ?? null;
            $externalUserId = $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null);

            $session = null;
            $sessionId = null;
            if ($channelId && $externalUserId) {
                $session = $this->findOrCreateSession((int) $channelId, (string) $externalUserId);
                $sessionId = $session['id'] ?? null;
                if ($sessionId)
                    $context['session_id'] = (int) $sessionId;
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
            $delayMs = (int) ($config['llm']['reply_delay_ms'] ?? 0);
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
                    // ✅ Use configurable timeout (default 5 minutes = 300 seconds)
                    $adminActiveThreshold = (int) ($handoffCfg['timeout_seconds'] ?? 300);
                    $lastAdminTime = strtotime((string) $lastAdminMsg);
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
                    if ($text !== '')
                        $this->storeMessage($sessionId, 'user', $text);
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

            // =========================================================
            // ✅ Delivery de-duplication (protect against duplicate webhook deliveries)
            // =========================================================
            $sessionPolicy = $config['session_policy'] ?? [];
            if (!$isAdmin && $sessionId && $messageType === 'text' && $text !== '') {
                $dedupeEnabled = (bool) ($sessionPolicy['dedupe_enabled'] ?? true);
                $dedupeWindowSec = (int) ($sessionPolicy['dedupe_window_seconds'] ?? 3);
                if ($dedupeEnabled && $this->isDuplicateDelivery((int) $sessionId, $text, $dedupeWindowSec)) {
                    Logger::info('[ROUTER_V1] Suppress duplicate delivery', [
                        'trace_id' => $traceId,
                        'session_id' => $sessionId,
                        'window_sec' => $dedupeWindowSec,
                        'text' => mb_substr($text, 0, 120, 'UTF-8'),
                    ]);

                    return [
                        'reply_text' => null,
                        'actions' => [],
                        'meta' => [
                            'handler' => 'router_v1',
                            'reason' => 'duplicate_delivery_suppressed',
                            'trace_id' => $traceId,
                        ]
                    ];
                }
            }

            // ✅ Anti-spam / repeat message behavior (text only)
            $antiSpamCfg = $config['anti_spam'] ?? [];
            $antiSpamEnabled = (bool) ($antiSpamCfg['enabled'] ?? true);
            $repeatThreshold = (int) ($antiSpamCfg['repeat_threshold'] ?? 3);
            $repeatWindowSec = (int) ($antiSpamCfg['window_seconds'] ?? 25);
            $repeatAction = (string) ($antiSpamCfg['action'] ?? 'template'); // template | silent | handoff
            $repeatTemplateKey = (string) ($antiSpamCfg['template_key'] ?? 'repeat_detected');
            $repeatDefaultReply = (string) ($antiSpamCfg['default_reply']
                ?? 'เหมือนข้อความเดิมเมื่อสักครู่ค่ะ 😊 รบกวนพิมพ์รายละเอียดเพิ่มอีกนิดนะคะ');

            // New: extra safety bypasses to prevent false positives
            $antiSpamMinLen = (int) ($antiSpamCfg['min_length'] ?? 0); // optional config
            $antiSpamBypassShortLen = (int) ($antiSpamCfg['bypass_short_length'] ?? 3); // default: bypass <= 3 chars

            if ($antiSpamEnabled && !$isAdmin && $sessionId && $messageType === 'text' && $text !== '') {
                $normalized = $this->normalizeTextForRepeat($text);

                // Bypass ultra-short texts and common acknowledgements
                $normalizedLen = mb_strlen($normalized, 'UTF-8');
                $ackSet = [
                    'ok',
                    'okay',
                    'kk',
                    'k',
                    'thx',
                    'thanks',
                    'ty',
                    'ค่ะ',
                    'ครับ',
                    'คับ',
                    'จ้า',
                    'ได้',
                    'ได้ค่ะ',
                    'ได้ครับ',
                    'yes',
                    'no',
                    'y',
                    'n',
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

                        if ($reply !== '')
                            $this->storeMessage($sessionId, 'assistant', $reply);

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

                if ($sessionId && $reply !== '')
                    $this->storeMessage($sessionId, 'assistant', $reply);
                $this->logBotReply($context, $reply, 'text');

                Logger::info('[ROUTER_V1] end', [
                    'trace_id' => $traceId,
                    'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                    'reason' => $meta['reason'] ?? null,
                    'reply_len' => mb_strlen((string) $reply, 'UTF-8'),
                    'actions_count' => 0,
                ]);

                return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
            }

            // =========================================================
            // ✅ Policy guard: Out-of-scope check
            // =========================================================
            $policy = $this->getPolicy($config);
            if ($text !== '' && $this->isOutOfScopeByPolicy($text, $policy)) {
                $key = (string) ($policy['out_of_scope_template_key'] ?? 'out_of_scope');
                $reply = $templates[$key] ?? $fallback;
                $meta['reason'] = 'policy_out_of_scope';

                if ($sessionId && $reply !== '')
                    $this->storeMessage($sessionId, 'assistant', $reply);
                $this->logBotReply($context, $reply, 'text');

                return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
            }

            // =========================================================
            // ✅ Quick answers: Store info (before KB / routing)
            // =========================================================
            if ($this->looksLikeStoreInfoQuestion($text)) {
                $name = trim((string) ($storeCfg['name'] ?? ''));
                $desc = trim((string) ($storeCfg['description'] ?? ''));
                $contact = trim((string) ($storeCfg['contact'] ?? ''));
                $hours = trim((string) ($storeCfg['hours'] ?? ''));

                // If you want address to be handled by KB, keep it out here.
                $reply = $templates['store_info']
                    ?? ($name ? "ร้าน{$name}ค่ะ 😊 " : "ยินดีให้ข้อมูลร้านค่ะ 😊 ")
                    . ($desc ? $desc . " " : "")
                    . ($hours ? "เวลาเปิด-ปิด: {$hours} " : "")
                    . ($contact ? "ติดต่อ: {$contact}" : "");

                $reply = trim($reply);
                if ($reply === '')
                    $reply = $fallback;

                $meta['reason'] = 'store_info_quick_answer';
                $meta['route'] = 'store_info';

                if ($sessionId && $reply !== '')
                    $this->storeMessage($sessionId, 'assistant', $reply);
                $this->logBotReply($context, $reply, 'text');

                return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
            }

            // =========================================================
            // ✅ MENU RESET DETECTION: Clear checkout state when user clicks menu buttons
            // Keywords like "ดูสินค้า", "สอบถาม" should reset checkout and start fresh
            // =========================================================
            $menuResetKeywords = '/^(ดูสินค้า|สอบถาม|ติดต่อ|เมนู|menu|หน้าหลัก|กลับ|ยกเลิก|cancel|หยุด)$/iu';
            $currentCheckoutStepForReset = trim((string) ($lastSlots['checkout_step'] ?? ''));
            $hasProductInSession = ((float) ($lastSlots['product_price'] ?? 0)) > 0 || trim((string) ($lastSlots['product_name'] ?? '')) !== '';
            
            // ✅ Strip emoji before matching
            $textForMenuCheck = preg_replace('/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}❌✅⭕🔴⚪💬📍💳🚚🛍️]/u', '', $text);
            $textForMenuCheck = trim($textForMenuCheck);
            
            if (preg_match($menuResetKeywords, $textForMenuCheck) && ($currentCheckoutStepForReset !== '' || $hasProductInSession)) {
                Logger::info('[MENU_RESET] Resetting checkout state for menu keyword', [
                    'trace_id' => $traceId,
                    'text' => $text,
                    'old_checkout_step' => $currentCheckoutStepForReset,
                    'had_product' => $hasProductInSession,
                ]);
                
                // ✅ Full reset - clear everything
                $resetSlots = [
                    'checkout_step' => '',
                    'payment_method' => '',
                    'delivery_method' => '',
                    'order_status' => '',
                    'address_buffer' => '',
                    'product_code' => '',
                    'product_name' => '',
                    'product_price' => 0,
                    'product_ref_id' => '',
                    'product_image_url' => '',
                    'first_payment' => 0,
                ];
                $lastSlots = $this->mergeSlots($lastSlots, $resetSlots);
                $this->updateSessionState((int) $sessionId, 'menu_reset', $resetSlots);
                
                // ✅ Don't return - let the flow continue to handle "ดูสินค้า" or "สอบถาม" normally
            }

            // =========================================================
            // ✅ GENERIC INQUIRY DETECTION: Handle bare "สอบถาม" without specific question
            // User just wants to ask questions - give them a helpful prompt
            // =========================================================
            if (preg_match('/^(สอบถาม|สอบถามเพิ่ม|ถามหน่อย|มีคำถาม)$/iu', $textForMenuCheck)) {
                Logger::info('[GENERIC_INQUIRY] User wants to ask questions', [
                    'trace_id' => $traceId,
                    'text' => $text,
                ]);
                
                $reply = "ยินดีค่ะ 😊 สอบถามได้เลยนะคะ\n\n";
                $reply .= "พิมพ์คำถามได้เลยค่ะ เช่น:\n";
                $reply .= "• มีสินค้า [ชื่อ/รหัส] ไหม?\n";
                $reply .= "• ราคาเท่าไหร่?\n";
                $reply .= "• นโยบายเปลี่ยน/คืนสินค้า\n";
                $reply .= "• วิธีผ่อนชำระ\n";
                
                if ($sessionId)
                    $this->storeMessage($sessionId, 'assistant', $reply);
                $this->logBotReply($context, $reply, 'text');
                
                $quickReplyActions = [
                    [
                        'type' => 'quick_reply',
                        'items' => [
                            ['label' => '🛍️ ดูสินค้า', 'text' => 'ดูสินค้า'],
                            ['label' => '📋 นโยบายคืนสินค้า', 'text' => 'นโยบายคืนสินค้า'],
                            ['label' => '💳 วิธีผ่อนชำระ', 'text' => 'วิธีผ่อนชำระ'],
                            ['label' => '📞 ติดต่อร้าน', 'text' => 'ติดต่อร้าน'],
                        ]
                    ]
                ];
                
                return ['reply_text' => $reply, 'actions' => $quickReplyActions, 'meta' => ['reason' => 'generic_inquiry_prompt']];
            }

            // =========================================================
            // ✅ POLICY QUESTION DETECTION: Route to KB BEFORE product detection
            // Questions about return/warranty/policy should go to KB, NOT product search
            // =========================================================
            $policyQuestionPattern = '/(\bเปลี่ยน.*คืน|\bคืน.*สินค้า|\bประกัน|\bรับประกัน|\breturn|\brefund|\bwarranty|\bexchange|\bนโยบาย|\bpolicy|\bเงื่อนไข|\bข้อตกลง|\bเปลี่ยนสินค้า|\bคืนเงิน|\bรับซื้อคืน)/iu';
            if (preg_match($policyQuestionPattern, $text)) {
                Logger::info('[POLICY_QUESTION] Detected policy question - routing to KB first', [
                    'trace_id' => $traceId,
                    'text' => $text,
                ]);
                
                // Search KB for policy answer
                $kbResults = $this->searchKnowledgeBase($context, $text);
                if (!empty($kbResults) && isset($kbResults[0])) {
                    $bestMatch = $kbResults[0];
                    $reply = (string) ($bestMatch['answer'] ?? $fallback);

                    $meta['knowledge_base'] = [
                        'matched' => true,
                        'match_type' => $bestMatch['match_type'] ?? 'policy_question',
                        'match_score' => $bestMatch['match_score'] ?? 0,
                        'matched_keyword' => $bestMatch['matched_keyword'] ?? null,
                        'category' => $bestMatch['category'] ?? 'policy',
                    ];
                    $meta['reason'] = 'policy_question_kb_answer';
                    $meta['route'] = 'policy';

                    if ($sessionId && $reply !== '')
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    return [
                        'reply_text' => $reply,
                        'actions' => [],
                        'meta' => $meta,
                    ];
                }
                // If no KB match, fall through to LLM (not product search)
            }

            // =========================================================
            // ✅ EARLY PURCHASE DETECTION: Catch "สนใจ/เอา/ซื้อ" BEFORE LLM
            // When product context exists, these words should start checkout,
            // NOT be interpreted as a new product search by LLM
            // =========================================================
            $earlyProductPrice = (float) ($lastSlots['product_price'] ?? 0);
            $earlyProductName = trim((string) ($lastSlots['product_name'] ?? '')); // ✅ Check name too
            $earlyCheckoutStep = trim((string) ($lastSlots['checkout_step'] ?? ''));

            // ✅ DEBUG: Log early checkout state check
            Logger::info('[EARLY_CHECKOUT_CHECK]', [
                'trace_id' => $traceId,
                'text' => $text,
                'earlyProductPrice' => $earlyProductPrice,
                'earlyProductName' => $earlyProductName,
                'earlyCheckoutStep' => $earlyCheckoutStep,
                'hasProductContext' => ($earlyProductPrice > 0 || $earlyProductName !== ''),
            ]);

            // ✅ Logic: ถ้ามีชื่อสินค้า หรือ มีราคา (อย่างใดอย่างหนึ่ง)
            // ถ้า checkout_step ว่าง → เริ่ม checkout ใหม่
            // ถ้า checkout_step = ask_payment และ user พิมพ์ "สนใจ" → ถามอีกครั้ง
            if ($earlyProductPrice > 0 || $earlyProductName !== '') {
                // ✅ FIX: Regex Relaxed - รองรับทั้งคำเดี่ยวและมีคำต่อท้าย
                // จับคำขึ้นต้นด้วย สนใจ/เอา/ซื้อ ตามด้วยอะไรก็ได้
                $purchaseRegex = '/^(สนใจ|เอา|ซื้อ|ตกลง|จอง|cf|เอาเลย|ซื้อเลย|รับเลย|รับ)/iu';
                $hasInterestWord = preg_match($purchaseRegex, trim($text));
                
                // ✅ NEW: ตรวจว่าข้อความมี product code ไหม (สำหรับ กรณี "สนใจสินค้า ROL-DAY-001")
                $hasProductCodeInText = preg_match('/\b([A-Z]{2,4}[-_][A-Z]{2,4}[-_]\d{2,4})\b/i', $text);
                
                // ✅ เงื่อนไขเริ่ม/ถาม checkout
                $shouldStartCheckout = false;
                
                // ✅ FIX: "มีไหม/อยู่ไหม" = inquiry ไม่ใช่ interest → ไม่ควรเข้า checkout
                // ลูกค้าถามว่า "มีไหม ROL-DAY-001" คือต้องการดูสินค้า ยังไม่ได้สนใจซื้อ
                $isInquiryNotInterest = preg_match('/(มีไหม|อยู่ไหม|ยังอยู่ไหม|ยังมีไหม|หมดยัง|เหลือไหม|ถามหน่อย|สอบถาม)/iu', $text);
                
                if ($isInquiryNotInterest) {
                    // This is inquiry - don't start checkout, let product search handle it
                    Logger::info('[EARLY_CHECKOUT] Skipping - inquiry pattern detected, not interest', [
                        'trace_id' => $traceId,
                        'text' => $text,
                    ]);
                    $shouldStartCheckout = false;
                } elseif ($earlyCheckoutStep === '' && $hasInterestWord) {
                    // ไม่มี checkout ค้าง + พิมพ์สนใจ → เริ่มใหม่
                    $shouldStartCheckout = true;
                } elseif ($earlyCheckoutStep === 'ask_payment' && $hasInterestWord) {
                    // checkout ค้างที่ ask_payment + พิมพ์สนใจ → ถามอีกครั้ง
                    // ✅ FIX: ไม่ใช้ $hasProductCodeInText alone - ต้องมี interest word ด้วย
                    $shouldStartCheckout = true;
                    Logger::info('[EARLY_CHECKOUT] Re-asking payment for stale session', [
                        'trace_id' => $traceId,
                        'hasInterestWord' => $hasInterestWord,
                    ]);
                }
                
                if ($shouldStartCheckout) {
                    Logger::info('[EARLY_CHECKOUT] Product context detected, starting checkout', [
                        'trace_id' => $traceId,
                        'product_price' => $earlyProductPrice,
                        'product_name' => $earlyProductName,
                        'text' => $text,
                    ]);

                    // Build checkout response (earlyProductName already set above)
                    $earlyProductCode = trim((string) ($lastSlots['product_code'] ?? ''));

                    // Update slots for checkout
                    $slots = $this->mergeSlots($lastSlots, ['checkout_step' => 'ask_payment']);
                    $this->updateSessionState((int) $sessionId, 'ask_payment', $slots);

                    $reply = "ยินดีค่ะ 😊\n\n";
                    $reply .= "📦 {$earlyProductName}\n";
                    if ($earlyProductCode !== '') {
                        $reply .= "🏷️ รหัส: {$earlyProductCode}\n";
                    }
                    $reply .= "💰 " . number_format($earlyProductPrice, 0) . " บาท\n\n";
                    $reply .= "สะดวกชำระแบบไหนดีคะ?\n";
                    $reply .= "1️⃣ โอนเต็ม\n";
                    $reply .= "2️⃣ ผ่อน 3 งวด (+3% ค่าดำเนินการครั้งแรก)\n";
                    $reply .= "3️⃣ มัดจำ 10%";

                    if ($sessionId)
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    $quickReplyActions = [
                        [
                            'type' => 'quick_reply',
                            'items' => [
                                ['label' => '💰 โอนเต็ม', 'text' => 'โอนเต็ม'],
                                ['label' => '💳 ผ่อน 3 งวด', 'text' => 'ผ่อน 3 งวด'],
                                ['label' => '🎯 มัดจำ', 'text' => 'มัดจำ'],
                                ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                            ]
                        ]
                    ];

                    $meta['reason'] = 'early_checkout_detection';
                    return ['reply_text' => $reply, 'actions' => $quickReplyActions, 'meta' => $meta];
                }
            }

            // =========================================================
            // ✅ Follow-up: ใช้ last_image_url เมื่อ user ถาม "มีไหม/ราคา" หลังส่งรูป
            // =========================================================
            if ($sessionId && !$isAdmin) {
                $follow = $this->tryHandleFollowupFromLastMedia(
                    (int) $sessionId,
                    $lastIntent,
                    $lastSlots,
                    $context,
                    $config,
                    $templates,
                    $text
                );

                if (!empty($follow['handled'])) {
                    $reply = (string) ($follow['reply_text'] ?? $fallback);
                    $meta['reason'] = $follow['reason'] ?? 'followup_handled';
                    $meta['route'] = $follow['route'] ?? $meta['route'];
                    if (!empty($follow['meta']))
                        $meta['followup'] = $follow['meta'];

                    if (!empty($follow['intent'])) {
                        $meta['intent'] = $follow['intent'];
                        $meta['slots'] = $follow['slots'] ?? null;
                        $this->updateSessionState((int) $sessionId, $follow['intent'], $follow['slots'] ?? []);
                    }

                    if ($reply !== '')
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
                }
            }

            // =========================================================
            // ✅ Product context: reset / change / select from last list
            // =========================================================
            if ($sessionId && !$isAdmin) {
                $productContextKeys = $sessionPolicy['product_context_keys'] ?? [
                    'product_code',
                    'product_name',
                    'product_ref_id',
                    'product_price',
                    'last_product_query',
                    'last_product_candidates',
                    'last_product_candidates_ts',
                ];

                // Explicit reset command
                if ($this->looksLikeResetContext($text, $sessionPolicy)) {
                    $this->removeSlotKeys((int) $sessionId, $productContextKeys);
                    $reply = $templates['reset_confirmed'] ?? "โอเคค่ะ ✅ ล้างบริบทเดิมแล้วนะคะ\nตอนนี้อยากให้ช่วยหา 'สินค้า/รุ่น/รหัส/งบ' อะไรดีคะ? 😊";
                    $meta['reason'] = 'reset_context';

                    if ($reply !== '') {
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    }
                    $this->logBotReply($context, $reply, 'text');

                    return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta];
                }

                // User says "change product" => clear product-only cache to avoid stale answers
                if ($this->looksLikeChangeProduct($text, $sessionPolicy)) {
                    $this->removeSlotKeys((int) $sessionId, $productContextKeys);
                }

                // ✅ CRITICAL: Skip product selection if already in checkout flow
                // When checkout_step is set (ask_payment, ask_delivery, etc.), "1", "2", "3" 
                // should go to checkout flow, NOT product selection from candidates list
                $currentCheckoutStep = trim((string) ($lastSlots['checkout_step'] ?? ''));
                $isInCheckoutFlow = in_array($currentCheckoutStep, ['ask_payment', 'ask_delivery', 'ask_address'], true);

                // Selection from last candidates list: "1" / "ข้อ 2" / "เอาอันที่ 3"
                // ✅ Only process if NOT in checkout flow
                $sel = $this->detectSelectionIndex($text);
                if ($sel !== null && !$isInCheckoutFlow) {
                    $cands = $this->getRecentProductCandidates($lastSlots, $sessionPolicy);
                    if (!empty($cands)) {
                        $idx = $sel - 1;
                        if (isset($cands[$idx])) {
                            $p = $cands[$idx];
                            $pName = trim((string) ($p['name'] ?? ''));
                            $pCode = trim((string) ($p['code'] ?? ''));
                            $pPrice = (string) ($p['price'] ?? '');
                            $pRef = $p['ref_id'] ?? null;
                            $pImg = $p['image_url'] ?? null;

                            // Build a more sales-friendly reply
                            // ✅ ไม่ถามซ้ำ "สนใจทำรายการแบบไหน" เพราะจะไปถามวิธีชำระใน checkout flow
                            $tpl = $templates['product_selected']
                                ?? "โอเคค่ะ 😊 {{name}}" . ($pCode ? " ({{code}})" : "") . ($pPrice !== '' ? "\n💰 {{price}} บาท" : "")
                                . "\n\nพิมพ์ 'สนใจ' หรือถามรายละเอียดเพิ่มได้เลยนะคะ";
                            $reply = $this->renderTemplate($tpl, [
                                'name' => $pName ?: 'สินค้า',
                                'code' => $pCode,
                                'price' => number_format((float) $pPrice, 0),
                            ]);

                            // ✅ ไม่ส่งรูปซ้ำ - ลูกค้าเห็นรูปไปแล้วตอนค้นหา
                            $actionsOut = [];

                            // Create/update case
                            try {
                                $caseEngine = new CaseEngine($config, $context);
                                $caseSlots = [
                                    'product_ref_id' => $pRef,
                                    'product_code' => $pCode,
                                    'product_name' => $pName,
                                    'product_price' => $pPrice,
                                    'product_image_url' => $pImg,
                                ];
                                $case = $caseEngine->getOrCreateCase(CaseEngine::CASE_PRODUCT_INQUIRY, $caseSlots);
                                $meta['case'] = ['id' => $case['id'] ?? null, 'case_no' => $case['case_no'] ?? null];
                            } catch (Throwable $e) {
                                Logger::error('[ROUTER_V1] case create failed (selection)', ['error' => $e->getMessage(), 'trace_id' => $traceId]);
                            }

                            // Update session with selected product
                            $slots = $this->mergeSlots($lastSlots, [
                                'product_ref_id' => $pRef,
                                'product_code' => $pCode,
                                'product_name' => $pName,
                                'product_price' => $pPrice,
                                'product_image_url' => $pImg,
                            ]);
                            $this->updateSessionState((int) $sessionId, 'product_selected', $slots);

                            if ($reply !== '') {
                                $this->storeMessage($sessionId, 'assistant', $reply);
                            }
                            $this->logBotReply($context, $reply, 'text');

                            return [
                                'reply_text' => $reply,
                                'actions' => $actionsOut,
                                'meta' => $meta,
                            ];
                        }
                    }
                }

                // Selection by price: "ตัวราคา 280000", "เอาตัว 195000", "ราคา 68000", "195,000"
                // ✅ FIX: Support comma-separated numbers like "195,000"
                if (preg_match('/(?:ตัว|เอา)?(?:ราคา|price)?\s*([\d,]{3,10})/iu', $text, $priceMatch)) {
                    // Remove commas from matched number
                    $targetPrice = (int) str_replace(',', '', $priceMatch[1]);
                    $cands = $this->getRecentProductCandidates($lastSlots, $sessionPolicy);
                    if (!empty($cands) && $targetPrice > 0) {
                        foreach ($cands as $p) {
                            // Also clean price from candidate (could have comma)
                            $pPrice = (int) str_replace(',', '', (string) ($p['price'] ?? 0));
                            if ($pPrice === $targetPrice) {
                                $pName = trim((string) ($p['name'] ?? ''));
                                $pCode = trim((string) ($p['code'] ?? ''));
                                $pRef = $p['ref_id'] ?? null;
                                $pImg = $p['image_url'] ?? null;

                                // ✅ FIX: Sanitize price - ensure clean number for session
                                $cleanPrice = (float) str_replace(',', '', (string) $pPrice);

                                $tpl = $templates['product_selected']
                                    ?? "ได้เลยค่ะ 😊 เลือก: {{name}}" . ($pCode ? " (รหัส {{code}})" : "") . "\nราคา: {{price}} บาท"
                                    . "\n\n💡 พิมพ์ 'สนใจ' หรือ 'จอง' เพื่อทำรายการได้เลยค่ะ\nหรือพิมพ์ 'ผ่อน' ถ้าต้องการดูตารางผ่อน 😊";
                                $reply = $this->renderTemplate($tpl, [
                                    'name' => $pName ?: 'สินค้า',
                                    'code' => $pCode,
                                    'price' => number_format($cleanPrice, 0), // Format for display only
                                ]);

                                $actionsOut = [];
                                if ($pImg) {
                                    $actionsOut[] = ['type' => 'image', 'url' => $pImg];
                                }

                                // ✅ FIX: Update session with clean price number + image
                                $slots = $this->mergeSlots($lastSlots, [
                                    'product_ref_id' => $pRef,
                                    'product_code' => $pCode,
                                    'product_name' => $pName,
                                    'product_price' => $cleanPrice, // Save clean number
                                    'product_image_url' => $pImg,
                                ]);
                                $this->updateSessionState((int) $sessionId, 'product_selected', $slots);

                                if ($reply !== '') {
                                    $this->storeMessage($sessionId, 'assistant', $reply);
                                }
                                $this->logBotReply($context, $reply, 'text');

                                Logger::info('[ROUTER_V1] Product selected by price', [
                                    'target_price' => $targetPrice,
                                    'selected_code' => $pCode,
                                    'trace_id' => $traceId
                                ]);

                                return [
                                    'reply_text' => $reply,
                                    'actions' => $actionsOut,
                                    'meta' => $meta,
                                ];
                            }
                        }
                    }
                }
            }

            // =========================================================
            // ✅ CHECKOUT FLOW - Direct response when customer shows interest
            // =========================================================
            $productPrice = (float) ($lastSlots['product_price'] ?? 0);
            $productName = trim((string) ($lastSlots['product_name'] ?? ''));
            $productCode = trim((string) ($lastSlots['product_code'] ?? ''));
            $checkoutStep = trim((string) ($lastSlots['checkout_step'] ?? ''));
            $paymentMethod = trim((string) ($lastSlots['payment_method'] ?? ''));

            // =========================================================
            // ✅ NEW PRODUCT CODE DETECTION: Clear checkout if user switches product
            // If user types a NEW product code different from current, reset checkout
            // =========================================================
            $newProductCodePattern = '/\b([A-Z]{2,4}[-_][A-Z]{2,4}[-_]\d{2,4})\b/i';
            if ($checkoutStep !== '' && preg_match($newProductCodePattern, $text, $newCodeMatch)) {
                $newCode = strtoupper($newCodeMatch[1]);
                $currentCode = strtoupper($productCode);
                
                // If it's a DIFFERENT product code, clear checkout and let product detection handle it
                if ($newCode !== $currentCode) {
                    Logger::info('[CHECKOUT_FLOW] New product code detected - clearing checkout', [
                        'trace_id' => $traceId,
                        'old_code' => $currentCode,
                        'new_code' => $newCode,
                        'old_checkout_step' => $checkoutStep,
                    ]);
                    
                    // Clear checkout-related slots but keep product context for the NEW product
                    $clearSlots = [
                        'checkout_step' => '',
                        'payment_method' => '',
                        'delivery_method' => '',
                        'order_status' => '',
                        'address_buffer' => '',
                        'product_code' => '', // Clear to allow new product
                        'product_name' => '',
                        'product_price' => 0,
                        'product_ref_id' => '',
                        'product_image_url' => '',
                    ];
                    $lastSlots = $this->mergeSlots($lastSlots, $clearSlots);
                    
                    if ($sessionId) {
                        $this->updateSessionState((int) $sessionId, 'product_switch', $clearSlots);
                    }
                    
                    // Reset local variables to reflect cleared state
                    $productPrice = 0;
                    $productName = '';
                    $productCode = '';
                    $checkoutStep = '';
                    $paymentMethod = '';
                }
            }

            // ✅ DEBUG: Log checkout flow state
            Logger::info('[CHECKOUT_FLOW_DEBUG]', [
                'trace_id' => $traceId,
                'text' => $text,
                'product_price' => $productPrice,
                'product_name' => $productName,
                'checkout_step' => $checkoutStep,
                'payment_method' => $paymentMethod,
                'has_product' => $productPrice > 0,
            ]);

            // ✅ CRITICAL: เมื่อมี product ใน session และลูกค้าแสดงความสนใจ ต้อง RETURN ทันที
            if ($productPrice > 0) {
                $originalText = $text; // เก็บ text เดิมไว้ก่อน inject

                // ✅ FIX: Strip emoji และ whitespace ก่อน match cancel keywords
                $textForCancelCheck = preg_replace('/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}❌✅⭕🔴⚪]/u', '', $originalText);
                $textForCancelCheck = trim($textForCancelCheck);

                // =========================================================
                // ✅ CANCEL DETECTION: ดักคำยกเลิก checkout
                // =========================================================
                if (preg_match('/^(ยกเลิก|cancel|ไม่เอา|พอแค่นี้|หยุด|ไม่ซื้อ|เลิก|ไม่|ไม่เอาแล้ว|ยกเลิกเลย|เปลี่ยนใจ)/iu', $textForCancelCheck) && $checkoutStep !== '') {
                    // ✅ FIX: ล้าง ALL slots รวม product_* เพื่อ reset สถานะทั้งหมด
                    $slots = $this->mergeSlots($lastSlots, [
                        'checkout_step' => '',
                        'payment_method' => '',
                        'delivery_method' => '',
                        'order_status' => '',
                        'address_buffer' => '',
                        'product_code' => '',
                        'product_name' => '',
                        'product_price' => 0,
                        'product_ref_id' => '',
                        'product_image_url' => '',
                        'first_payment' => 0,
                    ]);
                    $this->updateSessionState((int) $sessionId, 'cancelled', $slots);

                    $reply = "รับทราบค่ะ ยกเลิกรายการให้แล้วนะคะ 👌||SPLIT||หากสนใจชิ้นไหน สอบถามใหม่ได้ตลอดเลยค่ะ 😊";

                    if ($sessionId)
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    // ✅ Quick Reply กลับไป Main Menu
                    $quickReplyActions = [
                        [
                            'type' => 'quick_reply',
                            'items' => [
                                ['label' => '🛍️ ดูสินค้า', 'text' => 'ดูสินค้า'],
                                ['label' => '💬 สอบถาม', 'text' => 'สอบถาม'],
                            ]
                        ]
                    ];

                    return ['reply_text' => $reply, 'actions' => $quickReplyActions, 'meta' => ['reason' => 'checkout_cancelled']];
                }

                // =========================================================
                // CASE 1: ลูกค้าบอก "สนใจ/เอา/ซื้อ" → ถามวิธีชำระทันที
                // =========================================================
                // ✅ FIX: ใช้คำ specific - ลบคำกว้างๆ เช่น "ได้/ใช่/ok/yes" ที่ match กับประโยคทั่วไป
                $purchasePatterns = '/(เอา|ซื้อ|ตกลง|สนใจ|เอาเลย|ซื้อเลย|รับเลย|จอง|cf)(\s*(เรือน|ตัว|ชิ้น|อัน)?(นี้|นั้น|เลย)?(ครับ|ค่ะ|คะ|นะ)?)?/iu';
                // ✅ FIX: เริ่ม checkout ได้เมื่อ checkout_step ว่างเท่านั้น (หลังจบจะ reset เป็น '' แล้ว)
                $canStartNewCheckout = ($checkoutStep === '');
                if ($canStartNewCheckout && preg_match($purchasePatterns, $originalText)) {
                    Logger::info('[CHECKOUT] Customer interested - asking payment method', [
                        'product_name' => $productName,
                        'product_price' => $productPrice,
                        'trace_id' => $traceId
                    ]);

                    // Update slots
                    $slots = $this->mergeSlots($lastSlots, ['checkout_step' => 'ask_payment']);
                    $this->updateSessionState((int) $sessionId, 'ask_payment', $slots);

                    // ✅ DIRECT RETURN - ไม่ให้ fall through ไป LLM
                    $reply = "ยินดีค่ะ 😊\n\n";
                    $reply .= "📦 {$productName}\n";
                    $reply .= "🏷️ รหัส: {$productCode}\n";
                    $reply .= "💰 " . number_format($productPrice, 0) . " บาท\n\n";
                    $reply .= "สะดวกชำระแบบไหนดีคะ?\n";
                    $reply .= "1️⃣ โอนเต็ม\n";
                    $reply .= "2️⃣ ผ่อน 3 งวด (+3% ค่าดำเนินการครั้งแรก)\n";
                    $reply .= "3️⃣ มัดจำ 10%";

                    if ($sessionId)
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    // ✅ Quick Reply ปุ่มกดสำหรับเลือกวิธีชำระ
                    $quickReplyActions = [
                        [
                            'type' => 'quick_reply',
                            'items' => [
                                ['label' => '💰 โอนเต็ม', 'text' => 'โอนเต็ม'],
                                ['label' => '💳 ผ่อน 3 งวด', 'text' => 'ผ่อน 3 งวด'],
                                ['label' => '🎯 มัดจำ', 'text' => 'มัดจำ'],
                                ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                            ]
                        ]
                    ];

                    return ['reply_text' => $reply, 'actions' => $quickReplyActions, 'meta' => ['reason' => 'checkout_ask_payment', 'checkout_step' => 'ask_payment']];
                }

                // =========================================================
                // CASE 2: ลูกค้าเลือกวิธีชำระ (อยู่ใน ask_payment step)
                // =========================================================
                if ($checkoutStep === 'ask_payment') {
                    $selectedPayment = null;
                    $replyText = '';

                    // ✅ FIX: ใช้ stripos แทน regex เพื่อรองรับ emoji นำหน้า
                    $textClean = preg_replace('/[^\p{L}\p{N}\s]/u', '', $originalText); // ลบ emoji
                    $textClean = trim(mb_strtolower($textClean, 'UTF-8'));
                    
                    // ✅ NEW: ถ้า user พิมพ์ "สนใจ/เอา/ซื้อ" ใน ask_payment → ถามวิธีชำระอีกครั้ง
                    if (preg_match('/^(สนใจ|เอา|ซื้อ|ตกลง|จอง|cf|ok|ได้|ใช่|yes)$/iu', $textClean)) {
                        $reply = "ยินดีค่ะ 😊 สะดวกชำระแบบไหนดีคะ?\n\n";
                        $reply .= "📦 {$productName}\n";
                        $reply .= "💰 " . number_format($productPrice, 0) . " บาท\n\n";
                        $reply .= "1️⃣ โอนเต็ม\n";
                        $reply .= "2️⃣ ผ่อน 3 งวด (+3% ค่าดำเนินการครั้งแรก)\n";
                        $reply .= "3️⃣ มัดจำ 10%";
                        
                        if ($sessionId)
                            $this->storeMessage($sessionId, 'assistant', $reply);
                        $this->logBotReply($context, $reply, 'text');
                        
                        $quickReplyActions = [
                            [
                                'type' => 'quick_reply',
                                'items' => [
                                    ['label' => '💰 โอนเต็ม', 'text' => 'โอนเต็ม'],
                                    ['label' => '💳 ผ่อน 3 งวด', 'text' => 'ผ่อน 3 งวด'],
                                    ['label' => '🎯 มัดจำ', 'text' => 'มัดจำ'],
                                    ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                                ]
                            ]
                        ];
                        
                        return ['reply_text' => $reply, 'actions' => $quickReplyActions, 'meta' => ['reason' => 'checkout_repeat_ask_payment']];
                    }

                    if ($originalText === '1' || preg_match('/(เต็ม|โอน|full|cash|โอนเต็ม)/iu', $originalText)) {
                        $selectedPayment = 'full';
                        $replyText = "โอเคค่ะ โอนเต็ม ✅\n\n";
                        $replyText .= "💰 " . number_format($productPrice, 0) . " บาท\n";
                    } elseif ($originalText === '2' || preg_match('/(ผ่อน|ออม|งวด)/iu', $originalText)) {
                        $selectedPayment = 'installment';
                        $fee = round($productPrice * 0.03);
                        $p1 = ceil(($productPrice / 3) / 500) * 500;
                        $p2 = $p1;
                        $p3 = $productPrice - $p1 - $p2;
                        if ($p3 < 0) {
                            $p1 = ceil($productPrice / 3);
                            $p2 = $p1;
                            $p3 = $productPrice - $p1 - $p2;
                        }

                        $replyText = "โอเคค่ะ ผ่อน 3 งวด ✅\n\n";
                        $replyText .= "💰 ราคา: " . number_format($productPrice, 0) . " บาท\n";
                        $replyText .= "📝 ค่าดำเนินการ 3%: " . number_format($fee, 0) . " บาท\n\n";
                        $replyText .= "งวด 1: " . number_format($p1 + $fee, 0) . " บาท\n";
                        $replyText .= "งวด 2: " . number_format($p2, 0) . " บาท\n";
                        $replyText .= "งวด 3: " . number_format($p3, 0) . " บาท\n";
                    } elseif ($originalText === '3' || preg_match('/(มัดจำ|จอง)/iu', $originalText)) {
                        $selectedPayment = 'deposit';
                        $depositAmount = round($productPrice * 0.1);
                        $replyText = "โอเคค่ะ มัดจำ 10% ✅\n\n";
                        $replyText .= "💰 " . number_format($depositAmount, 0) . " บาท\n";
                    }

                    if ($selectedPayment) {
                        $slots = $this->mergeSlots($lastSlots, [
                            'checkout_step' => 'ask_delivery',
                            'payment_method' => $selectedPayment,
                        ]);
                        $this->updateSessionState((int) $sessionId, 'ask_delivery', $slots);

                        $replyText .= "รับสินค้ายังไงดีคะ?\n";
                        $replyText .= "1️⃣ รับหน้าร้าน\n";
                        $replyText .= "2️⃣ ส่ง EMS (+150 บาท)\n";
                        $replyText .= "3️⃣ ส่ง Grab (ค่าส่งตามจริง)";

                        if ($sessionId)
                            $this->storeMessage($sessionId, 'assistant', $replyText);
                        $this->logBotReply($context, $replyText, 'text');

                        // ✅ Quick Reply ปุ่มกดสำหรับเลือกวิธีรับสินค้า
                        $quickReplyActions = [
                            [
                                'type' => 'quick_reply',
                                'items' => [
                                    ['label' => '🏢 รับหน้าร้าน', 'text' => 'รับหน้าร้าน'],
                                    ['label' => '📦 ส่ง EMS', 'text' => 'ส่ง EMS'],
                                    ['label' => '🛵 ส่ง Grab', 'text' => 'ส่ง Grab'],
                                    ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                                ]
                            ]
                        ];

                        return ['reply_text' => $replyText, 'actions' => $quickReplyActions, 'meta' => ['reason' => 'checkout_ask_delivery', 'payment_method' => $selectedPayment]];
                    }
                }

                // =========================================================
                // CASE 3: ลูกค้าเลือกวิธีรับ (อยู่ใน ask_delivery step)
                // =========================================================
                if ($checkoutStep === 'ask_delivery') {
                    // ✅ Guard: Check if we still have valid product context
                    if (!$this->hasValidProductContext($lastSlots)) {
                        Logger::info('[ASK_DELIVERY] No valid product context - skipping checkout flow', [
                            'trace_id' => $traceId,
                            'text' => $originalText,
                        ]);
                        // Clear stale checkout state and let flow continue to KB/LLM
                        $this->updateSessionState((int) $sessionId, 'menu_reset', [
                            'checkout_step' => '',
                            'delivery_method' => '',
                        ]);
                        // Don't return - fall through to general handling
                    } else {
                        $selectedDelivery = null;
                        $replyText = '';

                        // ✅ FIX: Handle "สอบถามเพิ่ม" - pause delivery selection, let user ask question
                        if (preg_match('/^(สอบถาม|ถาม|คำถาม|question|ask)/iu', $originalText)) {
                            $paymentLabel = match ($paymentMethod) {
                                'installment' => 'ผ่อน 3 งวด',
                                'deposit' => 'มัดจำ 10%',
                                default => 'โอนเต็ม',
                            };

                            $reply = "ได้เลยค่ะ 😊 สอบถามได้เลยนะคะ||SPLIT||";
                            $reply .= "📦 ออเดอร์ปัจจุบัน: {$productName}\n";
                            $reply .= "💰 ราคา: " . number_format($productPrice, 0) . " บาท\n";
                            $reply .= "💳 วิธีชำระ: {$paymentLabel}\n\n";
                            $reply .= "พิมพ์คำถามได้เลยค่ะ หรือพร้อมเลือกวิธีรับสินค้าพิมพ์ได้เลยนะคะ 📍";

                            if ($sessionId)
                                $this->storeMessage($sessionId, 'assistant', $reply);
                            $this->logBotReply($context, $reply, 'text');

                            $quickReplyActions = [
                                [
                                    'type' => 'quick_reply',
                                    'items' => [
                                        ['label' => '🏢 รับหน้าร้าน', 'text' => 'รับหน้าร้าน'],
                                        ['label' => '📦 ส่ง EMS', 'text' => 'ส่ง EMS'],
                                        ['label' => '🚙 ส่ง Grab', 'text' => 'ส่ง Grab'],
                                        ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                                    ]
                                ]
                            ];
                            return ['reply_text' => $reply, 'actions' => $quickReplyActions, 'meta' => ['reason' => 'checkout_ask_question_pause_delivery']];
                        }

                    // ✅ FIX: รองรับ emoji จาก Quick Reply
                    if ($originalText === '1' || preg_match('/(ร้าน|หน้าร้าน|รับ|pickup|มารับ|สีลม|รับหน้าร้าน)/iu', $originalText)) {
                        $selectedDelivery = 'pickup';
                        $paymentLabel = match ($paymentMethod) {
                            'installment' => 'ผ่อน 3 งวด',
                            'deposit' => 'มัดจำ 10%',
                            default => 'โอนเต็ม',
                        };

                        $replyText = "โอเคค่ะ รับหน้าร้าน ✅\n\n";
                        $replyText .= "📦 {$productName}\n";
                        $replyText .= "💳 {$paymentLabel}\n";
                        $replyText .= "🏢 รับที่ร้าน\n\n";
                        $replyText .= "เดี๋ยวส่งเลขบัญชีให้นะคะ 🙏";

                        $slots = $this->mergeSlots($lastSlots, [
                            'checkout_step' => '',  // ✅ Reset เพื่อให้ลูกค้าถามเพิ่มเติมได้
                            'delivery_method' => 'pickup',
                            'order_status' => 'pending_payment',  // เก็บสถานะว่าสั่งซื้อแล้ว
                        ]);
                        $this->updateSessionState((int) $sessionId, 'completed', $slots);

                        if ($sessionId)
                            $this->storeMessage($sessionId, 'assistant', $replyText);
                        $this->logBotReply($context, $replyText, 'text');

                        return ['reply_text' => $replyText, 'actions' => [], 'meta' => ['reason' => 'checkout_order_confirmed', 'handoff_to_admin' => true]];

                    } elseif ($originalText === '2' || preg_match('/\bems\b/iu', $originalText)) {
                        // ✅ EMS delivery - ค่าส่ง 150 บาท
                        $selectedDelivery = 'ems';
                        $paymentLabel = match ($paymentMethod) {
                            'installment' => 'ผ่อน 3 งวด',
                            'deposit' => 'มัดจำ 10%',
                            default => 'โอนเต็ม',
                        };

                        $replyText = "โอเคค่ะ ส่ง EMS ✅\n\n";
                        $replyText .= "📦 {$productName}\n";
                        $replyText .= "💳 {$paymentLabel}\n";
                        $replyText .= "🚚 EMS (+150 บาท)\n\n";
                        $replyText .= "แจ้งชื่อ-ที่อยู่-เบอร์ ได้เลยค่ะ";

                        $slots = $this->mergeSlots($lastSlots, [
                            'checkout_step' => 'ask_address',
                            'delivery_method' => 'ems',
                            'shipping_fee' => 150,
                        ]);
                        $this->updateSessionState((int) $sessionId, 'ask_address', $slots);

                        if ($sessionId)
                            $this->storeMessage($sessionId, 'assistant', $replyText);
                        $this->logBotReply($context, $replyText, 'text');

                        // ✅ Quick Reply สำหรับ ask_address
                        $addressQuickReply = [
                            [
                                'type' => 'quick_reply',
                                'items' => [
                                    ['label' => '💬 สอบถามเพิ่ม', 'text' => 'สอบถามเพิ่ม'],
                                    ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                                ]
                            ]
                        ];

                        return ['reply_text' => $replyText, 'actions' => $addressQuickReply, 'meta' => ['reason' => 'checkout_ask_address', 'delivery_method' => 'ems']];

                    } elseif ($originalText === '3' || preg_match('/(grab|แกร็บ|แกรบ)/iu', $originalText)) {
                        // ✅ Grab delivery - ค่าส่งตามจริง
                        $selectedDelivery = 'grab';
                        $paymentLabel = match ($paymentMethod) {
                            'installment' => 'ผ่อน 3 งวด',
                            'deposit' => 'มัดจำ 10%',
                            default => 'โอนเต็ม',
                        };

                        $replyText = "โอเคค่ะ ส่ง Grab ✅\n\n";
                        $replyText .= "📦 {$productName}\n";
                        $replyText .= "💳 {$paymentLabel}\n";
                        $replyText .= "🛵 Grab (ค่าส่งตามจริง - จะแจ้งให้ทราบอีกครั้งค่ะ)\n\n";
                        $replyText .= "แจ้งชื่อ-ที่อยู่-เบอร์ ได้เลยค่ะ";

                        $slots = $this->mergeSlots($lastSlots, [
                            'checkout_step' => 'ask_address',
                            'delivery_method' => 'grab',
                            'shipping_fee' => 0, // ค่าส่งตามจริง จะคำนวณทีหลัง
                        ]);
                        $this->updateSessionState((int) $sessionId, 'ask_address', $slots);

                        if ($sessionId)
                            $this->storeMessage($sessionId, 'assistant', $replyText);
                        $this->logBotReply($context, $replyText, 'text');

                        // ✅ Quick Reply สำหรับ ask_address
                        $addressQuickReply = [
                            [
                                'type' => 'quick_reply',
                                'items' => [
                                    ['label' => '💬 สอบถามเพิ่ม', 'text' => 'สอบถามเพิ่ม'],
                                    ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                                ]
                            ]
                        ];

                        return ['reply_text' => $replyText, 'actions' => $addressQuickReply, 'meta' => ['reason' => 'checkout_ask_address', 'delivery_method' => 'grab']];

                    } elseif (preg_match('/^(ส่ง|จัดส่ง|deliver)/iu', $originalText)) {
                        // ✅ ลูกค้าพิมพ์แค่ "ส่ง" โดยไม่ระบุ EMS หรือ Grab → ถาม clarify
                        $paymentLabel = match ($paymentMethod) {
                            'installment' => 'ผ่อน 3 งวด',
                            'deposit' => 'มัดจำ 10%',
                            default => 'โอนเต็ม',
                        };

                        $replyText = "รับทราบค่ะ สะดวกส่งแบบไหนดีคะ? 🚚\n\n";
                        $replyText .= "📦 EMS (+150 บาท) - ได้รับภายใน 2-3 วันทำการ\n";
                        $replyText .= "🛵 Grab (ค่าส่งตามจริง) - ได้รับภายในวันเดียวกัน";

                        if ($sessionId)
                            $this->storeMessage($sessionId, 'assistant', $replyText);
                        $this->logBotReply($context, $replyText, 'text');

                        $quickReplyActions = [
                            [
                                'type' => 'quick_reply',
                                'items' => [
                                    ['label' => '📦 ส่ง EMS', 'text' => 'ส่ง EMS'],
                                    ['label' => '🛵 ส่ง Grab', 'text' => 'ส่ง Grab'],
                                    ['label' => '🏢 รับหน้าร้าน', 'text' => 'รับหน้าร้าน'],
                                    ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                                ]
                            ]
                        ];

                        return ['reply_text' => $replyText, 'actions' => $quickReplyActions, 'meta' => ['reason' => 'checkout_clarify_delivery']];
                    }
                    // ✅ HYBRID: ไม่ match → ปล่อยไป LLM (ไม่มี else return)
                    } // End of else block (hasValidProductContext)
                } // End of if ($checkoutStep === 'ask_delivery')

                // =========================================================
                // ✅ HYBRID: ส่ง Context ไป LLM เมื่อลูกค้าถามนอกเรื่อง
                // =========================================================
                $inCheckoutFlow = in_array($checkoutStep, ['ask_payment', 'ask_delivery', 'ask_address'], true);

                // ✅ Guard: Only add checkout context if we have valid product
                if (!$this->hasValidProductContext($lastSlots)) {
                    $inCheckoutFlow = false; // Skip checkout context injection
                }

                $checkoutContext = "";
                if ($inCheckoutFlow && $this->hasValidProductContext($lastSlots)) {
                    $checkoutContext = "\n\n[CHECKOUT CONTEXT]\n";
                    $checkoutContext .= "สินค้า: {$productName} (รหัส: {$productCode}) ราคา " . number_format($productPrice, 0) . " บาท\n";

                    $checkoutContext .= "สถานะ: กำลังรอลูกค้าเลือก '{$checkoutStep}'\n";
                    $checkoutContext .= "คำสั่ง: ตอบคำถามสั้นๆ แล้ววกกลับมาถามเรื่องชำระ/จัดส่ง ตามขั้นตอนเดิม\n";
                    if ($checkoutStep === 'ask_payment') {
                        $checkoutContext .= "วกกลับถาม: 'สะดวกชำระแบบไหนดีคะ? โอนเต็ม ผ่อน หรือ มัดจำ?'\n";
                    } elseif ($checkoutStep === 'ask_delivery') {
                        $checkoutContext .= "วกกลับถาม: 'รับหน้าร้าน, ส่ง EMS (+150 บาท) หรือ ส่ง Grab (ค่าส่งตามจริง) ดีคะ?'\n";
                    }
                    if ($paymentMethod) {
                        $checkoutContext .= "วิธีชำระ: {$paymentMethod}\n";
                    }
                    $checkoutContext .= "[END CONTEXT]\n\n";
                    $text = $checkoutContext . "ข้อความลูกค้า: " . $originalText;
                }
            } // End of if ($productPrice > 0)

            // =========================================================
            // ✅ ADDRESS COLLECTION with BUFFERING
            // รองรับลูกค้าที่พิมพ์ที่อยู่ทีละส่วน (ชื่อ, ที่อยู่, เบอร์)
            // =========================================================
            $checkoutStep = trim((string) ($lastSlots['checkout_step'] ?? ''));
            $deliveryMethod = trim((string) ($lastSlots['delivery_method'] ?? ''));

            // ✅ FIX: รองรับทั้ง 'ems' และ 'grab' (ไม่ใช่แค่ 'delivery')
            $needsAddress = in_array($deliveryMethod, ['ems', 'grab', 'delivery'], true);
            
            // ✅ Guard: Only enter ask_address flow if we have valid product context
            if ($checkoutStep === 'ask_address' && $needsAddress && $this->hasValidProductContext($lastSlots)) {
                $originalTextForAddress = $originalText ?? $text;

                // ✅ Check for cancel before processing address (strip emoji first)
                $textForCheck = preg_replace('/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}❌✅⭕🔴⚪💬📍💳🚚🛍️]/u', '', $originalTextForAddress);
                $textForCheck = trim($textForCheck);

                if (preg_match('/^(ยกเลิก|cancel|ไม่เอา|พอแค่นี้|หยุด|ไม่ซื้อ|เลิก|ไม่เอาแล้ว|ยกเลิกเลย|เปลี่ยนใจ)$/iu', $textForCheck)) {
                    // ✅ FIX: ล้าง ALL slots รวม product_* เพื่อ reset สถานะทั้งหมด
                    $slots = $this->mergeSlots($lastSlots, [
                        'checkout_step' => '',
                        'payment_method' => '',
                        'delivery_method' => '',
                        'order_status' => '',
                        'address_buffer' => '',
                        'product_code' => '',
                        'product_name' => '',
                        'product_price' => 0,
                        'product_ref_id' => '',
                        'product_image_url' => '',
                        'first_payment' => 0,
                    ]);
                    $this->updateSessionState((int) $sessionId, 'cancelled', $slots);

                    $reply = "รับทราบค่ะ ยกเลิกรายการให้แล้วนะคะ 👌||SPLIT||หากสนใจชิ้นไหน สอบถามใหม่ได้ตลอดเลยค่ะ 😊";
                    if ($sessionId)
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    $quickReplyActions = [
                        [
                            'type' => 'quick_reply',
                            'items' => [
                                ['label' => '🛍️ ดูสินค้า', 'text' => 'ดูสินค้า'],
                                ['label' => '💬 สอบถาม', 'text' => 'สอบถาม'],
                            ]
                        ]
                    ];
                    return ['reply_text' => $reply, 'actions' => $quickReplyActions, 'meta' => ['reason' => 'checkout_cancelled']];
                }

                // ✅ FIX: Handle "สอบถามเพิ่ม" - รองรับ emoji นำหน้า
                if (preg_match('/(สอบถาม|ถาม|คำถาม|question|ask)/iu', $textForCheck)) {
                    $productName = trim((string) ($lastSlots['product_name'] ?? ''));
                    $productPrice = (float) ($lastSlots['product_price'] ?? 0);

                    $reply = "ได้เลยค่ะ 😊 สอบถามได้เลยนะคะ\n\n";
                    $reply .= "📦 ออเดอร์ปัจจุบัน: {$productName}\n";
                    $reply .= "💰 ราคา: " . number_format($productPrice, 0) . " บาท\n\n";
                    $reply .= "พิมพ์คำถามได้เลยค่ะ หรือถ้าพร้อมแจ้งที่อยู่ พิมพ์ได้เลยนะคะ 📍";

                    if ($sessionId)
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    $quickReplyActions = [
                        [
                            'type' => 'quick_reply',
                            'items' => [
                                ['label' => '📍 แจ้งที่อยู่', 'text' => 'แจ้งที่อยู่'],
                                ['label' => '💳 วิธีชำระ', 'text' => 'ชำระเงินอย่างไร'],
                                ['label' => '🚚 ค่าส่ง', 'text' => 'ค่าส่งเท่าไหร่'],
                                ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                            ]
                        ]
                    ];
                    return ['reply_text' => $reply, 'actions' => $quickReplyActions, 'meta' => ['reason' => 'checkout_ask_question_pause']];
                }
                
                // =========================================================
                // ✅ NEW: ตรวจว่าข้อความดูเหมือน address หรือไม่
                // ถ้าไม่ใช่ → ปล่อยไป LLM พร้อม checkout context
                // =========================================================
                $looksLikeAddress = $this->looksLikeAddressText($originalTextForAddress);
                
                if (!$looksLikeAddress) {
                    // ไม่ใช่ address → ปล่อยไป LLM พร้อม context
                    Logger::info('[ADDRESS_FLOW] Text does not look like address - passing to LLM', [
                        'text' => $originalTextForAddress,
                        'trace_id' => $traceId,
                    ]);
                    
                    // ✅ Inject checkout context for LLM
                    $productName = trim((string) ($lastSlots['product_name'] ?? ''));
                    $productPrice = (float) ($lastSlots['product_price'] ?? 0);
                    $productCode = trim((string) ($lastSlots['product_code'] ?? ''));
                    
                    $checkoutContext = "\n\n[CHECKOUT CONTEXT]\n";
                    $checkoutContext .= "สินค้า: {$productName} (รหัส: {$productCode}) ราคา " . number_format($productPrice, 0) . " บาท\n";
                    $checkoutContext .= "สถานะ: กำลังรอลูกค้าแจ้งที่อยู่จัดส่ง\n";
                    $checkoutContext .= "คำสั่ง: ตอบคำถามสั้นๆ แล้ววกกลับมาขอที่อยู่จัดส่ง 'กรุณาแจ้งชื่อ-ที่อยู่-เบอร์โทร ด้วยนะคะ'\n";
                    $checkoutContext .= "[END CONTEXT]\n\n";
                    $text = $checkoutContext . "ข้อความลูกค้า: " . $originalTextForAddress;
                    
                    // ไม่ return - ปล่อยให้ flow ไปต่อที่ KB/LLM
                } else {
                    // ✅ ดูเหมือน address → process ปกติ
                    $addressBuffer = trim((string) ($lastSlots['address_buffer'] ?? ''));

                // Append ข้อความใหม่เข้า buffer (คั่นด้วย newline)
                if ($addressBuffer !== '') {
                    $addressBuffer .= "\n" . $originalTextForAddress;
                } else {
                    $addressBuffer = $originalTextForAddress;
                }

                Logger::info('[ADDRESS_BUFFER] Appending to buffer', [
                    'new_text' => $originalTextForAddress,
                    'buffer_so_far' => $addressBuffer,
                    'trace_id' => $traceId,
                ]);

                // ตรวจสอบว่า buffer มีข้อมูลครบหรือยัง
                $addressValidation = $this->validateAddressBuffer($addressBuffer);

                if ($addressValidation['is_complete']) {
                    // ✅ ข้อมูลครบ! Parse และบันทึก
                    $addressData = $this->parseShippingAddress($addressBuffer);

                    // Save to customer_addresses
                    try {
                        // Try multiple sources for platform_user_id
                        $platformUserId = $context['platform_user_id']
                            ?? $context['external_user_id']
                            ?? $context['customer']['external_user_id']
                            ?? null;
                        $platform = $context['platform'] ?? 'line';

                        Logger::info('[ADDRESS_BUFFER] Attempting to save address', [
                            'platform_user_id' => $platformUserId,
                            'platform' => $platform,
                            'address_data' => $addressData,
                            'context_external_user_id' => $context['external_user_id'] ?? 'N/A',
                            'trace_id' => $traceId,
                        ]);

                        if ($platformUserId) {
                            // หา customer_id จาก customer_profiles (optional)
                            $customer = $this->db->queryOne(
                                "SELECT id FROM customer_profiles WHERE platform_user_id = ? AND platform = ? LIMIT 1",
                                [$platformUserId, $platform]
                            );
                            $customerId = $customer ? (int) $customer['id'] : null;

                            // ✅ INSERT ลง customer_addresses 
                            // ใช้ platform_user_id เป็นหลัก ถ้าไม่มี customer_id ก็ใส่ 1 เป็น fallback
                            $this->db->execute(
                                "INSERT INTO customer_addresses (
                                    customer_id, platform, platform_user_id, address_type, 
                                    recipient_name, phone, address_line1, address_line2, 
                                    subdistrict, district, province, postal_code, country, 
                                    is_default, created_at
                                ) VALUES (?, ?, ?, 'shipping', ?, ?, ?, ?, ?, ?, ?, ?, 'Thailand', 1, NOW())",
                                [
                                    $customerId ?: 1,
                                    $platform,
                                    $platformUserId,
                                    $addressData['name'] ?? '',
                                    $addressData['phone'] ?? '',
                                    $addressData['address_line1'] ?? '',
                                    $addressData['address_line2'] ?? '',
                                    $addressData['subdistrict'] ?? '',
                                    $addressData['district'] ?? '',
                                    $addressData['province'] ?? '',
                                    $addressData['postal_code'] ?? '',
                                ]
                            );

                            $newAddressId = $this->db->lastInsertId();
                            Logger::info('[ADDRESS_BUFFER] Address saved successfully', [
                                'address_id' => $newAddressId,
                                'customer_id' => $customerId,
                                'platform_user_id' => $platformUserId,
                                'trace_id' => $traceId
                            ]);
                        } else {
                            Logger::warning('[ADDRESS_BUFFER] No platform_user_id found', [
                                'context_keys' => array_keys($context),
                                'trace_id' => $traceId
                            ]);
                        }
                    } catch (\Exception $e) {
                        Logger::error('[ADDRESS_BUFFER] Failed to save address: ' . $e->getMessage(), [
                            'trace_id' => $traceId,
                            'exception' => $e->getTraceAsString()
                        ]);
                    }

                    // สรุปออเดอร์
                    $productName = trim((string) ($lastSlots['product_name'] ?? 'สินค้า'));
                    $productPrice = (float) ($lastSlots['product_price'] ?? 0);
                    $firstPayment = (float) ($lastSlots['first_payment'] ?? $productPrice);
                    $paymentMethod = trim((string) ($lastSlots['payment_method'] ?? 'full'));
                    $deliveryMethod = trim((string) ($lastSlots['delivery_method'] ?? 'pickup'));
                    
                    // ✅ ค่าส่งตามวิธีจัดส่ง
                    $shippingFee = match ($deliveryMethod) {
                        'ems' => 150,
                        'grab' => (int) ($lastSlots['shipping_fee'] ?? 0), // ค่าส่งตามจริง - จะแจ้งทีหลัง
                        default => 0,
                    };

                    $paymentLabel = match ($paymentMethod) {
                        'installment' => 'ผ่อน 3 งวด',
                        'deposit' => 'มัดจำ 10%',
                        default => 'โอนเต็ม',
                    };

                    // ✅ สร้าง Order และ Installment Contract
                    $orderId = null;
                    $contractId = null;
                    $contractNo = null;
                    $orderNumber = null;
                    $installmentSchedule = '';

                    try {
                        // Generate order number
                        $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

                        // Get channel_id from context
                        $channelId = $context['channel']['id'] ?? 4;
                        $platform = $context['platform'] ?? 'facebook';
                        $externalUserId = $platformUserId ?? $context['external_user_id'] ?? '';

                        // Determine order type
                        $orderType = match ($paymentMethod) {
                            'installment' => 'installment',
                            'deposit' => 'deposit',
                            default => 'full_payment',
                        };

                        // ✅ BUG FIX: Check for duplicate orders (Race Condition Prevention)
                        // ป้องกันลูกค้ากดรัวๆ แล้วสร้าง Order ซ้ำ
                        $existingOrder = $this->db->queryOne(
                            "SELECT id, order_number FROM orders 
                             WHERE customer_profile_id = ? 
                             AND status = 'pending_payment'
                             AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                             ORDER BY id DESC LIMIT 1",
                            [$customerId]
                        );

                        if ($existingOrder) {
                            // ⚡ Already created recently - use existing order
                            $orderId = (int) $existingOrder['id'];
                            $orderNumber = $existingOrder['order_number'];
                            Logger::info('[CHECKOUT] Using existing order (race condition prevented)', [
                                'order_id' => $orderId,
                                'order_number' => $orderNumber,
                                'customer_id' => $customerId,
                                'trace_id' => $traceId ?? null
                            ]);
                        } else {
                            // Create Order
                            $this->db->execute(
                                "INSERT INTO orders (
                                    order_number, customer_profile_id, order_type,
                                    subtotal, shipping_fee, total_amount,
                                    status, payment_status,
                                    shipping_address_id, notes, created_at, updated_at
                                ) VALUES (?, ?, ?, ?, ?, ?, 'pending_payment', 'unpaid', ?, ?, NOW(), NOW())",
                                [
                                    $orderNumber,
                                    $customerId ?? null,
                                    $orderType,
                                    $productPrice,
                                    $shippingFee,
                                    $productPrice + $shippingFee,
                                    $newAddressId ?? null,
                                    "สั่งจาก Chatbot - {$platform}"
                                ]
                            );
                            $orderId = $this->db->lastInsertId();
                        }

                        // Create order item (skip if using existing order from race condition)
                        if (!$existingOrder) {
                            $productRefId = $lastSlots['product_ref_id'] ?? $lastSlots['product_code'] ?? '';
                            $productImage = $lastSlots['product_image'] ?? '';
                            $productMetadata = json_encode([
                                'image_url' => $productImage,
                                'from_chatbot' => true,
                                'session_id' => $sessionId
                            ]);

                            $this->db->execute(
                                "INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, total_price, product_metadata, created_at)
                                 VALUES (?, ?, ?, 1, ?, ?, ?, NOW())",
                                [$orderId, $productRefId, $productName, $productPrice, $productPrice, $productMetadata]
                            );
                        }

                        Logger::info('[CHECKOUT] Order created', [
                            'order_id' => $orderId,
                            'order_number' => $orderNumber,
                            'order_type' => $orderType,
                            'trace_id' => $traceId
                        ]);

                        // ✅ Create Installment Contract if payment_method = installment
                        if ($paymentMethod === 'installment') {
                            $contractNo = 'INS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                            $totalPeriods = 3;

                            // สูตรคำนวณตามนโยบายร้าน ฮ.เฮง เฮง:
                            // ค่าธรรมเนียม = ราคาสินค้า x 3%
                            // ยอดต่องวด = ราคาสินค้า / 3
                            // งวด 1 = (ราคา/3) + ค่าธรรมเนียม
                            // งวด 2 = (ราคา/3)
                            // งวด 3 = (ราคา/3) + เศษ
                            $serviceFee = round($productPrice * 0.03, 0); // 3%
                            $basePerPeriod = floor($productPrice / $totalPeriods);
                            $remainder = $productPrice - ($basePerPeriod * $totalPeriods);

                            $firstPaymentAmount = $basePerPeriod + $serviceFee; // งวด 1 + ค่าธรรมเนียม
                            $secondPaymentAmount = $basePerPeriod; // งวด 2
                            $thirdPaymentAmount = $basePerPeriod + $remainder; // งวด 3 + เศษ

                            $totalAmount = $productPrice + $serviceFee; // ไม่รวมค่าส่ง (จ่ายตอนรับของ)
                            $amountPerPeriod = $basePerPeriod;

                            // Calculate due dates - 3 งวด รวม 60 วัน (ตามนโยบายร้าน ฮ.เฮง เฮง)
                            // งวด 1 = Day 0, งวด 2 = Day 30, งวด 3 = Day 60 (รับของ)
                            $firstDueDate = date('Y-m-d'); // งวดแรก = Day 0 (วันเปิดบิล)
                            $secondDueDate = date('Y-m-d', strtotime('+30 days')); // Day 30
                            $thirdDueDate = date('Y-m-d', strtotime('+60 days')); // Day 60 -> รับของ
                            $endDate = $thirdDueDate; // สิ้นสุดสัญญา = Day 60

                            $this->db->execute(
                                "INSERT INTO installment_contracts (
                                    contract_no, tenant_id, customer_id, channel_id, external_user_id,
                                    platform, customer_name, customer_phone,
                                    product_ref_id, product_name, product_price,
                                    total_amount, down_payment, financed_amount,
                                    total_periods, amount_per_period,
                                    interest_rate, interest_type, total_interest,
                                    start_date, next_due_date, end_date,
                                    status, order_id, admin_notes,
                                    created_at, updated_at
                                ) VALUES (
                                    ?, 'default', ?, ?, ?,
                                    ?, ?, ?,
                                    ?, ?, ?,
                                    ?, 0, ?,
                                    ?, ?,
                                    3, 'flat', ?,
                                    ?, ?, ?,
                                    'active', ?, ?,
                                    NOW(), NOW()
                                )",
                                [
                                    $contractNo,
                                    $customerId ?? null,
                                    $channelId,
                                    $externalUserId,
                                    $platform,
                                    $addressData['name'] ?? '',
                                    $addressData['phone'] ?? '',
                                    $productRefId,
                                    $productName,
                                    $productPrice,
                                    $totalAmount,
                                    $totalAmount,
                                    $totalPeriods,
                                    $amountPerPeriod,
                                    $serviceFee,
                                    $firstDueDate,
                                    $firstDueDate,
                                    $endDate,
                                    $orderId,
                                    "สร้างจาก Chatbot - session: {$sessionId}"
                                ]
                            );
                            $contractId = $this->db->lastInsertId();

                            // Update order with installment_id
                            $this->db->execute(
                                "UPDATE orders SET installment_id = ? WHERE id = ?",
                                [$contractId, $orderId]
                            );

                            // Build installment schedule message
                            $installmentSchedule = "\n\n📋 ตารางผ่อนชำระ:\n";
                            $installmentSchedule .= "งวด 1: " . number_format($firstPaymentAmount, 0) . " บาท (วันนี้)\n";
                            $installmentSchedule .= "งวด 2: " . number_format($secondPaymentAmount, 0) . " บาท (" . date('d/m/Y', strtotime($secondDueDate)) . ")\n";
                            $installmentSchedule .= "งวด 3: " . number_format($thirdPaymentAmount, 0) . " บาท (" . date('d/m/Y', strtotime($thirdDueDate)) . ")";

                            Logger::info('[CHECKOUT] Installment contract created', [
                                'contract_id' => $contractId,
                                'contract_no' => $contractNo,
                                'total_amount' => $totalAmount,
                                'periods' => $totalPeriods,
                                'trace_id' => $traceId
                            ]);
                        }

                    } catch (\Exception $e) {
                        Logger::error('[CHECKOUT] Failed to create order/contract: ' . $e->getMessage(), [
                            'trace_id' => $traceId,
                            'exception' => $e->getTraceAsString()
                        ]);
                    }

                    $reply = "ได้รับแล้วค่ะ ✅\n\n";
                    $reply .= "👤 " . ($addressData['name'] ?? '-') . "\n";
                    $reply .= "📍 " . ($addressData['address_line1'] ?? '-') . "\n";
                    $reply .= "📱 " . ($addressData['phone'] ?? '-') . "\n\n";
                    $reply .= "📦 {$productName}\n";
                    $reply .= "💰 " . number_format($firstPayment, 0) . " บาท ({$paymentLabel})\n";
                    if ($deliveryMethod === 'ems') {
                        $reply .= "🚚 EMS (+150 บาท)\n";
                    } elseif ($deliveryMethod === 'grab') {
                        $reply .= "🛵 Grab (ค่าส่งตามจริง - แจ้งให้ทราบอีกครั้งค่ะ)\n";
                    }
                    if ($orderNumber) {
                        $reply .= "🔖 เลขที่: {$orderNumber}";
                    }
                    $reply .= $installmentSchedule;
                    $reply .= "\n\nเดี๋ยวส่งเลขบัญชีให้นะคะ 🙏";

                    // Clear buffer and update step
                    // ✅ BUG FIX: Clear ALL product-related slots to prevent "Session Hangover"
                    // เคลียร์ข้อมูลสินค้าทิ้ง เพื่อเตรียมรับออเดอร์ใหม่
                    $slots = $this->mergeSlots($lastSlots, [
                        'checkout_step' => '',  // Reset เพื่อให้ลูกค้าถามเพิ่มเติมได้
                        'shipping_address' => json_encode($addressData),
                        'address_buffer' => '', // Clear buffer
                        'order_status' => 'pending_payment',

                        // ✅ BUG FIX: ล้างค่าสินค้าทิ้งหลังสร้าง Order สำเร็จ
                        'product_name' => null,
                        'product_code' => null,
                        'product_price' => null,
                        'product_ref_id' => null,
                        'product_image_url' => null,
                        'first_payment' => null,
                        'delivery_method' => null,
                        'last_product_candidates' => null,
                        'last_product_query' => null,
                    ]);
                    $this->updateSessionState((int) $sessionId, 'completed', $slots);

                    if ($sessionId)
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    return [
                        'reply_text' => $reply,
                        'actions' => [],
                        'meta' => ['reason' => 'checkout_address_complete', 'trace_id' => $traceId],
                        'handoff_to_admin' => true
                    ];
                } else {
                    // ❌ ข้อมูลยังไม่ครบ → บันทึก buffer และถามเพิ่ม
                    $slots = $this->mergeSlots($lastSlots, [
                        'address_buffer' => $addressBuffer,
                    ]);
                    $this->updateSessionState((int) $sessionId, 'ask_address', $slots);

                    // สร้างข้อความถามเพิ่ม
                    $missing = $addressValidation['missing'];
                    $missingList = [];
                    if (in_array('name', $missing))
                        $missingList[] = 'ชื่อ-นามสกุล';
                    if (in_array('address', $missing))
                        $missingList[] = 'ที่อยู่';
                    if (in_array('phone', $missing))
                        $missingList[] = 'เบอร์โทร';

                    $reply = "รับทราบค่ะ 📝 กรุณาแจ้ง " . implode(', ', $missingList) . " เพิ่มด้วยนะคะ";

                    if ($sessionId)
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');

                    return [
                        'reply_text' => $reply,
                        'actions' => [],
                        'meta' => ['reason' => 'checkout_address_incomplete', 'missing' => $missing, 'trace_id' => $traceId],
                    ];
                }
                } // ✅ Close else block for looksLikeAddress
            }

            // =========================================================
            // ✅ ADDRESS COLLECTION - Legacy fallback (ข้อความยาว)
            // =========================================================
            // ถ้าอยู่ใน step order_confirmed + delivery = ems/grab และข้อความยาวกว่า 30 ตัวอักษร (น่าจะเป็นที่อยู่)
            $needsAddressLegacy = in_array($deliveryMethod, ['ems', 'grab', 'delivery'], true);
            if (($checkoutStep === 'order_confirmed' || $checkoutStep === 'ask_address') && $needsAddressLegacy && mb_strlen($text) > 30) {
                // พยายาม parse ที่อยู่จาก text
                $addressData = $this->parseShippingAddress($text);

                if (!empty($addressData['address_line1'])) {
                    try {
                        // Try multiple sources for platform_user_id
                        $platformUserId = $context['platform_user_id']
                            ?? $context['external_user_id']
                            ?? $context['customer']['external_user_id']
                            ?? null;
                        $platform = $context['platform'] ?? 'line';

                        Logger::info('[ROUTER_V1_LEGACY] Attempting to save address', [
                            'platform_user_id' => $platformUserId,
                            'platform' => $platform,
                            'address_data' => $addressData,
                            'context_external_user_id' => $context['external_user_id'] ?? 'N/A',
                            'trace_id' => $traceId,
                        ]);

                        if ($platformUserId) {
                            // 1. หา customer_id จาก customer_profiles (optional)
                            $customer = $this->db->queryOne(
                                "SELECT id FROM customer_profiles WHERE platform_user_id = ? AND platform = ? LIMIT 1",
                                [$platformUserId, $platform]
                            );
                            $customerId = $customer ? (int) $customer['id'] : null;

                            // 2. INSERT ลง customer_addresses
                            $this->db->execute(
                                "INSERT INTO customer_addresses (
                                    customer_id, platform, platform_user_id, address_type, 
                                    recipient_name, phone, address_line1, address_line2, 
                                    subdistrict, district, province, postal_code, country, 
                                    is_default, created_at
                                ) VALUES (?, ?, ?, 'shipping', ?, ?, ?, ?, ?, ?, ?, ?, 'Thailand', 1, NOW())",
                                [
                                    $customerId ?: 1,
                                    $platform,
                                    $platformUserId,
                                    $addressData['name'] ?? '',
                                    $addressData['phone'] ?? '',
                                    $addressData['address_line1'] ?? '',
                                    $addressData['address_line2'] ?? '',
                                    $addressData['subdistrict'] ?? '',
                                    $addressData['district'] ?? '',
                                    $addressData['province'] ?? '',
                                    $addressData['postal_code'] ?? '',
                                ]
                            );

                            $newAddressId = $this->db->lastInsertId();

                            Logger::info('[ROUTER_V1_LEGACY] Customer address saved to customer_addresses', [
                                'address_id' => $newAddressId,
                                'customer_id' => $customerId,
                                'platform_user_id' => $platformUserId,
                                'address' => $addressData,
                                'trace_id' => $traceId
                            ]);
                        } else {
                            Logger::warning('[ROUTER_V1_LEGACY] No platform_user_id found', [
                                'context_keys' => array_keys($context),
                                'trace_id' => $traceId
                            ]);
                        }
                    } catch (\Exception $e) {
                        Logger::error('[ROUTER_V1_LEGACY] Failed to save customer address', [
                            'error' => $e->getMessage(),
                            'trace_id' => $traceId
                        ]);
                    }

                    // ตอบกลับยืนยันที่อยู่ + สรุปออเดอร์
                    $productName = trim((string) ($lastSlots['product_name'] ?? ''));
                    $totalAmount = $lastSlots['first_payment'] ?? ($lastSlots['product_price'] ?? 0);
                    $paymentMethod = trim((string) ($lastSlots['payment_method'] ?? 'full'));

                    $paymentLabel = match ($paymentMethod) {
                        'installment' => 'ผ่อน 3 งวด',
                        'deposit' => 'มัดจำ 10%',
                        default => 'โอนเต็มจำนวน',
                    };

                    $reply = "ได้รับที่อยู่เรียบร้อยแล้วค่ะ ✅||SPLIT||" .
                        "📦 สรุปออเดอร์:\n" .
                        "• สินค้า: " . ($productName ?: 'สินค้า') . "\n" .
                        "• ยอดชำระ: " . number_format($totalAmount, 0) . " บาท ({$paymentLabel})\n" .
                        "• จัดส่ง: EMS (+150 บาท)||SPLIT||" .
                        "รอสักครู่นะคะ ระบบกำลังส่งเลขบัญชีให้ค่ะ 🙏";

                    // Update session to complete - reset checkout_step
                    $slots = $this->mergeSlots($lastSlots, [
                        'checkout_step' => '',  // ✅ Reset เพื่อให้ลูกค้าถามเพิ่มเติมได้
                        'shipping_address' => json_encode($addressData),
                        'order_status' => 'pending_payment',  // เก็บสถานะว่าสั่งซื้อแล้ว
                    ]);
                    $this->updateSessionState((int) $sessionId, 'completed', $slots);

                    if ($reply !== '') {
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    }
                    $this->logBotReply($context, $reply, 'text');

                    return [
                        'reply_text' => $reply,
                        'actions' => [],
                        'meta' => ['reason' => 'checkout_address_received', 'slots' => $slots, 'trace_id' => $traceId],
                        'handoff_to_admin' => true
                    ];
                }
            }

            // =========================================================
            // ✅ KB FIRST (with KB-only buffering)
            // =========================================================
            $kbQuery = $text;
            if ($sessionId) {
                $kbQuery = $this->buildKbBufferedText((int) $sessionId, $text, $bufferingCfg);
                $meta['kb_buffering'] = [
                    'enabled' => (bool) ($bufferingCfg['kb_enabled'] ?? true),
                    'window_seconds' => (int) ($bufferingCfg['kb_window_seconds'] ?? 25),
                    'max_messages' => (int) ($bufferingCfg['kb_max_messages'] ?? 2),
                    'kb_query' => $kbQuery,
                ];
            }

            $kbResults = $this->searchKnowledgeBase($context, $kbQuery);
            if (!empty($kbResults) && isset($kbResults[0])) {
                $bestMatch = $kbResults[0];
                $reply = (string) ($bestMatch['answer'] ?? $fallback);

                $meta['knowledge_base'] = [
                    'matched' => true,
                    'match_type' => $bestMatch['match_type'] ?? 'unknown',
                    'match_score' => $bestMatch['match_score'] ?? 0,
                    'matched_keyword' => $bestMatch['matched_keyword'] ?? null,
                    'category' => $bestMatch['category'] ?? null,
                    'metadata' => $bestMatch['metadata'] ?? [],
                ];
                $meta['reason'] = 'knowledge_base_answer';
                $meta['route'] = $bestMatch['category'] ?? 'knowledge';

                if ($sessionId && $reply !== '')
                    $this->storeMessage($sessionId, 'assistant', $reply);
                $this->logBotReply($context, $reply, 'text');

                return [
                    'reply_text' => $reply,
                    'actions' => [],
                    'meta' => $meta,
                ];
            }

            // ✅ KB pending hold (fixed logic)
            if (!$isAdmin && $sessionId) {
                $kbHoldEnabled = (bool) ($bufferingCfg['kb_enabled'] ?? true);
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
            // ✅ KEYWORD-BASED HANDOFF TRIGGERS (auto handoff to admin)
            // =========================================================
            $caseManagement = $config['case_management'] ?? [];
            $handoffTriggers = $caseManagement['admin_handoff_triggers'] ?? [];
            $matchedHandoffKeyword = null;

            if (!$isAdmin && !empty($handoffTriggers)) {
                $textLen = mb_strlen($text, 'UTF-8');
                $shortConfirmations = ['สนใจ', 'ใช่', 'รับเลย', 'ตกลง', 'เอา', 'รับ', 'โอเค', 'ok'];

                // =========================================================
                // ✅ SKIP HANDOFF TRIGGERS if in checkout flow
                // Let the checkout flow handle these keywords naturally
                // =========================================================
                $productPrice = (float) ($lastSlots['product_price'] ?? 0);
                $productName = trim((string) ($lastSlots['product_name'] ?? ''));
                $checkoutStep = trim((string) ($lastSlots['checkout_step'] ?? ''));

                // If already in checkout flow or just asked payment, skip handoff triggers
                // Let LLM handle the response contextually
                if ($productPrice > 0 && !empty($checkoutStep)) {
                    Logger::info('[HANDOFF_TRIGGERS] Skipping - in checkout flow', [
                        'checkout_step' => $checkoutStep,
                        'text' => $text,
                    ]);
                    // Don't check handoff triggers - let LLM handle checkout flow
                    $handoffTriggers = []; // Clear to skip the loop below
                }

                foreach ($handoffTriggers as $keyword) {
                    $keyword = trim((string) $keyword);
                    if ($keyword === '')
                        continue;

                    // For short confirmations, only trigger if message is SHORT (< 15 chars)
                    // This prevents "สนใจ rolex" from triggering handoff
                    if (in_array(mb_strtolower($keyword, 'UTF-8'), $shortConfirmations)) {
                        if ($textLen < 15 && mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
                            $matchedHandoffKeyword = $keyword;
                            break;
                        }
                    } else {
                        // Other keywords (ซื้อเลย, มัดจำ, etc.) work normally
                        if (mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
                            $matchedHandoffKeyword = $keyword;
                            break;
                        }
                    }
                }

                if ($matchedHandoffKeyword) {
                    Logger::info('[HANDOFF_TRIGGER] Keyword matched', [
                        'trace_id' => $traceId,
                        'keyword' => $matchedHandoffKeyword,
                        'text_preview' => mb_substr($text, 0, 50, 'UTF-8'),
                    ]);

                    // Get slots from LLM if available
                    $handoffSlots = $lastSlots;
                    if ($llmIntegration && !empty($config['llm']['enabled'])) {
                        $llmForSlots = $this->handleWithLlmIntent($llmIntegration, $config, $context, $text);
                        if (is_array($llmForSlots['slots'] ?? null)) {
                            $handoffSlots = $this->mergeSlots($lastSlots, $llmForSlots['slots']);
                        }
                    }

                    // Detect case type from keyword
                    $handoffCaseType = $this->detectCaseTypeFromKeyword($matchedHandoffKeyword);

                    // Create case via API with pending_admin status
                    $backendCfg = $config['backend_api'] ?? [];
                    if (!empty($caseManagement['enabled']) && !empty($backendCfg['enabled'])) {
                        try {
                            $caseEndpoint = $backendCfg['endpoints']['case_create'] ?? '/api/bot/cases';
                            $casePayload = [
                                'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? 'unknown'),
                                'channel_id' => $channelId,
                                'external_user_id' => $externalUserId,
                                'case_type' => $handoffCaseType,
                                'status' => 'pending_admin',
                                'slots' => $handoffSlots,
                                'intent' => 'handoff_request',
                                'message' => $text,
                                'handoff_trigger' => $matchedHandoffKeyword,
                            ];

                            $caseResp = $this->callBackendJson($backendCfg, $caseEndpoint, $casePayload);

                            if ($caseResp['ok'] && !empty($caseResp['data'])) {
                                $meta['case'] = [
                                    'id' => $caseResp['data']['id'] ?? null,
                                    'case_no' => $caseResp['data']['case_no'] ?? null,
                                    'case_type' => $handoffCaseType,
                                    'is_new' => $caseResp['data']['is_new'] ?? true,
                                    'handoff_trigger' => $matchedHandoffKeyword,
                                ];
                                Logger::info('[HANDOFF_TRIGGER] Case created with pending_admin', [
                                    'trace_id' => $traceId,
                                    'case_id' => $caseResp['data']['id'] ?? null,
                                ]);
                            }
                        } catch (Throwable $e) {
                            Logger::error('[HANDOFF_TRIGGER] Failed to create case', [
                                'trace_id' => $traceId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    // Add handoff action
                    $meta['actions'][] = ['type' => 'handoff_to_admin', 'reason' => 'keyword_trigger', 'keyword' => $matchedHandoffKeyword];
                    $meta['handoff_trigger'] = $matchedHandoffKeyword;

                    // Reply with handoff message
                    $handoffReply = $templates['handoff_to_admin'] ?? 'ขอส่งต่อให้เจ้าหน้าที่ดูแลต่อนะคะ 👩‍💼 สักครู่ค่ะ';
                    $meta['reason'] = 'handoff_keyword_trigger';

                    if ($sessionId) {
                        $this->updateSessionState($sessionId, 'handoff_request', $handoffSlots);
                        $this->storeMessage($sessionId, 'assistant', $handoffReply);
                    }
                    $this->logBotReply($context, $handoffReply, 'text');

                    return [
                        'reply_text' => $handoffReply,
                        'actions' => $meta['actions'],
                        'meta' => $meta,
                    ];
                }
            }

            // =========================================================
            // ✅ Product code pattern detection (BEFORE routing rules)
            // Matches patterns like ROL-SUB-002, DIA-RNG-001, GUC-MAR-001
            // ✅ CRITICAL: Skip if already in checkout flow to avoid duplicate search
            // =========================================================
            $currentCheckoutStepForCodeDetection = trim((string) ($lastSlots['checkout_step'] ?? ''));
            $skipProductCodeDetection = in_array($currentCheckoutStepForCodeDetection, ['ask_payment', 'ask_delivery', 'ask_address', 'order_confirmed', 'address_received'], true);

            $productCodePattern = '/\b([A-Z]{2,4}[-_][A-Z]{2,4}[-_]\d{2,4})\b/i';

            // ✅ DEBUG: Log product code detection attempt
            Logger::info('[PRODUCT_CODE_DETECTION]', [
                'trace_id' => $traceId,
                'text' => $text,
                'skip' => $skipProductCodeDetection,
                'checkout_step' => $currentCheckoutStepForCodeDetection,
                'pattern' => $productCodePattern,
                'matches' => preg_match($productCodePattern, $text, $debugMatch) ? $debugMatch : null,
            ]);

            if (!$skipProductCodeDetection && preg_match($productCodePattern, $text, $codeMatch)) {
                $detectedCode = strtoupper($codeMatch[1]);
                $matchedRoute = 'product_lookup_by_code';
                $meta['detected_product_code'] = $detectedCode;
                $meta['route'] = $matchedRoute;

                Logger::info('[ROUTER_V1] Product code detected by regex', [
                    'code' => $detectedCode,
                    'text' => $text,
                    'trace_id' => $traceId ?? null
                ]);

                // Skip keyword matching, go directly to intent handling
                $intent = 'product_lookup_by_code';
                $slots = $this->mergeSlots($lastSlots, ['product_code' => $detectedCode]);

                // =========================================================
                // ✅ Direct call to ProductSearchService
                // =========================================================
                try {
                    $products = ProductSearchService::searchByProductCode($detectedCode);

                    if (!empty($products)) {
                        $replyText = ProductSearchService::formatMultipleForChat($products, 3);
                        $imageUrl = $products[0]['thumbnail_url'] ?? null;

                        // Cache candidates for later selection (e.g., 'เอาอันที่ 2')
                        $slots = $this->attachProductCandidatesToSlots($slots, $products, $detectedCode, $sessionPolicy);

                        // ✅ CRITICAL: Save product info to slots for checkout flow
                        $foundProductName = $products[0]['title'] ?? ($products[0]['name'] ?? null);
                        $foundProductPrice = $products[0]['price'] ?? null;
                        $foundProductCode = $detectedCode;
                        
                        $slots = $this->mergeSlots($slots, [
                            'product_ref_id' => $products[0]['ref_id'] ?? null,
                            'product_code' => $foundProductCode,
                            'product_name' => $foundProductName,
                            'product_price' => $foundProductPrice,
                            'product_image_url' => $products[0]['thumbnail_url'] ?? null,
                        ]);

                        // Create case for product inquiry
                        try {
                            $caseEngine = new CaseEngine($config, $context);
                            $caseSlots = [
                                'product_code' => $detectedCode,
                                'product_name' => $foundProductName,
                                'product_price' => $foundProductPrice,
                                'product_ref_id' => $products[0]['ref_id'] ?? null,
                                'product_image_url' => $products[0]['thumbnail_url'] ?? null,
                            ];
                            $case = $caseEngine->getOrCreateCase(CaseEngine::CASE_PRODUCT_INQUIRY, $caseSlots);
                            $meta['case'] = ['id' => $case['id'] ?? null, 'case_no' => $case['case_no'] ?? null];
                        } catch (Exception $caseErr) {
                            Logger::error('[ROUTER_V1] Failed to create case: ' . $caseErr->getMessage());
                        }

                        // =========================================================
                        // ✅ NEW: Check if user also expressed interest (สนใจ/เอา/ซื้อ)
                        // If so, start checkout flow immediately instead of just showing product
                        // =========================================================
                        $interestPattern = '/(สนใจ|เอา|ซื้อ|ตกลง|จอง|cf|เอาเลย|ซื้อเลย|รับเลย|รับ)/iu';
                        $hasInterestKeyword = preg_match($interestPattern, $text);
                        
                        if ($hasInterestKeyword && $foundProductPrice > 0) {
                            Logger::info('[ROUTER_V1] Product code + interest keyword detected - starting checkout', [
                                'code' => $foundProductCode,
                                'price' => $foundProductPrice,
                                'name' => $foundProductName,
                                'trace_id' => $traceId ?? null
                            ]);
                            
                            // Update slots to start checkout
                            $slots = $this->mergeSlots($slots, ['checkout_step' => 'ask_payment']);
                            
                            if ($sessionId) {
                                $this->updateSessionState((int) $sessionId, 'ask_payment', $slots);
                            }
                            
                            // Build checkout reply
                            $checkoutReply = "ยินดีค่ะ 😊\n\n";
                            $checkoutReply .= "📦 {$foundProductName}\n";
                            $checkoutReply .= "🏷️ รหัส: {$foundProductCode}\n";
                            $checkoutReply .= "💰 " . number_format($foundProductPrice, 0) . " บาท\n\n";
                            $checkoutReply .= "สะดวกชำระแบบไหนดีคะ?\n";
                            $checkoutReply .= "1️⃣ โอนเต็ม\n";
                            $checkoutReply .= "2️⃣ ผ่อน 3 งวด (+3% ค่าดำเนินการครั้งแรก)\n";
                            $checkoutReply .= "3️⃣ มัดจำ 10%";
                            
                            if ($sessionId) {
                                $this->storeMessage($sessionId, 'assistant', $checkoutReply);
                            }
                            $this->logBotReply($context, $checkoutReply, 'text');
                            
                            // ✅ Build actions: Image first, then quick reply
                            $actionsOut = [];
                            
                            // Add product image (ลูกค้ายังไม่เคยเห็นรูป ต้องแสดงด้วย)
                            $productImageUrl = $products[0]['thumbnail_url'] ?? ($products[0]['image_url'] ?? null);
                            if ($productImageUrl) {
                                $actionsOut[] = ['type' => 'image', 'url' => $productImageUrl];
                            }
                            
                            // Add quick reply buttons
                            $actionsOut[] = [
                                'type' => 'quick_reply',
                                'items' => [
                                    ['label' => '💰 โอนเต็ม', 'text' => 'โอนเต็ม'],
                                    ['label' => '💳 ผ่อน 3 งวด', 'text' => 'ผ่อน 3 งวด'],
                                    ['label' => '🎯 มัดจำ', 'text' => 'มัดจำ'],
                                    ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                                ]
                            ];
                            
                            $meta['reason'] = 'product_code_with_interest_checkout';
                            return ['reply_text' => $checkoutReply, 'actions' => $actionsOut, 'meta' => $meta];
                        }

                        if ($sessionId) {
                            $this->updateSessionState($sessionId, $intent, $slots);
                            $this->storeMessage($sessionId, 'assistant', $replyText);
                        }
                        $this->logBotReply($context, $replyText, 'text');

                        $actionsOut = $this->buildImageActionsFromProducts($products, 3);

                        return [
                            'reply_text' => $replyText,
                            'actions' => $actionsOut,
                            'meta' => $meta,
                        ];
                    } else {
                        // ✅ FIX: Product code detected but not found - return clear message
                        $notFoundReply = $templates['product_not_found']
                            ?? "❌ ไม่พบสินค้ารหัส **{$detectedCode}** ในระบบค่ะ\n\nลองค้นหาด้วยรหัสอื่น หรือส่งรูปสินค้ามาให้แอดมินช่วยเช็คได้เลยนะคะ 😊";

                        if ($sessionId) {
                            $this->storeMessage($sessionId, 'assistant', $notFoundReply);
                        }
                        $this->logBotReply($context, $notFoundReply, 'text');

                        Logger::info('[ROUTER_V1] Product code not found', [
                            'code' => $detectedCode,
                            'trace_id' => $traceId ?? null
                        ]);

                        $meta['reason'] = 'product_not_found';
                        return [
                            'reply_text' => $notFoundReply,
                            'actions' => [],
                            'meta' => $meta,
                        ];
                    }
                } catch (Exception $e) {
                    Logger::error('[ROUTER_V1] ProductSearchService error: ' . $e->getMessage());
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
                    $kw = trim((string) $kw);
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
                    $slots = is_array($llm['slots'] ?? null) ? $llm['slots'] : [];
                    $confidence = $llm['confidence'] ?? null;
                    $nextQuestion = $llm['next_question'] ?? null;

                    // ✅ merge last slots
                    $slots = $this->mergeSlots($lastSlots, $slots);

                    // ✅ CONTEXT RESET: Clear stale product data when searching for new product
                    if (in_array($intent, ['product_availability', 'product_lookup_by_code', 'price_inquiry'])) {
                        $newProductName = trim((string) ($slots['product_name'] ?? ''));
                        $oldProductCode = trim((string) ($lastSlots['product_code'] ?? ''));

                        // If user is searching for something new and old code is NOT in current text
                        if ($newProductName !== '' && $oldProductCode !== '' && mb_stripos($text, $oldProductCode) === false) {
                            // Clear stale product context
                            unset($slots['product_code'], $slots['product_ref_id'], $slots['last_product_candidates']);
                            Logger::info('[CONTEXT_RESET] Cleared stale product context for new search', [
                                'new_product_name' => $newProductName,
                                'cleared_code' => $oldProductCode,
                                'text' => $text
                            ]);
                        }
                    }

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
                    if ($confidence !== null)
                        $meta['confidence'] = (float) $confidence;

                    if (!empty($handled['handled'])) {
                        $reply = (string) ($handled['reply_text'] ?? $fallback);
                        $meta['reason'] = $handled['reason'] ?? 'route_backend_handled';
                        $meta['backend'] = $handled['meta'] ?? null;

                        // ✅ Backend response - skip hallucination check
                        $backendWasUsed = !empty($meta['backend']);
                        $backendWorked = !empty($config['backend_api']['enabled']);
                        $reply = $this->applyPolicyGuards($reply, $intent, $config, $templates, $backendWorked, $backendWasUsed, $handled['slots'] ?? $slots);

                        if ($sessionId && $intent) {
                            $this->updateSessionState($sessionId, $intent, $handled['slots'] ?? $slots);
                        }
                        if ($sessionId && $reply !== '')
                            $this->storeMessage($sessionId, 'assistant', $reply);
                        $this->logBotReply($context, $reply, 'text');

                        return ['reply_text' => $reply, 'actions' => $handled['actions'] ?? [], 'meta' => $meta];
                    }

                    // ยังไม่ handled -> ถามต่อ
                    $reply = '';
                    if (!empty($handled['reply_text'])) {
                        $reply = (string) $handled['reply_text'];
                        $meta['reason'] = $handled['reason'] ?? 'route_need_more_info';
                    } elseif ($nextQuestion) {
                        $reply = (string) $nextQuestion;
                        $meta['reason'] = 'route_slot_filling_next_question';
                    } else {
                        $reply = $this->fallbackByIntentTemplate($intent, $templates, $fallback);
                        $meta['reason'] = 'route_fallback_template';
                    }

                    // handoff policy
                    $handoffEnabled = !empty($handoffCfg['enabled']);
                    $handoffThreshold = isset($handoffCfg['when_confidence_below']) ? (float) $handoffCfg['when_confidence_below'] : 0.0;
                    if ($handoffEnabled && $confidence !== null && (float) $confidence < $handoffThreshold) {
                        $meta['actions'][] = ['type' => 'handoff_to_admin', 'reason' => 'low_confidence'];
                    }

                    if ($sessionId && $intent) {
                        $this->updateSessionState($sessionId, $intent, $slots);
                    }

                    // ✅ Apply policy guards - LLM reply (not from backend)
                    $backendEnabled = !empty($config['backend_api']['enabled']);
                    $reply = $this->applyPolicyGuards($reply, $intent, $config, $templates, $backendEnabled, false, $slots);

                    if ($sessionId && $reply !== '')
                        $this->storeMessage($sessionId, 'assistant', $reply);
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
                if ($sessionId && $matchedRoute)
                    $this->updateSessionState($sessionId, $matchedRoute, $lastSlots);
                if ($sessionId && $reply !== '')
                    $this->storeMessage($sessionId, 'assistant', $reply);
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
                    'reply_preview' => isset($llmResult['reply_text']) ? mb_substr((string) $llmResult['reply_text'], 0, 120, 'UTF-8') : null,
                    'slots_keys' => (isset($llmResult['slots']) && is_array($llmResult['slots'])) ? array_keys($llmResult['slots']) : null,
                    'next_question_present' => !empty($llmResult['next_question'] ?? null),
                ]);

                $reply = (string) ($llmResult['reply_text'] ?? $fallback);
                $intent = $llmResult['intent'] ?? null;
                $slots = is_array($llmResult['slots'] ?? null) ? $llmResult['slots'] : [];
                $confidence = $llmResult['confidence'] ?? null;
                $nextQuestion = $llmResult['next_question'] ?? null;

                $meta['llm_intent'] = $llmResult['meta'] ?? null;

                $slots = $this->mergeSlots($lastSlots, $slots);

                // ✅ CONTEXT RESET: Clear stale product data when searching for new product
                if (in_array($intent, ['product_availability', 'product_lookup_by_code', 'price_inquiry'])) {
                    $newProductName = trim((string) ($slots['product_name'] ?? ''));
                    $oldProductCode = trim((string) ($lastSlots['product_code'] ?? ''));

                    if ($newProductName !== '' && $oldProductCode !== '' && mb_stripos($text, $oldProductCode) === false) {
                        unset($slots['product_code'], $slots['product_ref_id'], $slots['last_product_candidates']);
                        Logger::info('[CONTEXT_RESET] Cleared stale product context (LLM path)', [
                            'new_product_name' => $newProductName,
                            'cleared_code' => $oldProductCode
                        ]);
                    }
                }

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
                        $llmReply = trim((string) $llmResult['reply_text'] ?? '');
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
                // ✅ CRITICAL: Skip if already in checkout flow
                // =========================================================
                $keywordCheckoutStep = trim((string) ($lastSlots['checkout_step'] ?? ''));
                $isInCheckoutFlowForKeyword = in_array($keywordCheckoutStep, ['ask_payment', 'ask_delivery', 'ask_address', 'order_confirmed', 'address_received'], true);

                if (empty($intent) && !$isInCheckoutFlowForKeyword) {
                    $textLower = mb_strtolower($text, 'UTF-8');

                    // =========================================================
                    // ✅ CONFIRMATION QUESTION DETECTION (Priority 1)
                    // Detect "ใช่ไหม", "ใช่มั้ย", "ใช่เหรอ" etc. patterns
                    // These need direct YES answer, not repeated info
                    // =========================================================
                    $isConfirmationQuestion = preg_match('/ใช่(ไหม|มั้ย|เหรอ|ป่ะ|รึเปล่า)|ถูก(ต้อง)?ไหม|หมายถึง.*ใช่|หมายความว่า.*ใช่/u', $textLower);

                    if ($isConfirmationQuestion) {
                        $intent = 'confirmation_response';
                        Logger::info("[INTENT_FALLBACK] Confirmation question detected - will confirm understanding", ['text' => $text]);
                    }
                    // =========================================================
                    // ✅ NEW INTENT PATTERNS - Added for dynamic conversation
                    // =========================================================
                    // Price negotiation (ลดราคา, ต่อรอง, discount)
                    elseif (preg_match('/ลดราคา|ลดได้|ต่อราคา|ต่อรอง|discount|ถูกกว่า|ขอลด|ลดหน่อย|ราคา.*ลด/iu', $textLower)) {
                        $intent = 'price_negotiation';
                        Logger::info("[INTENT_FALLBACK] Keyword match: price_negotiation", ['text' => $text]);
                    }
                    // Change payment method (เปลี่ยนวิธีจ่าย, จ่ายเต็มแทน)
                    elseif (preg_match('/เปลี่ยน.*(โอน|ผ่อน|มัดจำ|จ่าย|ชำระ|วิธี)|จ่ายเต็ม.*แทน|เปลี่ยนใจ.*(โอน|ผ่อน)|โอนเต็ม.*ดีกว่า|ผ่อน.*ดีกว่า|ขอเปลี่ยน.*(วิธี|ชำระ)/iu', $textLower)) {
                        $intent = 'change_payment_method';
                        Logger::info("[INTENT_FALLBACK] Keyword match: change_payment_method", ['text' => $text]);
                    }
                    // Consignment / ฝากขาย
                    elseif (preg_match('/ฝากขาย|ขายฝาก|เอามาฝาก.*ขาย|ฝาก.*ช่วยขาย|consign/iu', $textLower)) {
                        $intent = 'consignment';
                        Logger::info("[INTENT_FALLBACK] Keyword match: consignment", ['text' => $text]);
                    }
                    // General installment inquiry (ผ่อนได้ไหม, มีผ่อนไหม)
                    elseif (preg_match('/(ผ่อน|งวด).*(ได้ไหม|ได้มั้ย|มีไหม|มีมั้ย|รึเปล่า)|มี.*(ผ่อน|งวด).*ไหม/iu', $textLower)) {
                        $intent = 'installment_inquiry';
                        Logger::info("[INTENT_FALLBACK] Keyword match: installment_inquiry (asking if available)", ['text' => $text]);
                    }
                    // General pawn inquiry (จำนำได้ไหม, รับจำนำไหม)
                    elseif (preg_match('/(จำนำ|รับฝาก).*(ได้ไหม|ได้มั้ย|มีไหม|มีมั้ย|รึเปล่า)|มี.*จำนำ.*ไหม|จำนำ.*ละ/iu', $textLower)) {
                        $intent = 'pawn_inquiry';
                        Logger::info("[INTENT_FALLBACK] Keyword match: pawn_inquiry (asking if available)", ['text' => $text]);
                    }
                    // =========================================================
                    // END NEW INTENT PATTERNS
                    // =========================================================
                    // Savings keywords
                    elseif (preg_match('/ดอก\s*กี่|ดอก\s*เท่าไหร่|ดอกเบี้ย\s*กี่|ดอกเบี้ย\s*เท่าไหร่|ดอก\s*%|ดอกเบี้ย\s*%/u', $textLower)) {
                        $intent = 'interest_rate_inquiry';
                        // Determine mode: pawn vs installment
                        $slots['interest_mode'] = (preg_match('/จำนำ|ฝากจำนำ|ต่อดอก|ไถ่ถอน/u', $textLower)) ? 'pawn' : 'installment';
                        Logger::info("[INTENT_FALLBACK] Keyword match: interest_rate_inquiry", ['text' => $text, 'mode' => $slots['interest_mode']]);
                    }


                    // Savings keywords
                    elseif (preg_match('/ออม|ออมทอง|เปิดออม|สะสม/u', $textLower)) {
                        $intent = 'savings_new';
                        $slots['action_type'] = 'new';
                        Logger::info("[INTENT_FALLBACK] Keyword match: savings_new", ['text' => $text]);
                    }
                    // =========================================================
                    // ✅ Installment Summary Query Detection (PRIORITY before generic)
                    // Pattern: ต้องมี (งวด|ผ่อน) + (inquiry words)
                    // เพื่อแยก "ถามยอดค้าง" vs "ถามโปรโมผ่อน" vs "จ่ายค่างวด"
                    // =========================================================
                    elseif (
                        preg_match(
                            '/(' .
                            // Pattern 1: (งวด|ผ่อน) + inquiry (เหลือ|ค้าง|กี่|สรุป|เช็ค)
                            '(งวด|ผ่อน).{0,10}(เหลือ|ค้าง|กี่บาท|กี่งวด|สรุป|เช็ค|ดู|ขอดู)|' .
                            // Pattern 2: inquiry + (งวด|ผ่อน)
                            '(เหลือ|ค้าง|ยอด).{0,10}(งวด|ผ่อน)|' .
                            // Pattern 3: Explicit summary requests
                            '(เช็คยอด|ดูยอด|ขอยอด|สรุปยอด).{0,5}(ผ่อน|งวด)|' .
                            // Pattern 4: "เหลือกี่งวด", "ต้องจ่ายอีกเท่าไหร่"
                            '(เหลือ.*กี่.*งวด|ต้องจ่าย.*อีก.*เท่าไหร่|จ่ายไปแล้ว.*กี่.*งวด)' .
                            ')/u',
                            $textLower
                        )
                    ) {
                        $intent = 'installment_flow';
                        $slots['action_type'] = 'summary';
                        Logger::info("[INTENT_FALLBACK] Keyword match: installment_flow (summary query)", ['text' => $text]);
                    }
                    // =========================================================
                    // ✅ Explicit Installment Action Keywords (ปิดยอด, ต่อดอก)
                    // These are specific installment actions, NOT pawn actions
                    // Must be checked BEFORE generic installment/pawn patterns
                    // =========================================================
                    elseif (preg_match('/ปิดยอด/u', $textLower)) {
                        $intent = 'installment_flow';
                        $slots['action_type'] = 'close_check';
                        Logger::info("[INTENT_FALLBACK] Keyword match: installment_flow (close_check)", ['text' => $text]);
                    } elseif (preg_match('/ต่อดอก/u', $textLower)) {
                        // "ต่อดอก" can be installment or pawn context
                        // Check for pawn context words
                        $isPawnContext = preg_match('/จำนำ|ฝากจำนำ|ของจำนำ|ไถ่|ไถ่ถอน/u', $textLower);
                        if ($isPawnContext) {
                            $intent = 'pawn_new';
                            $slots['action_type'] = 'extend';
                            Logger::info("[INTENT_FALLBACK] Keyword match: pawn_new (extend interest)", ['text' => $text]);
                        } else {
                            // Default to installment context
                            $intent = 'installment_flow';
                            $slots['action_type'] = 'extend_interest';
                            Logger::info("[INTENT_FALLBACK] Keyword match: installment_flow (extend_interest)", ['text' => $text]);
                        }
                    }
                    // Installment keywords - SMART DETECTION
                    // Distinguish: Promotion inquiry vs Actual payment
                    elseif (preg_match('/ผ่อน|งวด|ผ่อนชำระ/u', $textLower)) {
                        // Check if this is PAYMENT context (จ่าย/โอน/ชำระ + งวด)
                        $isPaymentContext = preg_match('/จ่าย.*(งวด|ผ่อน)|โอน.*(งวด|ผ่อน)|ชำระ.*(งวด|ผ่อน)|งวดที่/u', $textLower);

                        if ($isPaymentContext) {
                            // Customer is making a payment
                            $intent = 'installment_flow';
                            $slots['action_type'] = 'pay';
                            Logger::info("[INTENT_FALLBACK] Keyword match: installment_flow (payment)", ['text' => $text]);
                        } else {
                            // Customer is asking about promotion/terms
                            $intent = 'interest_rate_inquiry';
                            Logger::info("[INTENT_FALLBACK] Keyword match: interest_rate_inquiry (promotion question)", ['text' => $text]);
                        }
                    }
                    // Pawn keywords (จำนำ context)
                    // Note: ต่อดอก is handled above with context detection
                    elseif (preg_match('/จำนำ|ฝากจำนำ|ไถ่ถอน/u', $textLower)) {
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
                    // Buy-back / Sell-back keywords (เอามาขาย/เทิร์น)
                    elseif (preg_match('/เอามาขาย|ขายคืน|เทิร์น|รับซื้อไหม|รับซื้อมั้ย|จะขาย/u', $textLower)) {
                        $intent = 'buy_back';
                        Logger::info("[INTENT_FALLBACK] Keyword match: buy_back", ['text' => $text]);
                    }
                    // Repair status keywords (เช็คสถานะซ่อม)
                    elseif (preg_match('/สถานะ.*(ซ่อม|งาน)|ซ่อมเสร็จ.*ยัง|งานซ่อม.*(ไหน|เมื่อไหร่)|เมื่อไหร่.*(ซ่อม|เสร็จ)/u', $textLower)) {
                        $intent = 'repair_inquiry';
                        $slots['action_type'] = 'inquiry';
                        Logger::info("[INTENT_FALLBACK] Keyword match: repair_inquiry (status check)", ['text' => $text]);
                    }
                    // Product inquiry keywords (general - LLM handles brand/product extraction)
                    // ✅ IMPORTANT: Only trigger product_availability if there's a REAL product indicator
                    // Short generic keywords like "สนใจ", "ราคา", "ดู" alone should NOT trigger product search
                    elseif (preg_match('/แหวน|สร้อย|นาฬิกา|กำไล|ต่างหู|กระเป๋า|เพชร|ทอง|แบบไหน.*บ้าง|มีอะไรบ้าง/iu', $textLower)) {
                        $intent = 'product_availability';
                        // If asking "มีแบบไหนบ้าง" without specifying, try to get from previous context
                        if (preg_match('/แบบไหน.*บ้าง|มีอะไรบ้าง|มีอะไร/u', $textLower)) {
                            if (!empty($lastSlots['product_name'])) {
                                $slots['product_name'] = $lastSlots['product_name'];
                                Logger::info("[INTENT_FALLBACK] Using product_name from previous context", ['product_name' => $slots['product_name']]);
                            } elseif (!empty($lastSlots['last_product_query'])) {
                                $slots['product_name'] = $lastSlots['last_product_query'];
                                Logger::info("[INTENT_FALLBACK] Using last_product_query from previous context", ['product_name' => $slots['product_name']]);
                            }
                        }
                        Logger::info("[INTENT_FALLBACK] Keyword match: product_availability (has product indicator)", ['text' => $text, 'slots' => $slots]);
                    }
                    // Generic interest keywords (สนใจ, ดู, ราคา, มีไหม) - only match if combined with product context
                    elseif (preg_match('/สนใจ|ดู|มี.*ไหม|ราคา/iu', $textLower)) {
                        // Check if there's product context from previous conversation
                        if (!empty($lastSlots['product_name']) || !empty($lastSlots['product_code']) || !empty($lastSlots['last_product_query'])) {
                            $intent = 'product_availability';
                            $slots['product_name'] = $lastSlots['product_name'] ?? ($lastSlots['last_product_query'] ?? null);
                            $slots['product_code'] = $lastSlots['product_code'] ?? null;
                            Logger::info("[INTENT_FALLBACK] Generic keyword with product context", ['text' => $text, 'slots' => $slots]);
                        } else {
                            // No product context - let LLM handle or ask for clarification
                            Logger::info("[INTENT_FALLBACK] Generic keyword without product context - letting LLM handle", ['text' => $text]);
                            // Don't set intent - let it fall through to LLM
                        }
                    }
                }

                $intentConfigMap = $config['intents'] ?? [];
                $intentConfig = ($intent && isset($intentConfigMap[$intent])) ? $intentConfigMap[$intent] : [];
                $missingSlots = $intent ? $this->detectMissingSlots($intent, $intentConfig, $slots) : [];

                $meta['intent'] = $intent;
                $meta['slots'] = $slots;
                $meta['missing_slots'] = $missingSlots;
                if ($confidence !== null)
                    $meta['confidence'] = (float) $confidence;

                // =========================================================
                // ✅ PERSIST PRODUCT CONTEXT: Save product_name for follow-up questions
                // =========================================================
                if (!empty($slots['product_name']) && $sessionId) {
                    $this->updateSessionState((int) $sessionId, 'product_context', [
                        'product_name' => $slots['product_name'],
                        'last_product_query' => $slots['product_name'],
                    ]);
                    Logger::info('[CONTEXT] Saved product_name for follow-up', ['product_name' => $slots['product_name']]);
                }

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
                    $reply = (string) ($handled['reply_text'] ?? $fallback);
                    $meta['backend'] = $handled['meta'] ?? null;
                    $meta['reason'] = $handled['reason'] ?? 'llm_intent_backend_handled';
                    if (!empty($intent))
                        $meta['route'] = $intent;

                    // ✅ PRESERVE actions from backend (for product images, etc.)
                    $actionsOut = (isset($handled['actions']) && is_array($handled['actions'])) ? $handled['actions'] : [];

                    if ($sessionId && $intent) {
                        $this->updateSessionState($sessionId, $intent, $handled['slots'] ?? $slots);
                    }

                    if ($sessionId && $reply !== '')
                        $this->storeMessage($sessionId, 'assistant', $reply);
                    $this->logBotReply($context, $reply, 'text');
                    return [
                        'reply_text' => $reply,
                        'actions' => $actionsOut,  // ✅ FIXED: Send actions!
                        'meta' => $meta,
                    ];
                }

                // ถ้ายังไม่พร้อม -> ถามต่อ
                if ($intent && !empty($missingSlots) && $nextQuestion) {
                    $reply = (string) $nextQuestion;
                    $meta['reason'] = 'llm_intent_slot_filling';
                } else {
                    if ($sessionId) {
                        $this->updateSessionState($sessionId, $intent, $slots);
                    }
                    $meta['reason'] = 'llm_intent_default';
                }

                // handoff policy
                $handoffEnabled = !empty($handoffCfg['enabled']);
                $handoffThreshold = isset($handoffCfg['when_confidence_below']) ? (float) $handoffCfg['when_confidence_below'] : 0.0;

                if ($handoffEnabled && $confidence !== null && (float) $confidence < $handoffThreshold) {
                    $meta['actions'][] = ['type' => 'handoff_to_admin', 'reason' => 'low_confidence'];
                    if ($reply === '' && $nextQuestion)
                        $reply = (string) $nextQuestion;
                }

                if (!empty($intent))
                    $meta['route'] = $intent;

                // ✅ Apply policy guards - LLM only
                $backendEnabled = !empty($config['backend_api']['enabled']);
                $reply = $this->applyPolicyGuards($reply, $intent, $config, $templates, $backendEnabled, false, $slots);
            } elseif ($llmIntegration && !empty($config['llm']['enabled'])) {
                $llmResult = $this->handleWithLlm($llmIntegration, $config, $context, $text);
                $reply = (string) ($llmResult['reply_text'] ?? $fallback);
                $meta['llm'] = $llmResult['meta'] ?? null;
                if (!empty($llmResult['intent']))
                    $meta['route'] = $llmResult['intent'];
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

            if ($sessionId && $reply !== '')
                $this->storeMessage($sessionId, 'assistant', $reply);
            $this->logBotReply($context, $reply, 'text');

            Logger::info('[ROUTER_V1] end', [
                'trace_id' => $traceId,
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                'reason' => $meta['reason'] ?? null,
                'route' => $meta['route'] ?? null,
                'intent' => $meta['intent'] ?? null,
                'reply_len' => mb_strlen((string) $reply, 'UTF-8'),
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
                'reply_text' => (string) ('ขออภัยค่ะ ระบบขัดข้องชั่วคราว รบกวนลองใหม่อีกครั้งนะคะ'),
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
        $lastImageUrl = (string) ($lastSlots['last_image_url'] ?? '');
        $lastImageKind = (string) ($lastSlots['last_image_kind'] ?? ''); // product_image | payment_proof | image_generic
        $lastImageTs = (string) ($lastSlots['last_image_ts'] ?? '');
        $lastImageAge = $lastImageTs ? ($now - strtotime($lastImageTs)) : 999999;

        // if too old, ignore
        if ($lastImageUrl === '' || $lastImageAge > 600) { // 10 minutes
            return ['handled' => false];
        }

        $tLower = mb_strtolower($text, 'UTF-8');

        // Follow-up product from last image
        $askHave = $this->containsAny($tLower, ["มีไหม", "ยังอยู่ไหม", "เช็คของ", "อยู่มั้ย", "มีของไหม", "มีรุ่นนี้ไหม", "มีมั้ย"]);
        $askPrice = $this->containsAny($tLower, ["ราคา", "เท่าไหร่", "ขอราคา", "ต่อรอง", "ลดได้ไหม"]);

        // Follow-up payment from last slip image
        $askPaid = $this->containsAny($tLower, ["โอนแล้ว", "ชำระแล้ว", "จ่ายแล้ว", "ส่งสลิป", "แนบสลิป", "โอนเงินแล้ว", "ตรวจสอบยอด", "เช็คยอด", "เช็คสลิป"]);

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
            if (empty($backendCfg['enabled']))
                return ['handled' => false];

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
                if (!is_array($products))
                    $products = [];

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
        $intent = trim((string) $intent);
        if ($intent === '') {
            return $slots;
        }

        // product_code extraction
        if ($intent === 'product_lookup_by_code') {
            $pc = trim((string) ($slots['product_code'] ?? ''));
            if ($pc === '') {
                // Examples: "รหัส xxxx", "code: RX-001", "SKU#123"
                if (preg_match('/(?:รหัส|โค้ด|code|sku|serial|ซีเรียล)\s*[:#]?\s*([A-Za-z0-9\-\_\.\/]+)\b/iu', $text, $m)) {
                    $slots['product_code'] = trim($m[1]);
                }
            }
        }

        // product_name extraction (improved to catch plain queries)
        if ($intent === 'product_availability' || $intent === 'price_inquiry') {
            $pn = trim((string) ($slots['product_name'] ?? ''));
            if ($pn === '') {
                // Try pattern with question keywords first
                if (preg_match('/(?:มีรุ่นนี้ไหม|มีของไหม|มีไหม|ราคา|เท่าไหร่|สนใจ|มี)\s+(.+?)(?:\s+ไหม|บ้าง|มั้ย)?$/iu', $text, $m)) {
                    $guess = trim($m[1]);
                    if (mb_strlen($guess, 'UTF-8') >= 2)
                        $slots['product_name'] = $guess;
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
            $amt = trim((string) ($slots['amount'] ?? ''));
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
        if (!$intent)
            return ['handled' => false];

        $backendCfg = $config['backend_api'] ?? [];
        $toolPolicy = $config['tool_policy'] ?? [];
        $preferBackend = (bool) ($toolPolicy['prefer_backend_over_llm'] ?? true);

        if (!$preferBackend || empty($backendCfg['enabled'])) {
            return ['handled' => false, 'reason' => 'backend_disabled_or_not_preferred'];
        }

        $channelId = $context['channel']['id'] ?? null;
        $externalUserId = $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null);

        // ✅ Define fallback message for error cases
        $fallback = $templates['fallback'] ?? $templates['product_search_error'] ?? 'ขออภัยค่ะ เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งค่ะ 🙏';

        // Normalize some slots
        if (!empty($slots['customer_phone']))
            $slots['customer_phone'] = $this->normalizePhone((string) $slots['customer_phone']);
        if (!empty($slots['amount']))
            $slots['amount'] = $this->normalizeAmount((string) $slots['amount']);

        // Helper ask templates
        $askProductCode = $templates['ask_product_code'] ?? $templates['fallback'] ?? 'รบกวนส่งรหัส/รุ่นเพิ่มนิดนึงค่ะ';
        $askInstallment = $templates['ask_installment_id'] ?? 'รบกวนแจ้งเลขสัญญา/ชื่อ-เบอร์/ยอดที่โอน/เวลาโอน เพิ่มนิดนึงค่ะ';
        $askSlipMissing = $templates['ask_slip_missing'] ?? 'รบกวนแจ้งยอด/เวลา/ชื่อผู้โอนเพิ่มนิดนึงค่ะ';

        // Endpoint resolver (supports both old & new keys)
        $ep = function (array $keys) use ($backendCfg) {
            $endpoints = $backendCfg['endpoints'] ?? [];
            foreach ($keys as $k) {
                if (!empty($endpoints[$k]) && is_string($endpoints[$k]))
                    return $endpoints[$k];
            }
            return null;
        };

        // Render helpers
        $renderProductReply = function (array $products) use ($templates) {
            $products = array_values($products);
            if (count($products) <= 0)
                return $templates['product_not_found'] ?? 'ตอนนี้ยังไม่เจอในระบบค่ะ 😅';

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
                if ($i > 5)
                    break;
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
            $code = trim((string) ($slots['product_code'] ?? ''));
            if ($code === '') {
                return ['handled' => false, 'reply_text' => $askProductCode, 'reason' => 'missing_product_code', 'slots' => $slots];
            }

            // Always use internal ProductSearchService (mock data for now)
            // When Data team provides real API, change this to call backend
            try {
                $products = ProductSearchService::searchByProductCode($code);

                Logger::info('[ROUTER_V1] ProductSearchService result', [
                    'code' => $code,
                    'found' => count($products),
                    'trace_id' => $context['trace_id'] ?? null
                ]);

                if (empty($products)) {
                    return [
                        'handled' => true,
                        'reply_text' => "❌ ไม่พบสินค้ารหัส {$code} ค่ะ\n\nลองค้นหาด้วยรหัสอื่น หรือพิมพ์ชื่อสินค้าได้เลยค่ะ 😊",
                        'reason' => 'product_not_found',
                        'slots' => $slots
                    ];
                }

                $replyText = ProductSearchService::formatMultipleForChat($products, 3);

                // Get thumbnail from first product for image message
                $imageUrl = null;
                if (!empty($products[0]['thumbnail_url'])) {
                    $imageUrl = $products[0]['thumbnail_url'];
                }

                // ✅ Create case for product inquiry
                try {
                    $botProfileCfg = json_decode($context['bot_profile']['config'] ?? '{}', true) ?: [];
                    $caseEngine = new CaseEngine($botProfileCfg, $context);
                    $caseSlots = [
                        'product_code' => $code,
                        'product_name' => $products[0]['title'] ?? $products[0]['name'] ?? null,
                        'product_price' => $products[0]['price'] ?? null,
                        'product_ref_id' => $products[0]['ref_id'] ?? null,
                        'product_image_url' => $products[0]['thumbnail_url'] ?? null,
                    ];
                    $case = $caseEngine->getOrCreateCase(CaseEngine::CASE_PRODUCT_INQUIRY, $caseSlots);
                    Logger::info('[ROUTER_V1] Created/updated case for product inquiry', [
                        'case_id' => $case['id'] ?? null,
                        'case_no' => $case['case_no'] ?? null,
                        'product_code' => $code
                    ]);
                } catch (Exception $caseErr) {
                    Logger::error('[ROUTER_V1] Failed to create case: ' . $caseErr->getMessage());
                }

                // Cache candidates for selection and store key product fields
                $slots = $this->attachProductCandidatesToSlots($slots, $products, $code, $config['session_policy'] ?? []);
                $slots = $this->mergeSlots($slots, [
                    'product_ref_id' => $products[0]['ref_id'] ?? null,
                    'product_code' => $code,
                    'product_name' => $products[0]['title'] ?? ($products[0]['name'] ?? null),
                    'product_price' => $products[0]['price'] ?? null,
                    'product_image_url' => $products[0]['thumbnail_url'] ?? null,
                ]);

                $actionsOut = $this->buildImageActionsFromProducts($products, 3);

                // ✅ Add Quick Reply buttons for guided checkout (LINE/Facebook)
                if (count($products) === 1) {
                    $actionsOut[] = [
                        'type' => 'quick_reply',
                        'items' => [
                            ['label' => '🛍️ สนใจ', 'text' => 'สนใจ'],
                            ['label' => '💳 ดูตารางผ่อน', 'text' => 'ผ่อน'],
                            ['label' => '💬 สอบถามเพิ่ม', 'text' => 'สอบถาม'],
                        ]
                    ];
                }

                return [
                    'handled' => true,
                    'reply_text' => $replyText,
                    'actions' => $actionsOut,
                    'reason' => 'internal_product_lookup_by_code',
                    'meta' => ['products' => $products],
                    'slots' => $slots
                ];
            } catch (Exception $e) {
                Logger::error('[ROUTER_V1] ProductSearchService error', [
                    'code' => $code,
                    'error' => $e->getMessage(),
                    'trace_id' => $context['trace_id'] ?? null
                ]);

                return [
                    'handled' => false,
                    'reply_text' => $fallback,
                    'reason' => 'product_search_error',
                    'slots' => $slots
                ];
            }
        }

        // -------------------------
        // Intent: product_availability / price_inquiry
        // -------------------------
        if ($intent === 'product_availability' || $intent === 'price_inquiry') {
            // Initialize fallback for error cases
            $fallback = $templates['product_search_error'] ?? $templates['fallback'] ?? 'ขออภัยค่ะ ระบบค้นหาสินค้าขัดข้อง กรุณาลองใหม่อีกครั้งค่ะ 🙏';

            // Determine search keyword - prefer product_code (user selected specific item)
            $productCode = trim((string) ($slots['product_code'] ?? ''));
            $productType = trim((string) ($slots['product_type'] ?? ''));
            $productName = trim((string) ($slots['product_name'] ?? ''));
            $incomingText = trim((string) ($context['message']['text'] ?? ''));
            $priceSlot = trim((string) ($slots['price'] ?? ''));

            $searchKeyword = '';
            $searchByCode = false;

            // HIGHEST PRIORITY: If LLM extracted product_code AND it appears in current message
            // This handles cases like "เอาตัว GLD-NCK-002" where user explicitly mentions code
            // ⚠️ IMPORTANT: Only use product_code if it appears in current text to avoid session caching issue
            if ($productCode !== '' && preg_match('/^[A-Z]{2,4}-[A-Z]{2,4}-\d{3}$/i', $productCode)) {
                // Check if product_code appears in current text - if not, it's from session cache
                if (mb_stripos($incomingText, $productCode) !== false) {
                    $searchKeyword = $productCode;
                    $searchByCode = true;
                    Logger::info('[ROUTER_V1] Product search - using product_code from text', [
                        'product_code' => $productCode,
                        'incoming_text' => $incomingText
                    ]);
                } else {
                    // Product code is from session cache, ignore it for new search
                    Logger::info('[ROUTER_V1] Product search - ignoring cached product_code', [
                        'cached_code' => $productCode,
                        'incoming_text' => $incomingText
                    ]);
                }
            }

            // If no product_code, check if user mentions price from previous list
            // This helps match "เอาตัวราคา 34000" to a specific product
            if ($searchKeyword === '' && $priceSlot !== '') {
                // Check if we have this price in slot history - defer to product_name search
                Logger::info('[ROUTER_V1] Product search - user mentioned price', [
                    'price' => $priceSlot,
                    'product_name' => $productName
                ]);
            }

            // If incoming text mentions product types (สร้อย, แหวน, กำไล, etc.), use that for search
            // LLM handles brand extraction via slots - no hardcode here
            $categoryKeywords = ['สร้อย', 'แหวน', 'กำไล', 'ต่างหู', 'จี้', 'เพชร', 'ทอง', 'สายสร้อย', 'พระ', 'นาฬิกา', 'กระเป๋า'];

            // Check category keywords
            if ($searchKeyword === '') {
                foreach ($categoryKeywords as $cat) {
                    if (mb_strpos($incomingText, $cat) !== false) {
                        $searchKeyword = $cat;
                        Logger::info('[ROUTER_V1] Product search - using category from text', [
                            'category' => $cat,
                            'incoming_text' => $incomingText
                        ]);
                        break;
                    }
                }
            }

            // Check category keywords only if no brand/product_code
            if ($searchKeyword === '') {
                foreach ($categoryKeywords as $cat) {
                    if (mb_strpos($incomingText, $cat) !== false) {
                        $searchKeyword = $cat;
                        Logger::info('[ROUTER_V1] Product search - using category from text', [
                            'category' => $cat,
                            'incoming_text' => $incomingText
                        ]);
                        break;
                    }
                }
            }

            // Second priority: Use product_type if available and different from previous product_name
            if ($searchKeyword === '' && $productType !== '' && $productType !== $productName) {
                $searchKeyword = $productType;
                Logger::info('[ROUTER_V1] Product search - using product_type', [
                    'product_type' => $productType
                ]);
            }

            // Third priority: Use product_name from slots (may come from previous context)
            // ✅ FIX: จะใช้ product_name เดิมค้นหา ก็ต่อเมื่อในประโยคใหม่มีคำค้นหาเท่านั้น
            // ไม่ใช่เอะอะก็ค้นของเดิม (ป้องกัน Search Loop)
            $reSearchTriggers = ['มี', 'หา', 'ดู', 'ราคา', 'เท่าไหร่', 'รายละเอียด', 'สภาพ', 'เช็ค', 'ตรวจ'];
            $shouldReSearch = false;
            foreach ($reSearchTriggers as $trig) {
                if (mb_stripos($incomingText, $trig) !== false) {
                    $shouldReSearch = true;
                    break;
                }
            }

            if ($searchKeyword === '' && $productName !== '' && $shouldReSearch) {
                $searchKeyword = $productName;
                Logger::info('[ROUTER_V1] Product search - using product_name from slots (re-search triggered)', [
                    'product_name' => $productName,
                    'trigger_found' => true
                ]);
            }

            // Fourth priority: Check last_product_query from session (context from previous message)
            if ($searchKeyword === '' && !empty($lastSlots['last_product_query'])) {
                $searchKeyword = $lastSlots['last_product_query'];
                Logger::info('[ROUTER_V1] Product search - using last_product_query from context', [
                    'last_product_query' => $searchKeyword
                ]);
            }

            // =========================================================
            // ✅ SMART FALLBACK: Don't use generic keywords as search query
            // Keywords like "สนใจ", "ราคา", "ดู" alone are NOT product names
            // =========================================================
            $genericKeywords = [
                'สนใจ',
                'ดู',
                'ราคา',
                'ราคาเท่าไหร่',
                'ราคาเท่าไร',
                'มีไหม',
                'มีมั้ย',
                'เท่าไหร่',
                'เท่าไร',
                'กี่บาท',
                'ขาย',
                'ซื้อ',
                'เอา',
                'รับ',
                'ตกลง',
                'ใช่',
                'โอเค',
                'ok',
                'yes',
                'อยากได้',
                'อยาก',
                'ต้องการ',
                'หา'
            ];
            $textLowerTrimmed = mb_strtolower(trim($incomingText), 'UTF-8');
            $isGenericOnly = in_array($textLowerTrimmed, $genericKeywords, true)
                || preg_match('/^(สนใจ|ดู|ราคา|มี)$/u', $textLowerTrimmed);

            // Fallback: Use incoming text as search query (only if it's a real product name)
            if ($searchKeyword === '' && !$isGenericOnly) {
                // Only use if text looks like a product name (has length > 2 and is not just a greeting)
                if (mb_strlen($incomingText, 'UTF-8') > 2 && !preg_match('/^(สวัสดี|hello|hi|ดีค่ะ|ดีครับ|หวัดดี)$/iu', $incomingText)) {
                    $searchKeyword = $incomingText;
                    Logger::info('[ROUTER_V1] Product search - using incoming text as fallback', [
                        'incoming_text' => $incomingText
                    ]);
                }
            }

            // If still no search keyword, ask user to specify
            if ($searchKeyword === '' || $isGenericOnly) {
                $tpl = $templates['ask_product_name'] ?? 'สนใจสินค้าอะไรคะ 😊 ช่วยบอกชื่อรุ่น/รหัส/แบรนด์ได้เลยค่ะ';
                Logger::info('[ROUTER_V1] Product search - no valid keyword, asking for product name', [
                    'incoming_text' => $incomingText,
                    'is_generic_only' => $isGenericOnly
                ]);
                return ['handled' => true, 'reply_text' => $tpl, 'reason' => 'ask_product_name', 'slots' => $slots];
            }

            $endpoint = $ep(['product_search']);
            if (!$endpoint)
                return ['handled' => false, 'reason' => 'missing_endpoint_product_search'];

            // Build payload - use product_code if searching by code, otherwise use keyword
            $payload = [
                'channel_id' => $channelId,
                'external_user_id' => $externalUserId,
            ];

            if ($searchByCode) {
                // Search by product code - should return exact match
                $payload['product_code'] = $searchKeyword;
                $payload['keyword'] = $searchKeyword;
            } else {
                // Search by keyword
                $payload['q'] = $searchKeyword;
                $payload['keyword'] = $searchKeyword;
                $payload['product_name'] = $searchKeyword;
            }

            Logger::info('[ROUTER_V1] Product search payload', [
                'search_keyword' => $searchKeyword,
                'search_by_code' => $searchByCode,
                'product_code_slot' => $productCode,
                'original_product_name' => $productName,
                'original_product_type' => $productType,
                'incoming_text' => $incomingText
            ]);

            // Extract attributes from slots (color, brand, etc.)
            $attributes = [];
            if (!empty($slots['color'])) {
                // Map Thai colors to English
                $colorMap = [
                    'ดำ' => 'black',
                    'สีดำ' => 'black',
                    'น้ำเงิน' => 'blue',
                    'สีน้ำเงิน' => 'blue',
                    'เงิน' => 'silver',
                    'สีเงิน' => 'silver',
                    'ทอง' => 'gold',
                    'สีทอง' => 'gold',
                    'ขาว' => 'white',
                    'สีขาว' => 'white',
                    'เขียว' => 'green',
                    'สีเขียว' => 'green',
                    'แดง' => 'red',
                    'สีแดง' => 'red',
                    'ชมพู' => 'pink',
                    'สีชมพู' => 'pink',
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
                $budget = (int) preg_replace('/[^0-9]/', '', $slots['budget']);
                if ($budget > 0) {
                    $payload['max_price'] = $budget;
                }
            }

            $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
            if (!$resp['ok']) {
                Logger::warning('[ROUTER_V1] Product search backend error', [
                    'search_keyword' => $searchKeyword,
                    'resp' => $resp
                ]);
                return ['handled' => false, 'reply_text' => $fallback, 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
            }

            // API returns {"data": [...products...]} directly, or {"data": {"products": [...]}}
            $respData = $resp['data'] ?? [];
            if (is_array($respData) && isset($respData[0])) {
                // Direct array of products
                $products = $respData;
            } else {
                // Wrapped in products/items/candidates key
                $products = $respData['products'] ?? ($respData['items'] ?? ($respData['candidates'] ?? []));
            }
            if (!is_array($products))
                $products = [];

            Logger::info('[ROUTER_V1] Product search result', [
                'search_keyword' => $searchKeyword,
                'product_count' => count($products),
                'first_product' => $products[0]['title'] ?? null
            ]);

            $rendered = $this->renderProductsFromBackend($products, $templates);

            // Cache candidates for selection (e.g., 'เอาอันที่ 2')
            $slots = $this->attachProductCandidatesToSlots($slots, $products, $searchKeyword, $config['session_policy'] ?? []);

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
            if (!$endpoint)
                return ['handled' => false, 'reason' => 'missing_endpoint_payment_verify'];

            $amount = trim((string) ($slots['amount'] ?? ''));
            $time = trim((string) ($slots['time'] ?? ''));
            $sender = trim((string) ($slots['sender_name'] ?? ''));
            $paymentRef = trim((string) ($slots['payment_ref'] ?? ''));
            $bank = trim((string) ($slots['bank'] ?? ''));

            $slipImageUrl = $extra['slip_image_url'] ?? null;
            if (!$slipImageUrl)
                $slipImageUrl = $context['message']['attachments'][0]['url'] ?? null;

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
        // -------------------------
        // Intent: interest_rate_inquiry
        // -------------------------
        if ($intent === 'interest_rate_inquiry') {
            $mode = trim((string) ($slots['interest_mode'] ?? ''));
            $rules = $config['business_rules'] ?? [];

            if ($mode === 'pawn') {
                $rate = $rules['pawn_interest_rate_percent_default'] ?? $rules['pawn_interest_rate_percent'] ?? 2;
                $tpl = $templates['deposit_interest_rate_info']
                    ?? "ดอกเบี้ยการรับฝากอยู่ที่ {{interest_rate}}% ต่อเดือนค่ะ 😊\n" .
                    "📌 เงื่อนไข:\n• รับฝากเฉพาะสินค้าที่ซื้อจากทางร้านเท่านั้น\n• ต้องมีใบรับประกันตัวจริงมาแสดง\n• ชำระดอกเบี้ยทุก 30 วัน";
                $reply = $this->renderTemplate($tpl, [
                    'interest_rate' => ($rate === null || $rate === '') ? '2' : (string) $rate,
                ]);
                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'interest_rate_info_deposit', 'slots' => $slots];
            }

            // Default: installment
            $rate = $rules['installment_interest_rate_percent_default'] ?? $rules['installment_interest_rate_percent'] ?? null;
            $tpl = $templates['installment_interest_rate_info']
                ?? "ดอกเบี้ย/เงื่อนไขการผ่อนจะขึ้นกับโปรฯ และสัญญาของแต่ละรายการค่ะ 😊
" .
                "ถ้าต้องการเช็คให้ตรงที่สุด พิมพ์ ‘เลขสัญญา/เบอร์ลูกค้า’ หรือส่งรูปสัญญา/ใบแจ้งหนี้ได้เลยค่ะ
" .
                "(ค่าเริ่มต้นที่ตั้งไว้: {{interest_rate}}%/เดือน)";
            $reply = $this->renderTemplate($tpl, [
                'interest_rate' => ($rate === null || $rate === '') ? '-' : (string) $rate,
            ]);
            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'interest_rate_info_installment', 'slots' => $slots];
        }

        // -------------------------
        // Intent: confirmation_response (ลูกค้าถามยืนยันความเข้าใจ - ใช่ไหม, ถูกต้องไหม)
        // -------------------------
        if ($intent === 'confirmation_response') {
            $textLower = mb_strtolower($text, 'UTF-8');

            // Context-aware confirmation based on what they're asking about
            if (preg_match('/ผ่อนครบ.*ถึง.*ได้ของ|ผ่อนครบ.*ได้ของ|ได้ของ.*หลังผ่อนครบ/u', $textLower)) {
                // Confirming installment completion = get product
                $reply = $templates['confirm_installment_receive']
                    ?? "ใช่ค่ะ ถูกต้องเลย ✅\n\nผ่อนครบ 3 งวด รับของได้ทันทีเลยค่ะ 🎁\nสนใจให้คำนวณยอดงวดแรกไหมคะ? 😊";
            } elseif (preg_match('/ไม่ต้อง.*เอกสาร|ไม่ใช้.*เอกสาร/u', $textLower)) {
                // Confirming no documents needed
                $reply = $templates['confirm_no_documents']
                    ?? "ใช่ค่ะ ถูกต้อง ✅\n\nไม่ต้องใช้เอกสารใดๆ เลยค่ะ ง่ายมากๆ 😊";
            } elseif (preg_match('/ผ่อน.*3.*งวด|3.*งวด.*ผ่อน/u', $textLower)) {
                // Confirming 3 installments
                $reply = $templates['confirm_3_installments']
                    ?? "ใช่ค่ะ ✅ ผ่อน 3 งวด ภายใน 60 วัน\nค่าดำเนินการ 3% จ่ายพร้อมงวดแรกค่ะ 😊";
            } else {
                // Generic confirmation
                $reply = $templates['confirm_understanding']
                    ?? "ใช่ค่ะ ถูกต้องเลย ✅\nมีอะไรให้ช่วยเพิ่มเติมไหมคะ? 😊";
            }

            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'confirmation_answered', 'slots' => $slots];
        }

        // =========================================================
        // ✅ NEW INTENT HANDLERS - For dynamic conversation
        // =========================================================

        // -------------------------
        // Intent: price_negotiation (ลดราคาได้ไหม, ต่อราคาได้ไหม)
        // -------------------------
        if ($intent === 'price_negotiation') {
            // Note: $slots already merged with lastSlots from caller
            $productName = trim((string) ($slots['product_name'] ?? ''));
            $productPrice = (float) ($slots['product_price'] ?? 0);

            if ($productPrice > 0 && $productName !== '') {
                $reply = $templates['price_negotiation_with_product']
                    ?? "ราคา " . number_format($productPrice) . " บาท สำหรับ {$productName} เป็นราคาพิเศษแล้วนะคะ 🙏||SPLIT||แต่ถ้าลูกค้าสนใจหลายชิ้น หรือซื้อพร้อมบริการอื่น ลองปรึกษาได้ค่ะ เดี๋ยวช่วยดูให้ 😊";
            } else {
                $reply = $templates['price_negotiation'] 
                    ?? "ราคาที่แจ้งเป็นราคาพิเศษแล้วค่ะ 🙏||SPLIT||ถ้าลูกค้าสนใจซื้อหลายชิ้น สามารถปรึกษาได้นะคะ เดี๋ยวลองดูให้ค่ะ 😊";
            }
            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'price_negotiation_answered', 'slots' => $slots];
        }

        // -------------------------
        // Intent: change_payment_method (เปลี่ยนวิธีชำระเงิน)
        // -------------------------
        if ($intent === 'change_payment_method') {
            // Note: $slots already merged with lastSlots from caller
            $productName = trim((string) ($slots['product_name'] ?? ''));
            $productCode = trim((string) ($slots['product_code'] ?? ''));
            $productPrice = (float) ($slots['product_price'] ?? 0);
            $newPaymentMethod = trim((string) ($slots['new_payment_method'] ?? ''));

            // ✅ If user explicitly asked for deposit/booking
            if ($newPaymentMethod === 'deposit' && $productPrice > 0) {
                $depositPolicy = $config['policies']['deposit'] ?? [];
                $depositPercent = (float) ($depositPolicy['percent'] ?? 10);
                $holdDays = (int) ($depositPolicy['hold_days'] ?? 14);
                $depositAmount = round($productPrice * ($depositPercent / 100));

                $reply = "ได้ค่ะ เปลี่ยนเป็นมัดจำได้เลยค่ะ 🎯\n\n";
                $reply .= "📦 สินค้า: " . ($productName ?: $productCode ?: 'รายการที่เลือก') . "\n";
                $reply .= "💰 ราคาเต็ม: " . number_format($productPrice) . " บาท\n";
                $reply .= "🎯 ยอดมัดจำ: " . number_format($depositAmount) . " บาท ({$depositPercent}%)\n";
                $reply .= "📅 เก็บสินค้าให้: {$holdDays} วัน\n\n";
                $reply .= "สะดวกรับสินค้าช่องทางไหนคะ?\n";
                $reply .= "🏢 พิมพ์ 1 = รับที่ร้าน (สีลม 5)\n";
                $reply .= "📦 พิมพ์ 2 = จัดส่ง EMS (+150 บาท)";

                // Update slots for next step
                $slots['checkout_step'] = 'ask_delivery';
                $slots['payment_method'] = 'deposit';
                $slots['deposit_amount'] = $depositAmount;

                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'change_to_deposit', 'slots' => $slots];
            }

            if ($productPrice > 0) {
                // คำนวณยอดผ่อน
                $feePercent = (float) ($config['policies']['installment']['service_fee_percent'] ?? 3);
                $fee = round($productPrice * ($feePercent / 100));
                $depositAmount = round($productPrice * 0.10);

                $reply = "ได้ค่ะ 😊 เปลี่ยนได้เลยค่ะ\n\n";
                $reply .= "📦 สินค้า: " . ($productName ?: 'รายการที่เลือก') . "\n";
                $reply .= "💰 ราคา: " . number_format($productPrice) . " บาท\n\n";
                $reply .= "เลือกวิธีชำระได้เลยค่ะ:\n";
                $reply .= "1️⃣ โอนเต็มจำนวน " . number_format($productPrice) . " บาท\n";
                $reply .= "2️⃣ ผ่อน 3 งวด (+" . number_format($fee) . " ค่าธรรมเนียม)\n";
                $reply .= "3️⃣ มัดจำ " . number_format($depositAmount) . " บาท (10%)\n";
                
                // Update checkout step
                $slots['checkout_step'] = 'payment_selection';
            } else {
                $reply = $templates['change_payment_method'] 
                    ?? "ได้ค่ะ 😊 ลูกค้าต้องการเปลี่ยนเป็นวิธีไหนดีคะ?||SPLIT||1️⃣ โอนเต็มจำนวน||SPLIT||2️⃣ ผ่อน 3 งวด (+3%)||SPLIT||3️⃣ มัดจำ 10%";
            }
            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'change_payment_answered', 'slots' => $slots];
        }

        // -------------------------
        // Intent: consignment (ฝากขาย)
        // -------------------------
        if ($intent === 'consignment') {
            $reply = $templates['consignment'] 
                ?? "ขอบคุณที่สนใจค่ะ 💎||SPLIT||ทางร้านรับฝากขายเฉพาะสินค้าที่ซื้อจากร้าน ฮ.เฮง เฮง ค่ะ||SPLIT||รบกวนถ่ายรูปสินค้าพร้อมใบรับประกันส่งมาให้ประเมินได้เลยนะคะ 📸";
            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'consignment_answered', 'slots' => $slots];
        }

        // -------------------------
        // Intent: pawn_inquiry (จำนำได้ไหม, รับจำนำไหม)
        // -------------------------
        if ($intent === 'pawn_inquiry') {
            $pawnPolicy = $config['policies']['pawn'] ?? [];
            $interestRate = (float) ($pawnPolicy['interest_rate_monthly'] ?? 2);

            $reply = $templates['pawn_info'] 
                ?? "บริการรับฝาก/จำนำค่ะ 💎||SPLIT||⚠️ รับเฉพาะสินค้าที่ซื้อจากร้าน ฮ.เฮง เฮง เท่านั้นนะคะ||SPLIT||• ดอกเบี้ย {$interestRate}%/เดือน||SPLIT||ถ่ายรูปสินค้า+ใบรับประกันมาให้ประเมินได้เลยค่ะ 📸";
            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'pawn_inquiry_answered', 'slots' => $slots];
        }

        // =========================================================
        // END NEW INTENT HANDLERS
        // =========================================================

        // -------------------------
        // Intent: installment_inquiry (ถามเงื่อนไขผ่อน/ออม - คำนวณยอด)
        // -------------------------
        if ($intent === 'installment_inquiry') {
            $policies = $config['policies'] ?? [];
            $installmentPolicy = $policies['installment'] ?? [];
            $periods = (int) ($installmentPolicy['periods'] ?? 3);
            $feePercent = (float) ($installmentPolicy['service_fee_percent'] ?? 3);

            // ✅ FIX: ดึง product_price จาก slots ก่อน ถ้าไม่มีให้ใช้จาก lastSlots (session context)
            $productPrice = (float) ($slots['product_price'] ?? ($lastSlots['product_price'] ?? 0));
            $productName = trim((string) ($slots['product_name'] ?? ($lastSlots['product_name'] ?? '')));

            if ($productPrice > 0) {
                // Calculate following spec:
                // - Service fee: 3% TOTAL (not per month)
                // - Period 1 & 2: equal (rounded up to nearest 500)
                // - Period 3: remaining balance
                $fee = round($productPrice * ($feePercent / 100));

                $baseAmount = $productPrice / 3;
                $p1 = ceil($baseAmount / 500) * 500;
                $p2 = $p1;
                $p3 = $productPrice - $p1 - $p2;

                if ($p3 < 0) {
                    $p1 = ceil($productPrice / 3);
                    $p2 = $p1;
                    $p3 = $productPrice - $p1 - $p2;
                    if ($p3 < 0) {
                        $p2 += $p3;
                        $p3 = 0;
                    }
                }

                $firstPeriod = $p1 + $fee;

                $tpl = $templates['installment_calculate']
                    ?? "คำนวณให้แล้วค่ะ 💎\n• ราคาสินค้า: {{price}} บาท\n• ค่าดำเนินการ {$feePercent}%: {{fee}} บาท\n• งวดที่ 1: {{p1}} + {{fee}} = {{period1}} บาท\n• งวดที่ 2: {{period2}} บาท\n• งวดที่ 3: {{period3}} บาท\nสนใจทำรายการเลยไหมคะ? 😊";
                $reply = $this->renderTemplate($tpl, [
                    'name' => $productName ?: 'สินค้า',
                    'price' => number_format($productPrice, 0),
                    'fee' => number_format($fee, 0),
                    'p1' => number_format($p1, 0),
                    'period1' => number_format($firstPeriod, 0),
                    'period2' => number_format($p2, 0),
                    'period3' => number_format($p3, 0),
                ]);

                Logger::info('[ROUTER_V1] Installment calculated', [
                    'product_price' => $productPrice,
                    'fee' => $fee,
                    'period1' => $firstPeriod,
                    'period2' => $p2,
                    'period3' => $p3
                ]);
            } else {
                // ไม่มีราคา แสดงเงื่อนไขทั่วไป
                $tpl = $templates['installment_info']
                    ?? "ผ่อน/ออม ได้ค่ะ ✅\n• ผ่อน {$periods} งวด (60 วัน) ไม่ต้องใช้เอกสาร\n• มีค่าดำเนินการ {$feePercent}% (ชำระพร้อมงวดแรก)\n• ผ่อนครบรับของทันทีค่ะ\nสนใจสินค้าตัวไหนคะ? 😊";
                $reply = $tpl;
            }

            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'installment_inquiry_answered', 'slots' => $slots];
        }

        // -------------------------
        // Intent: purchase_intent (ลูกค้าต้องการซื้อ - Guided Checkout Flow)
        // -------------------------
        if ($intent === 'purchase_intent' || (in_array($intent, ['handoff_to_admin']) && !empty($slots['action']) && $slots['action'] === 'buy')) {
            $productName = trim((string) ($slots['product_name'] ?? ($lastSlots['product_name'] ?? '')));
            $productCode = trim((string) ($slots['product_code'] ?? ($lastSlots['product_code'] ?? '')));
            $productPrice = (float) ($slots['product_price'] ?? ($lastSlots['product_price'] ?? 0));

            // =========================================================
            // ✅ NEW: If we have product_code but no price, search for product first
            // =========================================================
            if ($productCode !== '' && $productPrice <= 0) {
                Logger::info('[ROUTER_V1] Purchase intent with code but no price - searching product', [
                    'product_code' => $productCode
                ]);
                
                // Try to search product by code
                $productResult = $this->searchProductByCode($productCode, $config, $context);
                
                if ($productResult && !empty($productResult['product'])) {
                    $foundProduct = $productResult['product'];
                    $productPrice = (float) ($foundProduct['sale_price'] ?? $foundProduct['price'] ?? 0);
                    $productName = $foundProduct['title'] ?? $foundProduct['name'] ?? $productName;
                    
                    // Update slots with found product
                    $slots['product_name'] = $productName;
                    $slots['product_code'] = $productCode;
                    $slots['product_price'] = $productPrice;
                    $slots['product_ref_id'] = $foundProduct['ref_id'] ?? null;
                    $slots['product_image_url'] = $foundProduct['thumbnail_url'] ?? $foundProduct['image_url'] ?? null;
                    
                    Logger::info('[ROUTER_V1] Product found by code', [
                        'product_code' => $productCode,
                        'product_name' => $productName,
                        'product_price' => $productPrice
                    ]);
                } else {
                    // Product not found by code
                    Logger::info('[ROUTER_V1] Product not found by code', [
                        'product_code' => $productCode
                    ]);
                    
                    $reply = "ไม่พบสินค้ารหัส {$productCode} ค่ะ 🔍\n\nลองเช็ครหัสอีกครั้งนะคะ หรือพิมพ์ชื่อสินค้าที่สนใจได้เลยค่ะ 😊";
                    return ['handled' => true, 'reply_text' => $reply, 'reason' => 'product_not_found', 'slots' => $slots];
                }
            }

            // ถ้ามีข้อมูลสินค้าครบ แต่ยังไม่ได้เลือกวิธีชำระ → ถามวิธีชำระ
            $paymentMethod = trim((string) ($slots['payment_method'] ?? ($lastSlots['payment_method'] ?? '')));
            $deliveryMethod = trim((string) ($slots['delivery_method'] ?? ($lastSlots['delivery_method'] ?? '')));

            // =========================================================
            // ✅ SMART CHECKOUT: Skip payment question if already discussed installment
            // If customer already saw installment calculation, they're choosing installment
            // =========================================================
            $installmentCalculated = !empty($lastSlots['installment_calculated']);
            if ($paymentMethod === '' && $installmentCalculated) {
                $paymentMethod = 'installment';
                $slots['payment_method'] = 'installment';
                Logger::info('[SMART_CHECKOUT] Auto-set payment_method=installment from previous calculation');
            }

            if ($productPrice > 0 && $paymentMethod === '') {
                // Step 1: ถามวิธีชำระเงิน
                $tpl = $templates['confirm_buy_ask_payment']
                    ?? "รับทราบค่ะ ✅\nสินค้า: {{name}}\nราคา: {{price}} บาท\n\nชำระแบบไหนดีคะ?\n1️⃣ โอนเต็ม\n2️⃣ ผ่อน 3 งวด (+3%)\n3️⃣ มัดจำ 10%";
                $reply = $this->renderTemplate($tpl, [
                    'name' => $productName ?: 'สินค้า',
                    'code' => $productCode,
                    'price' => number_format($productPrice, 0),
                ]);

                // Update session state to track checkout step
                $slots['checkout_step'] = 'ask_payment';
                $this->updateSessionState((int) $sessionId, 'checkout_ask_payment', $slots);

                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'checkout_ask_payment', 'slots' => $slots];
            }

            // Step 2: ถ้าเลือกวิธีชำระแล้ว แต่ยังไม่ได้เลือกจัดส่ง → ถามจัดส่ง
            if ($paymentMethod !== '' && $deliveryMethod === '') {
                $policies = $config['policies'] ?? [];
                $installmentPolicy = $policies['installment'] ?? [];
                $depositPolicy = $policies['deposit'] ?? [];
                $feePercent = (float) ($installmentPolicy['service_fee_percent'] ?? 3);
                $depositPercent = (float) ($depositPolicy['percent'] ?? 10);

                if ($paymentMethod === 'installment' || $paymentMethod === '2') {
                    // คำนวณผ่อนตาม spec: 3% ตลอดสัญญา, งวด 1&2 เท่ากัน, งวด 3 ยอดคงเหลือ
                    $periods = (int) ($installmentPolicy['periods'] ?? 3);
                    $fee = round($productPrice * ($feePercent / 100));

                    $baseAmount = $productPrice / 3;
                    $p1 = ceil($baseAmount / 500) * 500;
                    $p2 = $p1;
                    $p3 = $productPrice - $p1 - $p2;
                    if ($p3 < 0) {
                        $p1 = ceil($productPrice / 3);
                        $p2 = $p1;
                        $p3 = $productPrice - $p1 - $p2;
                        if ($p3 < 0) {
                            $p2 += $p3;
                            $p3 = 0;
                        }
                    }

                    $firstPeriod = $p1 + $fee;

                    $tpl = $templates['installment_calculate_ask_delivery']
                        ?? "ผ่อน 3 งวดค่ะ (ภายใน 60 วัน)\n• งวดที่ 1: {{p1}} + {{fee}} = {{period1}} บาท\n• งวดที่ 2: {{period2}} บาท\n• งวดที่ 3: {{period3}} บาท\n\nรับสินค้าแบบไหนคะ?\n1️⃣ รับที่ร้าน\n2️⃣ จัดส่ง (+150)";
                    $reply = $this->renderTemplate($tpl, [
                        'name' => $productName ?: 'สินค้า',
                        'price' => number_format($productPrice, 0),
                        'fee' => number_format($fee, 0),
                        'p1' => number_format($p1, 0),
                        'period1' => number_format($firstPeriod, 0),
                        'period2' => number_format($p2, 0),
                        'period3' => number_format($p3, 0),
                    ]);
                    $slots['payment_method'] = 'installment';
                    $slots['first_payment'] = $firstPeriod;
                } elseif ($paymentMethod === 'deposit' || $paymentMethod === '3') {
                    // มัดจำ
                    $depositAmount = $productPrice * ($depositPercent / 100);
                    $tpl = $templates['deposit_ask_delivery']
                        ?? "ยอดมัดจำ 10% = {{deposit_amount}} บาท\n(เก็บให้ 14 วัน)\n\nรับสินค้าแบบไหนคะ?\n1️⃣ รับที่ร้าน\n2️⃣ จัดส่ง (+150)";
                    $reply = $this->renderTemplate($tpl, [
                        'name' => $productName ?: 'สินค้า',
                        'deposit_amount' => number_format($depositAmount, 0),
                    ]);
                    $slots['payment_method'] = 'deposit';
                    $slots['first_payment'] = $depositAmount;
                } else {
                    // โอนเต็ม
                    $tpl = $templates['full_payment_ask_delivery']
                        ?? "ยอดโอนเต็ม {{price}} บาท\n\nรับสินค้าแบบไหนคะ?\n1️⃣ รับที่ร้าน\n2️⃣ จัดส่ง (+150)";
                    $reply = $this->renderTemplate($tpl, [
                        'price' => number_format($productPrice, 0),
                    ]);
                    $slots['payment_method'] = 'full';
                    $slots['first_payment'] = $productPrice;
                }

                $slots['checkout_step'] = 'ask_delivery';
                $this->updateSessionState((int) $sessionId, 'checkout_ask_delivery', $slots);

                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'checkout_ask_delivery', 'slots' => $slots];
            }

            // Step 3: ถ้าเลือกทั้ง payment + delivery แล้ว → สรุปออเดอร์
            if ($paymentMethod !== '' && $deliveryMethod !== '') {
                $paymentLabel = match ($paymentMethod) {
                    'installment' => 'ผ่อน 3 งวด',
                    'deposit' => 'มัดจำ 10%',
                    default => 'โอนเต็มจำนวน',
                };

                $totalAmount = $slots['first_payment'] ?? $productPrice;

                if ($deliveryMethod === 'pickup' || $deliveryMethod === '1') {
                    $tpl = $templates['order_summary_pickup']
                        ?? "สรุปออเดอร์ค่ะ 📦\nสินค้า: {{name}}\nยอดชำระ: {{total_amount}} บาท ({{payment_type}})\nรับที่ร้าน สีลม 5\n\nสักครู่ค่ะ ระบบกำลังส่งเลขบัญชี 🙏";
                    $reply = $this->renderTemplate($tpl, [
                        'name' => $productName ?: 'สินค้า',
                        'total_amount' => number_format($totalAmount, 0),
                        'payment_type' => $paymentLabel,
                    ]);
                    $slots['delivery_method'] = 'pickup';
                } else {
                    $tpl = $templates['order_summary_delivery']
                        ?? "สรุปออเดอร์ค่ะ 📦\nสินค้า: {{name}}\nยอดชำระ: {{total_amount}} บาท ({{payment_type}})\nจัดส่ง EMS (+150 บาท)\n\nรบกวนแจ้ง ชื่อ-ที่อยู่-เบอร์ด้วยนะคะ 📝";
                    $reply = $this->renderTemplate($tpl, [
                        'name' => $productName ?: 'สินค้า',
                        'total_amount' => number_format($totalAmount, 0),
                        'payment_type' => $paymentLabel,
                    ]);
                    $slots['delivery_method'] = 'delivery';
                }

                $slots['checkout_step'] = '';  // ✅ Reset เพื่อให้ลูกค้าถามเพิ่มเติมได้
                $slots['order_status'] = 'pending_payment';  // เก็บสถานะว่าสั่งซื้อแล้ว
                $this->updateSessionState((int) $sessionId, 'completed', $slots);

                Logger::info('[ROUTER_V1] Checkout flow completed', [
                    'product_name' => $productName,
                    'product_price' => $productPrice,
                    'payment_method' => $paymentMethod,
                    'delivery_method' => $deliveryMethod,
                ]);

                // Return with handoff flag for admin to send bank account
                return [
                    'handled' => true,
                    'reply_text' => $reply,
                    'reason' => 'checkout_complete_handoff',
                    'slots' => $slots,
                    'handoff_to_admin' => true
                ];
            }

            // =========================================================
            // ✅ MISSING PRODUCT: ลูกค้าอยากซื้อแต่ยังไม่มีสินค้าใน Context
            // =========================================================
            if ($productPrice <= 0 && $productName === '' && $productCode === '') {
                $reply = $templates['purchase_missing_product']
                    ?? "คุณลูกค้าสนใจรับสินค้าชิ้นไหนดีคะ? 😊\n\nรบกวนส่ง **รหัสสินค้า** หรือ **รูปภาพ** ให้แอดมินได้เลยนะคะ เดี๋ยวแอดมินเช็คสต็อกและทำรายการให้ค่ะ 🙏";
                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'purchase_missing_product', 'slots' => $slots];
            }
        }

        // -------------------------
        // Intent: pawn_inquiry (ถามจำนำ - เน้นของร้านเท่านั้น)
        // -------------------------
        if ($intent === 'pawn_inquiry') {
            $policies = $config['policies'] ?? [];
            $pawnPolicy = $policies['pawn'] ?? [];
            $interestRate = $pawnPolicy['interest_rate'] ?? '2% ต่อเดือน';

            $tpl = $templates['pawn_info']
                ?? "ทางร้านรับฝาก/จำนำ เฉพาะสินค้าที่ซื้อจากร้าน ฮ.เฮง เฮง เท่านั้นนะคะ 💎\nดอกเบี้ย {$interestRate}ค่ะ\nถ้ามีใบรับประกัน ถ่ายรูปส่งมาประเมินได้เลยค่ะ";
            $reply = $tpl;

            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'pawn_inquiry_answered', 'slots' => $slots];
        }

        // -------------------------
        // Intent: repair_inquiry (ถามซ่อม - ขอรูป)
        // -------------------------
        if ($intent === 'repair_inquiry') {
            $tpl = $templates['repair_info']
                ?? "รับซ่อม/เซอร์วิสค่ะ 🔧\nรบกวนถ่ายรูปจุดที่เสียหายส่งมาให้ช่างประเมินเบื้องต้นได้เลยนะคะ";
            $reply = $tpl;

            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'repair_inquiry_answered', 'slots' => $slots];
        }

        // -------------------------
        // Intent: exchange_return_policy (ถามเปลี่ยน/คืน)
        // -------------------------
        if ($intent === 'exchange_return_policy') {
            // ✅ ใช้ policy handler - ดึงตัวเลขจาก config โดยตรง ไม่กิน LLM tokens
            $reply = $this->generateExchangeReturnPolicyReply($config);
            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'exchange_return_policy_answered', 'slots' => $slots];
        }

        // -------------------------
        // Intent: pawn_policy (ถามเรื่องจำนำ/ฝาก)
        // -------------------------
        if ($intent === 'pawn_policy') {
            // ✅ ใช้ policy handler
            $reply = $this->generatePawnPolicyReply($config);
            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'pawn_policy_answered', 'slots' => $slots];
        }

        // -------------------------
        // Intent: installment_policy (ถามเรื่องผ่อน/ออม ทั่วไป)
        // -------------------------
        if ($intent === 'installment_policy') {
            // ✅ ใช้ policy handler
            $reply = $this->generateInstallmentPolicyReply($config);
            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'installment_policy_answered', 'slots' => $slots];
        }

        // -------------------------
        // Intent: credit_card_policy (ถามเรื่องบัตรเครดิต)
        // -------------------------
        if ($intent === 'credit_card_policy') {
            // ✅ ใช้ policy handler
            $reply = $this->generateCreditCardPolicyReply($config);
            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'credit_card_policy_answered', 'slots' => $slots];
        }

        // -------------------------
        // Intent: buy_back (ลูกค้าต้องการขายคืน/เทิร์นสินค้า)
        // -------------------------
        if ($intent === 'buy_back' || $intent === 'sell_back') {
            $policies = $config['policies'] ?? [];
            $exchangePolicy = $policies['exchange_return'] ?? [];
            $returnDed = $exchangePolicy['return_deduction'] ?? '15%';
            $rolexDed = $exchangePolicy['rolex_deduction'] ?? '35%';

            $tpl = $templates['buy_back_info']
                ?? "ทางร้านรับซื้อคืน/เทิร์นสินค้าค่ะ 💎||SPLIT||• สินค้าทั่วไป: หัก {$returnDed} จากราคาซื้อ\n• Rolex: หัก {$rolexDed}||SPLIT||⚠️ ต้องมีใบรับประกันตัวจริง\nรบกวนส่งรูปสินค้า+ใบรับประกัน มาให้ประเมินได้เลยค่ะ 😊";
            $reply = $tpl;

            return ['handled' => true, 'reply_text' => $reply, 'reason' => 'buy_back_info_answered', 'slots' => $slots];
        }

        // Intent: installment_flow
        // -------------------------
        if ($intent === 'installment_flow') {
            $action = trim((string) ($slots['action_type'] ?? ''));
            if ($action === '') {
                $tpl = $templates['installment_choose_action']
                    ?? 'ต้องการ “ชำระงวด / ต่อดอก / ปิดยอด” แบบไหนคะ 😊 (พิมพ์คำที่ต้องการได้เลยค่ะ)';
                return ['handled' => false, 'reply_text' => $tpl, 'reason' => 'missing_action_type', 'slots' => $slots];
            }

            $installmentId = trim((string) ($slots['installment_id'] ?? ''));
            $phone = trim((string) ($slots['customer_phone'] ?? ''));

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
                $amount = trim((string) ($slots['amount'] ?? ''));
                $time = trim((string) ($slots['time'] ?? ''));
                $sender = trim((string) ($slots['sender_name'] ?? ''));

                $slipImageUrl = $extra['slip_image_url'] ?? null;
                if (!$slipImageUrl)
                    $slipImageUrl = $context['message']['attachments'][0]['url'] ?? null;

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
            if (!$endpoint)
                return ['handled' => false, 'reason' => 'missing_endpoint_order_status'];

            $orderId = trim((string) ($slots['order_id'] ?? ''));
            $phone = trim((string) ($slots['customer_phone'] ?? ''));
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
            if ($intent === 'savings_new')
                $actionType = 'new';
            elseif ($intent === 'savings_deposit')
                $actionType = 'deposit';
            elseif ($intent === 'savings_inquiry')
                $actionType = 'inquiry';

            // Get action_type from slots if provided
            if (!empty($slots['action_type'])) {
                $actionType = $slots['action_type'];
            }

            $askSavingsProduct = $templates['ask_savings_product'] ?? 'สนใจออมสินค้าตัวไหนคะ? 🎁 ส่งชื่อหรือรหัสสินค้ามาได้เลยค่ะ';
            $askSlipMissing = $templates['ask_slip_missing'] ?? 'รบกวนส่งรูปสลิปการโอนด้วยนะคะ 📷';

            // Handle savings_new
            if ($actionType === 'new') {
                $productRefId = trim((string) ($slots['product_ref_id'] ?? ''));
                $productName = trim((string) ($slots['product_name'] ?? ''));

                if ($productRefId === '' && $productName === '') {
                    return ['handled' => false, 'reply_text' => $askSavingsProduct, 'reason' => 'missing_product_for_savings', 'slots' => $slots];
                }

                $endpoint = $ep(['savings_create']);
                if (!$endpoint)
                    return ['handled' => false, 'reason' => 'missing_endpoint_savings_create'];

                $payload = [
                    'channel_id' => $channelId,
                    'external_user_id' => $externalUserId,
                    'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? 'unknown'),
                    'product_ref_id' => $productRefId ?: null,
                    'product_name' => $productName ?: 'Unknown Product',
                    'product_price' => (float) ($slots['product_price'] ?? ($slots['target_amount'] ?? 0))
                ];

                $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
                if (!$resp['ok']) {
                    return ['handled' => false, 'reply_text' => $templates['fallback'] ?? 'ขออภัยค่ะ มีปัญหาในการเปิดบัญชีออม', 'reason' => 'backend_error', 'meta' => $resp, 'slots' => $slots];
                }

                $data = $resp['data'] ?? [];
                $tpl = $templates['savings_created'] ?? "เปิดบัญชีออมให้แล้วค่ะ ✅\nสินค้า: {{product_name}}\nเป้าหมาย: {{target_amount}} บาท\nสินค้ากันไว้ให้แล้วนะคะ 🎯";
                $reply = $this->renderTemplate($tpl, [
                    'product_name' => $data['product_name'] ?? $productName,
                    'target_amount' => number_format((float) ($data['target_amount'] ?? 0)),
                    'account_no' => $data['account_no'] ?? ''
                ]);

                $slots['savings_id'] = $data['id'] ?? null;
                $slots['savings_account_no'] = $data['account_no'] ?? null;

                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_savings_created', 'meta' => $resp, 'slots' => $slots];
            }

            // Handle savings_deposit
            if ($actionType === 'deposit') {
                $savingsId = trim((string) ($slots['savings_id'] ?? ($slots['savings_account_id'] ?? '')));
                $slipImageUrl = $extra['slip_image_url'] ?? ($context['message']['attachments'][0]['url'] ?? null);

                // Try to find savings account if not provided
                if ($savingsId === '') {
                    $existingSavings = $this->db->queryOne(
                        "SELECT id FROM savings_accounts WHERE channel_id = ? AND external_user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1",
                        [$channelId, $externalUserId]
                    );
                    if ($existingSavings) {
                        $savingsId = (string) $existingSavings['id'];
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
                if (!$endpoint)
                    return ['handled' => false, 'reason' => 'missing_endpoint_savings_deposit'];

                // Replace {id} placeholder in endpoint
                $endpoint = str_replace('{id}', $savingsId, $endpoint);

                $payload = [
                    'amount' => (float) ($slots['amount'] ?? 0),
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
                $savingsId = trim((string) ($slots['savings_id'] ?? ($slots['savings_account_id'] ?? '')));

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
                        $current = (float) $sa['current_amount'];
                        $target = (float) $sa['target_amount'];
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
                        $current = (float) $sa['current_amount'];
                        $target = (float) $sa['target_amount'];
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
                        $current = (float) ($sa['current_amount'] ?? 0);
                        $target = (float) ($sa['target_amount'] ?? 0);
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
            if ($intent === 'deposit_new')
                $actionType = 'new';
            elseif ($intent === 'deposit_payment')
                $actionType = 'pay';
            elseif ($intent === 'deposit_inquiry')
                $actionType = 'inquiry';

            if (!empty($slots['action_type'])) {
                $actionType = $slots['action_type'];
            }

            $askProductForDeposit = $templates['ask_product_for_deposit'] ?? 'สนใจมัดจำสินค้าตัวไหนคะ? 🎁 ส่งชื่อหรือรหัสสินค้ามาได้เลยค่ะ';
            $askDepositSlip = $templates['ask_deposit_slip'] ?? 'รบกวนส่งรูปสลิปโอนมัดจำด้วยนะคะ 📷';

            // Handle deposit_new
            if ($actionType === 'new') {
                $productRefId = trim((string) ($slots['product_ref_id'] ?? ''));
                $productName = trim((string) ($slots['product_name'] ?? ''));

                if ($productRefId === '' && $productName === '') {
                    return ['handled' => false, 'reply_text' => $askProductForDeposit, 'reason' => 'missing_product_for_deposit', 'slots' => $slots];
                }

                $endpoint = $ep(['deposit_create']);
                if (!$endpoint)
                    return ['handled' => false, 'reason' => 'missing_endpoint_deposit_create'];

                $payload = [
                    'channel_id' => $channelId,
                    'external_user_id' => $externalUserId,
                    'platform' => $context['platform'] ?? ($context['channel']['platform'] ?? 'unknown'),
                    'product_ref_id' => $productRefId ?: null,
                    'product_name' => $productName ?: null,
                    'product_price' => (float) ($slots['product_price'] ?? 0),
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
                    'product_price' => number_format((float) ($data['product_price'] ?? 0)),
                    'deposit_amount' => number_format((float) ($data['deposit_amount'] ?? 0))
                ]);

                $slots['deposit_id'] = $data['id'] ?? null;
                $slots['deposit_no'] = $data['deposit_no'] ?? null;

                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'backend_deposit_created', 'meta' => $resp, 'slots' => $slots];
            }

            // Handle deposit_payment
            if ($actionType === 'pay') {
                $depositId = trim((string) ($slots['deposit_id'] ?? ''));
                $slipImageUrl = $extra['slip_image_url'] ?? ($context['message']['attachments'][0]['url'] ?? null);

                // Try to find deposit if not provided
                if ($depositId === '') {
                    $existingDeposit = $this->db->queryOne(
                        "SELECT id FROM deposits WHERE channel_id = ? AND external_user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1",
                        [$channelId, $externalUserId]
                    );
                    if ($existingDeposit) {
                        $depositId = (string) $existingDeposit['id'];
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
                if (!$endpoint)
                    return ['handled' => false, 'reason' => 'missing_endpoint_deposit_pay'];

                $endpoint = str_replace('{id}', $depositId, $endpoint);

                $payload = [
                    'slip_image_url' => $slipImageUrl,
                    'amount' => (float) ($slots['amount'] ?? 0),
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
                $depositId = trim((string) ($slots['deposit_id'] ?? ''));

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
                            'deposit_amount' => number_format((float) ($d['deposit_amount'] ?? 0)),
                            'status' => $d['status'] === 'pending' ? 'รอชำระ' : ($d['status'] === 'paid' ? 'ชำระแล้ว' : $d['status']),
                            'expires_at' => $d['expires_at'] ?? '-'
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'deposit_inquiry_single', 'slots' => $slots];
                    }

                    $lines = [];
                    foreach ($deposits as $i => $d) {
                        $statusTh = $d['status'] === 'pending' ? 'รอชำระ' : ($d['status'] === 'paid' ? 'ชำระแล้ว' : $d['status']);
                        $lines[] = ($i + 1) . ") {$d['product_name']}: " . number_format((float) ($d['deposit_amount'] ?? 0)) . " บ. ({$statusTh})";
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
                            'deposit_amount' => number_format((float) ($d['deposit_amount'] ?? 0)),
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
            if ($intent === 'pawn_new')
                $actionType = 'new';
            elseif ($intent === 'pawn_pay_interest')
                $actionType = 'pay_interest';
            elseif ($intent === 'pawn_redeem')
                $actionType = 'redeem';
            elseif ($intent === 'pawn_inquiry')
                $actionType = 'inquiry';

            if (!empty($slots['action_type'])) {
                $actionType = $slots['action_type'];
            }

            $askPawnItem = $templates['ask_pawn_item'] ?? 'ต้องการจำนำสินค้าชิ้นไหนคะ? 💎 บอกรายละเอียดได้เลยค่ะ';
            $askPawnInterestSlip = $templates['ask_pawn_interest_slip'] ?? 'รบกวนส่งรูปสลิปชำระดอกเบี้ยด้วยนะคะ 📷';

            // Handle pawn_new - ต้องส่งต่อแอดมิน (ต้องประเมินของ)
            if ($actionType === 'new') {
                $itemDesc = trim((string) ($slots['item_description'] ?? ($slots['product_name'] ?? '')));

                if ($itemDesc === '') {
                    return ['handled' => false, 'reply_text' => $askPawnItem, 'reason' => 'missing_pawn_item', 'slots' => $slots];
                }

                // Pawn ต้อง handoff to admin เพราะต้องประเมินราคา
                $tpl = $templates['pawn_handoff'] ?? "รับทราบค่ะ ต้องการจำนำ {{item_description}} 💎\nรบกวนส่งรูปสินค้ามาให้ตรวจสอบด้วยนะคะ\nเจ้าหน้าที่จะติดต่อกลับเพื่อประเมินราคาค่ะ ✨";
                $reply = $this->renderTemplate($tpl, [
                    'item_description' => $itemDesc
                ]);

                // Create case for admin follow-up
                // Get user_id from channel
                $channelUser = $this->db->queryOne("SELECT user_id FROM customer_channels WHERE id = ? LIMIT 1", [$channelId]);
                $caseUserId = $channelUser['user_id'] ?? null;
                
                $this->db->execute(
                    "INSERT INTO cases (channel_id, external_user_id, case_type, status, subject, description, priority, user_id) VALUES (?, ?, 'pawn', 'open', ?, ?, 'high', ?)",
                    [$channelId, $externalUserId, "ลูกค้าต้องการจำนำ: {$itemDesc}", $itemDesc, $caseUserId]
                );

                return ['handled' => true, 'reply_text' => $reply, 'reason' => 'pawn_handoff_to_admin', 'handoff' => true, 'slots' => $slots];
            }

            // Handle pawn_pay_interest (ต่อดอก)
            if ($actionType === 'pay_interest') {
                $pawnId = trim((string) ($slots['pawn_id'] ?? ''));
                $slipImageUrl = $extra['slip_image_url'] ?? ($context['message']['attachments'][0]['url'] ?? null);

                // Try to find active pawn if not provided
                if ($pawnId === '') {
                    $existingPawn = $this->db->queryOne(
                        "SELECT id FROM pawns WHERE channel_id = ? AND external_user_id = ? AND status = 'active' ORDER BY next_interest_due ASC LIMIT 1",
                        [$channelId, $externalUserId]
                    );
                    if ($existingPawn) {
                        $pawnId = (string) $existingPawn['id'];
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
                        $interestAmount = (float) $pawnData['principal_amount'] * ((float) $pawnData['interest_rate_percent'] / 100);
                        $tpl = $templates['pawn_interest_info'] ?? "ยอดดอกเบี้ยที่ต้องชำระ: {{interest_amount}} บาท\n\nโอนได้ที่:\nSCB: 1653014242 (บจก.เพชรวิบวับ)\nแล้วส่งสลิปมาได้เลยนะคะ 💳";
                        $reply = $this->renderTemplate($tpl, [
                            'interest_amount' => number_format($interestAmount)
                        ]);
                        return ['handled' => false, 'reply_text' => $reply, 'reason' => 'awaiting_pawn_slip', 'slots' => $slots];
                    }
                    return ['handled' => false, 'reply_text' => $askPawnInterestSlip, 'reason' => 'missing_pawn_slip', 'slots' => $slots];
                }

                $endpoint = $ep(['pawn_pay_interest']);
                if (!$endpoint)
                    return ['handled' => false, 'reason' => 'missing_endpoint_pawn_pay_interest'];

                $endpoint = str_replace('{id}', $pawnId, $endpoint);

                $payload = [
                    'slip_image_url' => $slipImageUrl,
                    'amount' => (float) ($slots['amount'] ?? 0),
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
                $pawnId = trim((string) ($slots['pawn_id'] ?? ''));

                if ($pawnId === '') {
                    $existingPawn = $this->db->queryOne(
                        "SELECT * FROM pawns WHERE channel_id = ? AND external_user_id = ? AND status = 'active' ORDER BY next_interest_due ASC LIMIT 1",
                        [$channelId, $externalUserId]
                    );
                    if ($existingPawn) {
                        $pawnId = (string) $existingPawn['id'];
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
                            'redemption_amount' => number_format((float) ($p['redemption_amount'] ?? 0)),
                            'principal' => number_format((float) ($p['principal_amount'] ?? 0)),
                            'outstanding_interest' => number_format((float) ($p['outstanding_interest'] ?? 0))
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'pawn_redeem_info', 'slots' => $slots];
                    }
                }

                return ['handled' => false, 'reply_text' => 'ไม่พบข้อมูลจำนำค่ะ กรุณาติดต่อเจ้าหน้าที่ 🙏', 'reason' => 'pawn_not_found', 'slots' => $slots];
            }

            // Handle pawn_inquiry
            if ($actionType === 'inquiry') {
                $pawnId = trim((string) ($slots['pawn_id'] ?? ''));

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
                            'principal' => number_format((float) ($p['principal_amount'] ?? 0)),
                            'interest_rate' => $p['interest_rate_percent'] ?? '2',
                            'next_due' => $p['next_interest_due'] ?? '-'
                        ]);
                        return ['handled' => true, 'reply_text' => $reply, 'reason' => 'pawn_inquiry_single', 'slots' => $slots];
                    }

                    $lines = [];
                    foreach ($pawns as $i => $p) {
                        $lines[] = ($i + 1) . ") {$p['item_description']}: " . number_format((float) ($p['principal_amount'] ?? 0)) . " บ. (ถึง: {$p['next_interest_due']})";
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
                            'principal' => number_format((float) ($p['principal_amount'] ?? 0)),
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
            if ($intent === 'repair_new')
                $actionType = 'new';
            elseif ($intent === 'repair_inquiry')
                $actionType = 'inquiry';

            if (!empty($slots['action_type'])) {
                $actionType = $slots['action_type'];
            }

            $askRepairItem = $templates['ask_repair_item'] ?? 'ต้องการซ่อมสินค้าชิ้นไหนคะ? 🔧 บอกรายละเอียดอาการเสียได้เลยค่ะ';

            // Handle repair_new
            if ($actionType === 'new') {
                $itemDesc = trim((string) ($slots['item_description'] ?? ($slots['product_name'] ?? '')));
                $issueDesc = trim((string) ($slots['issue_description'] ?? ''));

                if ($itemDesc === '' && $issueDesc === '') {
                    return ['handled' => false, 'reply_text' => $askRepairItem, 'reason' => 'missing_repair_item', 'slots' => $slots];
                }

                $endpoint = $ep(['repair_create']);
                if (!$endpoint) {
                    // Fallback: create case and handoff
                    // Get user_id from channel
                    $channelUser = $this->db->queryOne("SELECT user_id FROM customer_channels WHERE id = ? LIMIT 1", [$channelId]);
                    $caseUserId = $channelUser['user_id'] ?? null;
                    
                    $this->db->execute(
                        "INSERT INTO cases (channel_id, external_user_id, case_type, status, subject, description, priority, user_id) VALUES (?, ?, 'repair', 'open', ?, ?, 'medium', ?)",
                        [$channelId, $externalUserId, "ลูกค้าต้องการซ่อม: {$itemDesc}", "{$itemDesc}\nอาการ: {$issueDesc}", $caseUserId]
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
                $repairId = trim((string) ($slots['repair_id'] ?? ''));

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
            // Deposit intents
            case 'deposit_new':
                return $templates['ask_product_for_deposit'] ?? 'สนใจมัดจำสินค้าตัวไหนคะ? 🎁';
            case 'deposit_flow':
                return $templates['deposit_flow_ask_product'] ?? 'รับทราบค่ะ สนใจจองสินค้านะคะ 🎯||SPLIT||รบกวนบอกชื่อรุ่น/รหัส หรือส่งรูปสินค้าที่ต้องการจองมาให้แอดมินได้เลยค่ะ||SPLIT||แอดมินจะรีบเช็คและคำนวณยอดมัดจำให้นะคะ 😊';
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
            // =========================================================
            // ✅ NEW INTENT TEMPLATES - Dynamic conversation responses
            // =========================================================
            case 'price_negotiation':
                return $templates['price_negotiation'] ?? 'ราคาที่แจ้งเป็นราคาพิเศษแล้วค่ะ 🙏||SPLIT||ถ้าลูกค้าสนใจซื้อหลายชิ้น สามารถปรึกษาได้นะคะ เดี๋ยวลองดูให้ค่ะ 😊';
            case 'change_payment_method':
                return $templates['change_payment_method'] ?? 'ได้ค่ะ 😊 ลูกค้าต้องการเปลี่ยนเป็นวิธีไหนดีคะ?||SPLIT||1️⃣ โอนเต็มจำนวน||SPLIT||2️⃣ ผ่อน 3 งวด (+3%)||SPLIT||3️⃣ มัดจำ 10%';
            case 'consignment':
                return $templates['consignment'] ?? 'ขอบคุณที่สนใจค่ะ 💎||SPLIT||ทางร้านรับฝากขายเฉพาะสินค้าที่ซื้อจากร้าน ฮ.เฮง เฮง ค่ะ||SPLIT||รบกวนถ่ายรูปสินค้าพร้อมใบรับประกันส่งมาให้ประเมินได้เลยนะคะ 📸';
            case 'installment_inquiry':
                return $templates['installment_short'] ?? 'ผ่อน/ออมได้ค่ะ! ✅||SPLIT||• 3 งวด (60 วัน) ไม่ใช้เอกสาร||SPLIT||• ค่าธรรมเนียม 3%||SPLIT||สนใจสินค้าตัวไหนคะ? เดี๋ยวคำนวณยอดงวดแรกให้ดูเลยค่ะ 😊';
            case 'pawn_inquiry':
                return $templates['pawn_info'] ?? 'บริการรับฝาก/จำนำค่ะ 💎||SPLIT||⚠️ รับเฉพาะสินค้าที่ซื้อจากร้าน ฮ.เฮง เฮง เท่านั้นนะคะ||SPLIT||• ดอกเบี้ย 2%/เดือน||SPLIT||ถ่ายรูปสินค้า+ใบรับประกันมาให้ประเมินได้เลยค่ะ 📸';
            // =========================================================
            // END NEW INTENT TEMPLATES
            // =========================================================
            default:
                return $fallback;
        }
    }

    // =========================================================
    // ✅ Policy Template Handlers - ดึงตัวเลขจาก config ไม่กิน LLM tokens
    // =========================================================

    /**
     * Generate exchange/return policy reply from config (ไม่ต้องใช้ LLM)
     */
    protected function generateExchangeReturnPolicyReply(array $config): string
    {
        $p = $config['policies']['exchange_return'] ?? [];
        $upgradeDeduct = $p['exchange_upgrade_deduction'] ?? 10;
        $downgradeDeduct = $p['exchange_downgrade_deduction'] ?? 15;
        $returnDeduct = $p['return_cash_deduction'] ?? 15;
        $rolexDeduct = $p['rolex_deduction'] ?? 35;
        $nonRolexRule = $p['non_rolex_rule'] ?? 'ขายขาด ไม่รับคืน';
        $minValue = $p['min_value_for_exchange'] ?? 30000;

        $reply = "นโยบายเปลี่ยน/คืนค่ะ 📋\n\n";
        $reply .= "💎 งานเพชร (" . number_format($minValue) . "+ บาท):\n";
        $reply .= "• เปลี่ยนตัวแพงขึ้น: หัก {$upgradeDeduct}%\n";
        $reply .= "• เปลี่ยนตัวถูกลง/คืนเงิน: หัก {$returnDeduct}%\n\n";
        $reply .= "⌚ นาฬิกา Rolex: หัก {$rolexDeduct}%\n";
        $reply .= "👜 แบรนด์อื่น: {$nonRolexRule}\n\n";
        $reply .= "⚠️ ต้องมีใบรับประกันตัวจริงนะคะ";

        return $reply;
    }

    /**
     * Generate pawn policy reply from config (ไม่ต้องใช้ LLM)
     */
    protected function generatePawnPolicyReply(array $config): string
    {
        $p = $config['policies']['pawn'] ?? [];
        $onlyStore = $p['only_store_products'] ?? true;
        $appraisalMin = $p['appraisal_percent_min'] ?? 65;
        $appraisalMax = $p['appraisal_percent_max'] ?? 70;
        $interestRate = $p['interest_rate_monthly'] ?? 2;
        $cycleDays = $p['payment_cycle_days'] ?? 30;

        $reply = "บริการรับฝาก/จำนำค่ะ 💎\n\n";
        if ($onlyStore) {
            $reply .= "⚠️ รับเฉพาะสินค้าที่ซื้อจากทางร้านเท่านั้นนะคะ\n\n";
        }
        $reply .= "📊 ราคาประเมิน: {$appraisalMin}-{$appraisalMax}% ของราคาซื้อ\n";
        $reply .= "💰 ดอกเบี้ย: {$interestRate}% ต่อเดือน\n";
        $reply .= "📅 ชำระดอก: ทุก {$cycleDays} วัน\n\n";
        $reply .= "📸 ส่งรูปสินค้า + ใบรับประกัน มาให้ประเมินได้เลยค่ะ";

        return $reply;
    }

    /**
     * Generate installment policy reply from config (ไม่ต้องใช้ LLM)
     */
    protected function generateInstallmentPolicyReply(array $config): string
    {
        $p = $config['policies']['installment'] ?? [];
        $periods = $p['periods'] ?? 3;
        $fee = $p['service_fee_percent'] ?? 3;
        $maxDays = $p['max_days'] ?? 60;
        $deliveryRule = $p['delivery_rule'] ?? 'ส่งของเมื่อผ่อนครบ';
        $cancelDays = $p['cancel_refund_days'] ?? 7;
        $cancelFeeRefund = $p['cancel_fee_refund'] ?? false;

        $reply = "บริการผ่อน/ออมค่ะ ✅\n\n";
        $reply .= "📝 เงื่อนไข:\n";
        $reply .= "• ผ่อน {$periods} งวด (ภายใน {$maxDays} วัน)\n";
        $reply .= "• ค่าธรรมเนียม {$fee}% (จ่ายงวดแรก)\n";
        $reply .= "• ไม่ต้องใช้เอกสาร\n\n";
        $reply .= "📦 {$deliveryRule}\n\n";
        $reply .= "❌ ยกเลิก: คืนเงินต้น" . ($cancelFeeRefund ? "+ค่าธรรมเนียม" : " (ไม่คืนค่าธรรมเนียม)") . " ภายใน {$cancelDays} วัน\n\n";
        $reply .= "สนใจให้คำนวณยอดงวดไหมคะ? 😊";

        return $reply;
    }

    /**
     * Generate credit card policy reply from config
     */
    protected function generateCreditCardPolicyReply(array $config): string
    {
        $p = $config['policies']['credit_card'] ?? [];
        $surcharge = $p['surcharge_percent'] ?? 3;
        $availableAt = $p['available_at'] ?? 'หน้าร้านเท่านั้น';

        $reply = "บัตรเครดิตค่ะ 💳\n\n";
        $reply .= "✅ รับบัตรเครดิต: " . $availableAt . "\n";
        $reply .= "💰 ค่าธรรมเนียม: {$surcharge}% (บวกเพิ่มจากราคาสินค้า)\n\n";
        $reply .= "รับทุกธนาคาร Visa/Mastercard/JCB\n\n";
        $reply .= "สนใจมาชำระที่ร้านเลยนะคะ 😊";

        return $reply;
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
            $visionText = (string) ($visionMeta['text'] ?? '');
            $labelTextLower = mb_strtolower(implode(' ', (array) $labels), 'UTF-8');
            $visionTextLower = mb_strtolower($visionText, 'UTF-8');

            $vr = $config['vision_routing'] ?? [];
            $productHints = $vr['product_hints_labels'] ?? ($vr['product_hints'] ?? ['watch', 'bag', 'shoe', 'ring', 'jewelry', 'phone']);
            $payHintsTh = $vr['payment_hints_text_th'] ?? ($vr['payment_hints'] ?? ['receipt', 'bill', 'invoice', 'payment', 'slip']);
            $payHintsEn = $vr['payment_hints_text_en'] ?? [];
            $useTextDetection = (bool) ($vr['use_text_detection'] ?? true);

            $isPayment = false;
            if ($useTextDetection) {
                if ($this->containsAny($visionTextLower, $payHintsTh) || $this->containsAny($visionTextLower, $payHintsEn))
                    $isPayment = true;
            }
            if (!$isPayment) {
                if ($this->containsAny($labelTextLower, array_merge($payHintsTh, $payHintsEn)))
                    $isPayment = true;
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
                if ($slipAmount)
                    $extractedInfo .= "💰 จำนวนเงิน: {$slipAmount} บาท\n";
                if ($slipBank)
                    $extractedInfo .= "🏦 ธนาคาร: {$slipBank}\n";
                if ($slipDate)
                    $extractedInfo .= "📅 วันที่: {$slipDate}\n";
                if ($slipRef)
                    $extractedInfo .= "🔢 เลขอ้างอิง: {$slipRef}\n";
                if ($slipSender)
                    $extractedInfo .= "👤 ผู้โอน: {$slipSender}\n";

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
                        // =========================================================
                        // ✅ SMART SLIP: No auto-match - check for pending orders
                        // =========================================================
                        $externalUserId = $context['external_user_id'] ?? null;
                        $pendingOrders = [];
                        $quickReplyItems = [];

                        if ($externalUserId) {
                            $pendingOrders = $this->findPendingOrdersForCustomer(
                                (string) $externalUserId,
                                $context['channel']['id'] ?? null,
                                $slipAmount  // Exclude exact match (already handled by PaymentService)
                            );
                        }

                        if (count($pendingOrders) > 0) {
                            // Found pending orders - show as options
                            $reply = "ได้รับสลิปเรียบร้อยแล้วค่ะ 💳\n\n" . $extractedInfo
                                . "\n📝 เลขที่รายการ: {$paymentNo}";

                            if (count($pendingOrders) == 1) {
                                // Single pending order - likely this one
                                $order = $pendingOrders[0];
                                $orderNo = $order['order_number'];
                                $productName = $order['product_name'] ?? 'สินค้า';
                                $balance = number_format((float) ($order['balance'] ?? $order['total_amount']), 0);

                                $reply .= "\n\n💡 พบออเดอร์ค้างชำระ:"
                                    . "\n• #{$orderNo} - {$productName}"
                                    . "\n• ยอดคงเหลือ: {$balance} บาท"
                                    . "\n\nสลิปนี้ต้องการจ่ายค่าออเดอร์นี้ใช่ไหมคะ?";

                                $quickReplyItems = [
                                    ['label' => "✅ ใช่ค่ะ #{$orderNo}", 'text' => "ยืนยันจ่าย {$orderNo}"],
                                    ['label' => '❌ ไม่ใช่', 'text' => 'ไม่ใช่ออเดอร์นี้'],
                                ];
                                $meta['pending_orders'] = $pendingOrders;
                                $meta['suggested_order_no'] = $orderNo;

                            } else {
                                // Multiple pending orders - ask to select
                                $reply .= "\n\n💡 พบหลายออเดอร์ค้างชำระ:";
                                $i = 1;
                                foreach ($pendingOrders as $order) {
                                    $orderNo = $order['order_number'];
                                    $productName = mb_substr($order['product_name'] ?? 'สินค้า', 0, 20, 'UTF-8');
                                    $balance = number_format((float) ($order['balance'] ?? $order['total_amount']), 0);

                                    $reply .= "\n{$i}. #{$orderNo} - {$productName} ({$balance} บาท)";

                                    // Add quick reply (max 4 items typical limit)
                                    if ($i <= 4) {
                                        $quickReplyItems[] = [
                                            'label' => "#{$orderNo}",
                                            'text' => "จ่ายค่า {$orderNo}"
                                        ];
                                    }
                                    $i++;
                                }
                                $reply .= "\n\nสลิปนี้ต้องการจ่ายค่าออเดอร์ไหนคะ?";
                                $meta['pending_orders'] = $pendingOrders;
                            }
                        } else {
                            // No pending orders found - generic response
                            $reply = "ได้รับสลิปเรียบร้อยแล้วค่ะ 💳\n\n" . $extractedInfo
                                . "\n📝 เลขที่รายการ: {$paymentNo}"
                                . "\nรอเจ้าหน้าที่ตรวจสอบและ matching กับออเดอร์นะคะ 😊";
                        }

                        // Add quick replies if available
                        if (!empty($quickReplyItems)) {
                            $meta['quick_reply_items'] = $quickReplyItems;
                        }
                    }

                } elseif (!empty($paymentResult['is_duplicate'])) {
                    // Duplicate slip
                    $existingPaymentNo = $paymentResult['existing_payment_no'] ?? '';
                    $meta['payment_saved'] = false;
                    $meta['payment_duplicate'] = true;
                    $meta['existing_payment_id'] = $paymentResult['existing_payment_id'];
                    $reply = "สลิปนี้เคยส่งมาแล้วค่ะ 📋 (เลขอ้างอิง: {$existingPaymentNo})\nรอเจ้าหน้าที่ตรวจสอบนะคะ 😊";

                    if ($sessionId && $reply !== '')
                        $this->storeMessage($sessionId, 'assistant', $reply);
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
                        $reply = (string) ($handled['reply_text'] ?? $reply);
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

            if ($sessionId && $reply !== '')
                $this->storeMessage($sessionId, 'assistant', $reply);
            $this->logBotReply($context, $reply, 'text');

            // ✅ Include quick replies if available (from smart slip detection)
            $actions = [];
            if (!empty($meta['quick_reply_items'])) {
                $actions[] = [
                    'type' => 'quick_reply',
                    'items' => $meta['quick_reply_items']
                ];
            }

            return ['reply_text' => $reply, 'actions' => $actions, 'meta' => $meta];
        }

        // =========================================================
        // ✅ CONTEXT-AWARE IMAGE ROUTING: Check if customer was discussing pawn/repair
        // If they send product image AFTER asking about pawn -> create pawn case
        // If they send product image AFTER asking about repair -> create repair case
        // =========================================================
        $lastIntent = $lastSlots['last_intent'] ?? null;
        $isPawnContext = in_array($lastIntent, ['pawn_new', 'pawn_inquiry']) ||
            preg_match('/จำนำ|ฝาก|ต่อดอก/u', $lastSlots['last_message'] ?? '');
        $isRepairContext = in_array($lastIntent, ['repair_new', 'repair_inquiry']) ||
            preg_match('/ซ่อม|ชำรุด|เสีย|ขาด/u', $lastSlots['last_message'] ?? '');

        // Handle pawn context image - create case instead of product search
        if ($detectedRoute === 'product_image' && $isPawnContext) {
            Logger::info('[IMAGE_CONTEXT] Pawn context detected - creating pawn case instead of product search');

            $reply = $templates['pawn_image_received']
                ?? "รับรูปเรียบร้อยแล้วค่ะ 💎||SPLIT||เดี๋ยวช่างประเมินราคาจำนำให้นะคะ||SPLIT||จะรีบติดต่อกลับโดยเร็วค่ะ 🙏";

            // Create pawn case
            try {
                $caseEngine = new CaseEngine($config, $context);
                $caseSlots = [
                    'image_url' => $imageUrl,
                    'gemini_details' => $geminiDetails,
                    'item_description' => $geminiDetails['description'] ?? ($geminiDetails['brand'] . ' ' . ($geminiDetails['model'] ?? '')),
                ];
                $case = $caseEngine->getOrCreateCase(CaseEngine::CASE_PAWN, $caseSlots);
                $meta['case'] = ['id' => $case['id'] ?? null, 'case_no' => $case['case_no'] ?? null];

                if (!empty($case['case_no'])) {
                    $reply .= "||SPLIT||📋 เลขเคส: " . $case['case_no'];
                }
            } catch (Throwable $e) {
                Logger::error('[IMAGE_CONTEXT] Failed to create pawn case', ['error' => $e->getMessage()]);
            }

            $meta['reason'] = 'pawn_image_case_created';
            if ($sessionId && $reply !== '')
                $this->storeMessage($sessionId, 'assistant', $reply);
            $this->logBotReply($context, $reply, 'text');
            return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta, 'handoff_to_admin' => true];
        }

        // Handle repair context image - create case instead of product search
        if ($detectedRoute === 'product_image' && $isRepairContext) {
            Logger::info('[IMAGE_CONTEXT] Repair context detected - creating repair case instead of product search');

            $reply = $templates['repair_image_received']
                ?? "รับรูปเรียบร้อยแล้วค่ะ 🔧||SPLIT||เดี๋ยวช่างประเมินค่าซ่อมให้นะคะ||SPLIT||จะรีบติดต่อกลับโดยเร็วค่ะ 🙏";

            // Create repair case
            try {
                $caseEngine = new CaseEngine($config, $context);
                $caseSlots = [
                    'image_url' => $imageUrl,
                    'gemini_details' => $geminiDetails,
                    'item_description' => $geminiDetails['description'] ?? 'งานซ่อม',
                    'damage_description' => $visionText,
                ];
                $case = $caseEngine->getOrCreateCase(CaseEngine::CASE_REPAIR, $caseSlots);
                $meta['case'] = ['id' => $case['id'] ?? null, 'case_no' => $case['case_no'] ?? null];

                if (!empty($case['case_no'])) {
                    $reply .= "||SPLIT||📋 เลขเคส: " . $case['case_no'];
                }
            } catch (Throwable $e) {
                Logger::error('[IMAGE_CONTEXT] Failed to create repair case', ['error' => $e->getMessage()]);
            }

            $meta['reason'] = 'repair_image_case_created';
            if ($sessionId && $reply !== '')
                $this->storeMessage($sessionId, 'assistant', $reply);
            $this->logBotReply($context, $reply, 'text');
            return ['reply_text' => $reply, 'actions' => [], 'meta' => $meta, 'handoff_to_admin' => true];
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
                if ($productBrand)
                    $productInfo .= "🏷️ แบรนด์: {$productBrand}\n";
                if ($productModel)
                    $productInfo .= "📋 รุ่น: {$productModel}\n";
                if ($productCategory)
                    $productInfo .= "📁 หมวด: {$productCategory}\n";
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
                if (!$endpoint)
                    $endpoint = '/api/searchImage';

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
                    if (!is_array($products))
                        $products = [];

                    // ✅ renderProductsFromBackend returns {text, actions}
                    $rendered = $this->renderProductsFromBackend($products, $templates);
                    $reply = (string) ($rendered['text'] ?? $reply);

                    // Cache candidates for selection from image search
                    if ($sessionId) {
                        $slotsCand = $this->attachProductCandidatesToSlots([], $products, 'image_search', $config['session_policy'] ?? []);
                        $this->updateSessionState((int) $sessionId, 'product_lookup_by_image', $slotsCand);
                    }
                    $actionsOut = (isset($rendered['actions']) && is_array($rendered['actions'])) ? $rendered['actions'] : [];

                    $meta['reason'] = 'image_product_backend';
                } else {
                    $reply = $templates['ask_product_code']
                        ?? 'รบกวนส่ง "ชื่อรุ่น/รหัส/ซีเรียล/งบ" เพิ่มนิดนึงค่ะ 😊 เพื่อให้เช็คได้ตรงขึ้นค่ะ';
                    $meta['reason'] = 'image_product_backend_error';
                }
            }

            if ($sessionId && $reply !== '')
                $this->storeMessage($sessionId, 'assistant', $reply);
            $this->logBotReply($context, $reply, 'text');
            return ['reply_text' => $reply, 'actions' => $actionsOut, 'meta' => $meta];
        }

        // generic image
        $reply = $templates['image_generic']
            ?? 'ได้รับรูปภาพแล้วค่ะ 🖼️ รบกวนบอกเพิ่มนิดนึงนะคะ ว่าอยากให้ช่วยดูเรื่องอะไรเกี่ยวกับรูปนี้ค่ะ';

        if ($llmIntegration && !empty($config['llm']['enabled'])) {
            $prompt = "ลูกค้าส่งรูปภาพมาแต่ไม่อธิบาย:\n";
            $prompt .= "URL รูปภาพ: {$imageUrl}\n";
            if ($labels)
                $prompt .= "Vision: " . implode(', ', $labels) . "\n";
            $prompt .= "ช่วยตอบสุภาพ เป็นกันเอง และถามต่อให้ชัดว่าอยากให้เช็คสต็อก/ถามราคา/ส่งสลิป/สอบถามอื่น ๆ\n";

            $llm = $this->handleWithLlm($llmIntegration, $config, $context, $prompt);
            if (!empty($llm['reply_text']))
                $reply = (string) $llm['reply_text'];
            $meta['llm'] = $llm['meta'] ?? null;
            $meta['reason'] = 'image_generic_llm';
        } else {
            $meta['reason'] = 'image_generic_template';
        }

        if ($sessionId && $reply !== '')
            $this->storeMessage($sessionId, 'assistant', $reply);
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
            $_img = $this->extractProductImageUrl($p);
            if (!empty($_img)) {
                $actions[] = [
                    'type' => 'image',
                    'url' => $_img
                ];
                Logger::info("[RENDER_PRODUCTS] ✅ Added image for single product", [
                    'image_url' => $_img,
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
            $_img = $this->extractProductImageUrl($p);
            if ($i <= 3 && !empty($_img)) {
                $actions[] = [
                    'type' => 'image',
                    'url' => $_img
                ];
                Logger::info("[RENDER_PRODUCTS] ✅ Added image #{$i}", [
                    'image_url' => $_img,
                    'product_name' => $name
                ]);
            } elseif ($i <= 3) {
                Logger::warning("[RENDER_PRODUCTS] ⚠️ No image_url for product #{$i}", [
                    'product_name' => $name,
                    'sku' => $code
                ]);
            }

            $i++;
            if ($i > 5)
                break;
        }

        $tpl = $templates['product_found_many'] ?? "พบหลายรายการ:\n{{list}}\nพิมพ์เลือกเลข 1-{{n}} ได้เลยค่ะ";
        $text = $this->renderTemplate($tpl, [
            'list' => implode("\n", $lines),
            'n' => min(count($products), 5)
        ]);

        Logger::info("[RENDER_PRODUCTS] ✅ Final result", [
            'total_products' => count($products),
            'actions_count' => count($actions),
            'image_urls' => array_map(function ($a) {
                return $a['url'] ?? 'N/A';
            }, $actions)
        ]);

        return ['text' => $text, 'actions' => $actions];
    }

    protected function detectInstallmentActionTypeFromText(string $text): ?string
    {
        $t = mb_strtolower($text, 'UTF-8');

        // =========================================================
        // ✅ Priority 1: SUMMARY patterns (check balance, remaining)
        // Uses compound patterns to avoid false positives
        // =========================================================
        if (
            preg_match(
                '/(' .
                // Pattern: inquiry + งวด/ผ่อน context
                '(เหลือ|ค้าง|ยอด|สรุป).{0,10}(งวด|ผ่อน)|' .
                '(งวด|ผ่อน).{0,10}(เหลือ|ค้าง|เท่าไหร่|กี่)|' .
                // Pattern: explicit summary keywords
                '(เช็คยอด|ดูยอด|ขอยอด|สรุปยอด)|' .
                // Pattern: specific questions
                '(เหลือ.*กี่.*งวด|จ่ายไปแล้ว.*กี่|ต้องจ่ายอีก)' .
                ')/u',
                $t
            )
        ) {
            return 'summary';
        }

        // =========================================================
        // ✅ Priority 2: CLOSE_CHECK (ปิดยอด)
        // =========================================================
        if (mb_strpos($t, 'ปิดยอด', 0, 'UTF-8') !== false) {
            return 'close_check';
        }

        // =========================================================
        // ✅ Priority 3: EXTEND_INTEREST (ต่อดอก)
        // Note: 'ต่อดอก' only, not 'ต่อ' alone (too broad)
        // =========================================================
        if (mb_strpos($t, 'ต่อดอก', 0, 'UTF-8') !== false) {
            return 'extend_interest';
        }

        // =========================================================
        // ✅ Priority 4: PAY (payment context)
        // Requires payment action words, not just 'งวด' alone
        // =========================================================
        if (preg_match('/(ชำระ|โอน|จ่าย|ส่งงวด|แจ้งโอน|จ่ายงวด)/u', $t)) {
            return 'pay';
        }

        // =========================================================
        // ✅ Fallback: Check for generic summary words
        // Only match if 'เช็ค' or 'สรุป' appears (with context)
        // =========================================================
        if (mb_strpos($t, 'เช็ค', 0, 'UTF-8') !== false || mb_strpos($t, 'สรุป', 0, 'UTF-8') !== false) {
            return 'summary';
        }

        return null;
    }

    /**
     * Detect case type from handoff keyword
     */
    protected function detectCaseTypeFromKeyword(string $keyword): string
    {
        $k = mb_strtolower(trim($keyword), 'UTF-8');

        // Purchase/Buy intent
        if (
            mb_strpos($k, 'ซื้อ', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'สนใจ', 0, 'UTF-8') !== false
        ) {
            return 'product_inquiry';
        }

        // Deposit/Reserve intent  
        if (
            mb_strpos($k, 'มัดจำ', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'จอง', 0, 'UTF-8') !== false
        ) {
            return 'deposit';
        }

        // Installment intent
        if (mb_strpos($k, 'ผ่อน', 0, 'UTF-8') !== false) {
            return 'payment_installment';
        }

        // Pawn intent
        if (
            mb_strpos($k, 'จำนำ', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'ต่อดอก', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'ไถ่ถอน', 0, 'UTF-8') !== false
        ) {
            return 'pawn';
        }

        // Repair intent
        if (
            mb_strpos($k, 'ซ่อม', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'เซอร์วิส', 0, 'UTF-8') !== false
        ) {
            return 'repair';
        }

        // Return/Exchange intent
        if (
            mb_strpos($k, 'เปลี่ยน', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'คืน', 0, 'UTF-8') !== false
        ) {
            return 'return_exchange';
        }

        // Price negotiation / discount
        if (
            mb_strpos($k, 'ลด', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'ต่อรอง', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'ส่วนลด', 0, 'UTF-8') !== false
        ) {
            return 'product_inquiry';
        }

        // Video call / appointment
        if (
            mb_strpos($k, 'call', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'นัด', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'ดูของ', 0, 'UTF-8') !== false
        ) {
            return 'product_inquiry';
        }

        // Bank account request
        if (
            mb_strpos($k, 'เลขบัญชี', 0, 'UTF-8') !== false ||
            mb_strpos($k, 'โอน', 0, 'UTF-8') !== false
        ) {
            return 'payment_full';
        }

        // Default
        return 'general_inquiry';
    }

    // =========================================================
    // Backend HTTP helper
    // =========================================================
    protected function callBackendJson(array $backendCfg, string $endpointOrUrl, array $payload): array
    {
        $base = rtrim((string) ($backendCfg['base_url'] ?? ''), '/');
        $timeout = (int) ($backendCfg['timeout_seconds'] ?? 8);
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
        $responseTime = (int) ((microtime(true) - $startTime) * 1000); // milliseconds
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
        // Support both 'ok' and 'success' keys, or data array directly
        $isOk = $data['ok'] ?? $data['success'] ?? null;

        // If API returns {"data": [...]} without explicit ok/success, treat as successful
        if ($isOk === null && isset($data['data'])) {
            $isOk = true;
        }

        if (isset($data['data'])) {
            // API response format: {"ok": true, "data": {...}} or {"success": true, "data": {...}}
            // Or just: {"data": [...]} which we treat as success
            // Return: {"ok": <from API>, "data": <from API>, "status": <http>, "url": <url>}
            return ['ok' => (bool) $isOk, 'data' => $data['data'], 'status' => $status, 'url' => $url];
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
            if (!$channelId)
                return; // Skip if no channel context

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

    /**
     * Search product by code using backend API
     * ✅ NEW: Helper method to search product by code
     * @param string $productCode Product code to search
     * @param array $config Bot config
     * @param array $context Chat context
     * @return array|null ['product' => [...]] or null if not found
     */
    protected function searchProductByCode(string $productCode, array $config, array $context): ?array
    {
        $backendCfg = $config['backend_api'] ?? [];
        
        if (empty($backendCfg['enabled'])) {
            Logger::info('[ROUTER_V1] searchProductByCode - backend not enabled');
            return null;
        }
        
        $endpoints = $backendCfg['endpoints'] ?? [];
        $endpoint = $endpoints['product_search'] ?? null;
        
        if (!$endpoint) {
            Logger::info('[ROUTER_V1] searchProductByCode - no product_search endpoint');
            return null;
        }
        
        $channelId = $context['channel']['id'] ?? null;
        $externalUserId = $context['external_user_id'] ?? null;
        
        $payload = [
            'channel_id' => $channelId,
            'external_user_id' => $externalUserId,
            'product_code' => $productCode,
            'keyword' => $productCode,
        ];
        
        $resp = $this->callBackendJson($backendCfg, $endpoint, $payload);
        
        if (!$resp['ok']) {
            Logger::warning('[ROUTER_V1] searchProductByCode - API error', [
                'product_code' => $productCode,
                'error' => $resp['error'] ?? 'unknown'
            ]);
            return null;
        }
        
        $data = $resp['data'] ?? [];
        $products = $data['products'] ?? ($data['items'] ?? $data);
        
        if (!is_array($products) || empty($products)) {
            Logger::info('[ROUTER_V1] searchProductByCode - no products found', [
                'product_code' => $productCode
            ]);
            return null;
        }
        
        // Return first matching product
        $product = $products[0];
        
        Logger::info('[ROUTER_V1] searchProductByCode - found product', [
            'product_code' => $productCode,
            'product_name' => $product['title'] ?? $product['name'] ?? null,
            'product_price' => $product['sale_price'] ?? $product['price'] ?? null
        ]);
        
        return ['product' => $product];
    }

    // =========================================================
    // Detectors
    // =========================================================
    protected function detectMessageType(array $message): string
    {
        $t = (string) ($message['message_type'] ?? ($message['type'] ?? ''));
        $t = trim($t);
        if ($t !== '')
            return $t;

        $atts = $message['attachments'] ?? [];
        if (is_array($atts)) {
            foreach ($atts as $a) {
                $atype = (string) ($a['type'] ?? '');
                $url = (string) ($a['url'] ?? ($a['payload']['url'] ?? ''));
                $mime = (string) ($a['mime_type'] ?? '');

                if ($atype === 'image')
                    return 'image';
                if ($mime !== '' && stripos($mime, 'image/') === 0)
                    return 'image';

                if ($url !== '') {
                    if (preg_match('/\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i', $url))
                        return 'image';
                }
            }
        }
        return 'text';
    }

    protected function extractFirstImageUrl(array $message): ?string
    {
        $atts = $message['attachments'] ?? [];
        if (!is_array($atts) || empty($atts))
            return null;

        foreach ($atts as $a) {
            $url = $a['url'] ?? ($a['payload']['url'] ?? null);
            if ($url && is_string($url))
                return $url;
        }
        return null;
    }

    protected function isAdminContext(array $context, array $message): bool
    {
        if (!empty($context['is_admin']))
            return true;
        if (!empty($context['user']['is_admin']))
            return true;
        if (!empty($context['sender_role']) && $context['sender_role'] === 'admin')
            return true;
        if (!empty($message['meta']['is_admin']))
            return true;

        // New: allow webhook metadata to carry sender_role
        if (!empty($message['meta']['sender_role']) && $message['meta']['sender_role'] === 'admin')
            return true;

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
        if ($row)
            return $row;

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
        $text = trim((string) $text);
        if ($text === '')
            return;

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
        if (trim($replyText) === '')
            return;

        try {
            $channel = $context['channel'] ?? [];
            $channelId = $channel['id'] ?? null;
            // ✅ FIX: Fallback to context['user']['external_user_id'] for LINE compatibility
            $externalUserId = $context['external_user_id'] ?? ($context['user']['external_user_id'] ?? null);

            if (!$channelId)
                return; // Skip if no channel context

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
    // ✅ Also update cases.slots for guided checkout flow
    protected function updateSessionState(int $sessionId, ?string $intent, ?array $slots): void
    {
        $existing = $this->db->queryOne('SELECT last_slots_json, active_case_id FROM chat_sessions WHERE id = ? LIMIT 1', [$sessionId]);
        $oldSlots = [];
        if (!empty($existing['last_slots_json'])) {
            $tmp = json_decode($existing['last_slots_json'], true);
            if (is_array($tmp))
                $oldSlots = $tmp;
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

        // ✅ Also update cases.slots if there's an active case
        // This syncs checkout progress (payment_method, shipping_method, etc.) to the case
        $activeCaseId = $existing['active_case_id'] ?? null;
        if ($activeCaseId && !empty($slots)) {
            // Only sync relevant checkout fields to cases.slots
            $checkoutFields = ['payment_method', 'shipping_method', 'delivery_method', 'shipping_fee', 'shipping_address', 'checkout_step'];
            $caseSlotUpdates = array_intersect_key($slots, array_flip($checkoutFields));

            if (!empty($caseSlotUpdates)) {
                try {
                    $caseRow = $this->db->queryOne("SELECT slots FROM cases WHERE id = ?", [$activeCaseId]);
                    if ($caseRow) {
                        $caseSlots = json_decode($caseRow['slots'] ?? '{}', true) ?: [];
                        $mergedCaseSlots = array_merge($caseSlots, $caseSlotUpdates);
                        $this->db->execute(
                            "UPDATE cases SET slots = ?, updated_at = NOW() WHERE id = ?",
                            [json_encode($mergedCaseSlots, JSON_UNESCAPED_UNICODE), $activeCaseId]
                        );
                    }
                } catch (\Exception $e) {
                    // Silently fail - don't break the main flow
                    error_log("[RouterV1Handler] Failed to sync slots to case {$activeCaseId}: " . $e->getMessage());
                }
            }
        }
    }

    protected function getConversationHistory(int $sessionId, int $limit = 10): array
    {
        $limit = max(1, min(50, (int) $limit));
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

        if (count($rows) < $limit)
            return false;

        foreach ($rows as $r) {
            $t = $this->normalizeTextForRepeat((string) ($r['text'] ?? ''));
            if ($t !== $normalizedText)
                return false;
        }
        return true;
    }



    // =========================================================
    // ✅ Delivery de-duplication & session slot maintenance
    // =========================================================

    /**
     * Prevent duplicate webhook deliveries from producing duplicated replies.
     * We treat a delivery as duplicate if the same normalized user text has been recorded
     * within the last N seconds.
     */
    protected function isDuplicateDelivery(int $sessionId, string $text, int $windowSeconds = 3): bool
    {
        $windowSeconds = max(1, min(30, (int) $windowSeconds));
        $normalized = $this->normalizeTextForRepeat($text);
        if ($normalized === '') {
            return false;
        }

        $sql = "SELECT text, created_at
                FROM chat_messages
                WHERE session_id = ?
                  AND role = 'user'
                  AND created_at >= (NOW() - INTERVAL {$windowSeconds} SECOND)
                ORDER BY created_at DESC
                LIMIT 3";
        $rows = $this->db->query($sql, [$sessionId]);
        foreach ($rows as $r) {
            $t = $this->normalizeTextForRepeat((string) ($r['text'] ?? ''));
            if ($t === $normalized) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove keys from session slots (overwrite slots JSON).
     * IMPORTANT: updateSessionState() merges and never deletes keys, so we need this for cache busting.
     */
    protected function removeSlotKeys(int $sessionId, array $keys, ?string $intent = null): void
    {
        $keys = array_values(array_filter(array_map('strval', $keys)));
        if (empty($keys)) {
            return;
        }

        $row = $this->db->queryOne('SELECT last_intent, last_slots_json FROM chat_sessions WHERE id = ? LIMIT 1', [$sessionId]);
        $currentIntent = $intent ?? ($row['last_intent'] ?? null);
        $slots = [];
        if (!empty($row['last_slots_json'])) {
            $tmp = json_decode($row['last_slots_json'], true);
            if (is_array($tmp)) {
                $slots = $tmp;
            }
        }

        foreach ($keys as $k) {
            if (array_key_exists($k, $slots)) {
                unset($slots[$k]);
            }
        }

        $this->db->execute(
            'UPDATE chat_sessions SET last_intent = ?, last_slots_json = ?, updated_at = NOW() WHERE id = ?',
            [
                $currentIntent,
                !empty($slots) ? json_encode($slots, JSON_UNESCAPED_UNICODE) : null,
                $sessionId,
            ]
        );
    }

    /**
     * Detect selection index from a list: "1", "ข้อ 2", "เอาอันที่ 3", "ตัวที่4".
     */
    protected function detectSelectionIndex(string $text): ?int
    {
        $t = trim($text);
        if ($t === '') {
            return null;
        }

        // Pure number
        if (preg_match('/^\s*(\d{1,2})\s*$/u', $t, $m)) {
            $n = (int) $m[1];
            return ($n >= 1 && $n <= 20) ? $n : null;
        }

        if (preg_match('/(?:ข้อ|ตัว|อัน|รายการ|item|no\.?|หมายเลข|เบอร์)\s*#?\s*(\d{1,2})/iu', $t, $m)) {
            $n = (int) $m[1];
            return ($n >= 1 && $n <= 20) ? $n : null;
        }

        return null;
    }

    /**
     * Get recent product candidates from slots if still within TTL.
     */
    protected function getRecentProductCandidates(array $lastSlots, array $sessionPolicy): array
    {
        $ttl = (int) ($sessionPolicy['product_context_ttl_seconds'] ?? 600);
        $ttl = max(30, min(7200, $ttl));

        $cands = $lastSlots['last_product_candidates'] ?? null;
        $ts = $lastSlots['last_product_candidates_ts'] ?? null;
        if (!is_array($cands) || empty($cands) || !$ts) {
            return [];
        }

        $t0 = strtotime((string) $ts);
        if (!$t0) {
            return [];
        }
        if (time() - $t0 > $ttl) {
            return [];
        }

        return array_values(array_filter($cands, 'is_array'));
    }

    /**
     * Attach product candidates (for later selection: "เอาอันที่ 2") into slots.
     */
    protected function attachProductCandidatesToSlots(array $slots, array $products, string $query, array $sessionPolicy): array
    {
        $max = (int) ($sessionPolicy['max_product_candidates'] ?? 5);
        $max = max(1, min(10, $max));

        $cands = [];
        $i = 0;
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $i++
            ;
            $cands[] = $this->extractProductCandidate($p);
            if ($i >= $max) {
                break;
            }
        }

        if (!empty($cands)) {
            $slots['last_product_query'] = mb_substr((string) $query, 0, 120, 'UTF-8');
            $slots['last_product_candidates'] = $cands;
            $slots['last_product_candidates_ts'] = date('c');
        }

        return $slots;
    }

    /**
     * Extract a compact product structure to store in session slots.
     */
    protected function extractProductCandidate(array $p): array
    {
        $code = $p['sku'] ?? ($p['code'] ?? ($p['product_code'] ?? ($p['productCode'] ?? '')));
        $name = $p['name'] ?? ($p['title'] ?? ($p['product_name'] ?? ''));
        $price = $p['price'] ?? ($p['selling_price'] ?? ($p['sellingPrice'] ?? ''));
        $refId = $p['ref_id'] ?? ($p['id'] ?? ($p['product_id'] ?? null));
        $img = $this->extractProductImageUrl($p);

        return [
            'ref_id' => $refId,
            'code' => $code,
            'name' => $name,
            'price' => $price,
            'image_url' => $img,
            // keep minimal fields only
        ];
    }

    /**
     * Extract image url from various product formats.
     */
    protected function extractProductImageUrl(array $p): ?string
    {
        $candidates = [
            $p['image_url'] ?? null,
            $p['thumbnail_url'] ?? null,
            $p['thumb_url'] ?? null,
            $p['image'] ?? null,
        ];

        // images: [ {url:...}, ... ]
        if (empty($candidates[0]) && !empty($p['images']) && is_array($p['images'])) {
            $first = $p['images'][0] ?? null;
            if (is_array($first)) {
                $candidates[] = $first['url'] ?? ($first['image_url'] ?? null);
            } elseif (is_string($first)) {
                $candidates[] = $first;
            }
        }

        // media: {thumbnail:..., url:...}
        if (empty($candidates[0]) && !empty($p['media']) && is_array($p['media'])) {
            $candidates[] = $p['media']['thumbnail'] ?? null;
            $candidates[] = $p['media']['url'] ?? null;
        }

        foreach ($candidates as $u) {
            $u = is_string($u) ? trim($u) : '';
            if ($u !== '' && preg_match('~^https?://~i', $u)) {
                return $u;
            }
        }
        return null;
    }

    /**
     * Build image actions for chat channels.
     */
    protected function buildImageActionsFromProducts(array $products, int $max = 3): array
    {
        $max = max(0, min(10, (int) $max));
        $actions = [];
        $i = 0;
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $img = $this->extractProductImageUrl($p);
            if ($img) {
                $actions[] = ['type' => 'image', 'url' => $img];
                $i++;
                if ($i >= $max) {
                    break;
                }
            }
        }
        return $actions;
    }

    protected function looksLikeResetContext(string $text, array $sessionPolicy): bool
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        if ($t === '') {
            return false;
        }
        $cmds = $sessionPolicy['reset_keywords'] ?? ['reset', 'ล้างค่า', 'เริ่มใหม่', 'เริ่มต้นใหม่', 'ลืมเรื่องเดิม'];
        if (!is_array($cmds)) {
            return false;
        }
        foreach ($cmds as $k) {
            $k = mb_strtolower(trim((string) $k), 'UTF-8');
            if ($k !== '' && mb_strpos($t, $k, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }

    protected function looksLikeChangeProduct(string $text, array $sessionPolicy): bool
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        if ($t === '') {
            return false;
        }
        $keys = $sessionPolicy['change_product_keywords'] ?? [
            'เปลี่ยน',
            'เปลี่ยนเป็น',
            'หาใหม่',
            'ขอดูตัวอื่น',
            'ตัวอื่น',
            'อันอื่น',
            'รุ่นอื่น',
            'อย่างอื่น',
            'ไม่เอาอันนี้',
        ];
        if (!is_array($keys)) {
            return false;
        }
        foreach ($keys as $k) {
            $k = mb_strtolower(trim((string) $k), 'UTF-8');
            if ($k !== '' && mb_strpos($t, $k, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }
    // =========================================================
    // Slot helpers
    // =========================================================
    protected function mergeSlots(array $existingSlots = null, array $newSlots = null): array
    {
        $existingSlots = $existingSlots ?: [];
        $newSlots = $newSlots ?: [];
        foreach ($newSlots as $k => $v) {
            if ($v !== null && $v !== '')
                $existingSlots[$k] = $v;
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

    /**
     * Parse Thai shipping address from freeform text
     * Expected format: ชื่อ-นามสกุล, ที่อยู่, เบอร์โทร
     * 
     * @param string $text Raw address text from customer
     * @return array Parsed address components
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
        $provinces = [
            'กรุงเทพ',
            'กรุงเทพฯ',
            'กทม',
            'นนทบุรี',
            'ปทุมธานี',
            'สมุทรปราการ',
            'ชลบุรี',
            'เชียงใหม่',
            'ขอนแก่น',
            'นครราชสีมา',
            'สงขลา',
            'ภูเก็ต',
            'ระยอง',
            'พระนครศรีอยุธยา'
        ];
        foreach ($provinces as $prov) {
            if (mb_stripos($text, $prov) !== false) {
                $result['province'] = $prov === 'กทม' ? 'กรุงเทพฯ' : $prov;
                $text = preg_replace('/จ\\.?\\s*' . preg_quote($prov, '/') . '/u', '', $text);
                $text = preg_replace('/จังหวัด\\s*' . preg_quote($prov, '/') . '/u', '', $text);
                $text = str_ireplace($prov, '', $text);
                break;
            }
        }

        // Extract district (อำเภอ/เขต)
        if (preg_match('/(?:อ\\.?|อำเภอ|เขต)\\s*([ก-๙a-zA-Z]+)/u', $text, $districtMatch)) {
            $result['district'] = $districtMatch[1];
            $text = str_replace($districtMatch[0], '', $text);
        }

        // Extract subdistrict (ตำบล/แขวง)
        if (preg_match('/(?:ต\\.?|ตำบล|แขวง)\\s*([ก-๙a-zA-Z]+)/u', $text, $subdistMatch)) {
            $result['subdistrict'] = $subdistMatch[1];
            $text = str_replace($subdistMatch[0], '', $text);
        }

        // Clean remaining text and split into name and address
        $text = preg_replace('/\\s+/', ' ', trim($text));
        $parts = preg_split('/[,\n\\s]{2,}/u', $text, 2);

        if (count($parts) >= 2) {
            // First part is likely name, second is address
            $result['name'] = trim($parts[0]);
            $result['address_line1'] = trim($parts[1]);
        } else {
            // Try to extract name (typically first 2-4 words if Thai)
            if (preg_match('/^([ก-๙]+\\s+[ก-๙]+(?:\\s+[ก-๙]+)?)/u', $text, $nameMatch)) {
                $result['name'] = trim($nameMatch[1]);
                $result['address_line1'] = trim(str_replace($nameMatch[1], '', $text));
            } else {
                $result['address_line1'] = $text;
            }
        }

        // Clean up address_line1
        $result['address_line1'] = preg_replace('/^[,\\s]+|[,\\s]+$/', '', $result['address_line1']);
        $result['address_line1'] = preg_replace('/\\s+/', ' ', $result['address_line1']);

        return $result;
    }

    /**
     * Check if text looks like an address (vs a general question)
     * 
     * @param string $text Text to check
     * @return bool True if text looks like address info
     */
    protected function looksLikeAddressText(string $text): bool
    {
        $text = trim($text);
        $textLen = mb_strlen($text, 'UTF-8');
        
        // ✅ Too short to be address (less than 10 chars)
        if ($textLen < 10) {
            return false;
        }
        
        // ✅ GUARD: Check for product code pattern - NOT address
        $productCodePattern = '/\b[A-Z]{2,4}[-_][A-Z]{2,4}[-_]\d{2,4}\b/i';
        if (preg_match($productCodePattern, $text)) {
            return false; // This is a product code, not an address
        }
        
        // ✅ GUARD: Check for purchase interest keywords - NOT address
        $purchaseKeywords = ['สนใจ', 'เอา', 'ซื้อ', 'ตกลง', 'จอง', 'cf', 'เอาเลย', 'ซื้อเลย'];
        foreach ($purchaseKeywords as $keyword) {
            if (mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
                return false; // This is purchase interest, not an address
            }
        }
        
        // ✅ Check for phone number (strong indicator)
        $hasPhone = (bool) preg_match('/0[689]\d{8}|0[1-5]\d{7}/u', $text);
        if ($hasPhone) {
            return true;
        }
        
        // ✅ Check for postal code (strong indicator)
        $hasPostalCode = (bool) preg_match('/\b\d{5}\b/', $text);
        if ($hasPostalCode) {
            return true;
        }
        
        // ✅ Check for address indicators
        $addressIndicators = [
            '/\d+\/\d+/u',                              // House number like 123/45
            '/ถ\\.?|ถนน|road|rd/iu',                    // Road
            '/ซ\\.?|ซอย|soi/iu',                        // Soi
            '/ม\\.?|หมู่/iu',                           // Moo
            '/ต\\.?|ตำบล|แขวง/iu',                      // Subdistrict
            '/อ\\.?|อำเภอ|เขต/iu',                      // District
            '/จ\\.?|จังหวัด|กรุงเทพ|กทม/iu',            // Province
            '/บ้านเลขที่|เลขที่/iu',                    // House number prefix
        ];
        
        $addressScore = 0;
        foreach ($addressIndicators as $pattern) {
            if (preg_match($pattern, $text)) {
                $addressScore++;
            }
        }
        
        // ✅ At least 1 address indicator found
        if ($addressScore >= 1) {
            return true;
        }
        
        // ✅ Check for question keywords (NOT address)
        $questionKeywords = [
            'ไหม', 'หรือ', 'ยังไง', 'อย่างไร', 'เท่าไหร่', 'เท่าไร', 'กี่',
            'ทำไม', 'เมื่อไหร่', 'ที่ไหน', 'อะไร', 'ใคร',
            'ได้ไหม', 'ได้มั้ย', 'ดีไหม', 'มีไหม',
            'คืน', 'เปลี่ยน', 'รับประกัน', 'warranty', 'return',
            '?', '？'
        ];
        
        foreach ($questionKeywords as $keyword) {
            if (mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
                return false; // This is a question, not an address
            }
        }
        
        // ✅ Long text (>30 chars) with numbers might be address
        if ($textLen > 30 && preg_match('/\d/', $text)) {
            return true;
        }
        
        // ✅ Check for Thai name pattern at the start
        if (preg_match('/^(คุณ|นาย|นาง|น\\.ส\\.|นางสาว)?[ก-๙]{2,}/u', $text)) {
            // Might be "ชื่อ + ที่อยู่" format
            if ($textLen > 20) {
                return true;
            }
        }
        
        // Default: probably not an address
        return false;
    }

    /**
     * Validate if address buffer has enough information (name + address + phone)
     * 
     * @param string $buffer Accumulated address text from customer
     * @return array ['is_complete' => bool, 'missing' => array of missing fields]
     */
    protected function validateAddressBuffer(string $buffer): array
    {
        $missing = [];

        // Clean buffer
        $buffer = trim($buffer);
        $bufferLen = mb_strlen($buffer, 'UTF-8');

        // Check for phone (10 digits starting with 0)
        $hasPhone = (bool) preg_match('/0[689]\d{8}|0[1-5]\d{7}/u', $buffer);
        if (!$hasPhone) {
            $missing[] = 'phone';
        }

        // Check for Thai name (at least 2 Thai words)
        // Names like "สมชาย ใจดี" or "นางสาว สมหญิง รักดี"
        $hasName = (bool) preg_match('/[ก-๙]{2,}[\s]+[ก-๙]{2,}/u', $buffer);
        if (!$hasName) {
            // Also accept English names
            $hasName = (bool) preg_match('/[a-zA-Z]{2,}[\s]+[a-zA-Z]{2,}/u', $buffer);
        }
        if (!$hasName) {
            $missing[] = 'name';
        }

        // Check for address indicators
        // Look for: house number, road, soi, moo, province, postal code
        $addressIndicators = [
            '/\d+\/\d+/u',                              // House number like 123/45
            '/ถ\\.?|ถนน|road|rd/iu',                    // Road
            '/ซ\\.?|ซอย|soi/iu',                        // Soi
            '/ม\\.?|หมู่/iu',                           // Moo
            '/ต\\.?|ตำบล|แขวง/iu',                      // Subdistrict
            '/อ\\.?|อำเภอ|เขต/iu',                      // District
            '/จ\\.?|จังหวัด|กรุงเทพ|กทม/iu',            // Province
            '/\b\d{5}\b/',                              // Postal code
        ];

        $addressScore = 0;
        foreach ($addressIndicators as $pattern) {
            if (preg_match($pattern, $buffer)) {
                $addressScore++;
            }
        }

        // Need at least 2 address indicators OR text longer than 40 chars (likely full address)
        $hasAddress = $addressScore >= 2 || ($bufferLen > 40 && preg_match('/\d/', $buffer));
        if (!$hasAddress) {
            $missing[] = 'address';
        }

        // ✅ BUG FIX: Emergency fallback for long text that looks like address
        // ป้องกัน Address Loop - ถ้าข้อความยาวมากพอ (≥50 ตัวอักษร) และมี phone หรือ address
        // ให้ถือว่าพอยอมรับได้ แม้ชื่อจะไม่ชัดเจน
        if (!empty($missing) && $bufferLen >= 50) {
            // Count what we have
            $hasItems = ($hasPhone ? 1 : 0) + ($hasName ? 1 : 0) + ($hasAddress ? 1 : 0);

            // If buffer is long (≥50) and has at least 2 out of 3 items, force accept
            // เพื่อไม่ให้ลูกค้าวนถามไม่จบ
            if ($hasItems >= 2 || $bufferLen >= 80) {
                Logger::info('[ADDRESS_VALIDATE] Emergency fallback accepted - long text', [
                    'buffer_len' => $bufferLen,
                    'has_items' => $hasItems,
                ]);
                $missing = []; // Clear missing - accept as complete
                $hasName = true;
                $hasAddress = true;
            }
        }

        $isComplete = empty($missing);

        Logger::info('[ADDRESS_VALIDATE]', [
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

    protected function containsAny(string $haystackLower, array $needles): bool
    {
        foreach ($needles as $n) {
            $n = mb_strtolower(trim((string) $n), 'UTF-8');
            if ($n !== '' && mb_stripos($haystackLower, $n, 0, 'UTF-8') !== false)
                return true;
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
        $confidence = (float) ($parsed['confidence'] ?? 0.5);
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

        $payload = ['requests' => [['image' => $imagePayload, 'features' => $features]]];

        $url = $endpoint . '?key=' . urlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
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
            if (mb_stripos($name, 'ผ่อน', 0, 'UTF-8') !== false) {
                $suggestedRoute = 'installment_flow';
                break;
            }
            if (mb_stripos($name, 'คิว', 0, 'UTF-8') !== false) {
                $suggestedRoute = 'booking';
                break;
            }
            if (mb_stripos($name, 'ราคา', 0, 'UTF-8') !== false || mb_stripos($name, 'มีไหม', 0, 'UTF-8') !== false) {
                $suggestedRoute = 'product_availability';
                break;
            }
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

        $llmCfg = $botConfig['llm'] ?? [];
        $endpoint = $cfg['endpoint'] ?? 'https://api.openai.com/v1/chat/completions';
        $model = $cfg['model'] ?? ($llmCfg['model'] ?? 'gpt-4.1-mini');

        $isGemini = (stripos($endpoint, 'generativelanguage.googleapis.com') !== false);

        // Use system_prompt from config (with all the detailed rules)
        $systemPrompt = trim((string) ($llmCfg['system_prompt'] ?? ''));

        // Only use fallback if config is truly empty
        if ($systemPrompt === '') {
            $systemPrompt = 'คุณคือแอดมินร้านค้าที่ตอบลูกค้าด้วยน้ำเสียงสุภาพ เป็นกันเอง กระชับ และช่วยปิดการขายอย่างสุภาพ ตอบเป็นภาษาไทยเท่านั้น';
        }

        $persona = $botConfig['persona'] ?? [];
        if (!empty($persona)) {
            // Only append persona if not already in system_prompt
            if (stripos($systemPrompt, 'บุคลิก') === false && stripos($systemPrompt, 'persona') === false) {
                $personaParts = [];
                if (!empty($persona['tone']))
                    $personaParts[] = 'โทนการพูด: ' . $persona['tone'];
                if (!empty($persona['language']))
                    $personaParts[] = 'ภาษาในการตอบหลัก: ' . $persona['language'];
                if (!empty($persona['max_chars']))
                    $personaParts[] = 'จำกัดความยาวข้อความตอบไม่เกินประมาณ ' . (int) $persona['max_chars'] . ' ตัวอักษร';
                if ($personaParts)
                    $systemPrompt .= "\n\nข้อกำหนดบุคลิก:\n- " . implode("\n- ", $personaParts);
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
            $maxMessages = (int) ($historyCfg['max_messages'] ?? 10);

            if ($historyEnabled) {
                $history = $this->getConversationHistory((int) $sessionId, $maxMessages);
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
                'temperature' => (float) ($llmCfg['temperature'] ?? 0.6),
                'max_tokens' => (int) ($llmCfg['max_tokens'] ?? 256),
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

        // ✅ Retry logic for 503/429 errors
        $maxRetries = 2;
        $retryDelay = 500; // ms
        $raw = null;
        $err = null;
        $status = 0;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff
                usleep($retryDelay * 1000 * $attempt);
                Logger::info("Gemini/LLM API retry attempt", [
                    'attempt' => $attempt,
                    'previous_status' => $status
                ]);
            }

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => (int) ($llmCfg['timeout_seconds'] ?? 10),
            ]);
            $raw = curl_exec($ch);
            $err = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Don't retry on success or non-retryable errors
            if ($status < 400 || ($status !== 503 && $status !== 429)) {
                break;
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Logger::info("Gemini/LLM API call completed", [
            'provider' => $isGemini ? 'gemini' : 'openai',
            'duration_ms' => $duration,
            'status' => $status,
            'has_error' => !empty($err),
            'response_size' => strlen($raw),
            'retries' => $attempt
        ]);

        if ($err || $status >= 400) {
            return ['reply_text' => null, 'intent' => null, 'meta' => ['error' => $err ?: ('http_' . $status), 'raw' => $raw, 'message' => $status == 503 ? 'The model is overloaded. Please try again later.' : null]];
        }

        $data = json_decode($raw, true);

        if ($isGemini) {
            $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } else {
            $content = $data['choices'][0]['message']['content'] ?? '';
        }

        $parsed = $this->extractJsonObject($content);
        if (!is_array($parsed)) {
            // ⚠️ CRITICAL FIX: ถ้า content ดูเหมือน JSON แต่ parse ไม่ได้
            // ให้ใช้ fallback template แทนการส่ง JSON raw ไปให้ลูกค้า
            $trimmedContent = trim($content);
            if ($trimmedContent !== '' && $trimmedContent[0] === '{') {
                // Content looks like JSON but failed to parse - use fallback
                Logger::warning('[LLM] Content looks like JSON but parse failed - using fallback', [
                    'content_preview' => mb_substr($content, 0, 200, 'UTF-8'),
                ]);
                return [
                    'reply_text' => null,  // Return null to trigger fallback
                    'intent' => null,
                    'slots' => null,
                    'confidence' => null,
                    'next_question' => null,
                    'meta' => ['raw_response' => $data, 'parse_error' => true, 'json_like_content' => true, 'provider' => $isGemini ? 'gemini' : 'openai'],
                ];
            }
            return [
                'reply_text' => $content ?: null,
                'intent' => null,
                'slots' => null,
                'confidence' => null,
                'next_question' => null,
                'meta' => ['raw_response' => $data, 'parse_error' => true, 'provider' => $isGemini ? 'gemini' : 'openai'],
            ];
        }

        $replyText = $parsed['reply_text'] ?? null;

        // ✅ CRITICAL FIX: ถ้า reply_text เป็น JSON string ให้ดึง reply_text ออกมา
        if ($replyText !== null && is_string($replyText)) {
            $replyTextTrimmed = trim($replyText);
            // ตรวจสอบว่า reply_text เป็น JSON object ที่มี reply_text ซ้อนอยู่
            if (strlen($replyTextTrimmed) > 2 && $replyTextTrimmed[0] === '{') {
                $nestedJson = json_decode($replyTextTrimmed, true);
                if (is_array($nestedJson) && isset($nestedJson['reply_text'])) {
                    Logger::warning('[LLM] Nested JSON detected in reply_text - extracting', [
                        'original_preview' => mb_substr($replyText, 0, 100, 'UTF-8'),
                    ]);
                    $replyText = (string)$nestedJson['reply_text'];
                    // Also merge slots if present
                    if (isset($nestedJson['slots']) && is_array($nestedJson['slots'])) {
                        $parsed['slots'] = array_merge($parsed['slots'] ?? [], $nestedJson['slots']);
                    }
                    if (isset($nestedJson['intent'])) {
                        $parsed['intent'] = $nestedJson['intent'];
                    }
                }
            }
        }

        // ✅ CLEANUP: Strip any JSON object that LLM may have accidentally included in reply_text
        if ($replyText !== null && strpos($replyText, '{"reply_text"') !== false) {
            $replyText = preg_replace('/\s*\{["\']reply_text["\'].+$/s', '', $replyText);
            $replyText = trim($replyText);
            Logger::warning('[LLM] Stripped JSON from reply_text', ['cleaned' => true]);
        }

        return [
            'reply_text' => $replyText,
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
        if ($query === '')
            return [];

        $tenantUserId = $this->resolveTenantUserId($context);
        if (!$tenantUserId)
            return [];

        $customerId = $context['customer']['id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;
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
        $origNorm = $this->normalizeTextForKb($originalQuery);

        if ($queryNorm === '' && $origNorm === '')
            return [];

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
            if (!is_array($keywords))
                $keywords = [];

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
                    $kwNorm = $this->normalizeTextForKb((string) $keyword);
                    if ($kwNorm === '')
                        continue;
                    if (mb_strlen($kwNorm, 'UTF-8') < 4)
                        continue;

                    $foundEnhanced = mb_strpos($queryNorm, $kwNorm, 0, 'UTF-8') !== false;
                    $foundOriginal = mb_strpos($origNorm, $kwNorm, 0, 'UTF-8') !== false;

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

            if (count($results) >= 5)
                break;
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
                    if (!is_array($kw))
                        $kw = [];

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

                    if (count($results) >= 5)
                        break;
                }
            }
        }

        return $results;
    }

    protected function matchAdvancedKeywords(string $queryNorm, array $rules): bool
    {
        $toList = function ($v): array {
            if ($v === null)
                return [];
            if (is_string($v)) {
                $v = trim($v);
                return $v === '' ? [] : [$v];
            }
            if (!is_array($v))
                return [];
            $out = [];
            foreach ($v as $item) {
                if (is_string($item)) {
                    $item = trim($item);
                    if ($item !== '')
                        $out[] = $item;
                }
            }
            return $out;
        };

        $requireAll = $toList($rules['require_all'] ?? null);
        $requireAny = $toList($rules['require_any'] ?? null);
        $excludeAny = $toList($rules['exclude_any'] ?? null);

        $hasRequireAll = count($requireAll) > 0;
        $hasRequireAny = count($requireAny) > 0;

        if (!$hasRequireAll && !$hasRequireAny)
            return false;

        if (isset($rules['min_query_len'])) {
            $minLen = (int) $rules['min_query_len'];
            $actualLen = mb_strlen($queryNorm, 'UTF-8');
            if ($actualLen < $minLen)
                return false;
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
            if ($requiredNorm !== '' && !$found)
                return false;
        }

        if ($hasRequireAny) {
            $foundAny = false;
            foreach ($requireAny as $anyKeyword) {
                $anyNorm = $this->normalizeTextForKb($anyKeyword);
                $found = ($anyNorm !== '' && mb_strpos($queryNorm, $anyNorm, 0, 'UTF-8') !== false);
                if ($found) {
                    $foundAny = true;
                    break;
                }
            }
            if (!$foundAny)
                return false;
        }

        return true;
    }

    // ✅ FIXED: pending เฉพาะ "require_all ครบ" และ "สั้นกว่า min_query_len"
    protected function isAdvancedPendingMatch(string $queryNorm, array $rules): bool
    {
        $toList = function ($v): array {
            if ($v === null)
                return [];
            if (is_string($v)) {
                $v = trim($v);
                return $v === '' ? [] : [$v];
            }
            if (!is_array($v))
                return [];
            $out = [];
            foreach ($v as $item) {
                if (is_string($item)) {
                    $item = trim($item);
                    if ($item !== '')
                        $out[] = $item;
                }
            }
            return $out;
        };

        $requireAll = $toList($rules['require_all'] ?? null);
        $excludeAny = $toList($rules['exclude_any'] ?? null);

        if (empty($requireAll))
            return false;

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
            $minLen = (int) $rules['min_query_len'];
            $actual = mb_strlen($queryNorm, 'UTF-8');
            return $actual < $minLen;
        }

        return false;
    }

    protected function hasAdvancedKbPending(array $context, string $query): bool
    {
        $tenantUserId = $this->resolveTenantUserId($context);
        if (!$tenantUserId)
            return false;

        $qNorm = $this->normalizeTextForKb($query);
        if ($qNorm === '')
            return false;

        $sql = "SELECT id, keywords
            FROM customer_knowledge_base
            WHERE user_id = ?
              AND is_active = 1
              AND is_deleted = 0
            ORDER BY priority DESC";
        $rows = $this->db->query($sql, [$tenantUserId]);

        foreach ($rows as $row) {
            $kw = json_decode($row['keywords'] ?? '[]', true);
            if (!is_array($kw))
                continue;

            $isAdvanced = isset($kw['mode']) && $kw['mode'] === 'advanced';
            if (!$isAdvanced)
                continue;

            if ($this->matchAdvancedKeywords($qNorm, $kw))
                continue;

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
        $enabled = (bool) ($bufferingCfg['kb_enabled'] ?? true);
        if (!$enabled)
            return $currentText;

        $windowSec = (int) ($bufferingCfg['kb_window_seconds'] ?? 25);
        $maxMessages = (int) ($bufferingCfg['kb_max_messages'] ?? 2);

        $windowSec = max(5, min(300, $windowSec));
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
            $role = (string) ($r['role'] ?? '');
            $t = trim((string) ($r['text'] ?? ''));

            if ($t === '')
                continue;
            if (stripos($t, '[image]') === 0)
                continue;

            if ($role === 'assistant') {
                if (mb_stripos($t, '[kb_pending]') === 0) {
                    continue;
                }
                break;
            }

            if ($role === 'user') {
                if ($t === $currentText)
                    continue;

                $collected[] = $t;
                $countUser++;
                if ($countUser >= ($maxMessages - 1))
                    break;
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
        $channel = $context['channel'] ?? [];

        $uid =
            ($botProfile['user_id'] ?? null)
            ?: ($channel['user_id'] ?? null)
            ?: ($context['tenant_user_id'] ?? null)
            ?: ($context['user_id'] ?? null);

        if (!$uid)
            return null;
        return (int) $uid;
    }

    protected function decodeJsonArray(?string $json): array
    {
        if (!$json)
            return [];
        $tmp = json_decode($json, true);
        return is_array($tmp) ? $tmp : [];
    }

    /**
     * Find pending orders for a customer by external_user_id
     * Used for smart slip detection - when customer sends slip without product context
     * 
     * @param string $externalUserId Platform user ID (LINE/Facebook)
     * @param int|null $channelId    Optional channel filter
     * @param float|null $amount     Optional amount to exclude exact matches (already handled)
     * @return array List of pending orders
     */
    protected function findPendingOrdersForCustomer(string $externalUserId, ?int $channelId = null, ?float $amount = null): array
    {
        try {
            // Query orders via customer_profiles link
            $sql = "
                SELECT 
                    o.id,
                    o.order_number,
                    o.total_amount,
                    o.paid_amount,
                    (o.total_amount - COALESCE(o.paid_amount, 0)) as balance,
                    o.status,
                    o.product_name,
                    o.created_at
                FROM orders o
                JOIN customer_profiles cp ON o.customer_id = cp.id
                WHERE cp.platform_user_id = :external_id
                AND o.status IN ('pending_payment', 'awaiting_payment', 'partial', 'confirmed')
                AND o.created_at > DATE_SUB(NOW(), INTERVAL 60 DAY)
            ";

            $params = [':external_id' => $externalUserId];

            // Optionally exclude orders with exact amount match (already auto-matched)
            if ($amount !== null && $amount > 0) {
                $sql .= " AND o.total_amount != :amount";
                $params[':amount'] = $amount;
            }

            $sql .= " ORDER BY o.created_at DESC LIMIT 5";

            $orders = $this->db->queryAll($sql, $params);

            Logger::info('[SMART_SLIP] findPendingOrdersForCustomer', [
                'external_user_id' => $externalUserId,
                'found_count' => count($orders),
            ]);

            return $orders ?: [];

        } catch (\Exception $e) {
            Logger::error('[SMART_SLIP] findPendingOrdersForCustomer failed', [
                'error' => $e->getMessage(),
                'external_user_id' => $externalUserId,
            ]);
            return [];
        }
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
        $jsonEnd = strrpos($trimmed, '}');
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
            $val = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
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
                $result = str_replace($placeholder, (string) $value, $result);
            }
        }

        return $result;
    }

    protected function normalizePhone(string $s): string
    {
        $s = preg_replace('/[^\d]/', '', $s);
        if (!$s)
            return '';
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
        if (!is_array($keywords))
            return false;

        foreach ($keywords as $kw) {
            $kw = mb_strtolower(trim((string) $kw), 'UTF-8');
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
                '15,900',
                '15900',
                '3,900',
                '3900',  // Plan 1
                '79,000',
                '79000',  // Plan 2
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
                    if ($cleanNum < 100)
                        continue;

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
            $k = (string) ($policy['no_backend_reply_template_key'] ?? 'no_backend_product_check');
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
                    $p = trim((string) $p);
                    if ($p !== '' && mb_strpos($reply, $p) !== false) {
                        $k = (string) ($policy['no_backend_reply_template_key'] ?? 'no_backend_product_check');
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
            'ร้านชื่ออะไร',
            'ชื่อร้าน',
            'ชื่อร้านอะไร',
            'ขอรายละเอียดร้าน',
            'รายละเอียดร้าน',
            'ร้านนี้ขายอะไร',
            'ขายอะไร',
            'ร้านทำอะไร',
            'ข้อมูลร้าน',
            'ขอข้อมูลร้าน',
            'contact',
            'ติดต่อร้าน',
            'ช่องทางติดต่อ'
        ];
        foreach ($keys as $k) {
            if (mb_stripos($t, $k, 0, 'UTF-8') !== false)
                return true;
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
            return $result ? (int) $result['id'] : null;
        } catch (Throwable $e) {
            Logger::warning('[ROUTER_V1] Failed to get customer profile: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================
    // ✅ Check if slots have valid product context (required_any pattern)
    // Returns true if we have meaningful product data to show user
    // =========================================================
    protected function hasValidProductContext(array $slots, array $options = []): bool
    {
        // Default: required_any = at least one of these must be non-empty/non-zero
        $requiredAny = $options['required_any'] ?? ['product_name', 'product_price', 'product_code'];
        // Optional: required_all = all of these must be present (stricter)
        $requiredAll = $options['required_all'] ?? [];
        
        // Check required_all first (if specified)
        foreach ($requiredAll as $field) {
            $value = $slots[$field] ?? null;
            if ($this->isEmptyValue($value)) {
                return false;
            }
        }
        
        // Check required_any - at least one must be valid
        $hasAny = false;
        foreach ($requiredAny as $field) {
            $value = $slots[$field] ?? null;
            if (!$this->isEmptyValue($value)) {
                $hasAny = true;
                break;
            }
        }
        
        return $hasAny;
    }
    
    // Helper to check if value is "empty" (null, '', 0, [], etc.)
    protected function isEmptyValue($value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        if (is_numeric($value) && (float)$value <= 0) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        return false;
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