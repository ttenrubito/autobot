<?php
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/AdminAuth.php';

AdminAuth::require();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();

function json_input() {
    $raw = file_get_contents('php://input');
    return $raw ? json_decode($raw, true) : [];
}

try {
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $row = $db->queryOne('SELECT * FROM customer_channels WHERE id = ? AND is_deleted = 0', [intval($_GET['id'])]);
            if (!$row) {
                Response::error('Channel not found', 404);
            }
            Response::success(['channel' => $row]);
        } elseif (isset($_GET['user_id'])) {
            $rows = $db->query(
                'SELECT cc.*, cbp.name AS bot_profile_name FROM customer_channels cc LEFT JOIN customer_bot_profiles cbp ON cc.bot_profile_id = cbp.id WHERE cc.user_id = ? AND cc.is_deleted = 0 ORDER BY cc.created_at DESC',
                [intval($_GET['user_id'])]
            );
            Response::success(['channels' => $rows]);
        } else {
            Response::error('Missing user_id or id', 400);
        }
    } elseif ($method === 'POST') {
        $data = json_input();
        $userId = intval($data['user_id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $type = trim($data['type'] ?? '');
        $inboundKey = trim($data['inbound_api_key'] ?? '');
        $botProfileId = isset($data['bot_profile_id']) ? intval($data['bot_profile_id']) : null;
        $status = $data['status'] ?? 'active';
        $config = isset($data['config']) ? json_encode($data['config']) : null;

        if (!$userId || !$name || !$type || !$inboundKey) {
            Response::error('Missing required fields');
        }

        $db->execute(
            'INSERT INTO customer_channels (user_id, name, type, inbound_api_key, bot_profile_id, status, config) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $name, $type, $inboundKey, $botProfileId, $status, $config]
        );

        Response::success(['id' => $db->lastInsertId()]);
    } elseif ($method === 'PUT') {
        if (!isset($_GET['id'])) {
            Response::error('Missing id', 400);
        }
        $id = intval($_GET['id']);
        $data = json_input();

        $fields = [];
        $params = [];

        foreach (['name','type','status','inbound_api_key'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (array_key_exists('bot_profile_id', $data)) {
            $fields[] = 'bot_profile_id = ?';
            $params[] = $data['bot_profile_id'] !== null ? intval($data['bot_profile_id']) : null;
        }
        if (array_key_exists('config', $data)) {
            $fields[] = 'config = ?';
            $params[] = json_encode($data['config']);
        }

        if (!$fields) {
            Response::error('No fields to update', 400);
        }

        $params[] = $id;
        $sql = 'UPDATE customer_channels SET ' . implode(', ', $fields) . ' WHERE id = ? AND is_deleted = 0';
        $db->execute($sql, $params);

        Response::success(['updated' => true]);
    } elseif ($method === 'DELETE') {
        if (!isset($_GET['id'])) {
            Response::error('Missing id', 400);
        }
        $id = intval($_GET['id']);
        $db->execute('UPDATE customer_channels SET is_deleted = 1 WHERE id = ?', [$id]);
        Response::success(['deleted' => true]);
    } else {
        Response::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log('customer-channels error: ' . $e->getMessage());
    Response::error('Server error', 500);
}
