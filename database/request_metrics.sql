-- ============================================
-- Request Metrics Table
-- ============================================

CREATE TABLE IF NOT EXISTS request_metrics (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    request_id VARCHAR(64) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    user_id INT DEFAULT NULL,
    api_key_id INT DEFAULT NULL,
    http_status INT NOT NULL,
    duration_ms DECIMAL(10,2) NOT NULL,
    request_size INT DEFAULT NULL COMMENT 'Bytes',
    response_size INT DEFAULT NULL COMMENT 'Bytes',
    ip_address VARCHAR(45),
    user_agent TEXT,
    error_code VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_endpoint (endpoint, created_at),
    INDEX idx_user (user_id, created_at),
    INDEX idx_status (http_status, created_at),
    INDEX idx_request_id (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- API Performance Analytics View
-- ============================================

CREATE OR REPLACE VIEW v_api_performance AS
SELECT 
    endpoint,
    DATE(created_at) as date,
    COUNT(*) as total_requests,
    AVG(duration_ms) as avg_duration_ms,
    MIN(duration_ms) as min_duration_ms,
    MAX(duration_ms) as max_duration_ms,
    PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms) as p95_duration_ms,
    SUM(CASE WHEN http_status >= 200 AND http_status < 300 THEN 1 ELSE 0 END) as success_count,
    SUM(CASE WHEN http_status >= 400 THEN 1 ELSE 0 END) as error_count,
    ROUND(100.0 * SUM(CASE WHEN http_status >= 200 AND http_status < 300 THEN 1 ELSE 0 END) / COUNT(*), 2) as success_rate
FROM request_metrics
GROUP BY endpoint, DATE(created_at);

-- ============================================
-- Cleanup old metrics (keep only 90 days)
-- ============================================

-- Run this periodically via cron
-- DELETE FROM request_metrics WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
