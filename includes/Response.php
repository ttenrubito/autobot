<?php
/**
 * Enhanced Response Helper with Error Codes
 */
class Response {
    // Error codes
    const ERR_UNAUTHORIZED = 'UNAUTHORIZED';
    const ERR_ACCESS_DENIED = 'ACCESS_DENIED';
    const ERR_RATE_LIMIT = 'RATE_LIMIT_EXCEEDED';
    const ERR_PAYLOAD_TOO_LARGE = 'PAYLOAD_TOO_LARGE';
    const ERR_SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    const ERR_INVALID_REQUEST = 'INVALID_REQUEST';
    const ERR_VALIDATION_ERROR = 'VALIDATION_ERROR';
    const ERR_INTERNAL_ERROR = 'INTERNAL_ERROR';
    const ERR_NOT_FOUND = 'NOT_FOUND';
    const ERR_GOOGLE_API_ERROR = 'GOOGLE_API_ERROR';
    
    /**
     * Send success response
     */
    public static function success($data = null, $message = 'Success', $httpCode = 200) {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        
        $response = [
            'success' => true,
            'message' => $message
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        // Add request ID if available
        if (class_exists('Logger')) {
            $response['request_id'] = Logger::getRequestId();
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Send error response with error code
     */
    public static function error($message = 'Error', $httpCode = 500, $errorCode = null, $details = null) {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        
        // Auto-detect error code from HTTP status if not provided
        if ($errorCode === null) {
            $errorCode = self::getErrorCodeFromHttpStatus($httpCode);
        }
        
        $response = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode
        ];
        
        if ($details !== null) {
            $response['details'] = $details;
        }
        
        // Add request ID if available
        if (class_exists('Logger')) {
            $response['request_id'] = Logger::getRequestId();
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Convenience helper: 401 Unauthorized
     * Used by Auth::require() and other middleware.
     */
    public static function unauthorized($message = 'Authentication required', $details = null) {
        // 401 with standard UNAUTHORIZED error code
        self::error(
            $message,
            401,
            self::ERR_UNAUTHORIZED,
            $details
        );
    }
    
    /**
     * Send validation error response
     */
    public static function validationError($errors, $message = 'Validation failed') {
        self::error($message, 400, self::ERR_VALIDATION_ERROR, ['errors' => $errors]);
    }
    
    /**
     * Rate limit error with retry information
     */
    public static function rateLimitError($limit, $used, $resetTime = null) {
        $details = [
            'limit' => $limit,
            'used' => $used
        ];
        
        if ($resetTime) {
            $details['reset_at'] = $resetTime;
            $details['retry_after_seconds'] = max(0, $resetTime - time());
        }
        
        self::error(
            'Rate limit exceeded. Please try again later.',
            429,
            self::ERR_RATE_LIMIT,
            $details
        );
    }
    
    /**
     * Get error code from HTTP status
     */
    private static function getErrorCodeFromHttpStatus($httpCode) {
        $map = [
            401 => self::ERR_UNAUTHORIZED,
            403 => self::ERR_ACCESS_DENIED,
            404 => self::ERR_NOT_FOUND,
            413 => self::ERR_PAYLOAD_TOO_LARGE,
            429 => self::ERR_RATE_LIMIT,
            503 => self::ERR_SERVICE_UNAVAILABLE,
            400 => self::ERR_INVALID_REQUEST,
            500 => self::ERR_INTERNAL_ERROR
        ];
        
        return $map[$httpCode] ?? self::ERR_INTERNAL_ERROR;
    }
    
    /**
     * Send JSON response (generic)
     */
    public static function json($data, $httpCode = 200) {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
