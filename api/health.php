<?php
/**
 * Health Check Endpoint
 * GET /api/health
 */

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Response.php';

// config-cloud.php declares helpers that can collide with config.php.
// When this endpoint is executed through api/index.php, config.php is already loaded.
if (!function_exists('getDB')) {
    require_once __DIR__ . '/../config-cloud.php';
}

header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'services' => []
];

// Check database
try {
    $db = Database::getInstance();
    $result = $db->queryOne("SELECT 1 as test");
    $health['services']['database'] = $result ? 'connected' : 'disconnected';
} catch (Exception $e) {
    $health['services']['database'] = 'error';
    $health['status'] = 'unhealthy';
}

// Check disk space
$freeSpace = disk_free_space('/');
$totalSpace = disk_total_space('/');
$usedPercent = (1 - ($freeSpace / $totalSpace)) * 100;

$health['services']['disk'] = [
    'status' => $usedPercent < 90 ? 'ok' : 'warning',
    'used_percent' => round($usedPercent, 2)
];

// Check API keys configured
$health['services']['google_vision_api'] = defined('GOOGLE_VISION_API_KEY') && !empty(GOOGLE_VISION_API_KEY) ? 'configured' : 'not_configured';
$health['services']['google_language_api'] = defined('GOOGLE_LANGUAGE_API_KEY') && !empty(GOOGLE_LANGUAGE_API_KEY) ? 'configured' : 'not_configured';

// Overall status
if ($health['services']['database'] === 'error') {
    $health['status'] = 'unhealthy';
    http_response_code(503);
} else {
    http_response_code( 200);
}

echo json_encode($health, JSON_PRETTY_PRINT);
