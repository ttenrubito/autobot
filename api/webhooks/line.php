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
function handleIncomingWebhook() {
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
function verifySignature($payload, $signature) {
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
function processEvent($event) {
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
    if ($messageId !== '' && isMessageAlreadyProcessed((int)$channel['id'], $messageId)) {
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
        upsertCustomerProfile('line', $userId, $channelAccessToken);
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
            recordMessageEvent((int)$channel['id'], $messageId, ['reply_text' => $stickerReply]);
        }
        return;
    }
    
    // ✅ Handle image messages - download and normalize
    if ($messageType === 'image') {
        $imageUrl = downloadLineImage($messageId, $channel);
        
        if ($imageUrl) {
            $gatewayMessage['attachments'] = [[
                'type' => 'image',
                'url' => $imageUrl  // ✅ Now has proper URL (GCS or local)!
            ]];
            Logger::info("LINE webhook: Image downloaded and normalized", [
                'message_id' => $messageId,
                'public_url' => $imageUrl
            ]);
            
            // ✅ Save image message to chat_messages
            saveChatMessage(
                (int)$channel['id'],
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
            $gatewayMessage['attachments'] = [[
                'type' => 'image',
                'message_id' => $messageId
            ]];
        }
    } elseif ($messageType === 'text') {
        // ✅ Save text message to chat_messages
        saveChatMessage(
            (int)$channel['id'],
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
    
    // ✅ NEW: Support for multi-message replies (human-like conversation)
    // Check for reply_texts array first, fallback to single reply_text
    $replyTexts = [];
    
    if (is_array($data)) {
        // Check for new format: reply_texts array
        if (!empty($data['reply_texts']) && is_array($data['reply_texts'])) {
            $replyTexts = $data['reply_texts'];
            Logger::info('[LINE_WEBHOOK] Multi-message reply detected', [
                'message_count' => count($replyTexts),
            ]);
        }
        // Fallback to single message format
        elseif (!empty($data['reply_text'])) {
            $replyTexts = [(string)$data['reply_text']];
            Logger::info('[LINE_WEBHOOK] Single message reply (legacy format)');
        }
    }
    
    $actions = is_array($data) && isset($data['actions']) ? $data['actions'] : [];
    
    Logger::info("[LINE_WEBHOOK] Gateway response received", [
        'has_reply' => !empty($replyTexts),
        'message_count' => count($replyTexts),
        'actions_count' => count($actions),
        'actions_full' => $actions
    ]);
    
    // ✅ Record message event for deduplication
    if ($messageId !== '') {
        recordMessageEvent((int)$channel['id'], $messageId, $response);
    }
    
    // ✅ Save bot reply messages to chat_messages
    if (!empty($replyTexts)) {
        foreach ($replyTexts as $idx => $replyText) {
            $replyMessageId = $messageId . '_reply_' . ($idx + 1);
            saveChatMessage(
                (int)$channel['id'],
                $userId,
                'text',
                $replyText,
                $replyMessageId,
                'outgoing',
                'bot'
            );
        }
    }
    
    // ✅ Send response back to LINE (multiple text messages + actions/images)
    if (!empty($replyTexts) || !empty($actions)) {
        sendLineReply($replyToken, $replyTexts, $actions, $channel);
    }
}

/**
 * ✅ Download image from LINE Content API and upload to Google Cloud Storage
 */
function downloadLineImage($messageId, $channel) {
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
    
    // Try uploading to GCS first
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
                'signed_url' => substr($result['signed_url'], 0, 100) . '...'
            ]);
            
            // Return signed URL for access
            return $result['signed_url'];
        } else {
            Logger::warning("GCS upload failed, falling back to local storage", [
                'error' => $result['error'] ?? 'Unknown'
            ]);
        }
    } catch (Exception $e) {
        Logger::warning("GCS not available, falling back to local storage", [
            'error' => $e->getMessage()
        ]);
    }
    
    // Fallback to local storage if GCS fails
    $uploadDir = __DIR__ . '/../../public/uploads/line_images';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filepath = $uploadDir . '/' . $filename;
    
    if (file_put_contents($filepath, $imageData) === false) {
        Logger::error("Failed to save LINE image to disk");
        return null;
    }
    
    // Generate public URL
    $domain = getenv('APP_URL') ?: 'https://autobot.boxdesign.in.th';
    $publicUrl = rtrim($domain, '/') . '/uploads/line_images/' . $filename;
    
    return $publicUrl;
}

/**
 * ✅ Check if message already processed (deduplication)
 */
function isMessageAlreadyProcessed(int $channelId, string $externalEventId): bool {
    try {
        $db = Database::getInstance();
        
        $sql = "SELECT COUNT(*) as cnt FROM gateway_message_events 
                WHERE channel_id = ? AND external_event_id = ? 
                AND created_at > NOW() - INTERVAL 24 HOUR";
        
        $result = $db->queryOne($sql, [$channelId, $externalEventId]);
        return ($result && (int)$result['cnt'] > 0);
    } catch (Exception $e) {
        Logger::error("Deduplication check error: " . $e->getMessage());
        return false;
    }
}

/**
 * ✅ Record message event for deduplication
 */
