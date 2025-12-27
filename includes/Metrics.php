<?php
/**
 * Metrics Tracker for API performance
 */
class Metrics {
    private static $startTime = null;
    private static $endpoint = null;
    private static $userId = null;
    
    /**
     * Start tracking a request
     */
    public static function start($endpoint, $userId = null) {
        self::$startTime = microtime(true);
        self::$endpoint = $endpoint;
        self::$userId = $userId;
    }
    
    /**
     * Record metrics for completed request
     */
    public static function record($httpStatus, $errorCode = null) {
        if (self::$startTime === null) {
            return; // Not tracking
        }
        
        $duration = (microtime(true) - self::$startTime) * 1000; // ms
        
        try {
            $db = new Database();
            
            $requestId = Logger::getRequestId();
            $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            // Get request/response sizes
            $requestSize = null;
            $responseSize = null;
            
            if (isset($_SERVER['CONTENT_LENGTH'])) {
                $requestSize = (int)$_SERVER['CONTENT_LENGTH'];
            }
            
            // Get API key ID if available
            $apiKeyId = null;
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ??  $_SERVER['HTTP_X_API_KEY'] ?? null;
            if ($auth) {
                // This would require looking up the API key - skip for now
            }
            
            $db->execute(
                "INSERT INTO request_metrics 
                (request_id, endpoint, method, user_id, http_status, duration_ms, request_size, response_size, ip_address, user_agent, error_code, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $requestId,
                    self::$endpoint,
                    $method,
                    self::$userId,
                    $httpStatus,
                    round($duration, 2),
                    $requestSize,
                    $responseSize,
                    $ip,
                    substr($userAgent, 0, 500),
                    $errorCode
                ]
            );
            
        } catch (Exception $e) {
            // Don't fail the request if metrics recording fails
            error_log("Metrics recording failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get performance statistics for endpoint
     */
    public static function getStats($endpoint, $days = 7) {
        try {
            $db = new Database();
            
            $stats = $db->queryOne(
                "SELECT 
                    COUNT(*) as total_requests,
                    AVG(duration_ms) as avg_duration,
                    MIN(duration_ms) as min_duration,
                    MAX(duration_ms) as max_duration,
                    SUM(CASE WHEN http_status >= 200 AND http_status < 300 THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN http_status >= 400 THEN 1 ELSE 0 END) as error_count
                FROM request_metrics
                WHERE endpoint = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$endpoint, $days]
            );
            
            return $stats;
        } catch (Exception $e) {
            return null;
        }
    }
}
