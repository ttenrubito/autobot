-- Add new columns to chat_sessions table (MySQL 5.7 compatible)
ALTER TABLE chat_sessions ADD COLUMN active_case_id BIGINT UNSIGNED NULL;
ALTER TABLE chat_sessions ADD COLUMN active_case_type VARCHAR(50) NULL;
