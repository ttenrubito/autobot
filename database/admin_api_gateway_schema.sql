-- ============================================
-- Admin Panel & API Gateway Schema Updates
-- ============================================

-- Admin users table
CREATE TABLE IF NOT EXISTS admin_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255),
    email VARCHAR(255),
    role ENUM('super_admin', 'admin', 'support') DEFAULT 'admin',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API service configuration (global settings)
CREATE TABLE IF NOT EXISTS api_service_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    service_code VARCHAR(50) UNIQUE NOT NULL,
    service_name VARCHAR(255) NOT NULL,
    description TEXT,
    is_enabled BOOLEAN DEFAULT TRUE,
    rate_limit_per_minute INT DEFAULT 60,
    rate_limit_per_day INT DEFAULT 10000,
    cost_per_request DECIMAL(10,4) DEFAULT 0.001,
    google_api_endpoint VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_service_code (service_code),
    INDEX idx_enabled (is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer API access control
CREATE TABLE IF NOT EXISTS customer_api_access (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    service_code VARCHAR(50) NOT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    daily_limit INT DEFAULT NULL COMMENT 'NULL means no limit',
    monthly_limit INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_service (user_id, service_code),
    INDEX idx_user (user_id),
    INDEX idx_service (service_code),
    INDEX idx_enabled (is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API keys for customers (for n8n integration)
CREATE TABLE IF NOT EXISTS api_keys (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    api_key VARCHAR(64) UNIQUE NOT NULL,
    name VARCHAR(255) DEFAULT 'Default API Key',
    is_active BOOLEAN DEFAULT TRUE,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_api_key (api_key),
    INDEX idx_user (user_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Insert Sample Data
-- ============================================

-- Create default admin user (username: admin, password: admin123)
INSERT INTO admin_users (username, password_hash, full_name, email, role) VALUES
('admin', '$2y$10$gPpELdyYS5M9HcbqSJ.PGOZ2D3vs/Anz4wub71JAhXMBWnn2/Ekm2', 'System Administrator', 'admin@aiautomation.com', 'super_admin');

-- Insert API service configurations
INSERT INTO api_service_config (service_code, service_name, description, is_enabled, rate_limit_per_minute, rate_limit_per_day, cost_per_request, google_api_endpoint) VALUES
('google_vision_labels', 'Google Vision - Label Detection', 'Detect and extract labels/tags from images', TRUE, 60, 5000, 0.0015, 'https://vision.googleapis.com/v1/images:annotate'),
('google_vision_text', 'Google Vision - Text Detection (OCR)', 'Extract text from images using OCR', TRUE, 60, 5000, 0.0015, 'https://vision.googleapis.com/v1/images:annotate'),
('google_vision_faces', 'Google Vision - Face Detection', 'Detect faces in images', TRUE, 60, 5000, 0.0015, 'https://vision.googleapis.com/v1/images:annotate'),
('google_vision_objects', 'Google Vision - Object Detection', 'Detect and localize objects in images', TRUE, 60, 5000, 0.0015, 'https://vision.googleapis.com/v1/images:annotate'),
('google_nl_sentiment', 'Google Natural Language - Sentiment Analysis', 'Analyze sentiment of text', TRUE, 60, 10000, 0.001, 'https://language.googleapis.com/v1/documents:analyzeSentiment'),
('google_nl_entities', 'Google Natural Language - Entity Extraction', 'Extract entities from text', TRUE, 60, 10000, 0.001, 'https://language.googleapis.com/v1/documents:analyzeEntities'),
('google_nl_syntax', 'Google Natural Language - Syntax Analysis', 'Analyze syntax and parts of speech', TRUE, 60, 10000, 0.001, 'https://language.googleapis.com/v1/documents:analyzeSyntax');

-- Grant API access to demo user (user_id = 1) for all services
INSERT INTO customer_api_access (user_id, service_code, is_enabled, daily_limit, monthly_limit) VALUES
(1, 'google_vision_labels', TRUE, 1000, 30000),
(1, 'google_vision_text', TRUE, 1000, 30000),
(1, 'google_vision_faces', TRUE, 500, 15000),
(1, 'google_vision_objects', TRUE, 500, 15000),
(1, 'google_nl_sentiment', TRUE, 2000, 60000),
(1, 'google_nl_entities', TRUE, 2000, 60000),
(1, 'google_nl_syntax', TRUE, 1000, 30000);

-- Generate API key for demo user (sample key - will be regenerated properly via API)
INSERT INTO api_keys (user_id, api_key, name, is_active) VALUES
(1, CONCAT('ak_', MD5(CONCAT('demo@aiautomation.com', UNIX_TIMESTAMP(), RAND()))), 'n8n Integration Key', TRUE);
