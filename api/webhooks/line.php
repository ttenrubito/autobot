<?php
/**
 * LINE Messaging API Webhook Handler
 * Receives messages from LINE and routes to bot gateway
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/GoogleCloudStorage.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    handleIncomingWebhook();
} catch (Exception $e) {
    Logger::error('LINE webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

/**
 * Handle incoming webhook from LINE
 */
function handleIncomingWebhook()
{
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }

    // Verify signature
    $signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
    if (!verifySignature($input, $signature)) {
        Logger::warning('LINE webhook signature verification failed');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid signature']);
        return;
    }

    // Process each event
    if (isset($data['events'])) {
        foreach ($data['events'] as $event) {
            processEvent($event);
        }
    }

    // LINE expects 200 OK response
    http_response_code(200);
    echo json_encode(['status' => 'received']);
}

/**
 * Verify LINE webhook signature
 */
function verifySignature($payload, $signature)
{
    if (empty($signature)) {
        return false;
    }

    // Get channel secret from database or environment
    $db = Database::getInstance();

    // Try to find active LINE channel
    $channels = $db->query(
        "SELECT config FROM customer_channels WHERE type = 'line' AND status = 'active' AND is_deleted = 0 LIMIT 1"
    );

    $channelSecret = '';
    if (!empty($channels)) {
        $config = json_decode($channels[0]['config'] ?? '{}', true);
        $channelSecret = $config['channel_secret'] ?? '';
    }

    // Fallback to environment variable
    if (empty($channelSecret)) {
        $channelSecret = getenv('LINE_CHANNEL_SECRET') ?: '';
    }

    if (empty($channelSecret)) {
        Logger::error('LINE channel secret not configured');
        return false;
    }

    $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $channelSecret, true));
    return hash_equals($expectedSignature, $signature);
}

/**
 * Process individual LINE event
 */
