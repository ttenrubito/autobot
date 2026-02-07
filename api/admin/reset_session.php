<?php
/**
 * Reset Chat Session API
 * 
 * Clears conversation history and slots for a specific session or user.
 * Use this for testing fresh conversation flows.
 * 
 * Usage:
 * POST /api/admin/reset_session.php
 * {
 *   "channel_id": 1,
 *   "external_user_id": "123456789",  // Facebook PSID or LINE user ID
 *   "clear_messages": true,            // Optional: also delete chat_messages
 *   "clear_admin_handoff": true        // Optional: clear admin handoff timestamp
 * }
 * 
 * Or by session_id:
 * {
 *   "session_id": 24
 * }
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/Auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getInstance();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    
    $sessionId = $input['session_id'] ?? null;
    $channelId = $input['channel_id'] ?? null;
    $externalUserId = $input['external_user_id'] ?? null;
    $clearMessages = (bool) ($input['clear_messages'] ?? true);
    $clearAdminHandoff = (bool) ($input['clear_admin_handoff'] ?? true);
    $resetAll = (bool) ($input['reset_all'] ?? false);
    $botProfileId = $input['bot_profile_id'] ?? null;
    
    // =========================================================
    // RESET ALL SESSIONS (for testing)
    // =========================================================
    if ($resetAll) {
        $where = '1=1';
        $params = [];
        
        // Optionally filter by bot_profile_id
        if ($botProfileId) {
            $where = 'cs.channel_id IN (SELECT id FROM customer_channels WHERE bot_profile_id = ?)';
            $params[] = $botProfileId;
        }
        
        // Get all session IDs
        $sessions = $db->query("SELECT cs.id FROM chat_sessions cs WHERE {$where}", $params);
        $sessionIds = array_column($sessions, 'id');
        $totalSessions = count($sessionIds);
        
        if ($totalSessions === 0) {
            echo json_encode([
                'success' => true,
                'message' => 'No sessions to reset',
                'data' => ['sessions_reset' => 0],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $totalMessages = 0;
        
        // Delete all messages
        if ($clearMessages && !empty($sessionIds)) {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $msgCount = $db->queryOne(
                "SELECT COUNT(*) as cnt FROM chat_messages WHERE session_id IN ({$placeholders})",
                $sessionIds
            );
            $totalMessages = $msgCount['cnt'] ?? 0;
            
            $db->execute("DELETE FROM chat_messages WHERE session_id IN ({$placeholders})", $sessionIds);
        }
        
        // Reset all session states
        $updateFields = [
            'last_intent = NULL',
            'last_slots_json = NULL',
            'updated_at = NOW()',
        ];
        
        if ($clearAdminHandoff) {
            $updateFields[] = 'last_admin_message_at = NULL';
        }
        
        $db->execute(
            "UPDATE chat_sessions cs SET " . implode(', ', $updateFields) . " WHERE {$where}",
            $params
        );
        
        Logger::info('[ADMIN] Reset ALL sessions', [
            'bot_profile_id' => $botProfileId,
            'sessions_reset' => $totalSessions,
            'messages_deleted' => $totalMessages,
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'All sessions reset successfully',
            'data' => [
                'bot_profile_id' => $botProfileId,
                'sessions_reset' => $totalSessions,
                'messages_deleted' => $totalMessages,
                'cleared_admin_handoff' => $clearAdminHandoff,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    // =========================================================
    // RESET SINGLE SESSION
    // =========================================================
    
    // Find session
    if ($sessionId) {
        $session = $db->queryOne('SELECT * FROM chat_sessions WHERE id = ?', [$sessionId]);
    } elseif ($channelId && $externalUserId) {
        $session = $db->queryOne(
            'SELECT * FROM chat_sessions WHERE channel_id = ? AND external_user_id = ?',
            [$channelId, $externalUserId]
        );
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing required parameters: session_id OR (channel_id + external_user_id)',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!$session) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Session not found',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $sessionId = $session['id'];
    $result = [
        'session_id' => $sessionId,
        'channel_id' => $session['channel_id'],
        'external_user_id' => $session['external_user_id'],
        'before' => [
            'last_intent' => $session['last_intent'],
            'last_slots_json' => $session['last_slots_json'],
            'last_admin_message_at' => $session['last_admin_message_at'],
        ],
        'actions' => [],
    ];
    
    // Clear session state
    $updateFields = [
        'last_intent = NULL',
        'last_slots_json = NULL',
        'updated_at = NOW()',
    ];
    
    if ($clearAdminHandoff) {
        $updateFields[] = 'last_admin_message_at = NULL';
        $result['actions'][] = 'cleared_admin_handoff';
    }
    
    $db->execute(
        'UPDATE chat_sessions SET ' . implode(', ', $updateFields) . ' WHERE id = ?',
        [$sessionId]
    );
    $result['actions'][] = 'cleared_session_state';
    
    // Clear messages
    if ($clearMessages) {
        $msgCount = $db->queryOne(
            'SELECT COUNT(*) as cnt FROM chat_messages WHERE session_id = ?',
            [$sessionId]
        );
        $result['messages_deleted'] = $msgCount['cnt'] ?? 0;
        
        $db->execute('DELETE FROM chat_messages WHERE session_id = ?', [$sessionId]);
        $result['actions'][] = 'deleted_messages';
    }
    
    Logger::info('[ADMIN] Session reset', [
        'session_id' => $sessionId,
        'channel_id' => $session['channel_id'],
        'external_user_id' => $session['external_user_id'],
        'actions' => $result['actions'],
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Session reset successfully',
        'data' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    Logger::error('[ADMIN] Reset session error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
