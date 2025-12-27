<?php
// MessageBuffer.php
// Lightweight message buffering + debounce per (channel_id, external_user_id)

require_once __DIR__ . '/../Database.php';

class MessageBuffer
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Decide if this message should bypass buffer and be answered immediately.
     * Uses simple keyword/pattern rules + message type.
     */
    public function shouldBypass(array $message, array $bufferConfig): bool
    {
        $type = $message['message_type'] ?? ($message['type'] ?? 'text');
        if ($type !== 'text') {
            // images/files/others → respond immediately
            return true;
        }

        $text = trim((string)($message['text'] ?? ''));
        if ($text === '') {
            return false;
        }

        $textLower = mb_strtolower($text, 'UTF-8');

        $bypassKeywords = $bufferConfig['bypass_keywords'] ?? ['ด่วน', 'ตอนนี้', 'โอนแล้ว', 'สลิป', 'ยกเลิก'];
        foreach ($bypassKeywords as $kw) {
            $kw = trim($kw);
            if ($kw !== '' && mb_stripos($textLower, mb_strtolower($kw, 'UTF-8')) !== false) {
                return true;
            }
        }

        // simple question patterns / explicit questions
        $bypassQuestions = $bufferConfig['bypass_question_patterns'] ?? ['ราคาเท่าไหร่', 'กี่บาท', '?'];
        foreach ($bypassQuestions as $q) {
            $q = trim($q);
            if ($q === '') {
                continue;
            }
            if ($q === '?' && str_ends_with($text, '?')) {
                return true;
            }
            if ($q !== '?' && mb_stripos($textLower, mb_strtolower($q, 'UTF-8')) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Append message text into buffer and decide whether to flush now or wait.
     *
     * Returns:
     * - ['action' => 'buffered'] → do not call handler
     * - ['action' => 'flush', 'combined_text' => '...'] → call handler with combined text
     */
    public function handle(array $channel, string $externalUserId, array $message, array $bufferConfig): array
    {
        $now = new DateTimeImmutable('now');
        $bufferWindow = (int)($bufferConfig['buffer_window_seconds'] ?? 3);
        $maxWait = (int)($bufferConfig['max_wait_seconds'] ?? 10);

        $text = trim((string)($message['text'] ?? ''));

        // Check for existing pending buffer
        $existingPending = $this->db->queryOne(
            "SELECT * FROM message_buffers WHERE channel_id = ? AND external_user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$channel['id'], $externalUserId]
        );

        // If pending buffer exists, this is a rapid follow-up message
        if ($existingPending) {
            // Append to existing buffer
            $combined = trim(($existingPending['buffer_text'] ?? '') . "\n" . $text);
            $this->db->execute(
                "UPDATE message_buffers SET buffer_text = ?, last_message_at = NOW(), last_event_id = ? WHERE id = ?",
                [$combined, $message['event_id'] ?? null, $existingPending['id']]
            );

            $first = new DateTimeImmutable($existingPending['first_message_at']);
            $last = new DateTimeImmutable($existingPending['last_message_at']);

            $sinceFirst = $now->getTimestamp() - $first->getTimestamp();
            $sinceLast = $now->getTimestamp() - $last->getTimestamp();

            // Check if we should flush the combined messages
            if ($sinceLast >= $bufferWindow || $sinceFirst >= $maxWait) {
                // Time window expired, flush combined messages
                $this->db->execute(
                    "UPDATE message_buffers SET status = 'flushed' WHERE id = ?",
                    [$existingPending['id']]
                );

                return [
                    'action' => 'flush',
                    'combined_text' => $combined,
                ];
            }

            // Still within buffer window, keep waiting
            return ['action' => 'buffered'];
        }

        // No pending buffer - check if there's a recently flushed buffer (within buffer window)
        $recentlyFlushed = $this->db->queryOne(
            "SELECT * FROM message_buffers 
             WHERE channel_id = ? AND external_user_id = ? 
             AND status = 'flushed' 
             AND last_message_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
             ORDER BY id DESC LIMIT 1",
            [$channel['id'], $externalUserId, $bufferWindow]
        );

        if ($recentlyFlushed) {
            // User is sending rapid messages - create new pending buffer for combining
            $this->db->execute(
                "INSERT INTO message_buffers (channel_id, external_user_id, buffer_text, first_message_at, last_message_at, status, last_event_id)
                 VALUES (?, ?, ?, NOW(), NOW(), 'pending', ?)",
                [$channel['id'], $externalUserId, $text, $message['event_id'] ?? null]
            );

            return ['action' => 'buffered'];
        }

        // First message or messages well-spaced apart - flush immediately
        $this->db->execute(
            "INSERT INTO message_buffers (channel_id, external_user_id, buffer_text, first_message_at, last_message_at, status, last_event_id)
             VALUES (?, ?, ?, NOW(), NOW(), 'flushed', ?)",
            [$channel['id'], $externalUserId, $text, $message['event_id'] ?? null]
        );

        return [
            'action' => 'flush',
            'combined_text' => $text,
        ];
    }
}
