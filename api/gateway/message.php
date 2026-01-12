<?php
// filepath: /opt/lampp/htdocs/autobot/api/gateway/message.php

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/bot/BotHandlerFactory.php';
require_once __DIR__ . '/../../includes/bot/MessageBuffer.php';

header('Content-Type: application/json');

// CORS ง่าย ๆ เผื่อยิงจาก n8n
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        Response::error('Invalid JSON body', 400);
    }

    $inboundKey = $data['inbound_api_key'] ?? null;
    if (!$inboundKey) {
        // รองรับกรณีส่งมาใน header แบบ Bearer ch_xxx
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) {
            $inboundKey = trim(substr($auth, 7));
        }
    }
    if (!$inboundKey) {
        Response::error('Missing inbound_api_key', 400);
    }

    $db = Database::getInstance();

    // 1) หา channel จาก inbound_api_key
    $channel = $db->queryOne(
        'SELECT c.*, u.email, u.full_name, u.status as user_status
         FROM customer_channels c
         JOIN users u ON c.user_id = u.id
         WHERE c.inbound_api_key = ? AND c.status = "active" AND c.is_deleted = 0',
        [$inboundKey]
    );
    if (!$channel) {
        // Security: Log invalid API key attempts
        Logger::warning('Invalid API key attempt', [
            'key_prefix' => substr($inboundKey, 0, 8) . '...',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        Response::error('Invalid or inactive API key', 401);
    }

    $userId = (int)$channel['user_id'];

    // ✅ Enhanced subscription validation
    $subscription = $db->queryOne(
        "SELECT s.*, sp.name as plan_name
         FROM subscriptions s
         JOIN subscription_plans sp ON s.plan_id = sp.id
         WHERE s.user_id = ? 
           AND s.status = 'active'
           AND s.current_period_end >= CURDATE()
        ",
        [$userId]
    );
    
    if (!$subscription) {
        // Check if expired or just inactive
        $expiredSub = $db->queryOne(
            "SELECT id FROM subscriptions 
             WHERE user_id = ? AND current_period_end < CURDATE()",
            [$userId]
        );
        
        if ($expiredSub) {
            Response::error('Subscription expired. Please renew to continue using the service.', 402);
        } else {
            Response::error('No active subscription found for this customer', 402);
        }
    }

    // ✅ Check for overdue invoices
    $unpaidInvoice = $db->queryOne(
        "SELECT id, invoice_number, total, due_date 
         FROM invoices 
         WHERE user_id = ? 
           AND status IN ('pending', 'failed')
           AND due_date < CURDATE()
         ORDER BY due_date ASC
         LIMIT 1",
        [$userId]
    );
    
    if ($unpaidInvoice) {
        Logger::warning('API access blocked - overdue invoice', [
            'user_id' => $userId,
            'invoice_number' => $unpaidInvoice['invoice_number']
        ]);
        Response::error('You have overdue invoices. Please pay before using the service.', 402);
    }

    // Optional idempotency: use event_id / external_event_id per channel
    $eventId = $data['event_id']
        ?? ($data['metadata']['event_id'] ?? ($data['metadata']['message_id'] ?? null));

    if ($eventId) {
        // Ensure table exists in schema: gateway_message_events
        $existing = $db->queryOne(
            "SELECT response_payload
             FROM gateway_message_events
             WHERE channel_id = ? AND external_event_id = ?",
            [$channel['id'], $eventId]
        );
        if ($existing && !empty($existing['response_payload'])) {
            $payload = json_decode($existing['response_payload'], true);
            if ($payload) {
                Response::success($payload);
            }
        }

        // If record exists but no payload, treat as duplicate/in-flight and stop processing
        if ($existing) {
            Logger::info('Duplicate/in-flight gateway event; short-circuit', [
                'channel_id' => $channel['id'],
                'external_event_id' => $eventId,
            ]);
            Response::success([
                'reply_text' => null,
                'actions' => [],
                'meta' => ['dedup' => 'duplicate_in_flight', 'event_id' => $eventId],
            ]);
        }

        // Create a placeholder event record early to prevent double-processing.
        // If another request races, it will hit UNIQUE constraint and will be treated as duplicate.
        try {
            $db->execute(
                "INSERT INTO gateway_message_events (channel_id, external_event_id, created_at)
                 VALUES (?, ?, NOW())",
                [$channel['id'], $eventId]
            );
        } catch (Exception $e) {
            // Best-effort duplicate detection without relying on vendor-specific error codes
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'uniq_') !== false || stripos($msg, 'unique') !== false) {
                Logger::info('Duplicate gateway event (unique key hit); short-circuit', [
                    'channel_id' => $channel['id'],
                    'external_event_id' => $eventId,
                ]);
                Response::success([
                    'reply_text' => null,
                    'actions' => [],
                    'meta' => ['dedup' => 'duplicate', 'event_id' => $eventId],
                ]);
            }
            throw $e;
        }
    }

    // 2) โหลดลูกค้า (optional ตอนนี้ใช้แค่ id/email)
    $customer = $db->queryOne('SELECT id, email, full_name, status FROM users WHERE id = ?', [$userId]);
    if (!$customer) {
        Response::error('Customer not found for this channel', 404);
    }

    // 3) หา bot profile ที่จะใช้
    $botProfileId = $channel['bot_profile_id'] ?? null;
    if ($botProfileId) {
        $botProfile = $db->queryOne(
            'SELECT * FROM customer_bot_profiles WHERE id = ? AND is_deleted = 0 AND is_active = 1',
            [$botProfileId]
        );
    } else {
        $botProfile = $db->queryOne(
            'SELECT * FROM customer_bot_profiles WHERE user_id = ? AND is_deleted = 0 AND is_active = 1 AND is_default = 1 ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    if (!$botProfile) {
        // ไม่มี profile ใช้ default config ว่าง ๆ
        $botProfile = [
            'id' => null,
            'user_id' => $userId,
            'name' => 'Default',
            'handler_key' => 'router_v1',
            'config' => null,
        ];
    }

    $handlerKey = $botProfile['handler_key'] ?? 'router_v1';

    // Decode bot_profile config once (for buffering + handler)
    $botConfig = [];
    if (!empty($botProfile['config'])) {
        $tmp = json_decode($botProfile['config'], true);
        if (is_array($tmp)) {
            $botConfig = $tmp;
        }
    }

    // 4) โหลด integrations ทั้งหมดของลูกค้า (ใช้ภายหลังเมื่อเชื่อม Google NLP/Vision จริง)
    $integrations = $db->query(
        'SELECT * FROM customer_integrations WHERE user_id = ? AND is_deleted = 0 AND is_active = 1',
        [$userId]
    );
    $integrationsByProvider = [];
    foreach ($integrations as $row) {
        $integrationsByProvider[$row['provider']][] = $row;
    }

    // 5) เตรียม message context ให้ handler (ก่อน buffer ปรับตาม combined_text ทีหลังได้)
    $incoming = [
        'channel_type' => $data['channel_type'] ?? ($channel['type'] ?? null),
        'external_user_id' => $data['external_user_id'] ?? null,
        'message_type' => $data['message_type'] ?? ($data['type'] ?? 'text'),
        'text' => $data['text'] ?? '',
        'attachments' => $data['attachments'] ?? [],
        'metadata' => $data['metadata'] ?? [],
        'is_admin' => $data['is_admin'] ?? false,  // ✅ CRITICAL: Forward admin flag from webhook
    ];

    // เติม event_id ลง message object ไว้ใช้ใน buffer
    if ($eventId) {
        $incoming['event_id'] = $eventId;
    }

    // ---------------------------------------------------------------------
    // ✅ Human handoff: stop bot replying for 10 minutes after admin replies
    // Requirement: admin UI must log human replies into bot_chat_logs with
    //   direction='outgoing' and metadata JSON containing {"source":"admin"}
    // Fallback: if a system marker exists in chat_messages, also respect it.
    // ---------------------------------------------------------------------
    $handoffMinutes = (int)(getenv('HUMAN_HANDOFF_MINUTES') ?: 10);
    $externalUserIdForHandoff = $incoming['external_user_id'] ?? null;
    if (!empty($externalUserIdForHandoff)) {
        $handoffCutoff = date('Y-m-d H:i:s', strtotime('-' . $handoffMinutes . ' minutes'));

        // Primary check: bot_chat_logs metadata.source = 'admin'
        $humanRecent = $db->queryOne(
            "SELECT id, created_at
             FROM bot_chat_logs
             WHERE customer_service_id = ?
               AND platform_user_id = ?
               AND direction = 'outgoing'
               AND created_at >= ?
               AND (
                    (
                        metadata IS NOT NULL
                        AND (
                            JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.source')) = 'admin'
                            OR JSON_EXTRACT(metadata, '$.source') = 'admin'
                        )
                    )
                    OR (message_type = 'human')
               )
             ORDER BY created_at DESC
             LIMIT 1",
            [$channel['id'], $externalUserIdForHandoff, $handoffCutoff]
        );

        // Fallback check: system marker in chat_messages (optional)
        if (!$humanRecent) {
            try {
                $humanRecent = $db->queryOne(
                    "SELECT cm.id, cm.created_at
                     FROM chat_messages cm
                     JOIN chat_sessions cs ON cs.id = cm.session_id
                     WHERE cs.channel_id = ?
                       AND cs.external_user_id = ?
                       AND cm.role = 'system'
                       AND cm.text = 'HUMAN_HANDOFF'
                       AND cm.created_at >= ?
                     ORDER BY cm.created_at DESC
                     LIMIT 1",
                    [$channel['id'], $externalUserIdForHandoff, $handoffCutoff]
                );
            } catch (Exception $ignore) {
                // chat_messages may not exist in some deployments; ignore
            }
        }

        if ($humanRecent) {
            Logger::info('Human handoff active; suppressing bot reply', [
                'channel_id' => $channel['id'],
                'external_user_id' => $externalUserIdForHandoff,
                'handoff_minutes' => $handoffMinutes,
                'since' => $handoffCutoff,
            ]);

            Response::success([
                'reply_text' => null,
                'actions' => [],
                'meta' => [
                    'handoff' => [
                        'active' => true,
                        'minutes' => $handoffMinutes,
                    ],
                ],
            ]);
        }
    }

    // 5.1 Message Buffer + Debounce (Strategy A: flush inline)
    $bufferConfig = $botConfig['buffering'] ?? [];
    $bufferEnabled = $bufferConfig['enabled'] ?? false; // ปิดเป็น default เพื่อให้ตอบทันที (สามารถเปิดได้ใน config)

    // ให้บางประเภทข้อความ / keyword bypass buffer
    if (
        $bufferEnabled
        && !empty($incoming['external_user_id'])
    ) {
        $msgBuffer = new MessageBuffer($db);

        if (!$msgBuffer->shouldBypass($incoming, $bufferConfig)) {
            $bufferResult = $msgBuffer->handle($channel, $incoming['external_user_id'], $incoming, $bufferConfig);

            if ($bufferResult['action'] === 'buffered') {
                // ยังไม่ถึงเวลา flush → ไม่ตอบอะไรจริงจัง แค่ success เปล่า หรือ optional meta
                Response::success([
                    'reply_text' => null,
                    'actions' => [],
                    'meta' => [
                        'handler' => 'router_v1',
                        'buffering' => [
                            'status' => 'buffered',
                        ],
                    ],
                ]);
            } elseif ($bufferResult['action'] === 'flush' && !empty($bufferResult['combined_text'])) {
                // ใช้ข้อความรวมทั้งหมดแทนข้อความเดียวล่าสุด
                $incoming['text'] = $bufferResult['combined_text'];
            }
        }
    }

    // Get or create session for conversation history tracking
    $sessionId = null;
    $externalUserId = $incoming['external_user_id'] ?? null;
    if ($externalUserId) {
        // Use RouterV1Handler's session logic temporarily (should ideally be extracted to a service)
        require_once __DIR__ . '/../../includes/bot/RouterV1Handler.php';
        $tempHandler = new RouterV1Handler();
        $sessionData = $tempHandler->findOrCreateSession($channel['id'], $externalUserId);
        $sessionId = $sessionData['id'] ?? null;
    }

    $context = [
        'customer' => $customer,
        'channel' => $channel,
        'bot_profile' => $botProfile,
        'integrations' => $integrationsByProvider,
        'message' => $incoming,
        'session_id' => $sessionId,  // For conversation history
        'external_user_id' => $externalUserId,  // For reference
        'is_admin' => $incoming['is_admin'] ?? false,  // ✅ CRITICAL: Pass admin flag to Router
        'platform' => $incoming['metadata']['platform'] ?? $incoming['channel_type'] ?? ($channel['platform'] ?? 'unknown'),  // ✅ Platform for case creation
    ];

    // ---- Trace id for correlating gateway <-> webhook logs ----
    $traceId = bin2hex(random_bytes(8));
    Logger::info('[GATEWAY] request_start', [
        'trace_id' => $traceId,
        'channel_id' => $channel['id'] ?? null,
        'platform' => $incoming['channel_type'] ?? ($channel['platform'] ?? null),
        'external_user_id' => $incoming['external_user_id'] ?? null,
        'message_type' => $incoming['message_type'] ?? null,
        'event_id' => $eventId ?? null,
    ]);

    // Attach trace_id to context so RouterV1 can log it too
    $context['trace_id'] = $traceId;

    Logger::info('[GATEWAY] handler_dispatch', [
        'trace_id' => $traceId,
        'handler_key' => $handlerKey ?? null,
        'bot_profile_id' => $botProfile['id'] ?? null,
        'bot_profile_name' => $botProfile['name'] ?? null,
        'has_bot_config' => !empty($botConfig),
        'incoming_text_len' => isset($incoming['text']) ? mb_strlen((string)$incoming['text'], 'UTF-8') : null,
        'incoming_text_preview' => isset($incoming['text']) ? mb_substr((string)$incoming['text'], 0, 120, 'UTF-8') : null,
        'incoming_metadata_keys' => isset($incoming['metadata']) && is_array($incoming['metadata']) ? array_keys($incoming['metadata']) : null,
    ]);

    // 6) เรียก handler with timeout
    set_time_limit(30); // Max 30 seconds per request for Cloud Run
    $handler = BotHandlerFactory::get($handlerKey);

    $t0 = microtime(true);
    $result = $handler->handleMessage($context);
    $elapsedMs = (int)round((microtime(true) - $t0) * 1000);

    Logger::info('[GATEWAY] handler_result', [
        'trace_id' => $traceId,
        'elapsed_ms' => $elapsedMs,
        'result_type' => gettype($result),
        'has_reply_text_key' => is_array($result) ? array_key_exists('reply_text', $result) : false,
        'reply_text_len' => (is_array($result) && isset($result['reply_text']) && $result['reply_text'] !== null)
            ? mb_strlen((string)$result['reply_text'], 'UTF-8')
            : null,
        'actions_count' => (is_array($result) && isset($result['actions']) && is_array($result['actions']))
            ? count($result['actions'])
            : null,
        'meta_keys' => (is_array($result) && isset($result['meta']) && is_array($result['meta']))
            ? array_keys($result['meta'])
            : null,
    ]);

    // ✅ NEW: Auto-split reply_text into reply_texts array for human-like conversation
    // This allows LLM to split long responses using ||SPLIT|| delimiter
    // without modifying every return point in handlers
    $replyText = $result['reply_text'] ?? null;
    $replyTexts = [];
    
    if ($replyText !== null && $replyText !== '') {
        // Check if response contains split delimiter
        if (strpos($replyText, '||SPLIT||') !== false) {
            // Split and clean
            $messages = explode('||SPLIT||', $replyText);
            foreach ($messages as $msg) {
                $msg = trim($msg);
                if ($msg !== '') {
                    $replyTexts[] = $msg;
                }
            }
            
            Logger::info('[GATEWAY] Multi-message detected', [
                'trace_id' => $traceId,
                'original_len' => mb_strlen($replyText, 'UTF-8'),
                'split_count' => count($replyTexts),
            ]);
        } else {
            // Single message
            $replyTexts = [$replyText];
        }
    }

    $payload = [
        'reply_text' => $replyText,  // Keep original for backward compatibility
        'reply_texts' => $replyTexts, // ✅ NEW: Array for multi-message support
        'actions' => $result['actions'] ?? [],
        'meta' => $result['meta'] ?? [],
    ];

    // Always include trace_id in final response meta for cross-service correlation
    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = [];
    }
    $payload['meta']['trace_id'] = $traceId;

    // Store response payload for idempotency (best-effort)
    if ($eventId) {
        try {
            $db->execute(
                "UPDATE gateway_message_events
                 SET response_payload = ?
                 WHERE channel_id = ? AND external_event_id = ?",
                [json_encode($payload, JSON_UNESCAPED_UNICODE), $channel['id'], $eventId]
            );
        } catch (Exception $ignore) {
            // avoid breaking message flow due to dedup storage failure
        }
    }

    // Log incoming message to bot_chat_logs for usage tracking
    try {
        $db->execute(
            "INSERT INTO bot_chat_logs 
             (customer_service_id, platform_user_id, direction, message_type, message_content, metadata, created_at)
             VALUES (?, ?, 'incoming', ?, ?, ?, NOW())",
            [
                $channel['id'],
                $incoming['external_user_id'] ?? 'unknown',
                $incoming['message_type'] ?? 'text',
                substr($incoming['text'] ?? '', 0, 1000), // Limit to 1000 chars
                json_encode($incoming['metadata'] ?? [])
            ]
        );
    } catch (Exception $e) {
        // Don't fail the request if logging fails
        Logger::error('Failed to log message: ' . $e->getMessage());
    }

    // Store idempotent record
    if ($eventId) {
        $db->execute(
            "INSERT INTO gateway_message_events (channel_id, external_event_id, response_payload, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE response_payload = VALUES(response_payload)",
            [$channel['id'], $eventId, json_encode($result)]
        );
    }

    if (!is_array($result) || !array_key_exists('reply_text', $result)) {
        Logger::error('[GATEWAY] invalid_handler_result', [
            'trace_id' => $traceId,
            'handler_key' => $handlerKey ?? null,
            'result_dump' => is_scalar($result) ? (string)$result : json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);
        Response::error('Handler did not return a valid result', 500);
    }

    Logger::info('[GATEWAY] request_success', [
        'trace_id' => $traceId,
        'reply_text_present' => !empty($payload['reply_text']),
        'actions_count' => is_array($payload['actions']) ? count($payload['actions']) : null,
    ]);

    Response::success($payload);
} catch (Exception $e) {
    Logger::error('Gateway message error: ' . $e->getMessage(), [
        'trace_id' => $traceId ?? null,
        'exception_class' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    Response::error('Server error', 500);
}