function processEvent($event)
{
    $type = $event['type'] ?? '';

    // Only process message events
    if ($type !== 'message') {
        return;
    }

    $source = $event['source'] ?? [];
    $message = $event['message'] ?? [];
    $replyToken = $event['replyToken'] ?? null;

    $userId = $source['userId'] ?? null;
    $messageType = $message['type'] ?? 'text';
    $messageId = $message['id'] ?? null;
    $text = $message['text'] ?? '';

    if (!$userId || !$replyToken) {
        return;
    }

    // Find LINE channel
    $db = Database::getInstance();
    $channel = $db->queryOne(
        "SELECT * FROM customer_channels 
         WHERE type = 'line' 
         AND status = 'active' 
         AND is_deleted = 0 
         LIMIT 1"
    );

    if (!$channel) {
        Logger::warning('No active LINE channel found');
        return;
    }

    // ✅ Deduplication check
    if ($messageId !== '' && isMessageAlreadyProcessed((int) $channel['id'], $messageId)) {
        Logger::info("LINE webhook: Duplicate message detected (mid=$messageId, channel_id={$channel['id']}), skipping");
        return;
    }

    // ✅ NEW: Check if user is admin (via whitelist in config)
    $config = json_decode($channel['config'] ?? '{}', true);
    $adminUserIds = $config['admin_user_ids'] ?? [];
    $isAdmin = in_array($userId, $adminUserIds, true);

    // ✅ Upsert customer profile (ถ้าเป็นข้อความจากลูกค้า ไม่ใช่ admin)
    $channelAccessToken = $config['channel_access_token'] ?? '';
    if (!$isAdmin && !empty($userId) && !empty($channelAccessToken)) {
        upsertCustomerProfile('line', $userId, $channelAccessToken, 'default', (int)$channel['id']);
    }

    if ($isAdmin) {
        Logger::info('[LINE_WEBHOOK] Admin user detected', [
            'user_id' => $userId,
            'channel_id' => $channel['id']
        ]);
    }

    // Prepare message for gateway
    $gatewayMessage = [
        'inbound_api_key' => $channel['inbound_api_key'],
        'external_user_id' => $userId,
        'text' => $text,
        'message_type' => $messageType,
        'channel_type' => 'line',
        'is_admin' => $isAdmin,  // ✅ NEW: Admin detection
        'metadata' => [
            'message_id' => $messageId,
            'reply_token' => $replyToken,
            'source_type' => $source['type'] ?? 'user',
            'platform' => 'line',
            'is_admin' => $isAdmin
        ]
    ];

    // ✅ Handle sticker messages
    if ($messageType === 'sticker') {
        Logger::info("LINE webhook: Sticker received", [
            'sticker_id' => $message['stickerId'] ?? null,
            'package_id' => $message['packageId'] ?? null
        ]);

        // Send friendly sticker acknowledgment
        $stickerReply = 'รับสติกเกอร์แล้วค่ะ 😊 ต้องการให้ช่วยอะไรต่อดีคะ';
        sendLineReply($replyToken, $stickerReply, [], $channel);

        // Record event for deduplication
        if ($messageId !== '') {
            recordMessageEvent((int) $channel['id'], $messageId, ['reply_text' => $stickerReply]);
        }
        return;
    }

    // ✅ Handle image messages - download and normalize
    if ($messageType === 'image') {
        $imageUrl = downloadLineImage($messageId, $channel);

        if ($imageUrl) {
            $gatewayMessage['attachments'] = [
                [
                    'type' => 'image',
                    'url' => $imageUrl  // ✅ Now has proper URL (GCS or local)!
                ]
            ];
            Logger::info("LINE webhook: Image downloaded and normalized", [
                'message_id' => $messageId,
                'public_url' => $imageUrl
            ]);

            // ✅ Save image message to chat_messages
            saveChatMessage(
                (int) $channel['id'],
                $userId,
                'image',
                '[รูปภาพ]',
                $messageId,
                'incoming',
                'customer',
                [['type' => 'image', 'url' => $imageUrl]]
            );
        } else {
            Logger::error("LINE webhook: Failed to download image", [
                'message_id' => $messageId
            ]);
            $gatewayMessage['attachments'] = [
                [
                    'type' => 'image',
                    'message_id' => $messageId
                ]
            ];
        }
    } elseif ($messageType === 'text') {
        // ✅ Save text message to chat_messages
        saveChatMessage(
            (int) $channel['id'],
            $userId,
            'text',
            $text,
            $messageId,
            'incoming',
            'customer'
        );
    } elseif ($messageType === 'location') {
        $gatewayMessage['metadata']['location'] = [
            'latitude' => $message['latitude'] ?? null,
            'longitude' => $message['longitude'] ?? null,
            'address' => $message['address'] ?? null
        ];
    }

    // Call internal message gateway
    $response = callGateway($gatewayMessage);

    // Extract response data (gateway wraps in 'data')
    $data = is_array($response) && isset($response['data']) ? $response['data'] : $response;

    // ✅ NEW: Support for Flex Messages via reply_messages
    $replyMessages = [];
    $replyTexts = [];

    if (is_array($data)) {
        // Check for reply_messages (Flex Messages, Carousel, etc.)
        if (!empty($data['reply_messages']) && is_array($data['reply_messages'])) {
            $replyMessages = $data['reply_messages'];
            Logger::info('[LINE_WEBHOOK] Flex/Rich message reply detected', [
                'message_count' => count($replyMessages),
                'types' => array_map(fn($m) => $m['type'] ?? 'unknown', $replyMessages)
            ]);
        }
        
        // ✅ FIX: Check if reply_messages already has text content
        // If reply_messages contains text type, skip reply_texts to avoid duplicate
        $replyMessagesHasText = false;
        foreach ($replyMessages as $msg) {
            if (($msg['type'] ?? '') === 'text' && !empty($msg['text'])) {
                $replyMessagesHasText = true;
                break;
            }
        }
        
        // Check for new format: reply_texts array (ONLY if reply_messages doesn't have text)
        if (!$replyMessagesHasText && !empty($data['reply_texts']) && is_array($data['reply_texts'])) {
            $replyTexts = $data['reply_texts'];
            Logger::info('[LINE_WEBHOOK] Multi-message reply detected', [
                'message_count' => count($replyTexts),
            ]);
        }
        // Fallback to single message format (only if no Flex messages AND no text in reply_messages)
        elseif (!$replyMessagesHasText && !empty($data['reply_text']) && empty($replyMessages)) {
            $replyTexts = [(string) $data['reply_text']];
            Logger::info('[LINE_WEBHOOK] Single message reply (legacy format)');
        }
        elseif ($replyMessagesHasText) {
            Logger::info('[LINE_WEBHOOK] Skipped reply_texts - reply_messages already has text content');
        }
    }

    $actions = is_array($data) && isset($data['actions']) ? $data['actions'] : [];

    Logger::info("[LINE_WEBHOOK] Gateway response received", [
        'has_reply' => !empty($replyTexts) || !empty($replyMessages),
        'message_count' => count($replyTexts),
        'flex_count' => count($replyMessages),
        'actions_count' => count($actions),
        'actions_full' => $actions
    ]);

    // ✅ Record message event for deduplication
    if ($messageId !== '') {
        recordMessageEvent((int) $channel['id'], $messageId, $response);
    }

    // ✅ Save bot reply messages to chat_messages
    if (!empty($replyTexts)) {
        foreach ($replyTexts as $idx => $replyText) {
            $replyMessageId = $messageId . '_reply_' . ($idx + 1);
            saveChatMessage(
                (int) $channel['id'],
                $userId,
                'text',
                $replyText,
                $replyMessageId,
                'outgoing',
                'bot'
            );
        }
    }
    
    // Save Flex message summary to chat_messages
    if (!empty($replyMessages)) {
        foreach ($replyMessages as $idx => $msg) {
            $altText = $msg['altText'] ?? 'Rich message';
            $replyMessageId = $messageId . '_flex_' . ($idx + 1);
            saveChatMessage(
                (int) $channel['id'],
                $userId,
                'flex',
                $altText,
                $replyMessageId,
                'outgoing',
                'bot'
            );
        }
    }

    // ✅ Send response back to LINE (Flex messages, text messages + actions/images)
    if (!empty($replyMessages) || !empty($replyTexts) || !empty($actions)) {
        sendLineReply($replyToken, $replyTexts, $actions, $channel, $replyMessages);
    }
}

