<?php
/**
 * JWT (JSON Web Token) Helper Class
 * SECURITY: Secure token-based authentication
 * Algorithm: HS256 (HMAC-SHA256)
 */

class JWT {
    // IMPORTANT: Change this secret key in production!
    private static $secretKey = 'AI_AUTOMATION_SECRET_KEY_CHANGE_IN_PRODUCTION_2024';
    private static $algorithm = 'HS256';
    private static $tokenExpiry = 86400; // 24 hours in seconds

    /**
     * Generate JWT token
     * @param array $payload User data to encode
     * @param int $expiry Token expiry in seconds (default: 24 hours)
     * @return string JWT token
     */
    public static function generate($payload, $expiry = null) {
        $expiry = $expiry ?? self::$tokenExpiry;
        
        // Header
        $header = [
            'typ' => 'JWT',
            'alg' => self::$algorithm
        ];

        // Add issued at and expiration time
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $expiry;

        // Encode
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        // Create signature
        $signature = self::sign($headerEncoded . '.' . $payloadEncoded);
        $signatureEncoded = self::base64UrlEncode($signature);

        // Return complete token
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Verify and decode JWT token
     * @param string $token JWT token
     * @return array|false Decoded payload or false if invalid
     */
    public static function verify($token) {
        // Split token
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Verify signature
        $signature = self::base64UrlDecode($signatureEncoded);
        $expectedSignature = self::sign($headerEncoded . '.' . $payloadEncoded);

        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // Decode payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);

        if (!$payload) {
            return false;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false; // Token expired
        }

        return $payload;
    }

    /**
     * Refresh token (generate new token with same data but new expiry)
     * @param string $token Old JWT token
     * @return string|false New JWT token or false if invalid
     */
    public static function refresh($token) {
        $payload = self::verify($token);
        
        if (!$payload) {
            return false;
        }

        // Remove old timestamps
        unset($payload['iat'], $payload['exp']);

        // Generate new token
        return self::generate($payload);
    }

    /**
     * Extract token from Authorization header
     * @return string|null Token or null if not found
     */
    public static function getBearerToken() {
        $headers = self::getAuthorizationHeader();
        
        if (!$headers) {
            return null;
        }

        // Check if Bearer token
        if (preg_match('/Bearer\s+(.*)$/i', $headers, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get Authorization header
     * @return string|null
     */
    private static function getAuthorizationHeader() {
        // Common CGI/FastCGI variants
        if (isset($_SERVER['Authorization'])) {
            return trim($_SERVER['Authorization']);
        }

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }

        // Some environments pass it via REDIRECT_HTTP_AUTHORIZATION
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }

        // Fall back to scanning all headers (Cloud Run / proxies can change casing)
        foreach ($_SERVER as $k => $v) {
            if (stripos($k, 'HTTP_AUTHORIZATION') !== false && is_string($v) && $v !== '') {
                return trim($v);
            }
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                return trim($headers['Authorization']);
            }
            // Case-insensitive lookup
            foreach ($headers as $hk => $hv) {
                if (strcasecmp($hk, 'Authorization') === 0) {
                    return trim($hv);
                }
            }
        }

        return null;
    }

    /**
     * Create HMAC signature
     * @param string $data Data to sign
     * @return string Signature
     */
    private static function sign($data) {
        return hash_hmac('sha256', $data, self::$secretKey, true);
    }

    /**
     * Base64 URL encode
     * @param string $data Data to encode
     * @return string Encoded string
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     * @param string $data Data to decode
     * @return string Decoded string
     */
    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Set custom secret key
     * @param string $key Secret key
     */
    public static function setSecretKey($key) {
        self::$secretKey = $key;
    }

    /**
     * Set custom token expiry
     * @param int $seconds Expiry in seconds
     */
    public static function setTokenExpiry($seconds) {
        self::$tokenExpiry = $seconds;
    }
}
