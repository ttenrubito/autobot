<?php
/**
 * Database Configuration
 * Auto-detects environment: Cloud Run (env vars) or localhost (defaults)
 */

// Use environment variables if available (Cloud Run), otherwise use localhost defaults
define('DB_HOST', getenv('DB_HOST') ?: (getenv('INSTANCE_CONN_NAME') ? null : 'localhost'));
define('DB_NAME', getenv('DB_NAME') ?: 'autobot');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get PDO Database Connection
 * NOTE: This is a legacy function. Use Database::getInstance() instead for better error handling.
 */
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            // Cloud Run uses unix socket, localhost uses TCP
            $instanceConn = getenv('INSTANCE_CONN_NAME');
            
            if ($instanceConn) {
                // PRODUCTION: Cloud SQL via unix socket
                $socket = "/cloudsql/{$instanceConn}";
                $dsn = "mysql:unix_socket={$socket};dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            } else {
                // DEVELOPMENT: Local MySQL via TCP
                $host = DB_HOST ?: 'localhost';
                $dsn = "mysql:host={$host};dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            }
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("getDB() Error: " . $e->getMessage());
            die("Database Connection Error: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

/**
 * Site Configuration
 */
define('SITE_NAME', 'AI Automate');
define('SITE_TAGLINE', 'บริการ AI Automation สำหรับธุรกิจ');

// Auto-detect BASE_URL based on environment
if (getenv('INSTANCE_CONN_NAME')) {
    // Production: Cloud Run
    define('BASE_URL', 'https://autobot.boxdesign.in.th/');
} else {
    // Development: Local
    define('BASE_URL', 'http://localhost/autobot/');
}

/**
 * Load additional security configuration
 */
if (file_exists(__DIR__ . '/config-security.php')) {
    require_once __DIR__ . '/config-security.php';
}
?>
