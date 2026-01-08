<?php
/**
 * Facebook Messenger Webhook Handler (Multi-page / Multi-app safe)
 * - Verify webhook (GET)
 * - Verify signature (POST) using app_secret(s) from DB/env
 * - Route message to internal gateway
 * - Send reply back via Page Access Token
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        // Webhook Verification
        handleVerification();
        exit;
    }

    if ($method === 'POST') {
        handleIncomingMessage();
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    Logger::error('Facebook webhook fatal error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

/**
 * Facebook Webhook Verification
 * expects: hub.mode, hub.verify_token, hub.challenge
 */
function handleVerification(): void
{
    $mode      = $_GET['hub.mode'] ?? '';
    $token     = $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub.challenge'] ?? '';

    $expectedToken = getenv('FACEBOOK_VERIFY_TOKEN') ?: 'autobot_verify_2024';

    if ($mode === 'subscribe' && hash_equals($expectedToken, $token)) {
        http_response_code(200);
        echo $challenge;
        return;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Verification failed']);
}

/**
 * Handle incoming webhook POST
 */
function handleIncomingMessage(): void
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }

    // Verify signature first
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $matchedSecret = verifySignatureMulti($raw, $signature);
    if ($matchedSecret === null) {
        Logger::warning('Facebook webhook signature verification failed');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid signature']);
        return;
    }

    // Process entries
    if (!isset($data['entry']) || !is_array($data['entry'])) {
        http_response_code(200);
        echo json_encode(['status' => 'ignored']);
        return;
    }

    foreach ($data['entry'] as $entry) {
        // page_id ของเพจ = entry.id (ชัวร์สุด)
        $pageId = isset($entry['id']) ? (string)$entry['id'] : '';

        if (!isset($entry['messaging']) || !is_array($entry['messaging'])) {
            continue;
        }

        foreach ($entry['messaging'] as $event) {
            processMessagingEvent($event, $pageId);
        }
    }

    http_response_code(200);
    echo json_encode(['status' => 'received']);
}

/**
 * Verify signature with multiple possible app secrets:
 * - all active facebook channels (config.app_secret)
 * - env FACEBOOK_APP_SECRET (fallback)
 * Return matched secret or null if not matched.
 */
function verifySignatureMulti(string $payload, string $signature): ?string
{
    if ($signature === '' || stripos($signature, 'sha256=') !== 0) {
        Logger::warning('Signature missing or invalid format');
        return null;
    }

    $secrets = [];

    // 1) load from DB (all active facebook channels)
    $db = Database::getInstance();
    $rows = $db->query(
        "SELECT config FROM customer_channels
         WHERE type='facebook' AND status='active' AND is_deleted=0"
    );

    foreach ($rows as $r) {
        $cfg = json_decode($r['config'] ?? '{}', true);
        if (is_array($cfg)) {
            $s = trim((string)($cfg['app_secret'] ?? ''));
            if ($s !== '') $secrets[$s] = true;
        }
    }

    // 2) fallback env
    $envSecret = trim((string)(getenv('FACEBOOK_APP_SECRET') ?: ''));
    if ($envSecret !== '') $secrets[$envSecret] = true;

    if (empty($secrets)) {
        Logger::error('No Facebook app_secret found (DB/env)');
        return null;
    }

    foreach (array_keys($secrets) as $secret) {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        if (hash_equals($expected, $signature)) {
            Logger::info('Signature verification: SUCCESS');
            return $secret;
        }
    }

    Logger::warning('Signature verification: FAILED');
    return null;
}

/**
 * Process one messaging event
 */
