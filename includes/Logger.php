<?php
/**
 * Simple Logger with Structured Logging
 */

// Skip if Logger already defined (e.g., in tests)
if (!class_exists('Logger')) {
    
class Logger {
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
    const CRITICAL = 'CRITICAL';
    
    private static $requestId = null;
    
    public static function init() {
        if (self::$requestId === null) {
            self::$requestId = uniqid('req_', true);
        }
    }
    
    public static function getRequestId() {
        self::init();
        return self::$requestId;
    }
    
    private static function log($level, $message, $context = []) {
        self::init();
        
        // Check log level
        $levels = [self::DEBUG => 0, self::INFO => 1, self::WARNING => 2, self::ERROR => 3, self::CRITICAL => 4];
        $configLevel = defined('LOG_LEVEL') ? LOG_LEVEL : self::INFO;
        
        if ($levels[$level] < $levels[$configLevel]) {
            return; // Skip lower priority logs
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'request_id' => self::$requestId,
            'message' => $message,
            'context' => $context,
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? ''
        ];
        
        // For Cloud Run: Always output to stderr for visibility in logs
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        if (defined('STDERR')) {
            fwrite(STDERR, $logLine);
        } else {
            // Fallback to error_log
            error_log($logLine);
        }
        
        // Also write to file if path is writable
        $logPath = defined('LOG_PATH') ? LOG_PATH : __DIR__ . '/../logs';
        if (is_writable(dirname($logPath)) || is_dir($logPath)) {
            if (!is_dir($logPath)) {
                @mkdir($logPath, 0755, true);
            }
            
            if (is_dir($logPath) && is_writable($logPath)) {
                $logFile = $logPath . '/app-' . date('Y-m-d') . '.log';
                @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
            }
        }
    }
    
    public static function debug($message, $context = []) {
        self::log(self::DEBUG, $message, $context);
    }
    
    public static function info($message, $context = []) {
        self::log(self::INFO, $message, $context);
    }
    
    public static function warning($message, $context = []) {
        self::log(self::WARNING, $message, $context);
    }
    
    public static function error($message, $context = []) {
        self::log(self::ERROR, $message, $context);
    }
    
    public static function critical($message, $context = []) {
        self::log(self::CRITICAL, $message, $context);
    }
    
    /**
     * Log API request
     */
    public static function apiRequest($method, $endpoint, $duration = 0, $statusCode = 200) {
        self::info('API Request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'duration_ms' => round($duration * 1000, 2),
            'status_code' => $statusCode
        ]);
    }
    
    /**
     * Log database query
     */
    public static function dbQuery($query, $duration = 0, $error = null) {
        if ($error) {
            self::error('Database Query Failed', [
                'query' => substr($query, 0, 200),
                'duration_ms' => round($duration * 1000, 2),
                'error' => $error
            ]);
        } else {
            self::debug('Database Query', [
                'query' => substr($query, 0, 200),
                'duration_ms' => round($duration * 1000, 2)
            ]);
        }
    }
}

} // End of class_exists check