/**
 * ✅ Download image from LINE Content API and upload to Google Cloud Storage
 */
function downloadLineImage($messageId, $channel)
{
    $config = json_decode($channel['config'] ?? '{}', true);
    $channelAccessToken = $config['channel_access_token'] ?? '';

    if (empty($channelAccessToken)) {
        Logger::error('LINE channel access token not configured');
        return null;
    }

    // Download image from LINE
    $url = "https://api-data.line.me/v2/bot/message/$messageId/content";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $channelAccessToken
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode !== 200 || !$imageData) {
        Logger::error("Failed to download LINE image: HTTP $httpCode");
        return null;
    }

    // Determine file extension from content type
    $ext = 'jpg';
    if (strpos($contentType, 'png') !== false) {
        $ext = 'png';
    } elseif (strpos($contentType, 'gif') !== false) {
        $ext = 'gif';
    }

    $filename = $messageId . '.' . $ext;
    $mimeType = $contentType ?: 'image/jpeg';

    // Upload to GCS (required for production)
    try {
        $gcs = GoogleCloudStorage::getInstance();
        $result = $gcs->uploadFile(
            $imageData,
            $filename,
            $mimeType,
            'line_images',  // folder in GCS bucket
            [
                'source' => 'line',
                'message_id' => $messageId,
                'channel_id' => $channel['id'] ?? ''
            ]
        );

        if ($result['success']) {
            Logger::info("LINE image uploaded to GCS", [
                'message_id' => $messageId,
                'gcs_path' => $result['path'],
                'public_url' => $result['url']
            ]);

            // Return public URL (not signed URL which expires)
            // GCS bucket should be configured with public read access
            return $result['url'];
        } else {
            Logger::error("GCS upload failed", [
                'error' => $result['error'] ?? 'Unknown',
                'message_id' => $messageId
            ]);
            return null;
        }
    } catch (Exception $e) {
        Logger::error("GCS upload exception", [
            'error' => $e->getMessage(),
            'message_id' => $messageId
        ]);
        return null;
    }
}

/**
 * ✅ Check if message already processed (deduplication)
 */
