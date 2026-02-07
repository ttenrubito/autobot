<?php
/**
 * AdminHandoffService
 * 
 * จัดการ admin handoff logic - เมื่อไหร่ที่บอทควรหยุดตอบและให้แอดมินเข้ามาตอบแทน
 * 
 * @package Autobot\Bot\Services
 * @version 1.0.0
 * @date 2026-02-05
 */

namespace Autobot\Bot\Services;

use Database;
use Logger;

class AdminHandoffService
{
    private $db;
    
    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }
    
    /**
     * Check if message is from admin context
     * 
     * @param array $context Message context
     * @param array $message Original message data
     * @return bool True if from admin
     */
    public function isAdminContext(array $context, array $message = []): bool
    {
        // Explicit flag
        if (!empty($context['is_admin'])) {
            return true;
        }

        // Check user role
        if (!empty($context['user']['is_admin'])) {
            return true;
        }

        // Facebook page echo
        if (!empty($message['is_echo'])) {
            return true;
        }

        // Check sender_is_page
        if (!empty($context['sender_is_page'])) {
            return true;
        }

        return false;
    }

    /**
     * Handle admin message - update timestamps and log
     * 
     * @param array $context Message context
     * @param string $text Admin's message text
     * @param int|null $sessionId Chat session ID
     */
    public function handleAdminMessage(array $context, string $text, ?int $sessionId): void
    {
        if (!$sessionId) {
            return;
        }

        // Update last admin message timestamp
        try {
            $this->db->execute(
                'UPDATE chat_sessions SET last_admin_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$sessionId]
            );
        } catch (\Exception $e) {
            Logger::error('[ADMIN_HANDOFF] Failed to update admin timestamp', ['error' => $e->getMessage()]);
        }

        Logger::info('[ADMIN_HANDOFF] Admin message handled', [
            'session_id' => $sessionId,
            'text_preview' => substr($text, 0, 50),
        ]);
    }

    /**
     * Check if admin handoff is still active (admin recently sent message)
     * 
     * @param int|null $sessionId Chat session ID
     * @param array $config Bot config
     * @return bool True if handoff is active
     */
    public function isAdminHandoffActive(?int $sessionId, array $config = []): bool
    {
        if (!$sessionId) {
            return false;
        }

        try {
            $row = $this->db->queryOne(
                'SELECT last_admin_message_at FROM chat_sessions WHERE id = ? LIMIT 1',
                [$sessionId]
            );

            $lastAdminMsg = $row['last_admin_message_at'] ?? null;
            
            if (!$lastAdminMsg) {
                return false;
            }

            $handoffCfg = $config['handoff'] ?? [];
            $timeoutSec = (int)($handoffCfg['timeout_seconds'] ?? 300);
            
            $lastAdminTime = strtotime($lastAdminMsg);
            $elapsed = time() - $lastAdminTime;

            return $elapsed < $timeoutSec;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Activate admin handoff - bot will stop auto-replying for a while
     * Sets last_admin_message_at timestamp to trigger handoff mode
     * 
     * @param int|null $sessionId Session ID
     * @param array $context Message context
     * @param string $reason Reason for handoff (for logging)
     */
    public function activateAdminHandoff(?int $sessionId, array $context = [], string $reason = 'manual'): void
    {
        if (!$sessionId) {
            Logger::warning('[ADMIN_HANDOFF] Cannot activate handoff - no session_id', ['reason' => $reason]);
            return;
        }

        try {
            $this->db->execute(
                'UPDATE chat_sessions SET last_admin_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$sessionId]
            );
            
            Logger::info('[ADMIN_HANDOFF] Admin handoff activated', [
                'session_id' => $sessionId,
                'reason' => $reason,
                'platform_user_id' => $context['platform_user_id'] ?? null,
            ]);
        } catch (\Exception $e) {
            Logger::error('[ADMIN_HANDOFF] Failed to activate admin handoff', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);
        }
    }
    
    /**
     * Deactivate admin handoff - allow bot to resume auto-replying
     * 
     * @param int|null $sessionId Session ID
     */
    public function deactivateAdminHandoff(?int $sessionId): void
    {
        if (!$sessionId) {
            return;
        }

        try {
            $this->db->execute(
                'UPDATE chat_sessions SET last_admin_message_at = NULL, updated_at = NOW() WHERE id = ?',
                [$sessionId]
            );
            
            Logger::info('[ADMIN_HANDOFF] Admin handoff deactivated', [
                'session_id' => $sessionId,
            ]);
        } catch (\Exception $e) {
            Logger::error('[ADMIN_HANDOFF] Failed to deactivate admin handoff', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);
        }
    }
    
    /**
     * Get handoff status info
     * 
     * @param int|null $sessionId Session ID
     * @param array $config Bot config
     * @return array Status info
     */
    public function getHandoffStatus(?int $sessionId, array $config = []): array
    {
        if (!$sessionId) {
            return [
                'active' => false,
                'reason' => 'no_session',
            ];
        }

        try {
            $row = $this->db->queryOne(
                'SELECT last_admin_message_at FROM chat_sessions WHERE id = ? LIMIT 1',
                [$sessionId]
            );

            $lastAdminMsg = $row['last_admin_message_at'] ?? null;
            
            if (!$lastAdminMsg) {
                return [
                    'active' => false,
                    'reason' => 'no_admin_message',
                ];
            }

            $handoffCfg = $config['handoff'] ?? [];
            $timeoutSec = (int)($handoffCfg['timeout_seconds'] ?? 300);
            
            $lastAdminTime = strtotime($lastAdminMsg);
            $elapsed = time() - $lastAdminTime;
            $remaining = max(0, $timeoutSec - $elapsed);
            
            return [
                'active' => $elapsed < $timeoutSec,
                'last_admin_message_at' => $lastAdminMsg,
                'elapsed_seconds' => $elapsed,
                'remaining_seconds' => $remaining,
                'timeout_seconds' => $timeoutSec,
            ];
        } catch (\Exception $e) {
            return [
                'active' => false,
                'reason' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Check if text triggers admin handoff request
     * 
     * @param string $text User's message
     * @return bool True if user is requesting admin
     */
    public function isAdminRequest(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        
        $patterns = [
            'ขอคุยกับแอดมิน',
            'ขอคุยกับคน',
            'ขอคุยกับพนักงาน',
            'ต้องการคุยกับคน',
            'ติดต่อแอดมิน',
            'ติดต่อพนักงาน',
            'เรียกแอดมิน',
            'เรียกคน',
            'ขอพูดกับคน',
            'talk to human',
            'talk to admin',
            'agent',
            'human',
        ];
        
        foreach ($patterns as $pattern) {
            if (mb_strpos($text, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