function processMessagingEvent(array $event, string $entryPageId): void
{
    // ✅ DEBUG: Log ALL events (not just messages)
    Logger::info('[FB_WEBHOOK_EVENT]', [
        'event_keys' => array_keys($event),
        'has_message' => isset($event['message']),
        'has_delivery' => isset($event['delivery']),
        'has_read' => isset($event['read']),
        'has_postback' => isset($event['postback']),
        'sender_id' => $event['sender']['id'] ?? null,
        'entry_page_id' => $entryPageId,
    ]);

    // Check for messages
    if (!isset($event['message']) || !is_array($event['message'])) {
        Logger::info('[FB_WEBHOOK] Event ignored (not a message)', [
            'event_type' => array_keys($event)[0] ?? 'unknown',
        ]);
        return;
    }
    
    // Extract IDs first
    $senderId    = isset($event['sender']['id']) ? (string)$event['sender']['id'] : '';
    $recipientId = isset($event['recipient']['id']) ? (string)$event['recipient']['id'] : '';
    
    // ใช้ pageId จาก entry.id ก่อน (ชัวร์สุด) ถ้าไม่มีค่อย fallback recipient.id
    $pageId = $entryPageId !== '' ? $entryPageId : $recipientId;
    
    // ✅ Detect admin messages - 2 ways:
    // 1) is_echo flag (when replying via Page Inbox)
    // 2) sender_id === page_id (when Page sends message)
    $isEcho = !empty($event['message']['is_echo']);
    $isAdmin = $isEcho || ($senderId === $pageId);

    $text      = (string)($event['message']['text'] ?? '');
    $messageId = (string)($event['message']['mid'] ?? '');

    if ($senderId === '' || $pageId === '') return;

    // ✅ CRITICAL FIX: Admin Handoff Detection at Webhook Level
    // When staff/admin sends a message from Facebook Business Suite containing "admin"
    // → Pause bot for 1 hour by updating last_admin_message_at in database
    // NOTE: Must find channel FIRST before checking admin command
    
    // Find channel by page_id (needed for admin handoff)
    $channel = findFacebookChannelByPageId($pageId);
    if ($channel === null) {
        Logger::warning("No active Facebook channel found for page_id={$pageId}");
        return;
    }
    
    $isAdminCommand = false;
    if ($isAdmin && $text !== '') {  // ✅ Check if message is FROM the page (human staff OR automation bot)
        $textLower = mb_strtolower(trim($text), 'UTF-8');
        if (preg_match('/^(?:\/admin|#admin|admin)(?:\s|$)/u', $textLower)) {
            $isAdminCommand = true;
            
            Logger::info('[FB_WEBHOOK] 🚨 ADMIN HANDOFF TRIGGERED!', [
                'text' => $text,
                'sender_id' => $senderId,
                'page_id' => $pageId,
                'is_echo' => $isEcho,
                'sender_is_page' => ($senderId === $pageId),
                'is_admin' => $isAdmin,
                'channel_id' => $channel['id'],
                'action' => 'Pausing bot for 1 hour',
            ]);
            
            // ✅ Update database immediately - pause bot for this user
            // NOTE: We need to find the session for the RECIPIENT (customer), not sender (page)
            // When is_echo=true, recipient is the customer who will receive the reply
            try {
                $db = Database::getInstance();
                
                // Get recipient from event (the customer)
                $customerId = $recipientId; // When echo, recipient = customer
                
                // Find or create session for this customer
                $sessionSql = "SELECT id FROM chat_sessions 
                              WHERE channel_id = ? AND external_user_id = ? 
                              ORDER BY created_at DESC LIMIT 1";
                $session = $db->queryOne($sessionSql, [(int)$channel['id'], $customerId]);
                
                if ($session) {
                    $sessionId = (int)$session['id'];
                    $db->execute(
                        'UPDATE chat_sessions SET last_admin_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                        [$sessionId]
                    );
                    
                    Logger::info('[FB_WEBHOOK] ✅ Admin handoff activated', [
                        'session_id' => $sessionId,
                        'channel_id' => $channel['id'],
                        'customer_id' => $customerId,
                        'paused_until' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                    ]);
                } else {
                    Logger::warning('[FB_WEBHOOK] No session found for admin handoff - will create on next customer message', [
                        'channel_id' => $channel['id'],
                        'customer_id' => $customerId,
                    ]);
                }
            } catch (Exception $e) {
                Logger::error('[FB_WEBHOOK] Failed to activate admin handoff: ' . $e->getMessage());
            }
            
            // Don't send this message to gateway - it's an admin command, not a real conversation
            return;
        }
    }

    // ✅ DEBUG: Log admin detection
    Logger::info('[FB_WEBHOOK] Message received', [
        'page_id' => $pageId,
        'sender_id' => $senderId,
        'is_echo' => $isEcho,
        'sender_is_page' => ($senderId === $pageId),
        'is_admin' => $isAdmin,
        'is_admin_command' => $isAdminCommand,
        'text_preview' => substr($text, 0, 50),
    ]);

    Logger::info("Extracted page_id={$pageId}");

    // Channel already loaded above (for admin handoff check)
    // No need to load again

    // ✅ Deduplication: Check if we've already processed this message
    // ใช้ gateway_message_events ที่มีอยู่แล้ว แทนการสร้างตารางใหม่
    if ($messageId !== '' && isMessageAlreadyProcessed((int)$channel['id'], $messageId)) {
        Logger::info("Facebook webhook: Duplicate message detected (mid=$messageId, channel_id={$channel['id']}), skipping");
        return;
    }

    // Per-webhook trace id for correlation (also copied to gateway payload metadata)
    $traceId = bin2hex(random_bytes(8));

    $gatewayPayload = [
        'inbound_api_key'   => $channel['inbound_api_key'],
        'channel_type'      => 'facebook',
        'external_user_id'  => $senderId,
        'message_type'      => 'text',
        'text'              => $text,
        'is_admin'          => $isAdmin,  // ✅ Use combined detection
        'metadata' => [
            'message_id' => $messageId,
            'page_id'    => $pageId,
            'platform'   => 'facebook',
            // Useful for debugging after switching bot profile handler
            'bot_profile_id' => $channel['bot_profile_id'] ?? null,
            'trace_id' => $traceId,
            'is_echo' => $isEcho,
            'sender_is_page' => ($senderId === $pageId),
        ],
    ];

    Logger::info('[FB_WEBHOOK] Gateway call start', [
        'trace_id' => $traceId,
        'page_id' => $pageId ?? null,
        'sender_id' => $senderId ?? null,
        'message_id' => $messageId ?? null,
        'text_len' => mb_strlen((string)$text, 'UTF-8'),
        'text_preview' => mb_substr((string)$text, 0, 120, 'UTF-8'),
    ]);

    $t0 = microtime(true);
    $resp = callGateway($gatewayPayload);
    $elapsedMs = (int)round((microtime(true) - $t0) * 1000);

    Logger::info('[FB_WEBHOOK] Gateway call finished', [
        'trace_id' => $traceId,
        'elapsed_ms' => $elapsedMs,
        'resp_type' => gettype($resp),
        'resp_success' => (is_array($resp) && isset($resp['success'])) ? (bool)$resp['success'] : null,
        'resp_keys' => is_array($resp) ? array_keys($resp) : null,
    ]);

    // ---- Robust gateway unwrapping (handle null / unexpected shapes / JSON-string bodies) ----
    if ($resp === null || (isset($resp['success']) && $resp['success'] === false)) {
        Logger::error('[FB_WEBHOOK] Gateway returned error or null', [
            'response' => $resp,
        ]);
        return;
    }

    // Some code paths may accidentally return a JSON string; normalize it.
    if (is_string($resp)) {
        $tmp = json_decode($resp, true);
        if (is_array($tmp)) {
            $resp = $tmp;
        }
    }

    // Gateway wraps response in 'data' object via Response::success()
    // Typical: {success:true, data:{reply_text, actions, meta}}
    $data = (is_array($resp) && isset($resp['data']) && is_array($resp['data'])) ? $resp['data'] : $resp;

    // Some older handlers may return {reply_text, actions} directly under root,
    // or nested deeper; keep it defensive.
    $replyText = '';
    $actions = [];
    if (is_array($data)) {
        $replyText = (string)($data['reply_text'] ?? '');
        $actions = (isset($data['actions']) && is_array($data['actions'])) ? $data['actions'] : [];
    }

    // ✅ Enhanced debug logging with FULL response
    Logger::info("[FB_WEBHOOK] Gateway response received", [
        'has_reply' => !empty($replyText),
        'actions_count' => is_array($actions) ? count($actions) : 0,
        'actions_full' => $actions,
        'response_structure' => [
            'has_data_wrapper' => (is_array($resp) && array_key_exists('data', $resp)),
            'data_is_array' => (is_array($resp) && isset($resp['data']) && is_array($resp['data'])),
            'has_reply_text' => is_array($data) ? array_key_exists('reply_text', $data) : false,
            'has_actions' => is_array($data) ? array_key_exists('actions', $data) : false,
            'actions_is_array' => is_array($actions),
            'full_resp_type' => gettype($resp),
            'full_resp_keys' => is_array($resp) ? array_keys($resp) : 'not_array',
            'full_data_type' => gettype($data),
            'full_data_keys' => is_array($data) ? array_keys($data) : 'not_array',
        ],
        'FULL_RESPONSE' => $resp,
        'FULL_DATA' => $data
    ]);

    // ✅ Deduplication audit: only write event record if not already present
    // facebook.php may do a pre-check (isMessageAlreadyProcessed). That check is race-prone.
    // Persist should be idempotent (ignore duplicate-key).
    if ($messageId !== '') {
        try {
            if (isMessageAlreadyProcessed((int)$channel['id'], $messageId)) {
                updateMessageEventPayload((int)$channel['id'], $messageId, $resp);
            } else {
                recordMessageEvent((int)$channel['id'], $messageId, $resp);
            }
        } catch (Throwable $e) {
            // Best-effort only; do not break the reply path
            Logger::warning('[FB_WEBHOOK] Persist gateway_message_events failed (ignored): ' . $e->getMessage());
        }
    }

    // ✅ NEW: Support for multi-message replies (human-like conversation)
    // Check for reply_texts array first, fallback to single reply_text
    $replyTexts = [];
    
    if (is_array($data)) {
        // Check for new format: reply_texts array
        if (!empty($data['reply_texts']) && is_array($data['reply_texts'])) {
            $replyTexts = $data['reply_texts'];
            Logger::info('[FB_WEBHOOK] Multi-message reply detected', [
                'trace_id' => $traceId,
                'message_count' => count($replyTexts),
            ]);
        }
        // Fallback to single message format
        elseif (!empty($data['reply_text'])) {
            $replyTexts = [(string)$data['reply_text']];
            Logger::info('[FB_WEBHOOK] Single message reply (legacy format)', [
                'trace_id' => $traceId,
            ]);
        }
    }

    // Send text messages with natural delays
    if (!empty($replyTexts)) {
        $messageCount = count($replyTexts);
        Logger::info('[FB_WEBHOOK] Sending ' . $messageCount . ' message(s)', [
            'trace_id' => $traceId,
            'recipient_psid' => $senderId,
        ]);
        
        foreach ($replyTexts as $index => $messageText) {
            $messageText = trim((string)$messageText);
            if ($messageText === '') {
                continue;
            }
            
            // Add natural delay between messages (except for first message)
            if ($index > 0) {
                // Random delay between 500-800ms for human-like feel
                $delayMicros = rand(500000, 800000);
                Logger::info('[FB_WEBHOOK] Delay before message #' . ($index + 1), [
                    'delay_ms' => round($delayMicros / 1000),
                ]);
                usleep($delayMicros);
            }
            
            Logger::info('[FB_WEBHOOK] Sending message #' . ($index + 1) . '/' . $messageCount, [
                'trace_id' => $traceId,
                'message_len' => mb_strlen($messageText, 'UTF-8'),
                'message_preview' => mb_substr($messageText, 0, 50, 'UTF-8'),
            ]);
            
            $ok = sendFacebookMessage($senderId, $messageText, $channel);
            
            Logger::info('[FB_WEBHOOK] Message #' . ($index + 1) . ' send result', [
                'trace_id' => $traceId,
                'ok' => $ok,
            ]);
        }
        
        Logger::info('[FB_WEBHOOK] All messages sent', [
            'trace_id' => $traceId,
            'total_sent' => $messageCount,
        ]);
    }

    // Send images if actions contain images
    if (!empty($actions) && is_array($actions)) {
        Logger::info("[FB_WEBHOOK] ✅ Processing " . count($actions) . " actions for sending");
        $imageCount = 0;
        foreach ($actions as $idx => $action) {
            if (is_array($action) && isset($action['type']) && $action['type'] === 'image' && !empty($action['url'])) {
                $imageCount++;
                Logger::info("[FB_WEBHOOK] 📸 Sending image #{$imageCount}", [
                    'action_index' => $idx,
                    'image_url' => $action['url']
                ]);
                $success = sendFacebookImage($senderId, $action['url'], $channel);
                if ($success) {
                    Logger::info("[FB_WEBHOOK] ✅ Image #{$imageCount} sent successfully");
                } else {
                    Logger::error("[FB_WEBHOOK] ❌ Image #{$imageCount} failed to send");
                }
                // Small delay to ensure proper ordering
                usleep(200000); // 200ms
            } else {
                Logger::warning("[FB_WEBHOOK] ⚠️ Skipping action at index {$idx}", [
                    'reason' => 'Not an image action or missing URL',
                    'action' => $action
                ]);
            }
        }
        Logger::info("[FB_WEBHOOK] ✅ Finished processing actions - sent {$imageCount} images");
    } else {
        Logger::warning("[FB_WEBHOOK] ⚠️ No actions to process", [
            'actions_empty' => empty($actions),
            'actions_is_array' => is_array($actions),
            'actions_value' => $actions
        ]);
    }
}