function isMessageAlreadyProcessed(int $channelId, string $externalEventId): bool
{
    try {
        $db = Database::getInstance();

        $sql = "SELECT COUNT(*) as cnt FROM gateway_message_events 
                WHERE channel_id = ? AND external_event_id = ? 
                AND created_at > NOW() - INTERVAL 24 HOUR";

        $result = $db->queryOne($sql, [$channelId, $externalEventId]);
        return ($result && (int) $result['cnt'] > 0);
    } catch (Exception $e) {
        Logger::error("Deduplication check error: " . $e->getMessage());
        return false;
    }
}

/**
 * ✅ Record message event for deduplication
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
        Logger::error("Failed to record message event: " . $e->getMessage());
    }
}

/**
 * ✅ Save chat message to database for history tracking
 * FIXED: Use correct schema (session_id, role, text) instead of old schema
 */
function saveChatMessage($channelId, $userId, $messageType, $text, $messageId, $direction = 'incoming', $senderType = 'customer', $attachments = null)
{
    try {
        $db = Database::getInstance();

        // Get or create session_id from chat_sessions table
        $session = $db->queryOne(
            "SELECT id FROM chat_sessions WHERE channel_id = ? AND external_user_id = ? LIMIT 1",
            [$channelId, $userId]
        );
        
        $sessionId = null;
        if ($session) {
            $sessionId = $session['id'];
        } else {
            // Create new session
            $db->execute(
                "INSERT INTO chat_sessions (channel_id, external_user_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())",
                [$channelId, $userId]
            );
            $sessionId = $db->lastInsertId();
        }
        
        if (!$sessionId) {
            Logger::warning("[LINE_WEBHOOK] Could not get/create session for chat message");
            return null;
        }
        
        // Map direction/senderType to role
        $role = 'user';
        if ($direction === 'outgoing') {
            $role = ($senderType === 'bot') ? 'assistant' : 'system';
        }
        
        // Skip empty messages
        if (empty($text)) {
            return null;
        }
        
        // Insert into chat_messages with correct schema
        $sql = "INSERT INTO chat_messages (session_id, role, text, created_at) VALUES (?, ?, ?, NOW())";
        $db->execute($sql, [$sessionId, $role, $text]);

        Logger::info("[LINE_WEBHOOK] Chat message saved to database", [
            'session_id' => $sessionId,
            'role' => $role,
            'direction' => $direction,
            'type' => $messageType
        ]);

        return $sessionId;

    } catch (Exception $e) {
        Logger::error("Failed to save chat message: " . $e->getMessage());
        return null;
    }
}

/**
 * Call internal message gateway
 */
function callGateway($message)
{
    $gatewayUrl = getenv('GATEWAY_URL');
    if (!$gatewayUrl) {
        // Use production domain (same as Facebook webhook)
        $gatewayUrl = 'https://autobot.boxdesign.in.th/api/gateway/message.php';
    }

    $ch = curl_init($gatewayUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        return $data ?? null;
    }

    return null;
}

/**
 * ✅ Send reply to LINE user (Flex messages, text messages + images from actions)
 * @param string $replyToken LINE reply token
 * @param array $texts Array of text messages to send
 * @param array $actions Bot actions (images, etc.)
 * @param array $channel Channel configuration
 * @param array $flexMessages Array of Flex/rich messages to send
 */
