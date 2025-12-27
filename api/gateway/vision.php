<?php
/**
 * API Gateway - Google Vision API Proxy
 * POST /api/gateway/vision/{feature}
 * 
 * Features: labels, text, faces, objects
 * 
 * Requires header: X-API-Key
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/CORS.php';
require_once __DIR__ . '/../../config-cloud.php';

CORS::setCORSHeaders();
CORS::handlePreflightRequest();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$startTime = microtime(true);

try {
    // Get API key from header
    $headers = getallheaders();
    $apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? '';
    
    if (empty($apiKey)) {
        Logger::warning('API Gateway - Missing API key', ['endpoint' => 'vision']);
        Response::error('API key is required', 401);
    }
    
    $db = Database::getInstance();
    
    // Validate API key and get user
    $keyData = $db->queryOne(
        "SELECT ak.user_id, ak.is_active, u.status as user_status
         FROM api_keys ak
         JOIN users u ON ak.user_id = u.id
         WHERE ak.api_key = ? AND ak.is_active = TRUE
         AND (ak.expires_at IS NULL OR ak.expires_at > NOW())
         LIMIT 1",
        [$apiKey]
    );
    
    if (!$keyData) {
        Logger::warning('API Gateway - Invalid API key', ['endpoint' => 'vision', 'api_key' => substr($apiKey, 0, 10) . '...']);
        Response::error('Invalid or expired API key', 401);
    }
    
    if ($keyData['user_status'] !== 'active') {
        Logger::warning('API Gateway - Inactive user', ['user_id' => $keyData['user_id']]);
        Response::error('User account is not active', 403);
    }
    
    $userId = $keyData['user_id'];
    
    // Detect feature from path
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    $feature = end($pathParts); // labels, text, faces, objects
    
    // Map feature to service code
    $serviceCodeMap = [
        'labels' => 'google_vision_labels',
        'text' => 'google_vision_text',
        'faces' => 'google_vision_faces',
        'objects' => 'google_vision_objects'
    ];
    
    $serviceCode = $serviceCodeMap[$feature] ?? null;
    
    if (!$serviceCode) {
        Logger::warning('API Gateway - Invalid feature', ['feature' => $feature]);
        Response::error('Invalid feature. Use: labels, text, faces, or objects', 400);
    }
    
    // Check if service is globally enabled
    $serviceConfig = $db->queryOne(
        "SELECT * FROM api_service_config WHERE service_code = ? LIMIT 1",
        [$serviceCode]
    );
    
    if (!$serviceConfig || !$serviceConfig['is_enabled']) {
        Logger::info('API Gateway - Service unavailable', ['service' => $serviceCode]);
        Response::error('This service is currently unavailable', 503);
    }
    
    // Check if user has access to this service
    $userAccess = $db->queryOne(
        "SELECT * FROM customer_api_access 
         WHERE user_id = ? AND service_code = ? 
         LIMIT 1",
        [$userId, $serviceCode]
    );
    
    if (!$userAccess || !$userAccess['is_enabled']) {
        Logger::warning('API Gateway - Access denied', ['user_id' => $userId, 'service' => $serviceCode]);
        Response::error('You do not have access to this service', 403);
    }
    
    // Check rate limits (today's usage)
    $todayUsage = $db->queryOne(
        "SELECT COALESCE(SUM(request_count), 0) as total
         FROM api_usage_logs
         WHERE customer_service_id IN (
             SELECT id FROM customer_services WHERE user_id = ?
         )
         AND api_type = ?
         AND DATE(created_at) = CURDATE()",
        [$userId, $serviceCode]
    );
    
    $dailyUsage = (int)($todayUsage['total'] ?? 0);
    
    if ($userAccess['daily_limit'] && $dailyUsage >= $userAccess['daily_limit']) {
        Logger::warning('API Gateway - Rate limit exceeded', ['user_id' => $userId, 'service' => $serviceCode, 'usage' => $dailyUsage]);
        Response::error('Daily rate limit exceeded', 429);
    }
    
    if ($serviceConfig['rate_limit_per_day'] && $dailyUsage >= $serviceConfig['rate_limit_per_day']) {
        Logger::warning('API Gateway - Service rate limit exceeded', ['service' => $serviceCode, 'usage' => $dailyUsage]);
        Response::error('Service rate limit exceeded', 429);
    }
    
    // Get request body
    $requestBody = file_get_contents('php://input');
    $requestData = json_decode($requestBody, true);
    
    if (!$requestData || !isset($requestData['image'])) {
        Logger::warning('API Gateway - Invalid request body', ['user_id' => $userId]);
        Response::error('Image data is required in request body', 400);
    }
    
    // Validate image data
    if (isset($requestData['image']['content'])) {
        $imageSize = strlen(base64_decode($requestData['image']['content']));
        $maxSize = defined('API_MAX_IMAGE_SIZE') ? API_MAX_IMAGE_SIZE : 10485760;
        
        if ($imageSize > $maxSize) {
            Logger::warning('API Gateway - Image too large', ['size' => $imageSize, 'max' => $maxSize]);
            Response::error('Image size exceeds maximum allowed (' . round($maxSize/1024/1024, 1) . 'MB)', 413);
        }
    }
    
    // Get Google API key
    $googleApiKey = defined('GOOGLE_VISION_API_KEY') ? GOOGLE_VISION_API_KEY : '';
    if (empty($googleApiKey)) {
        Logger::error('API Gateway - Google API key not configured');
        Response::error('API configuration error', 500);
    }
    
    // Prepare Google Vision API request
    $visionRequest = [
        'requests' => [
            [
                'image' => $requestData['image'],
                'features' => []
            ]
        ]
    ];
    
    // Add feature based on endpoint
    switch ($feature) {
        case 'labels':
            $visionRequest['requests'][0]['features'][] = ['type' => 'LABEL_DETECTION', 'maxResults' => 10];
            break;
        case 'text':
            $visionRequest['requests'][0]['features'][] = ['type' => 'TEXT_DETECTION'];
            break;
        case 'faces':
            $visionRequest['requests'][0]['features'][] = ['type' => 'FACE_DETECTION'];
            break;
        case 'objects':
            $visionRequest['requests'][0]['features'][] = ['type' => 'OBJECT_LOCALIZATION'];
            break;
    }
    
    // Call Google Vision API
    $ch = curl_init();
    $timeout = defined('API_REQUEST_TIMEOUT') ? API_REQUEST_TIMEOUT : 30;
    
    curl_setopt($ch, CURLOPT_URL, $serviceConfig['google_api_endpoint'] . '?key=' . $googleApiKey);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($visionRequest));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $apiStartTime = microtime(true);
    $response = curl_exec($ch);
    $apiDuration = microtime(true) - $apiStartTime;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        Logger::error('API Gateway - Google API call failed', ['error' => $curlError, 'service' => $serviceCode]);
        Response::error('External API call failed', 502);
    }
    
    // Log usage
    $customerService = $db->queryOne(
        "SELECT id FROM customer_services WHERE user_id = ? LIMIT 1",
        [$userId]
    );
    
    if ($customerService) {
        $db->execute(
            "INSERT INTO api_usage_logs (customer_service_id, api_type, endpoint, request_count, response_time, status_code, cost, created_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, NOW())",
            [
                $customerService['id'],
                $serviceCode,
                $feature,
                round($apiDuration * 1000, 2),
                $httpCode,
                $serviceConfig['cost_per_request']
            ]
        );
    }
    
    // Update API key last used
    $db->execute("UPDATE api_keys SET last_used_at = NOW() WHERE api_key = ?", [$apiKey]);
    
    $totalDuration = microtime(true) - $startTime;
    Logger::info('API Gateway - Request completed', [
        'user_id' => $userId,
        'service' => $serviceCode,
        'feature' => $feature,
        'duration_ms' => round($totalDuration * 1000, 2),
        'api_duration_ms' => round($apiDuration * 1000, 2),
        'status_code' => $httpCode
    ]);
    
    // Return response from Google
    http_response_code($httpCode);
    echo $response;

} catch (Exception $e) {
    Logger::error('API Gateway - Exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    Response::error('Gateway error', 500);
}
