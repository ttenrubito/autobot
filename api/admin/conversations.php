<?php
/**
 * Admin Conversations API
 * GET /api/admin/conversations - List all conversations
 * GET /api/admin/conversations/{id}/messages - Get messages
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/AdminAuth.php';

$auth = verifyAdminToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));

try {
    $pdo = getDB();
    
    if ($method === 'GET') {
        if (isset($uri_parts[3]) && $uri_parts[4] === 'messages') {
            // GET /api/admin/conversations/{id}/messages
            $conversation_id = $uri_parts[3];
            
            $stmt = $pdo->prepare("
                SELECT * FROM chat_messages
                WHERE conversation_id = ?
                ORDER BY sent_at ASC
            ");
            $stmt->execute([$conversation_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($messages as &$msg) {
                $msg['message_data'] = $msg['message_data'] ? json_decode($msg['message_data'], true) : null;
                $msg['entities'] = $msg['entities'] ? json_decode($msg['entities'], true) : null;
            }
            
            echo json_encode(['success' => true, 'data' => ['messages' => $messages]]);
            
        } else {
            // GET /api/admin/conversations - List all
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
            $offset = ($page - 1) * $limit;
            
            $customer_id = isset($_GET['customer_id']) ? $_GET['customer_id'] : null;
            $platform = isset($_GET['platform']) ? $_GET['platform'] : null;
            $date = isset($_GET['date']) ? $_GET['date'] : null;
            
            $where = ['1=1'];
            $params = [];
            
            if ($customer_id) {
                $where[] = 'c.customer_id = ?';
                $params[] = $customer_id;
            }
            
            if ($platform) {
                $where[] = 'c.platform = ?';
                $params[] = $platform;
            }
            
            if ($date) {
                $where[] = 'DATE(c.started_at) = ?';
                $params[] = $date;
            }
            
            $where_clause = implode(' AND ', $where);
            
            // Get conversations
            $stmt = $pdo->prepare("
                SELECT 
                    c.*,
                    u.full_name as customer_name,
                    u.email as customer_email
                FROM conversations c
                JOIN users u ON c.customer_id = u.id
                WHERE $where_clause
                ORDER BY c.started_at DESC
                LIMIT ? OFFSET ?
            ");
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($conversations as &$conv) {
                $conv['conversation_summary'] = $conv['conversation_summary'] ? 
                    json_decode($conv['conversation_summary'], true) : null;
            }
            
            // Get customers list for filter
            $stmt = $pdo->query("SELECT id, full_name, email FROM users ORDER BY full_name ASC LIMIT 100");
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'conversations' => $conversations,
                    'customers' => $customers
                ]
            ]);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Admin Conversations API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}
