<?php
/**
 * PHPUnit Bootstrap
 * Setup test environment and load dependencies
 */

// Set timezone
date_default_timezone_set('Asia/Bangkok');

// Define test environment
define('TESTING_MODE', true);

// Load composer autoloader if exists
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Mock Logger (BEFORE any includes that might load real Logger)
if (!class_exists('Logger')) {
    class Logger
    {
        public static $logs = [];
        public static function info(string $message, array $context = []): void
        {
            self::$logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        }
        public static function error(string $message, array $context = []): void
        {
            self::$logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        }
        public static function warning(string $message, array $context = []): void
        {
            self::$logs[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
        }
        public static function clearLogs(): void
        {
            self::$logs = [];
        }
        public static function getLogs(): array
        {
            return self::$logs;
        }
    }
}

// Mock Database class BEFORE loading any includes
if (!class_exists('Database')) {
    class Database
    {
        private static $instance = null;
        private $mockData = [];
        private $executeCalls = [];
        private $queryCalls = [];
        
        public function __construct($host = null, $port = null, $dbname = null, $user = null, $pass = null)
        {
            // Mock constructor - do nothing
        }
        
        public static function getInstance()
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        public function queryOne(string $sql, array $params = []): ?array
        {
            $this->queryCalls[] = ['sql' => $sql, 'params' => $params];
            return $this->mockData['queryOne'] ?? null;
        }
        
        public function query(string $sql, array $params = []): array
        {
            $this->queryCalls[] = ['sql' => $sql, 'params' => $params];
            return $this->mockData['query'] ?? [];
        }
        
        public function execute(string $sql, array $params = []): bool
        {
            $this->executeCalls[] = ['sql' => $sql, 'params' => $params];
            return true;
        }
        
        public function setMockData(string $method, $data): void
        {
            $this->mockData[$method] = $data;
        }
        
        public function getExecuteCalls(): array
        {
            return $this->executeCalls;
        }
        
        public function getQueryCalls(): array
        {
            return $this->queryCalls;
        }
        
        public function resetCalls(): void
        {
            $this->executeCalls = [];
            $this->queryCalls = [];
        }
    }
}

// Load ONLY RouterV1Handler (it will try to load Logger/Database but we already have mocks)
// Suppress errors from includes trying to redeclare classes
set_error_handler(function($errno, $errstr) {
    if (strpos($errstr, 'Cannot declare class') !== false) {
        return true; // suppress
    }
    return false; // use default handler
});

require_once __DIR__ . '/../includes/bot/RouterV1Handler.php';

restore_error_handler();

echo "✅ Test bootstrap loaded\n";
