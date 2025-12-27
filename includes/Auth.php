<?php
/**
 * Authentication & Session Management
 * SECURITY: JWT Token + Secure session handling with CSRF protection
 */

require_once __DIR__ . '/JWT.php';

class Auth {
    private static $sessionStarted = false;

    /**
     * Initialize secure session
     */
    public static function initSession() {
        if (self::$sessionStarted) {
            return;
        }

        // Security: Configure secure session settings
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Strict');
        
        // Use HTTPS in production
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', 1);
        }

        session_name('AUTOBOT_SESSION');
        session_start();
        self::$sessionStarted = true;

        // Regenerate session ID periodically to prevent session fixation
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }

    /**
     * Login user
     * @param int $userId User ID
     * @param array $userData Additional user data
     */
    public static function login($userId, $userData = []) {
        self::initSession();
        
        // Regenerate session ID on login (prevent session fixation)
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_data'] = $userData;
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        
        // Generate CSRF token
        self::generateCsrfToken();
    }

    /**
     * Logout user
     */
    public static function logout() {
        self::initSession();
        
        // Clear all session data
        $_SESSION = [];
        
        // Destroy session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        
        // Destroy session
        session_destroy();
    }

    /**
     * Check if user is authenticated via JWT token
     * @return array|false User data or false
     */
    public static function checkJWT() {
        $token = JWT::getBearerToken();
        
        if (!$token) {
            return false;
        }

        $payload = JWT::verify($token);
        
        if (!$payload) {
            return false;
        }

        return $payload;
    }

    /**
     * Check if user is logged in (JWT or Session)
     * @return bool
     */
    public static function check() {
        // First try JWT authentication
        $jwtUser = self::checkJWT();
        if ($jwtUser) {
            return true;
        }

        // Fallback to session authentication
        self::initSession();
        
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }

        // Security: Check for session hijacking
        if (!self::validateSession()) {
            self::logout();
            return false;
        }

        // Update last activity
        $_SESSION['last_activity'] = time();
        
        return true;
    }

    /**
     * Get user ID (JWT or Session)
     * @return int|null
     */
    public static function id() {
        // Try JWT first
        $jwtUser = self::checkJWT();
        
        // Debug: Log JWT payload structure
        if ($jwtUser) {
            error_log("Auth::id() - JWT Payload: " . json_encode($jwtUser));
            error_log("Auth::id() - user_id present: " . (isset($jwtUser['user_id']) ? "YES" : "NO"));
            error_log("Auth::id() - id present: " . (isset($jwtUser['id']) ? "YES" : "NO"));
        }
        
        // Check for user_id or id field
        if ($jwtUser) {
            if (isset($jwtUser['user_id'])) {
                return (int)$jwtUser['user_id'];
            }
            if (isset($jwtUser['id'])) {
                return (int)$jwtUser['id'];
            }
        }

        // Fallback to session
        self::initSession();
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current user data (JWT or Session)
     * @return array|null
     */
    public static function user() {
        // Try JWT first
        $jwtUser = self::checkJWT();
        if ($jwtUser) {
            return $jwtUser;
        }

        // Fallback to session
        self::initSession();
        return $_SESSION['user_data'] ?? null;
    }

    /**
     * Validate session integrity
     * Security: Prevent session hijacking
     */
    private static function validateSession() {
        // Check if IP address matches
        if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
            return false;
        }

        // Check if user agent matches
        if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
            return false;
        }

        // Check session timeout (30 minutes)
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
            return false;
        }

        return true;
    }


    /**
     * Require authentication (middleware)
     */
    public static function require() {
        if (!self::check()) {
            Response::unauthorized('Authentication required');
        }
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken() {
        self::initSession();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Get CSRF token
     */
    public static function getCsrfToken() {
        self::initSession();
        return $_SESSION['csrf_token'] ?? self::generateCsrfToken();
    }

    /**
     * Verify CSRF token
     * Security: Prevent CSRF attacks
     */
    public static function verifyCsrfToken($token) {
        self::initSession();
        
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Require CSRF token validation
     */
    public static function requireCsrfToken() {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        if (!$token || !self::verifyCsrfToken($token)) {
            Response::forbidden('Invalid CSRF token');
        }
    }

    /**
     * Hash password securely
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verify password
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    /**
     * Get client IP address
     */
    public static function getIpAddress() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }

    /**
     * Get user agent
     */
    public static function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
}