/**
 * Check if a message has already been processed (using gateway_message_events)
 */
function isMessageAlreadyProcessed(int $channelId, string $externalEventId): bool
{
    try {
        $db = Database::getInstance();
        
        // Check if event exists in last 24 hours
        $sql = "SELECT COUNT(*) as cnt FROM gateway_message_events 
                WHERE channel_id = ? AND external_event_id = ? 
                AND created_at > NOW() - INTERVAL 24 HOUR";
        
        $result = $db->queryOne($sql, [$channelId, $externalEventId]);
        return ($result && (int)$result['cnt'] > 0);
    } catch (Exception $e) {
        Logger::error("Deduplication check error: " . $e->getMessage());
        // On error, assume not processed to avoid blocking legitimate messages
        return false;
    }
}

/**
 * Record a message event for deduplication and audit trail
 */
function recordMessageEvent(int $channelId, string $externalEventId, ?array $responsePayload): void
{
    try {
        $db = Database::getInstance();
        
        $payloadJson = $responsePayload !== null ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE) : null;
        
        $sql = "INSERT INTO gateway_message_events (channel_id, external_event_id, response_payload, created_at) 
                VALUES (?, ?, ?, NOW())";
        
        $db->execute($sql, [$channelId, $externalEventId, $payloadJson]);
    } catch (Exception $e) {
        $msg = $e->getMessage();
        // Ignore duplicates silently (webhook can retry / parallel delivery)
        if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'uniq_') !== false || stripos($msg, 'unique') !== false) {
            Logger::info('[FB_WEBHOOK] Duplicate message event insert ignored', [
                'channel_id' => $channelId,
                'external_event_id' => $externalEventId,
            ]);
            return;
        }

        Logger::error("Failed to record message event: " . $msg);
        // Not critical, just log it
    }
}

