<?php
/**
 * Rate Limiter - Prevent brute force attacks
 */
class RateLimiter {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Check if request is rate limited
     * @param string $identifier - IP address or username
     * @param string $action - login, api_call, etc.
     * @param int $maxAttempts - Maximum attempts allowed
     * @param int $windowSeconds - Time window in seconds
     * @return bool - True if rate limited
     */
    public function isRateLimited($identifier, $action, $maxAttempts = 5, $windowSeconds = 300) {
        // Clean up old entries first
        $this->cleanup($action, $windowSeconds);
        
        // Check current attempts
        $attempts = $this->db->queryOne(
            "SELECT COUNT(*) as count 
             FROM rate_limits 
             WHERE identifier = ? 
             AND action = ? 
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$identifier, $action, $windowSeconds]
        );
        
        $currentAttempts = (int)($attempts['count'] ?? 0);
        
        if ($currentAttempts >= $maxAttempts) {
            Logger::warning('Rate limit exceeded', [
                'identifier' => $identifier,
                'action' => $action,
                'attempts' => $currentAttempts
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Record an attempt
     */
    public function recordAttempt($identifier, $action, $metadata = null) {
        $this->db->execute(
            "INSERT INTO rate_limits (identifier, action, metadata, created_at) 
             VALUES (?, ?, ?, NOW())",
            [$identifier, $action, $metadata ? json_encode($metadata) : null]
        );
    }
    
    /**
     * Reset attempts for an identifier
     */
    public function reset($identifier, $action) {
        $this->db->execute(
            "DELETE FROM rate_limits WHERE identifier = ? AND action = ?",
            [$identifier, $action]
        );
    }
    
    /**
     * Clean up old entries
     */
    private function cleanup($action, $windowSeconds) {
        $this->db->execute(
            "DELETE FROM rate_limits 
             WHERE action = ? 
             AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$action, $windowSeconds * 2] // Keep 2x window for analysis
        );
    }
    
    /**
     * Get remaining attempts
     */
    public function getRemainingAttempts($identifier, $action, $maxAttempts = 5, $windowSeconds = 300) {
        $attempts = $this->db->queryOne(
            "SELECT COUNT(*) as count 
             FROM rate_limits 
             WHERE identifier = ? 
             AND action = ? 
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$identifier, $action, $windowSeconds]
        );
        
        $currentAttempts = (int)($attempts['count'] ?? 0);
        return max(0, $maxAttempts - $currentAttempts);
    }
    
    /**
     * Get time until reset
     */
    public function getTimeUntilReset($identifier, $action, $windowSeconds = 300) {
        $oldest = $this->db->queryOne(
            "SELECT created_at 
             FROM rate_limits 
             WHERE identifier = ? 
             AND action = ? 
             ORDER BY created_at ASC 
             LIMIT 1",
            [$identifier, $action]
        );
        
        if (!$oldest) {
            return 0;
        }
        
        $oldestTime = strtotime($oldest['created_at']);
        $resetTime = $oldestTime + $windowSeconds;
        $now = time();
        
        return max(0, $resetTime - $now);
    }
}
