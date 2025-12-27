<?php
/**
 * Customer Conversations API
 * GET /api/customer/conversations - Get all conversations for logged-in user
 * GET /api/customer/conversations/{id}/messages - Get messages in a conversation
 * GET /api/customer/conversations/stats - Get conversation statistics
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

// Verify authentication
$auth = verifyToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $auth['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Parse URI to get conversation ID
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
// Expected: api/customer/conversations[/{id}/messages]

try {
    $pdo = getDB();
    
    if ($method === 'GET') {
        // Check if requesting messages for specific conversation
        if (isset($uri_parts[3]) && $uri_parts[3] !== 'stats') {
            // GET /api/customer/conversations/{id}/messages
            $conversation_id = $uri_parts[3];
            
            // Verify conversation belongs to user
            $stmt = $pdo->prepare("
                SELECT id FROM conversations 
                WHERE conversation_id = ? AND customer_id = ?
            ");
            $stmt->execute([$conversation_id, $user_id]);
            
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Conversation not found']);
                exit;
            }
            
            // Get messages
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    message_id,
                    platform,
                    direction,
                    sender_type,
                    message_type,
                    message_text,
                    message_data,
                    intent,
                    confidence,
                    entities,
                    sent_at,
                    received_at
                FROM chat_messages
                WHERE conversation_id = ?
                ORDER BY sent_at ASC
            ");
            $stmt->execute([$conversation_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON fields
            foreach ($messages as &$msg) {
                $msg['message_data'] = $msg['message_data'] ? json_decode($msg['message_data'], true) : null;
                $msg['entities'] = $msg['entities'] ? json_decode($msg['entities'], true) : null;
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'conversation_id' => $conversation_id,
                    'messages' => $messages,
                    'count' => count($messages)
                ]
            ]);
            
        } elseif (end($uri_parts) === 'stats') {
            // GET /api/customer/conversations/stats
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_conversations,
                    SUM(message_count) as total_messages,
                    SUM(CASE WHEN platform = 'line' THEN 1 ELSE 0 END) as line_count,
                    SUM(CASE WHEN platform = 'facebook' THEN 1 ELSE 0 END) as facebook_count,
                    MAX(last_message_at) as last_activity
                FROM conversations
                WHERE customer_id = ?
            ");
            $stmt->execute([$user_id]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $stats
            ]);
            
        } else {
            // GET /api/customer/conversations - List all conversations
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
            $offset = ($page - 1) * $limit;
            
            $platform = isset($_GET['platform']) ? $_GET['platform'] : null;
            $search = isset($_GET['search']) ? $_GET['search'] : null;
            
            // Build query
            $where = ['customer_id = ?'];
            $params = [$user_id];
            
            if ($platform) {
                $where[] = 'platform = ?';
                $params[] = $platform;
            }
            
            $where_clause = implode(' AND ', $where);
            
            // Get total count
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM conversations
                WHERE $where_clause
            ");
            $stmt->execute($params);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get conversations
            $stmt = $pdo->prepare("
                SELECT 
                    conversation_id,
                    platform,
                    platform_user_id,
                    started_at,
                    last_message_at,
                    ended_at,
                    status,
                    message_count,
                    conversation_summary
                FROM conversations
                WHERE $where_clause
                ORDER BY last_message_at DESC
                LIMIT ? OFFSET ?
            ");
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON fields
            foreach ($conversations as &$conv) {
                $conv['conversation_summary'] = $conv['conversation_summary'] ? 
                    json_decode($conv['conversation_summary'], true) : null;
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'conversations' => $conversations,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'total_pages' => ceil($total / $limit)
                    ]
                ]
            ]);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Conversations API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}
