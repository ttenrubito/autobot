<?php
/**
 * ChatService - Chat session and message management
 * 
 * Handles:
 * - Session management
 * - Message logging
 * - Conversation history
 * - Chat state management
 * 
 * @version 1.0
 * @date 2026-01-23
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../Logger.php';

class ChatService
{
    protected $db;
    protected $cachePrefix = 'chat_session:';
    protected $sessionTtl = 3600; // 1 hour

    public function __construct()
    {
        $this->db = \Database::getInstance();
    }

    // ==================== SESSION MANAGEMENT ====================

    /**
     * Get or create chat session
     */
    public function getOrCreateSession(array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        if (!$platformUserId || !$channelId) {
            return $this->createEmptySession();
        }

        // Try to get existing active session
        $session = $this->getActiveSession($platformUserId, $channelId);
        
        if (!$session) {
            $session = $this->createSession($platformUserId, $channelId);
        }

        return $session;
    }

    /**
     * Get active session from database
     * Note: chat_sessions uses external_user_id and channel_id
     */
    protected function getActiveSession(string $platformUserId, int $channelId): ?array
    {
        try {
            $sql = "SELECT * FROM chat_sessions 
                    WHERE external_user_id = ? 
                    AND channel_id = ? 
                    AND updated_at > NOW() - INTERVAL 24 HOUR
                    ORDER BY updated_at DESC 
                    LIMIT 1";
            
            $result = $this->db->queryOne($sql, [$platformUserId, $channelId]);
            return $result ?: null; // Convert false to null
        } catch (\Exception $e) {
            \Logger::warning("[ChatService] Failed to get active session", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Create new chat session
     * Note: chat_sessions uses external_user_id and channel_id
     */
    protected function createSession(string $platformUserId, int $channelId): array
    {
        try {
            $sql = "INSERT INTO chat_sessions 
                    (external_user_id, channel_id, created_at, updated_at) 
                    VALUES (?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE updated_at = NOW()";
            
            $this->db->execute($sql, [$platformUserId, $channelId]);
            $sessionId = $this->db->lastInsertId();
            
            // If ON DUPLICATE KEY, get the existing session ID
            if (!$sessionId) {
                $existing = $this->getActiveSession($platformUserId, $channelId);
                $sessionId = $existing['id'] ?? null;
            }

            return [
                'id' => $sessionId,
                'external_user_id' => $platformUserId,
                'channel_id' => $channelId,
                'context_data' => []
            ];
        } catch (\Exception $e) {
            \Logger::warning("[ChatService] Failed to create session", ['error' => $e->getMessage()]);
            return $this->createEmptySession();
        }
    }

    /**
     * Create empty session object (fallback)
     */
    protected function createEmptySession(): array
    {
        return [
            'id' => null,
            'status' => 'transient',
            'context_data' => []
        ];
    }

    /**
     * Update session context data
     */
    public function updateSessionContext(int $sessionId, array $contextData): bool
    {
        if (!$sessionId) {
            return false;
        }

        try {
            $sql = "UPDATE chat_sessions 
                    SET context_data = ?, updated_at = NOW() 
                    WHERE id = ?";
            
            $this->db->execute($sql, [json_encode($contextData, JSON_UNESCAPED_UNICODE), $sessionId]);
            return true;
        } catch (\Exception $e) {
            \Logger::warning("[ChatService] Failed to update session context", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * End session
     */
    public function endSession(int $sessionId, string $reason = 'completed'): bool
    {
        if (!$sessionId) {
            return false;
        }

        try {
            $sql = "UPDATE chat_sessions 
                    SET status = 'ended', ended_at = NOW(), end_reason = ?, updated_at = NOW() 
                    WHERE id = ?";
            
            $this->db->execute($sql, [$reason, $sessionId]);
            return true;
        } catch (\Exception $e) {
            \Logger::warning("[ChatService] Failed to end session", ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ==================== MESSAGE LOGGING ====================

    /**
     * Log incoming message
     */
    public function logIncomingMessage(array $context, string $message, string $messageType = 'text'): ?int
    {
        return $this->logMessage($context, $message, 'incoming', $messageType);
    }

    /**
     * Log outgoing message (bot reply)
     */
    public function logOutgoingMessage(array $context, string $message, string $messageType = 'text'): ?int
    {
        return $this->logMessage($context, $message, 'outgoing', $messageType);
    }

    /**
     * Log message to database
     * Uses bot_chat_logs table which exists in production
     * Columns: customer_service_id, platform_user_id, direction, message_type, message_content
     */
    protected function logMessage(array $context, string $message, string $direction, string $messageType): ?int
    {
        $channelId = $context['channel']['id'] ?? null;
        $platformUserId = $context['platform_user_id'] ?? $context['external_user_id'] ?? null;

        if (!$channelId || !$platformUserId) {
            return null;
        }

        // Get customer_service_id from channel
        $customerServiceId = $this->getCustomerServiceId($channelId);
        if (!$customerServiceId) {
            return null;
        }

        try {
            // bot_chat_logs uses: customer_service_id, platform_user_id, direction, message_type, message_content
            $sql = "INSERT INTO bot_chat_logs 
                    (customer_service_id, platform_user_id, direction, message_type, message_content, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())";
            
            $this->db->execute($sql, [
                $customerServiceId,
                $platformUserId,
                $direction, // 'incoming' or 'outgoing'
                $messageType,
                mb_substr($message, 0, 5000) // Limit message length
            ]);

            return (int)$this->db->lastInsertId();
        } catch (\Exception $e) {
            // Silently fail - logging is not critical
            \Logger::warning("[ChatService] Failed to log message", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get customer_service_id from channel_id
     * customer_channels links to customer_services via user_id
     */
    protected function getCustomerServiceId(int $channelId): ?int
    {
        try {
            // customer_channels has user_id, customer_services also has user_id
            // Need to join through user_id and match platform
            $row = $this->db->queryOne(
                "SELECT cs.id as customer_service_id 
                 FROM customer_channels cc
                 JOIN customer_services cs ON cs.user_id = cc.user_id
                 WHERE cc.id = ? 
                 LIMIT 1",
                [$channelId]
            );
            return $row ? ($row['customer_service_id'] ?? null) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Log intent detection result
     */
    public function logIntent(array $context, string $detectedIntent, float $confidence, array $metadata = []): void
    {
        $messageId = $context['message_id'] ?? null;
        
        if (!$messageId) {
            return;
        }

        try {
            $sql = "UPDATE chat_messages 
                    SET detected_intent = ?, intent_confidence = ?, intent_metadata = ? 
                    WHERE id = ?";
            
            $this->db->execute($sql, [
                $detectedIntent,
                $confidence,
                json_encode($metadata),
                $messageId
            ]);
        } catch (\Exception $e) {
            \Logger::warning("[ChatService] Failed to log intent", ['error' => $e->getMessage()]);
        }
    }

    // ==================== CONVERSATION HISTORY ====================

    /**
     * Get recent conversation history for LLM context
     * ✅ FIXED: Uses chat_messages table (via chat_sessions) which has complete checkout flow data
     */
    public function getConversationHistory(array $context, int $limit = 10): array
    {
        $platformUserId = $context['platform_user_id'] ?? $context['external_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        if (!$platformUserId || !$channelId) {
            \Logger::warning("[ChatService] Missing platformUserId or channelId for history", [
                'platformUserId' => $platformUserId,
                'channelId' => $channelId,
            ]);
            return [];
        }

        try {
            // ✅ FIXED: Use chat_messages table which has complete checkout flow data
            // Join with chat_sessions to get session_id from external_user_id + channel_id
            // chat_messages has: session_id, role (user/assistant/system), text, created_at
            $sql = "SELECT 
                        cm.role,
                        cm.text as message,
                        cm.created_at 
                    FROM chat_messages cm
                    JOIN chat_sessions cs ON cm.session_id = cs.id
                    WHERE cs.external_user_id = ? 
                    AND cs.channel_id = ?
                    AND cm.created_at > NOW() - INTERVAL 24 HOUR
                    ORDER BY cm.created_at DESC 
                    LIMIT ?";
            
            $rows = $this->db->query($sql, [$platformUserId, $channelId, $limit]);
            
            \Logger::info("[ChatService] Retrieved conversation history from chat_messages", [
                'platformUserId' => $platformUserId,
                'channelId' => $channelId,
                'count' => count($rows),
            ]);
            
            // Reverse to get chronological order
            return array_reverse($rows);
        } catch (\Exception $e) {
            \Logger::warning("[ChatService] Failed to get conversation history", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get formatted history for LLM
     * Uses chat_messages format (role: user/assistant/system)
     */
    public function getHistoryForLLM(array $context, int $limit = 10): string
    {
        $history = $this->getConversationHistory($context, $limit);
        
        if (empty($history)) {
            return '';
        }

        $formatted = [];
        foreach ($history as $msg) {
            $role = $msg['role'] ?? 'user';
            // Map role to Thai label
            switch ($role) {
                case 'user':
                    $roleLabel = 'ลูกค้า';
                    break;
                case 'assistant':
                    $roleLabel = 'บอท';
                    break;
                case 'system':
                    $roleLabel = 'ระบบ';
                    break;
                default:
                    $roleLabel = 'ลูกค้า';
            }
            
            $content = $msg['message'] ?? '';
            
            // Skip empty or image-only messages
            if (empty($content) || strpos($content, '[image]') === 0) {
                continue;
            }
            
            // Skip system admin messages (they start with [admin])
            if ($role === 'system' && strpos($content, '[admin]') === 0) {
                continue;
            }
            
            $formatted[] = "{$roleLabel}: {$content}";
        }

        return implode("\n", $formatted);
    }

    // ==================== QUICK STATE MANAGEMENT ====================

    /**
     * Get quick state (for backward compatibility with existing code)
     * Uses chat_state table with external_user_id and channel_id
     */
    public function getQuickState(string $key, string $platformUserId, int $channelId)
    {
        try {
            // 🧹 Lazy Cleanup: 1% chance to clean expired states
            // Prevents data bloat without impacting every request
            if (mt_rand(1, 100) === 1) {
                $this->cleanupExpiredStates();
            }
            
            $sql = "SELECT value FROM chat_state 
                    WHERE state_key = ? 
                    AND external_user_id = ? 
                    AND channel_id = ?
                    AND expires_at > NOW()";
            
            $row = $this->db->queryOne($sql, [$key, $platformUserId, $channelId]);
            
            if (!$row) {
                return null;
            }

            $value = $row['value'] ?? null;
            $decoded = json_decode($value, true);
            
            return $decoded !== null ? $decoded : $value;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Set quick state
     * Uses chat_state table with external_user_id and channel_id
     */
    public function setQuickState(string $key, $value, string $platformUserId, int $channelId, int $ttlSeconds = 3600): bool
    {
        try {
            $jsonValue = is_array($value) || is_object($value) 
                ? json_encode($value, JSON_UNESCAPED_UNICODE) 
                : (string)$value;

            $sql = "INSERT INTO chat_state (state_key, value, external_user_id, channel_id, expires_at, created_at) 
                    VALUES (?, ?, ?, ?, NOW() + INTERVAL ? SECOND, NOW())
                    ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at)";
            
            $this->db->execute($sql, [$key, $jsonValue, $platformUserId, $channelId, $ttlSeconds]);
            return true;
        } catch (\Exception $e) {
            \Logger::warning("[ChatService] Failed to set state", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete quick state
     * Uses chat_state table with external_user_id and channel_id
     */
    public function deleteQuickState(string $key, string $platformUserId, int $channelId): bool
    {
        try {
            $sql = "DELETE FROM chat_state 
                    WHERE state_key = ? 
                    AND external_user_id = ? 
                    AND channel_id = ?";
            
            $this->db->execute($sql, [$key, $platformUserId, $channelId]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clean up expired states
     */
    public function cleanupExpiredStates(): int
    {
        try {
            $sql = "DELETE FROM chat_state WHERE expires_at < NOW()";
            return $this->db->execute($sql);
        } catch (\Exception $e) {
            return 0;
        }
    }
}
