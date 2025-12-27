-- Add idempotency table for gateway message events

CREATE TABLE IF NOT EXISTS gateway_message_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel_id BIGINT UNSIGNED NOT NULL,
  external_event_id VARCHAR(191) NOT NULL,
  response_payload JSON NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_channel_event (channel_id, external_event_id),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
