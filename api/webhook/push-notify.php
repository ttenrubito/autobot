<?php
/**
 * Push Notification Webhook API
 * 
 * Endpoints:
 * POST /api/webhook/push-notify/send           - Send immediate notification
 * POST /api/webhook/push-notify/queue          - Queue notification for later
 * POST /api/webhook/push-notify/process        - Process pending queue (cron)
 * GET  /api/webhook/push-notify/status/{id}    - Get notification status
 * 
 * Security: Requires internal API key for production
 * 
 * @version 1.0
 * @date 2026-01-07
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/services/PushNotificationService.php';

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));

// Expected: /api/webhook/push-notify/{action}
$action = $_GET['action'] ?? ($uri_parts[3] ?? null);

// Verify internal API key (for production security)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
$expectedKey = getenv('INTERNAL_API_KEY') ?: 'dev-internal-key';

// Allow without key in dev mode
$isDev = !getenv('INSTANCE_CONN_NAME');
if (!$isDev && $apiKey !== $expectedKey) {
    // Check if called from same server
    $isLocalRequest = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR']]);
    if (!$isLocalRequest) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

try {
    $db = Database::getInstance();
    $pushService = new PushNotificationService($db);
    
    if ($method === 'POST' && $action === 'send') {
        // Send immediate notification
        sendNotification($pushService);
    } elseif ($method === 'POST' && $action === 'queue') {
        // Queue notification
        queueNotification($pushService);
    } elseif ($method === 'POST' && $action === 'process') {
        // Process pending queue
        processPending($pushService);
    } elseif ($method === 'GET' && $action === 'status') {
        // Get status
        getStatus($db);
    } elseif ($method === 'GET' && $action === 'stats') {
        // Get stats
        getStats($db);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    Logger::error('Push Notify Webhook Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Send immediate notification
 * 
 * Request body:
 * {
 *   "platform": "line|facebook",
 *   "platform_user_id": "U1234...|12345...",
 *   "notification_type": "payment_verified|...",
 *   "data": { "amount": 1000, ... },
 *   "channel_id": 1 (optional)
 * }
 */
function sendNotification($pushService) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['platform', 'platform_user_id', 'notification_type'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            return;
        }
    }
    
    $result = $pushService->send(
        $input['platform'],
        $input['platform_user_id'],
        $input['notification_type'],
        $input['data'] ?? [],
        $input['channel_id'] ?? null
    );
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Notification sent',
            'data' => $result
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send notification',
            'error' => $result['error'] ?? 'Unknown error'
        ]);
    }
}

/**
 * Queue notification for later
 */
function queueNotification($pushService) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['platform', 'platform_user_id', 'notification_type'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            return;
        }
    }
    
    $notificationId = $pushService->queue(
        $input['platform'],
        $input['platform_user_id'],
        $input['notification_type'],
        $input['data'] ?? [],
        $input['channel_id'] ?? null
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Notification queued',
        'data' => ['notification_id' => $notificationId]
    ]);
}

/**
 * Process pending notifications (for cron job)
 */
function processPending($pushService) {
    $input = json_decode(file_get_contents('php://input'), true);
    $limit = (int)($input['limit'] ?? 50);
    
    $results = $pushService->processPending($limit);
    
    Logger::info('Push notifications processed', $results);
    
    echo json_encode([
        'success' => true,
        'message' => "Processed {$results['sent']} sent, {$results['failed']} failed",
        'data' => $results
    ]);
}

/**
 * Get notification status
 */
function getStatus($db) {
    $notificationId = $_GET['notification_id'] ?? $_GET['id'] ?? null;
    
    if (!$notificationId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'notification_id is required']);
        return;
    }
    
    $notification = $db->queryOne(
        "SELECT * FROM push_notifications WHERE id = ?",
        [(int)$notificationId]
    );
    
    if (!$notification) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found']);
        return;
    }
    
    $notification['message_data'] = $notification['message_data'] ? json_decode($notification['message_data'], true) : null;
    $notification['api_response'] = $notification['api_response'] ? json_decode($notification['api_response'], true) : null;
    
    echo json_encode([
        'success' => true,
        'data' => $notification
    ]);
}

/**
 * Get notification stats
 */
function getStats($db) {
    $stats = $db->queryOne("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered
        FROM push_notifications
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    
    $byType = $db->queryAll("
        SELECT notification_type, COUNT(*) as count
        FROM push_notifications
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY notification_type
        ORDER BY count DESC
    ");
    
    echo json_encode([
        'success' => true,
        'data' => [
            'stats_24h' => $stats,
            'by_type' => $byType
        ]
    ]);
}
