-- Buffer table for message debounce / turn-taking per user per channel

CREATE TABLE IF NOT EXISTS message_buffers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel_id BIGINT UNSIGNED NOT NULL,
  external_user_id VARCHAR(191) NOT NULL,
  buffer_text TEXT NOT NULL,
  first_message_at DATETIME NOT NULL,
  last_message_at DATETIME NOT NULL,
  status ENUM('pending','flushed') NOT NULL DEFAULT 'pending',
  last_event_id VARCHAR(191) DEFAULT NULL,
  KEY idx_channel_user_status (channel_id, external_user_id, status),
  KEY idx_last_message_at (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