/**
 * Best-effort update response payload for an existing message event.
 */
function updateMessageEventPayload(int $channelId, string $externalEventId, ?array $responsePayload): void
{
    try {
        $db = Database::getInstance();
        $payloadJson = $responsePayload !== null ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE) : null;

        $sql = "UPDATE gateway_message_events
                SET response_payload = COALESCE(?, response_payload)
                WHERE channel_id = ? AND external_event_id = ?
                LIMIT 1";

        $db->execute($sql, [$payloadJson, $channelId, $externalEventId]);
    } catch (Exception $e) {
        Logger::error("Failed to update message event payload: " . $e->getMessage());
    }
}

/**
 * DB: find facebook channel by page_id in JSON config
 */
function findFacebookChannelByPageId(string $pageId): ?array
{
    $db = Database::getInstance();

    // IMPORTANT: JSON_EXTRACT ต้อง JSON_UNQUOTE ไม่งั้นเทียบ string ไม่ตรง
    $sql = "
        SELECT *
        FROM customer_channels
        WHERE type='facebook'
          AND status='active'
          AND is_deleted=0
          AND JSON_UNQUOTE(JSON_EXTRACT(config, '$.page_id')) = ?
        LIMIT 1
    ";

    $row = $db->queryOne($sql, [$pageId]);
    if (!$row || !is_array($row)) return null;
    return $row;
}

