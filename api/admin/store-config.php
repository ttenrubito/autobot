<?php
/**
 * Store Config API for Multi-tenant Chatbot (V6)
 * 
 * GET /api/admin/store-config.php?channel_id=X - Get store settings
 * POST /api/admin/store-config.php - Save store settings
 * 
 * Settings stored in customer_channels.config.store_settings
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/AdminAuth.php';

AdminAuth::require();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();

function json_input()
{
    $raw = file_get_contents('php://input');
    return $raw ? json_decode($raw, true) : [];
}

try {
    if ($method === 'GET') {
        $channelId = isset($_GET['channel_id']) ? intval($_GET['channel_id']) : 0;

        if (!$channelId) {
            Response::error('Missing channel_id', 400);
        }

        $row = $db->queryOne(
            "SELECT config FROM customer_channels WHERE id = ? AND is_deleted = 0",
            [$channelId]
        );

        if (!$row) {
            Response::error('Channel not found', 404);
        }

        $config = $row['config'] ? json_decode($row['config'], true) : [];
        $storeSettings = $config['store_settings'] ?? null;

        Response::success([
            'channel_id' => $channelId,
            'store_settings' => $storeSettings
        ]);

    } elseif ($method === 'POST') {
        $data = json_input();

        $channelId = isset($data['channel_id']) ? intval($data['channel_id']) : 0;
        $storeSettings = isset($data['store_settings']) ? $data['store_settings'] : null;

        if (!$channelId) {
            Response::error('Missing channel_id', 400);
        }

        if (!$storeSettings) {
            Response::error('Missing store_settings', 400);
        }

        // Get existing config
        $row = $db->queryOne(
            "SELECT config FROM customer_channels WHERE id = ? AND is_deleted = 0",
            [$channelId]
        );

        if (!$row) {
            Response::error('Channel not found', 404);
        }

        // Merge store_settings into existing config
        $config = $row['config'] ? json_decode($row['config'], true) : [];
        $config['store_settings'] = $storeSettings;

        // Save back
        $db->execute(
            "UPDATE customer_channels SET config = ? WHERE id = ?",
            [json_encode($config, JSON_UNESCAPED_UNICODE), $channelId]
        );

        Response::success([
            'channel_id' => $channelId,
            'store_settings' => $storeSettings
        ]);

    } else {
        Response::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log('store-config error: ' . $e->getMessage());
    Response::error('Server error', 500);
}
