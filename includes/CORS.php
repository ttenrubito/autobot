<?php
/**
 * CORS Helper
 */
class CORS {
    public static function setCORSHeaders() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (defined('ALLOWED_ORIGINS')) {
            $allowedOrigins = ALLOWED_ORIGINS;
            if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
                header("Access-Control-Allow-Origin: $origin");
                header('Access-Control-Allow-Credentials: true');
            }
        } else {
            // Fallback for development
            if (strpos($origin, 'localhost') !== false) {
                header("Access-Control-Allow-Origin: $origin");
            }
        }
        
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
        header('Access-Control-Max-Age: 3600');
    }
    
    public static function handlePreflightRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            self::setCORSHeaders();
            http_response_code(200);
            exit;
        }
    }
}
