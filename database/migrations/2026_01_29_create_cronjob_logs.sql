-- Create cronjob_logs table for monitoring
-- Run this migration on production database

CREATE TABLE IF NOT EXISTS `cronjob_logs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `job_id` VARCHAR(100) NOT NULL COMMENT 'Unique identifier for the cronjob',
    `status` ENUM('success', 'error', 'skipped', 'running') NOT NULL DEFAULT 'running',
    `result` JSON DEFAULT NULL COMMENT 'JSON result from execution',
    `error_message` TEXT DEFAULT NULL,
    `duration_ms` INT DEFAULT NULL COMMENT 'Execution time in milliseconds',
    `executed_at` DATETIME NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_job_id` (`job_id`),
    KEY `idx_executed_at` (`executed_at`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample data for testing
-- INSERT INTO cronjob_logs (job_id, status, result, duration_ms, executed_at) VALUES
-- ('installment-reminders', 'success', '{"processed": 5, "reminders_sent": 3}', 1234, NOW());