/**
 * Call internal gateway
 * (ตั้งเป็น env ได้ จะชัวร์กว่าใช้ Host ปัจจุบัน)
 */
function callGateway(array $payload): ?array
{
    $gatewayUrl = getenv('GATEWAY_URL');
    if (!$gatewayUrl) {
        // default domain ของคุณ
        $gatewayUrl = 'https://autobot.boxdesign.in.th/api/gateway/message.php';
    }

    $ch = curl_init($gatewayUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $http !== 200) {
        Logger::error('[FB_WEBHOOK] Gateway call failed', [
            'gateway_url' => $gatewayUrl,
            'http_code' => $http,
            'curl_error' => $err,
            'resp_snippet' => substr((string)$raw, 0, 500),
        ]);

        return [
            'success' => false,
            'error' => 'gateway_call_failed',
            'http_code' => $http,
            'curl_error' => $err,
            'gateway_url' => $gatewayUrl,
            'resp_snippet' => substr((string)$raw, 0, 500),
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        Logger::error('[FB_WEBHOOK] Gateway returned non-JSON', [
            'gateway_url' => $gatewayUrl,
            'resp_snippet' => substr((string)$raw, 0, 500),
        ]);

        return [
            'success' => false,
            'error' => 'gateway_invalid_json',
            'gateway_url' => $gatewayUrl,
            'resp_snippet' => substr((string)$raw, 0, 500),
        ];
    }

    // Return full response structure (with 'data' wrapper intact)
    // Webhook will handle unwrapping
    return $decoded;
}

/**
 * Send message back to user via Page Access Token
 */
function sendFacebookMessage(string $recipientPsid, string $text, array $channel): bool
{
    $cfg = json_decode($channel['config'] ?? '{}', true);
    $pageToken = trim((string)($cfg['page_access_token'] ?? ''));

    if ($pageToken === '') {
        Logger::error('Facebook page_access_token not configured for channel_id=' . ($channel['id'] ?? ''));
        return false;
    }

    // ใช้ access_token เป็น query param (กันเคส /me ไม่ resolve เพราะ token ไม่ใช่ page token)
    $url = 'https://graph.facebook.com/v18.0/me/messages?access_token=' . urlencode($pageToken);

    $payload = [
        'recipient'      => ['id' => $recipientPsid],
        'message'        => ['text' => $text],
        'messaging_type' => 'RESPONSE',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($http !== 200) {
        Logger::error('Facebook send message failed http_code=' . $http . ' err=' . $err . ' resp=' . substr((string)$resp, 0, 800));
        return false;
    }

    return true;
}

/**
 * Send image to user via Page Access Token
 */
function sendFacebookImage(string $recipientPsid, string $imageUrl, array $channel): bool
{
    Logger::info("[FB_IMAGE] Starting to send image", [
        'recipient_psid' => $recipientPsid,
        'image_url' => $imageUrl,
        'channel_id' => $channel['id'] ?? 'unknown'
    ]);
    
    $cfg = json_decode($channel['config'] ?? '{}', true);
    $pageToken = trim((string)($cfg['page_access_token'] ?? ''));

    if ($pageToken === '') {
        Logger::error('[FB_IMAGE] ❌ Facebook page_access_token not configured for channel_id=' . ($channel['id'] ?? ''));
        return false;
    }

    // Convert relative URL to absolute if needed
    if (strpos($imageUrl, 'http') !== 0) {
        // Use domain from env or default
        $domain = getenv('APP_URL') ?: 'https://autobot.boxdesign.in.th';
        $imageUrl = rtrim($domain, '/') . '/' . ltrim($imageUrl, '/');
        Logger::info("[FB_IMAGE] Converted relative URL to absolute", ['final_url' => $imageUrl]);
    }

    $url = 'https://graph.facebook.com/v18.0/me/messages?access_token=' . urlencode($pageToken);

    $payload = [
        'recipient' => ['id' => $recipientPsid],
        'message' => [
            'attachment' => [
                'type' => 'image',
                'payload' => [
                    'url' => $imageUrl,
                    'is_reusable' => true
                ]
            ]
        ],
        'messaging_type' => 'RESPONSE',
    ];
    
    Logger::info("[FB_IMAGE] Sending to Facebook API", [
        'api_endpoint' => 'v18.0/me/messages',
        'image_url' => $imageUrl
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($http !== 200) {
        Logger::error('[FB_IMAGE] ❌ Facebook API returned error', [
            'http_code' => $http,
            'curl_error' => $err,
            'facebook_response' => substr((string)$resp, 0, 800),
            'image_url' => $imageUrl
        ]);
        return false;
    }

    Logger::info("[FB_IMAGE] ✅ Image sent successfully", [
        'http_code' => $http,
        'image_url' => $imageUrl,
        'facebook_response' => json_decode($resp, true)
    ]);
    return true;
}
