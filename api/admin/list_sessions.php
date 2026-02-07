<?php
/**
 * List Chat Sessions API
 * 
 * Lists chat sessions for a specific bot profile or channel.
 * 
 * Usage:
 * GET /api/admin/list_sessions.php?bot_profile_id=1
 * GET /api/admin/list_sessions.php?channel_id=1
 * GET /api/admin/list_sessions.php?bot_profile_id=1&limit=20
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getInstance();
    
    $botProfileId = $_GET['bot_profile_id'] ?? null;
    $channelId = $_GET['channel_id'] ?? null;
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
    
    $params = [];
    $where = [];
    
    if ($botProfileId) {
        $where[] = 'c.bot_profile_id = ?';
        $params[] = $botProfileId;
    }
    
    if ($channelId) {
        $where[] = 'cs.channel_id = ?';
        $params[] = $channelId;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "SELECT cs.id as session_id, cs.channel_id, cs.external_user_id,
                   cs.last_intent, cs.last_slots_json,
                   cs.last_admin_message_at, cs.created_at, cs.updated_at,
                   c.type as platform, c.bot_profile_id,
                   bp.name as bot_profile_name,
                   (SELECT COUNT(*) FROM chat_messages WHERE session_id = cs.id) as message_count
            FROM chat_sessions cs
            JOIN customer_channels c ON cs.channel_id = c.id
            LEFT JOIN customer_bot_profiles bp ON c.bot_profile_id = bp.id
            {$whereClause}
            ORDER BY cs.updated_at DESC
            LIMIT {$limit}";
    
    $sessions = $db->query($sql, $params);
    
    // Format for readability
    $formatted = [];
    foreach ($sessions as $s) {
        $slots = null;
        if (!empty($s['last_slots_json'])) {
            $slots = json_decode($s['last_slots_json'], true);
        }
        
        $formatted[] = [
            'session_id' => (int) $s['session_id'],
            'channel_id' => (int) $s['channel_id'],
            'platform' => $s['platform'],
            'bot_profile_id' => (int) $s['bot_profile_id'],
            'bot_profile_name' => $s['bot_profile_name'],
            'external_user_id' => $s['external_user_id'],
            'message_count' => (int) $s['message_count'],
            'last_intent' => $s['last_intent'],
            'last_slots' => $slots,
            'last_admin_message_at' => $s['last_admin_message_at'],
            'created_at' => $s['created_at'],
            'updated_at' => $s['updated_at'],
        ];
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($formatted),
        'data' => $formatted,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    Logger::error('[ADMIN] List sessions error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
