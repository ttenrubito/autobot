<?php
/**
 * AntiSpamService - Spam detection and prevention
 * 
 * Features:
 * - Repeated message detection
 * - Duplicate delivery detection (webhook duplicates)
 * - Rate limiting
 * 
 * @version 1.0
 * @date 2026-01-23
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../Logger.php';

class AntiSpamService
{
    protected $db;

    public function __construct()
    {
        $this->db = \Database::getInstance();
    }

    /**
     * Check if message is a duplicate webhook delivery
     * 
     * @param int $sessionId Session ID
     * @param string $text Message text
     * @param int $windowSeconds Time window to check (default 3 seconds)
     * @return bool True if duplicate
     */
    public function isDuplicateDelivery(int $customerServiceId, string $text, int $windowSeconds = 3): bool
    {
        if (empty($text)) {
            return false;
        }

        try {
            // bot_chat_logs uses: customer_service_id, direction, message_content
            $sql = "SELECT COUNT(*) as cnt FROM bot_chat_logs 
                    WHERE customer_service_id = ?
                    AND direction = 'incoming'
                    AND message_content = ?
                    AND created_at >= NOW() - INTERVAL ? SECOND";

            $row = $this->db->queryOne($sql, [$customerServiceId, $text, $windowSeconds]);
            
            return ($row['cnt'] ?? 0) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if user is sending repeated messages (spam)
     * 
     * @param int $sessionId Session ID
     * @param string $text Normalized message text
     * @param int $threshold Number of repeats before triggering
     * @param int $windowSeconds Time window to check
     * @return bool True if spam detected
     */
    public function isRepeatedMessage(int $customerServiceId, string $text, int $threshold = 3, int $windowSeconds = 25): bool
    {
        $normalized = $this->normalizeForComparison($text);
        
        if (empty($normalized)) {
            return false;
        }

        // Bypass ultra-short texts and acknowledgements
        if ($this->shouldBypass($normalized)) {
            return false;
        }

        try {
            // bot_chat_logs uses: customer_service_id, direction, message_content
            $sql = "SELECT message_content FROM bot_chat_logs 
                    WHERE customer_service_id = ?
                    AND direction = 'incoming'
                    AND created_at >= NOW() - INTERVAL ? SECOND
                    ORDER BY created_at DESC
                    LIMIT 10";

            $rows = $this->db->query($sql, [$customerServiceId, $windowSeconds]);
            
            $matchCount = 0;
            foreach ($rows as $row) {
                $msgNormalized = $this->normalizeForComparison($row['message_content'] ?? '');
                if ($msgNormalized === $normalized) {
                    $matchCount++;
                }
            }

            return $matchCount >= $threshold;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if user is rate limited
     * 
     * @param int $sessionId Session ID
     * @param int $maxMessages Max messages allowed
     * @param int $windowSeconds Time window
     * @return bool True if rate limited
     */
    public function isRateLimited(int $customerServiceId, int $maxMessages = 20, int $windowSeconds = 60): bool
    {
        try {
            // bot_chat_logs uses: customer_service_id, direction
            $sql = "SELECT COUNT(*) as cnt FROM bot_chat_logs 
                    WHERE customer_service_id = ?
                    AND direction = 'incoming'
                    AND created_at >= NOW() - INTERVAL ? SECOND";

            $row = $this->db->queryOne($sql, [$customerServiceId, $windowSeconds]);
            
            return ($row['cnt'] ?? 0) >= $maxMessages;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get spam action from config
     * 
     * @param array $config Bot config
     * @return array ['action' => string, 'message' => string]
     */
    public function getSpamAction(array $config): array
    {
        $antiSpamCfg = $config['anti_spam'] ?? [];
        
        return [
            'action' => $antiSpamCfg['action'] ?? 'template', // template | silent | handoff
            'message' => $antiSpamCfg['default_reply'] 
                ?? 'เหมือนข้อความเดิมเมื่อสักครู่ค่ะ 😊 รบกวนพิมพ์รายละเอียดเพิ่มอีกนิดนะคะ',
        ];
    }

    /**
     * Normalize text for spam comparison
     */
    protected function normalizeForComparison(string $text): string
    {
        // Lowercase
        $text = mb_strtolower(trim($text), 'UTF-8');
        
        // Remove whitespace
        $text = preg_replace('/\s+/', '', $text);
        
        // Remove common Thai particles
        $text = preg_replace('/(ครับ|ค่ะ|คะ|นะ|จ้า|จ๊า)/u', '', $text);
        
        return $text;
    }

    /**
     * Check if text should bypass spam check
     */
    protected function shouldBypass(string $normalized): bool
    {
        // Very short texts
        if (mb_strlen($normalized, 'UTF-8') <= 3) {
            return true;
        }

        // Common acknowledgements
        $ackSet = [
            'ok', 'okay', 'kk', 'k', 'thx', 'thanks', 'ty',
            'ค่ะ', 'ครับ', 'คับ', 'จ้า', 'ได้', 'ได้ค่ะ', 'ได้ครับ',
            'yes', 'no', 'y', 'n', 'ใช่', 'ไม่', 'โอเค', 'โอ', 'ดี',
        ];

        if (in_array($normalized, $ackSet, true)) {
            return true;
        }

        return false;
    }
}