function sendLineReply($replyToken, $texts, $actions, $channel, $flexMessages = [])
{
    $config = json_decode($channel['config'] ?? '{}', true);
    $channelAccessToken = $config['channel_access_token'] ?? '';

    if (empty($channelAccessToken)) {
        Logger::error('LINE channel access token not configured for channel: ' . $channel['id']);
        return false;
    }

    $url = 'https://api.line.me/v2/bot/message/reply';

    // Build messages array
    $messages = [];
    
    // 0️⃣ Add Flex/Rich messages FIRST (if any)
    if (!empty($flexMessages) && is_array($flexMessages)) {
        Logger::info("[LINE_WEBHOOK] 🎨 Adding " . count($flexMessages) . " Flex message(s)");
        foreach ($flexMessages as $flexMsg) {
            if (!is_array($flexMsg)) continue;
            
            // Validate it's a valid LINE message format
            if (!empty($flexMsg['type'])) {
                $messages[] = $flexMsg;
                Logger::info("[LINE_WEBHOOK] 🎨 Added Flex message", [
                    'type' => $flexMsg['type'],
                    'altText' => $flexMsg['altText'] ?? 'Rich content'
                ]);
            }
            
            // LINE API limit: max 5 messages per reply
            if (count($messages) >= 5) {
                Logger::warning("[LINE_WEBHOOK] Reached LINE API limit of 5 messages (at flex)");
                break;
            }
        }
    }

    // ✅ Extract Quick Reply items and Images from actions (if any)
    $quickReplyItems = [];
    $imageActions = [];
    
    if (!empty($actions) && is_array($actions)) {
        Logger::info("[LINE_WEBHOOK] 🔍 Extracting Quick Reply and Images from actions", [
            'actions_count' => count($actions),
        ]);
        
        foreach ($actions as $action) {
            if (!is_array($action)) continue;
            
            // Collect quick_replies
            if (($action['type'] ?? '') === 'quick_reply' && !empty($action['items'])) {
                foreach ($action['items'] as $item) {
                    $quickReplyItems[] = [
                        'type' => 'action',
                        'action' => [
                            'type' => 'message',
                            'label' => mb_substr($item['label'] ?? $item['text'], 0, 20), // LINE limit 20 chars
                            'text' => $item['text']
                        ]
                    ];
                }
            }
            
            // Collect images
            if (($action['type'] ?? '') === 'image' && !empty($action['url'])) {
                $imageActions[] = $action;
            }
        }
        
        if (!empty($quickReplyItems)) {
            Logger::info("[LINE_WEBHOOK] ✅ Found quick_replies", ['count' => count($quickReplyItems)]);
        }
        if (!empty($imageActions)) {
            Logger::info("[LINE_WEBHOOK] ✅ Found images", ['count' => count($imageActions)]);
        }
    }

    // =========================================================
    // ✅ BUILD ORDER: Images FIRST, then text + Quick Reply LAST
    // This ensures Quick Reply buttons appear at the bottom
    // =========================================================
    
    // 1️⃣ Add image messages FIRST
    if (!empty($imageActions)) {
        Logger::info("[LINE_WEBHOOK] 📸 Adding " . count($imageActions) . " image(s) FIRST");
        foreach ($imageActions as $idx => $imgAction) {
            $imageUrl = $imgAction['url'];
            
            // Convert relative URL to absolute
            if (strpos($imageUrl, 'http') !== 0) {
                $domain = getenv('APP_URL') ?: 'https://autobot.boxdesign.in.th';
                $imageUrl = rtrim($domain, '/') . '/' . ltrim($imageUrl, '/');
            }
            
            // LINE requires both originalContentUrl and previewImageUrl
            $messages[] = [
                'type' => 'image',
                'originalContentUrl' => $imageUrl,
                'previewImageUrl' => $imageUrl
            ];
            
            Logger::info("[LINE_WEBHOOK] 📸 Added image #" . ($idx + 1), [
                'image_url' => $imageUrl
            ]);
            
            // LINE API limit: max 5 messages per reply
            if (count($messages) >= 5) {
                Logger::warning("[LINE_WEBHOOK] Reached LINE API limit of 5 messages (at images)");
                break;
            }
        }
    }

    // 2️⃣ Add text messages AFTER images (with Quick Reply on last message)
    if (!empty($texts) && is_array($texts)) {
        foreach ($texts as $idx => $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                // LINE API limit check
                if (count($messages) >= 5) {
                    Logger::warning("[LINE_WEBHOOK] Reached LINE API limit of 5 messages (at text)");
                    break;
                }
                
                $msg = [
                    'type' => 'text',
                    'text' => $text
                ];
                
                // ✅ Attach Quick Reply to the LAST text message only
                if (!empty($quickReplyItems) && $idx === count($texts) - 1) {
                    $msg['quickReply'] = ['items' => array_slice($quickReplyItems, 0, 13)]; // LINE limit 13 items
                    Logger::info("[LINE_WEBHOOK] ✅ Attached Quick Reply to last message", [
                        'quick_reply_count' => count($quickReplyItems),
                        'message_index' => $idx
                    ]);
                }
                
                $messages[] = $msg;
            }
        }
    }
    
    Logger::info("[LINE_WEBHOOK] ✅ Built messages array", [
        'total_count' => count($messages),
        'image_count' => count($imageActions),
        'text_count' => count($texts ?? []),
        'has_quick_reply' => !empty($quickReplyItems)
    ]);

    // If no messages to send, skip
    if (empty($messages)) {
        Logger::warning("[LINE_WEBHOOK] No messages to send");
        return true;
    }

    $payload = [
        'replyToken' => $replyToken,
        'messages' => $messages
    ];

    Logger::info("[LINE_WEBHOOK] Sending to LINE API", [
        'message_count' => count($messages),
        'has_text' => $text !== '',
        'image_count' => count($messages) - ($text !== '' ? 1 : 0)
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $channelAccessToken
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        Logger::error('[LINE_WEBHOOK] ❌ LINE API error', [
            'http_code' => $httpCode,
            'response' => substr((string) $response, 0, 500)
        ]);
        return false;
    }

    Logger::info("[LINE_WEBHOOK] ✅ Reply sent successfully", [
        'http_code' => $httpCode,
        'message_count' => count($messages)
    ]);
    return true;
}

