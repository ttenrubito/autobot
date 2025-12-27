<?php
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/AdminAuth.php';

AdminAuth::require();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();

function json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        Response::error('Invalid JSON body', 400);
    }

    // Ensure we always return an array (avoid array_key_exists() TypeError)
    if (!is_array($data)) {
        return [];
    }

    return $data;
}

try {
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $row = $db->queryOne('SELECT * FROM customer_bot_profiles WHERE id = ? AND is_deleted = 0', [intval($_GET['id'])]);
            if (!$row) {
                Response::error('Bot profile not found', 404);
            }
            Response::success(['profile' => $row]);
        } elseif (isset($_GET['user_id'])) {
            $rows = $db->query(
                'SELECT cbp.*, (
                SELECT COUNT(*) FROM customer_channels cc WHERE cc.bot_profile_id = cbp.id AND cc.is_deleted = 0
            ) AS channel_count FROM customer_bot_profiles cbp WHERE cbp.user_id = ? AND cbp.is_deleted = 0 ORDER BY cbp.created_at DESC',
                [intval($_GET['user_id'])]
            );
            Response::success(['profiles' => $rows]);
        } else {
            Response::error('Missing user_id or id', 400);
        }
    } elseif ($method === 'POST') {
        $data = json_input();
        $userId = intval($data['user_id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $handlerKey = trim($data['handler_key'] ?? '');
        $config = isset($data['config']) ? json_encode($data['config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null;
        $isDefault = isset($data['is_default']) ? (int)!!$data['is_default'] : 0;
        $isActive = isset($data['is_active']) ? (int)!!$data['is_active'] : 1;

        if (!$userId || !$name || !$handlerKey) {
            Response::error('Missing required fields');
        }

        $db->beginTransaction();

        if ($isDefault) {
            $db->execute('UPDATE customer_bot_profiles SET is_default = 0 WHERE user_id = ?', [$userId]);
        }

        $db->execute(
            'INSERT INTO customer_bot_profiles (user_id, name, handler_key, config, is_default, is_active) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $name, $handlerKey, $config, $isDefault, $isActive]
        );
        $id = $db->lastInsertId();

        $db->commit();

        Response::success(['id' => $id]);
    } elseif ($method === 'PUT') {
        if (!isset($_GET['id'])) {
            Response::error('Missing id', 400);
        }
        $id = intval($_GET['id']);
        $data = json_input();

        $profile = $db->queryOne('SELECT user_id FROM customer_bot_profiles WHERE id = ? AND is_deleted = 0', [$id]);
        if (!$profile) {
            Response::error('Bot profile not found', 404);
        }
        $userId = intval($profile['user_id']);

        $fields = [];
        $params = [];
        foreach (['name','handler_key'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (array_key_exists('config', $data)) {
            $fields[] = 'config = ?';
            $params[] = json_encode($data['config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = ?';
            $params[] = (int)!!$data['is_active'];
        }
        $setDefault = null;
        if (array_key_exists('is_default', $data)) {
            $setDefault = (int)!!$data['is_default'];
        }

        $db->beginTransaction();

        if ($setDefault === 1) {
            $db->execute('UPDATE customer_bot_profiles SET is_default = 0 WHERE user_id = ?', [$userId]);
            $fields[] = 'is_default = 1';
        } elseif ($setDefault === 0) {
            $fields[] = 'is_default = 0';
        }

        if ($fields) {
            $params[] = $id;
            $sql = 'UPDATE customer_bot_profiles SET ' . implode(', ', $fields) . ' WHERE id = ? AND is_deleted = 0';
            $db->execute($sql, $params);
        }

        $db->commit();

        Response::success(['updated' => true]);
    } elseif ($method === 'DELETE') {
        if (!isset($_GET['id'])) {
            Response::error('Missing id', 400);
        }
        $id = intval($_GET['id']);
        $db->execute('UPDATE customer_bot_profiles SET is_deleted = 1 WHERE id = ?', [$id]);
        Response::success(['deleted' => true]);
    } else {
        Response::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    $db->rollback();
    error_log('customer-bot-profiles error: ' . $e->getMessage());
    Response::error('Server error', 500);
}