function recordMessageEvent(int $channelId, string $externalEventId, ?array $responsePayload): void {
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
 */
function saveChatMessage($channelId, $userId, $messageType, $text, $messageId, $direction = 'incoming', $senderType = 'customer', $attachments = null) {
    try {
        $db = Database::getInstance();
        
        // Get or create conversation_id based on channel + user
        $conversationId = 'line_' . $channelId . '_' . $userId;
        
        // Build message_data JSON for attachments
        $messageData = null;
        if ($attachments) {
            $messageData = json_encode(['attachments' => $attachments], JSON_UNESCAPED_UNICODE);
        }
        
        // Convert message type to match enum
        $dbMessageType = $messageType;
        if ($messageType === 'sticker') {
            $dbMessageType = 'text'; // Store as text, actual content indicates it was sticker
        }
        
        $sql = "INSERT INTO chat_messages 
                (conversation_id, tenant_id, message_id, platform, direction, sender_type, sender_id, 
                 message_type, message_text, message_data, sent_at, received_at, created_at)
                VALUES (?, 'default', ?, 'line', ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()";
        
        $db->execute($sql, [
            $conversationId,
            $messageId,
            $direction,
            $senderType,
            $userId,
            $dbMessageType,
            $text ?: null,
            $messageData
        ]);
        
        Logger::info("[LINE_WEBHOOK] Chat message saved to database", [
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'direction' => $direction,
            'type' => $messageType
        ]);
        
        return $conversationId;
        
    } catch (Exception $e) {
        Logger::error("Failed to save chat message: " . $e->getMessage());
        return null;
    }
}

/**
 * Call internal message gateway
 */
function callGateway($message) {
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
 * ✅ Send reply to LINE user (multiple text messages + images from actions)
 * @param string $replyToken LINE reply token
 * @param array $texts Array of text messages to send
 * @param array $actions Bot actions (images, etc.)
 * @param array $channel Channel configuration
 */
function sendLineReply($replyToken, $texts, $actions, $channel) {
    $config = json_decode($channel['config'] ?? '{}', true);
    $channelAccessToken = $config['channel_access_token'] ?? '';
    
    if (empty($channelAccessToken)) {
        Logger::error('LINE channel access token not configured for channel: ' . $channel['id']);
        return false;
    }
    
    $url = 'https://api.line.me/v2/bot/message/reply';
    
    // Build messages array
    $messages = [];
    
    // ✅ Add multiple text messages if present
    if (!empty($texts) && is_array($texts)) {
        foreach ($texts as $text) {
            $text = trim((string)$text);
            if ($text !== '') {
                $messages[] = [
                    'type' => 'text',
                    'text' => $text
                ];
                
                // LINE API limit: max 5 messages per reply
                if (count($messages) >= 5) {
                    Logger::warning("[LINE_WEBHOOK] Reached LINE API limit of 5 messages (stopping at text messages)");
                    break;
                }
            }
        }
    }
    
    // ✅ Add image messages from actions
    if (!empty($actions) && is_array($actions)) {
        Logger::info("[LINE_WEBHOOK] Processing " . count($actions) . " actions");
        
        $imageCount = 0;
        foreach ($actions as $idx => $action) {
            if (is_array($action) && isset($action['type']) && $action['type'] === 'image' && !empty($action['url'])) {
                $imageUrl = $action['url'];
                
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
                
                $imageCount++;
                Logger::info("[LINE_WEBHOOK] 📸 Added image #{$imageCount} to messages", [
                    'action_index' => $idx,
                    'image_url' => $imageUrl
                ]);
                
                // LINE API limit: max 5 messages per reply
                if (count($messages) >= 5) {
                    Logger::warning("[LINE_WEBHOOK] Reached LINE API limit of 5 messages");
                    break;
                }
            }
        }
        
        Logger::info("[LINE_WEBHOOK] ✅ Built LINE messages array: text + {$imageCount} images");
    }
    
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
            'response' => substr((string)$response, 0, 500)
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
 */
function upsertCustomerProfile(string $platform, string $platformUserId, string $channelAccessToken): void
{
    if (empty($platformUserId) || empty($channelAccessToken)) {
        return;
    }
    
    try {
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
        
        if ($httpCode !== 200) {
            Logger::warning('[LINE_PROFILE] Failed to get profile from LINE', [
                'platform_user_id' => $platformUserId,
                'http_code' => $httpCode,
            ]);
            return;
        }
        
        $profile = json_decode($resp, true);
        if (!is_array($profile)) {
            return;
        }
        
        $displayName = $profile['displayName'] ?? null;
        $avatarUrl = $profile['pictureUrl'] ?? null;
        
        if (!$displayName && !$avatarUrl) {
            return;
        }
        
        // Upsert into customer_profiles (INSERT ... ON DUPLICATE KEY UPDATE)
        $db = Database::getInstance();
        $sql = "
            INSERT INTO customer_profiles (platform, platform_user_id, display_name, avatar_url, first_seen_at, last_active_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                display_name = COALESCE(VALUES(display_name), display_name),
                avatar_url = COALESCE(VALUES(avatar_url), avatar_url),
                last_active_at = NOW(),
                updated_at = NOW()
        ";
        
        $db->execute($sql, [$platform, $platformUserId, $displayName, $avatarUrl]);
        
        Logger::info('[LINE_PROFILE] Customer profile upserted', [
            'platform' => $platform,
            'platform_user_id' => $platformUserId,
            'display_name' => $displayName,
        ]);
        
    } catch (Throwable $e) {
        Logger::error('[LINE_PROFILE] Error upserting customer profile: ' . $e->getMessage(), [
            'platform_user_id' => $platformUserId,
        ]);
    }
}