/**
 * Upsert customer profile from LINE
 * บันทึกหรืออัพเดท profile ลูกค้าจาก LINE (ซ้ำไม่ได้)
 * @return int|null Customer profile ID or null on failure
 */
function upsertCustomerProfile(string $platform, string $platformUserId, string $channelAccessToken, string $tenantId = 'default', ?int $channelId = null): ?int
{
    if (empty($platformUserId) || empty($channelAccessToken)) {
        return null;
    }

    try {
        $db = Database::getInstance();
        
        // First check if profile already exists
        $existing = $db->queryOne(
            "SELECT id FROM customer_profiles WHERE platform = ? AND platform_user_id = ? LIMIT 1",
            [$platform, $platformUserId]
        );
        
        if ($existing) {
            // Update last_active_at and total_inquiries
            $db->execute(
                "UPDATE customer_profiles SET last_active_at = NOW(), total_inquiries = COALESCE(total_inquiries, 0) + 1, updated_at = NOW() WHERE id = ?",
                [$existing['id']]
            );
            return (int)$existing['id'];
        }

        // Get profile from LINE API
        $profileUrl = "https://api.line.me/v2/bot/profile/{$platformUserId}";

        $ch = curl_init($profileUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $channelAccessToken
            ]
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $displayName = null;
        $avatarUrl = null;
        
        if ($httpCode === 200) {
            $profile = json_decode($resp, true);
            if (is_array($profile)) {
                $displayName = $profile['displayName'] ?? null;
                $avatarUrl = $profile['pictureUrl'] ?? null;
            }
        } else {
            Logger::warning('[LINE_PROFILE] Failed to get profile from LINE API, creating with minimal data', [
                'platform_user_id' => $platformUserId,
                'http_code' => $httpCode,
            ]);
        }

        // Insert new customer profile (always create even if no display name)
        $sql = "
            INSERT INTO customer_profiles (tenant_id, channel_id, platform, platform_user_id, display_name, avatar_url, total_inquiries, first_seen_at, last_active_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                display_name = COALESCE(VALUES(display_name), display_name),
                avatar_url = COALESCE(VALUES(avatar_url), avatar_url),
                channel_id = COALESCE(VALUES(channel_id), channel_id),
                total_inquiries = COALESCE(total_inquiries, 0) + 1,
                last_active_at = NOW(),
                updated_at = NOW()
        ";

        $db->execute($sql, [$tenantId, $channelId, $platform, $platformUserId, $displayName, $avatarUrl]);
        
        // Get the ID (either new or existing due to race condition)
        $result = $db->queryOne(
            "SELECT id FROM customer_profiles WHERE platform = ? AND platform_user_id = ? LIMIT 1",
            [$platform, $platformUserId]
        );
        
        $profileId = $result ? (int)$result['id'] : null;

        Logger::info('[LINE_PROFILE] Customer profile upserted', [
            'profile_id' => $profileId,
            'platform' => $platform,
            'platform_user_id' => $platformUserId,
            'display_name' => $displayName,
        ]);
        
        return $profileId;

    } catch (Throwable $e) {
        Logger::error('[LINE_PROFILE] Error upserting customer profile: ' . $e->getMessage(), [
            'platform_user_id' => $platformUserId,
        ]);
        return null;
    }
}
