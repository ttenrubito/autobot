<?php
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/AdminAuth.php';

AdminAuth::require();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();

function json_input() {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        Response::error('Invalid JSON payload', 400, Response::ERR_INVALID_REQUEST, [
            'json_error' => json_last_error_msg(),
        ]);
    }

    if (!is_array($decoded)) {
        Response::error('Invalid JSON payload (must be an object)', 400, Response::ERR_INVALID_REQUEST);
    }

    return $decoded;
}

$knownProviderHints = [
    'llm' => [
        'label' => 'Generic LLM / OpenAI-compatible',
        'config_placeholder' => [
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
            'model' => 'gpt-4.1-mini'
        ],
        'help' => 'ใช้สำหรับเชื่อม OpenAI หรือ LLM ที่ compatible กับ Chat Completions API. ใส่ API Key ในช่อง api_key และกำหนด endpoint/model ใน JSON config.'
    ],
    'openai' => [
        'label' => 'OpenAI Chat API',
        'config_placeholder' => [
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
            'model' => 'gpt-4.1-mini'
        ],
        'help' => 'เชื่อมต่อ OpenAI โดยตรง. api_key = OpenAI API Key, config.model = รุ่นโมเดลที่ต้องการใช้.'
    ],
    'gemini' => [
        'label' => 'Google Gemini AI',
        'config_placeholder' => [
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent',
            'model' => 'gemini-2.0-flash-exp'
        ],
        'help' => 'เชื่อมต่อ Google Gemini API. ใส่ Google AI API Key ในช่อง api_key, config.endpoint = URL ของโมเดล (ระบบจะ detect format อัตโนมัติ)'
    ],
    'google_vision' => [
        'label' => 'Google Vision API',
        'config_placeholder' => [
            'endpoint' => 'https://vision.googleapis.com/v1/images:annotate'
        ],
        'help' => 'ใช้วิเคราะห์รูปภาพ. ใส่ Google Cloud API Key ในช่อง api_key, ใน JSON config สามารถกำหนด endpoint เพิ่มเติมได้ถ้าต้องการ.'
    ],
    'google_nlp' => [
        'label' => 'Google Natural Language API',
        'config_placeholder' => [
            'endpoint' => 'https://language.googleapis.com/v1/documents:analyzeEntitySentiment',
            'language' => 'th'
        ],
        'help' => 'ใช้วิเคราะห์ข้อความ. api_key = Google Cloud API Key, language = รหัสภาษา เช่น th, en.'
    ],
];

try {
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $row = $db->queryOne('SELECT * FROM customer_integrations WHERE id = ? AND is_deleted = 0', [intval($_GET['id'])]);
            if (!$row) {
                Response::error('Integration not found', 404);
            }
            $provider = $row['provider'] ?? null;
            if ($provider && isset($knownProviderHints[$provider])) {
                $row['provider_hints'] = $knownProviderHints[$provider];
            }
            Response::success(['integration' => $row]);
        } elseif (isset($_GET['user_id'])) {
            $rows = $db->query('SELECT * FROM customer_integrations WHERE user_id = ? AND is_deleted = 0 ORDER BY created_at DESC', [intval($_GET['user_id'])]);
            foreach ($rows as &$row) {
                $provider = $row['provider'] ?? null;
                if ($provider && isset($knownProviderHints[$provider])) {
                    $row['provider_hints'] = $knownProviderHints[$provider];
                }
            }
            Response::success(['integrations' => $rows]);
        } else {
            Response::error('Missing user_id or id', 400);
        }
    } elseif ($method === 'POST') {
        $data = json_input();
        $userId = intval($data['user_id'] ?? 0);
        $provider = trim((string)($data['provider'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $apiKey = $data['api_key'] ?? null;
        $credentials = array_key_exists('credentials', $data) ? json_encode($data['credentials']) : null;
        $config = array_key_exists('config', $data) ? json_encode($data['config']) : null;
        $isActive = array_key_exists('is_active', $data) ? (int)!!$data['is_active'] : 1;

        if (!$userId || $provider === '') {
            Response::error('Missing required fields', 400, Response::ERR_VALIDATION_ERROR, [
                'required' => ['user_id', 'provider'],
                'received' => [
                    'user_id' => $userId,
                    'provider' => $provider,
                ],
            ]);
        }

        $db->execute(
            'INSERT INTO customer_integrations (user_id, provider, name, api_key, credentials, config, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $provider, $name, $apiKey, $credentials, $config, $isActive]
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
        foreach (['provider','name','api_key'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (array_key_exists('credentials', $data)) {
            $fields[] = 'credentials = ?';
            $params[] = json_encode($data['credentials']);
        }
        if (array_key_exists('config', $data)) {
            $fields[] = 'config = ?';
            $params[] = json_encode($data['config']);
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = ?';
            $params[] = (int)!!$data['is_active'];
        }

        if (!$fields) {
            Response::error('No fields to update', 400);
        }

        $params[] = $id;
        $sql = 'UPDATE customer_integrations SET ' . implode(', ', $fields) . ' WHERE id = ? AND is_deleted = 0';
        $db->execute($sql, $params);

        Response::success(['updated' => true]);
    } elseif ($method === 'DELETE') {
        if (!isset($_GET['id'])) {
            Response::error('Missing id', 400);
        }
        $id = intval($_GET['id']);
        $db->execute('UPDATE customer_integrations SET is_deleted = 1 WHERE id = ?', [$id]);
        Response::success(['deleted' => true]);
    } else {
        Response::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log('customer-integrations error: ' . $e->getMessage());
    Response::error('Server error', 500);
}
