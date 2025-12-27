<?php
/**
 * Auto-detect environment and set base paths
 * Works on both localhost and Cloud Run
 */

// Detect base path robustly:
// - Local dev commonly runs under http://localhost/autobot/...
// - Production (Cloud Run) typically uses docroot at /public (no /autobot prefix)
// - Some deployments may still be hosted under /autobot on a real domain
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$uri  = $_SERVER['REQUEST_URI'] ?? '';

// Allow explicit override via env (safest for deployments behind proxies / subpaths)
$env_base = getenv('APP_BASE_PATH');
if ($env_base !== false && $env_base !== null && trim($env_base) !== '') {
    $base_path = rtrim(trim($env_base), '/');
} else {
    $is_local = (strpos($host, 'localhost') !== false) || (strpos($host, '127.0.0.1') !== false);

    // If the request URI contains /autobot, assume the app is hosted under that prefix
    // (works for both localhost and real domains)
    if (strpos($uri, '/autobot') !== false) {
        $base_path = '/autobot';
    } else {
        // Default to root
        $base_path = '';
    }
}

// Define constants (only once)
if (!defined('BASE_PATH')) define('BASE_PATH', $base_path);
if (!defined('ASSETS_PATH')) define('ASSETS_PATH', BASE_PATH . '/assets');
if (!defined('API_PATH')) define('API_PATH', BASE_PATH . '/api');
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', BASE_PATH . '/public');

// Helper function for asset URLs
function asset($path) {
    return ASSETS_PATH . '/' . ltrim($path, '/');
}

// Helper function for API URLs
function api($path) {
    return API_PATH . '/' . ltrim($path, '/');
}

// Helper function for public URLs
function public_url($path) {
    return PUBLIC_PATH . '/' . ltrim($path, '/');
}

// Inject JavaScript base path override (single source of truth for JS)
function inject_base_path() {
    echo "<script>window.BASE_PATH_OVERRIDE = '" . BASE_PATH . "';</script>\n";
}
