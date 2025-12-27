<?php
/**
 * Database Connection Handler
 * Singleton pattern for PDO connection
 * Supports both localhost (dev) and Cloud SQL (production)
 */

class Database
{
    private static $instance = null;
    private $conn;

    private function __construct()
    {
        try {
            // Get database credentials
            $instanceConn = getenv('INSTANCE_CONN_NAME'); // Cloud SQL connection name
            $dbname = getenv('DB_NAME') ?: 'autobot';
            $username = getenv('DB_USER') ?: 'root';
            $password = getenv('DB_PASSWORD') ?: '';
            
            // Auto-detect environment: Cloud Run uses unix socket, localhost uses TCP
            if ($instanceConn) {
                // PRODUCTION: Cloud SQL via unix socket
                $socket = "/cloudsql/{$instanceConn}";
                $dsn = "mysql:unix_socket={$socket};dbname={$dbname};charset=utf8mb4";
                error_log("Database: Connecting via Cloud SQL socket: {$socket}");
            } else {
                // DEVELOPMENT: Local MySQL via TCP
                $host = getenv('DB_HOST') ?: '127.0.0.1';
                $port = getenv('DB_PORT') ?: '3306';
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                error_log("Database: Connecting via localhost: {$host}:{$port}");
            }
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->conn = new PDO($dsn, $username, $password, $options);
            error_log("Database: Connected successfully");
            
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * Execute a SELECT query with prepared statements
     * Security: Prevents SQL Injection
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Database Query Error: " . $e->getMessage() . " | SQL: " . $sql);
            // Show actual error in development, generic in production
            $isProduction = getenv('INSTANCE_CONN_NAME') !== false;
            throw new Exception($isProduction ? "Database query failed" : "Database query failed: " . $e->getMessage());
        }
    }

    /**
     * Execute a SELECT query and return single row
     */
    public function queryOne($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Database Query Error: " . $e->getMessage() . " | SQL: " . $sql);
            $isProduction = getenv('INSTANCE_CONN_NAME') !== false;
            throw new Exception($isProduction ? "Database query failed" : "Database query failed: " . $e->getMessage());
        }
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Database Execute Error: " . $e->getMessage() . " | SQL: " . $sql);
            $isProduction = getenv('INSTANCE_CONN_NAME') !== false;
            throw new Exception($isProduction ? "Database operation failed" : "Database operation failed: " . $e->getMessage());
        }
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        return $this->conn->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->conn->rollBack();
    }

    /**
     * Count rows
     */
    public function count($table, $where = '', $params = []) {
        $sql = "SELECT COUNT(*) as count FROM $table";
        if ($where) {
            $sql .= " WHERE $where";
        }
        $result = $this->queryOne($sql, $params);
        return $result['count'] ?? 0;
    }

    /**
     * Check if record exists
     */
    public function exists($table, $where, $params = []) {
        return $this->count($table, $where, $params) > 0;
    }

    /**
     * Expose underlying PDO for advanced use cases (optional)
     */
    public function getPdo() {
        return $this->conn;
    }
}
