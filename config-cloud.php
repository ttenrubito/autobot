<?php
/**
 * Cloud Configuration for Google Cloud Run
 * This file handles environment-based configuration
 */

// Database Configuration (Environment-based)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'autobot');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Cloud SQL Socket (for Google Cloud)
$cloudSqlSocket = getenv('DB_SOCKET');
if ($cloudSqlSocket) {
    define('DB_SOCKET', $cloudSqlSocket);
}

/**
 * Get PDO Database Connection (Cloud-compatible)
 */
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            // Use socket connection for Cloud SQL if available
            if (defined('DB_SOCKET')) {
                $dsn = "mysql:unix_socket=" . DB_SOCKET . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            } else {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            }
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database Connection Error");
        }
    }
    
    return $pdo;
}

/**
 * Site Configuration
 */
define('SITE_NAME', 'AI Automate');
define('SITE_TAGLINE', 'บริการ AI Automation สำหรับธุรกิจ');
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost:8080/');

/**
 * JWT Configuration
 */
define('JWT_SECRET_KEY', getenv('JWT_SECRET_KEY') ?: 'AI_AUTOMATION_SECRET_KEY_CHANGE_IN_PRODUCTION_2024');
define('JWT_TOKEN_EXPIRY', getenv('JWT_TOKEN_EXPIRY') ?: 86400);

/**
 * Omise Configuration
 */
define('OMISE_PUBLIC_KEY', getenv('OMISE_PUBLIC_KEY') ?: 'pkey_test_xxxxx');
define('OMISE_SECRET_KEY', getenv('OMISE_SECRET_KEY') ?: 'skey_test_xxxxx');
define('OMISE_API_VERSION', '2019-05-29');

/**
 * Security Configuration
 */
define('ENABLE_HTTPS_ONLY', getenv('ENABLE_HTTPS_ONLY') === 'true');
define('SESSION_LIFETIME', getenv('SESSION_LIFETIME') ?: 1800);
define('MAX_LOGIN_ATTEMPTS', getenv('MAX_LOGIN_ATTEMPTS') ?: 5);
define('LOGIN_LOCKOUT_TIME', getenv('LOGIN_LOCKOUT_TIME') ?: 300);

/**
 * Environment
 */
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', getenv('APP_DEBUG') === 'true');

// Error reporting based on environment
if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

/**
 * Google Cloud API Configuration
 */
define('GOOGLE_VISION_API_KEY', getenv('GOOGLE_VISION_API_KEY') ?: '');
define('GOOGLE_LANGUAGE_API_KEY', getenv('GOOGLE_LANGUAGE_API_KEY') ?: '');
define('GOOGLE_APPLICATION_CREDENTIALS', __DIR__ . '/google-service-account.json');

/**
 * CORS Configuration
 */
define('ALLOWED_ORIGINS', explode(',', getenv('ALLOWED_ORIGINS') ?: 'http://localhost,http://localhost:8080'));

/**
 * API Gateway Settings
 */
define('API_REQUEST_TIMEOUT', (int)getenv('API_REQUEST_TIMEOUT') ?: 30);
define('API_MAX_IMAGE_SIZE', 10 * 1024 * 1024); // 10MB
define('API_CACHE_TTL', (int)getenv('API_CACHE_TTL') ?: 300); // 5 minutes

/**
 * Circuit Breaker Settings
 */
define('CIRCUIT_BREAKER_THRESHOLD', 5);
define('CIRCUIT_BREAKER_TIMEOUT', 30);

/**
 * Logging Configuration
 */
define('LOG_PATH', __DIR__ . '/logs');
define('LOG_LEVEL', getenv('LOG_LEVEL') ?: 'INFO');

