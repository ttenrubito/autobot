<?php
/**
 * Debug: Check bot_chat_logs and conversation history
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/bot/services/ChatService.php';

use Autobot\Bot\Services\ChatService;

try {
    $db = Database::getInstance();
    
    $result = [
        'timestamp' => date('Y-m-d H:i:s'),
        'checks' => [],
    ];
    
    // 1. Check table schema
    $schema = $db->query("DESCRIBE bot_chat_logs");
    $result['checks']['schema'] = [
        'columns' => array_column($schema, 'Field'),
        'ok' => in_array('direction', array_column($schema, 'Field')) 
             && in_array('message_content', array_column($schema, 'Field')),
    ];
    
    // 2. Get recent message counts
    $counts = $db->query("SELECT direction, COUNT(*) as cnt, MAX(created_at) as latest 
                          FROM bot_chat_logs 
                          GROUP BY direction");
    $result['checks']['message_counts'] = $counts;
    
    // 3. Get 10 most recent messages
    $recent = $db->query("SELECT id, platform_user_id, direction, message_type, 
                          LEFT(message_content, 100) as content_preview, created_at 
                          FROM bot_chat_logs 
                          ORDER BY created_at DESC LIMIT 10");
    $result['checks']['recent_messages'] = $recent;
    
    // 4. Test getConversationHistory for a specific user (if provided)
    $testUserId = $_GET['user_id'] ?? null;
    $testChannelId = $_GET['channel_id'] ?? null;
    
    if ($testUserId && $testChannelId) {
        $chatService = new ChatService();
        $context = [
            'platform_user_id' => $testUserId,
            'channel' => ['id' => (int)$testChannelId],
        ];
        
        $history = $chatService->getHistoryForLLM($context, 10);
        $result['checks']['history_for_llm'] = [
            'user_id' => $testUserId,
            'channel_id' => $testChannelId,
            'formatted_history' => $history,
            'length' => strlen($history),
        ];
    } else {
        // Get a sample user to test - simpler query
        try {
            $sampleUser = $db->query("SELECT platform_user_id, customer_service_id 
                                      FROM bot_chat_logs 
                                      WHERE created_at > NOW() - INTERVAL 24 HOUR 
                                      ORDER BY created_at DESC LIMIT 1");
            if (!empty($sampleUser)) {
                $sample = $sampleUser[0];
                // Get channel_id from customer_service_id
                $channels = $db->query("SELECT cc.id as channel_id 
                                        FROM customer_channels cc 
                                        JOIN customer_services cs ON cs.user_id = cc.user_id 
                                        WHERE cs.id = ?", 
                                       [$sample['customer_service_id']]);
                
                if (!empty($channels)) {
                    $channel = $channels[0];
                    $chatService = new ChatService();
                    $context = [
                        'platform_user_id' => $sample['platform_user_id'],
                        'channel' => ['id' => (int)$channel['channel_id']],
                    ];
                    
                    $history = $chatService->getHistoryForLLM($context, 10);
                    $result['checks']['sample_history'] = [
                        'user_id' => $sample['platform_user_id'],
                        'channel_id' => $channel['channel_id'],
                        'formatted_history' => $history,
                        'length' => strlen($history),
                    ];
                } else {
                    $result['checks']['sample_history'] = ['error' => 'No channel found'];
                }
            } else {
                $result['checks']['sample_history'] = ['error' => 'No recent messages'];
            }
        } catch (Exception $e) {
            $result['checks']['sample_history'] = ['error' => $e->getMessage()];
        }
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ], JSON_PRETTY_PRINT);
}
