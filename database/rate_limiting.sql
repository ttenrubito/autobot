-- ============================================
-- Rate Limiting Table
-- ============================================

CREATE TABLE IF NOT EXISTS rate_limits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    identifier VARCHAR(255) NOT NULL COMMENT 'IP address, username, or API key',
    action VARCHAR(50) NOT NULL COMMENT 'login, register, api_call, etc.',
    metadata JSON DEFAULT NULL COMMENT 'Additional context',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_limit_lookup (identifier, action, created_at),
    INDEX idx_rate_limit_cleanup (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auto cleanup old entries (optional, can be done via cron)
-- DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
