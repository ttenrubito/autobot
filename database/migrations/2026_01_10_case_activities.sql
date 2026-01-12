-- Case Activities Table
CREATE TABLE IF NOT EXISTS case_activities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id BIGINT UNSIGNED NOT NULL,
    activity_type ENUM('created', 'status_changed', 'assigned', 'slot_updated', 'customer_message', 'bot_message', 'admin_message', 'note_added', 'resolved', 'reopened', 'merged', 'linked_order', 'linked_payment') NOT NULL,
    description TEXT NULL,
    old_value JSON NULL,
    new_value JSON NULL,
    actor_type ENUM('bot', 'customer', 'admin', 'system') NOT NULL,
    actor_id VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_case (case_id),
    INDEX idx_activity_type (activity_type),
    INDEX idx_created (created_at),
    CONSTRAINT fk_case_activities_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
