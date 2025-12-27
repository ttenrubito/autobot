<?php
/**
 * Bot Profile Templates API
 * Provides CRUD operations for bot profile templates
 */

define('INCLUDE_CHECK', true);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/AdminAuth.php';
require_once __DIR__ . '/../../includes/Database.php';

// Verify admin authentication
AdminAuth::require();

header('Content-Type: application/json');

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        
        case 'POST':
            handlePost($db);
            break;
        
        case 'PUT':
            handlePut($db);
            break;
        
        case 'DELETE':
            handleDelete($db);
            break;
        
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("Bot Templates API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * GET - List all templates or get specific template
 */
function handleGet($db) {
    $category = $_GET['category'] ?? null;
    $key = $_GET['key'] ?? null;
    
    if ($key) {
        // Get specific template by key
        $template = $db->queryOne(
            "SELECT * FROM bot_profile_templates WHERE `key` = ? AND is_active = 1",
            [$key]
        );
        
        if (!$template) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Template not found'
            ]);
            return;
        }
        
        // Decode JSON config
        $template['config_template'] = json_decode($template['config_template'], true);
        
        echo json_encode([
            'success' => true,
            'data' => ['template' => $template]
        ]);
        return;
    }
    
    // List templates
    $sql = "SELECT * FROM bot_profile_templates WHERE is_active = 1";
    $params = [];
    
    if ($category) {
        $sql .= " AND category = ?";
        $params[] = $category;
    }
    
    $sql .= " ORDER BY sort_order ASC, id ASC";
    
    $templates = $db->query($sql, $params);
    
    // Decode JSON configs
    foreach ($templates as &$row) {
        $row['config_template'] = json_decode($row['config_template'], true);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'templates' => $templates,
            'count' => count($templates)
        ]
    ]);
}

/**
 * POST - Create new template (admin only)
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON input'
        ]);
        return;
    }
    
    // Validate required fields
    $required = ['key', 'category', 'name_th', 'name_en', 'config_template'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Missing required field: $field"
            ]);
            return;
        }
    }
    
    // Validate category
    $validCategories = ['shop', 'clinic', 'hotel', 'other'];
    if (!in_array($input['category'], $validCategories)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid category. Must be: shop, clinic, hotel, or other'
        ]);
        return;
    }
    
    // Encode config as JSON if it's an array
    $configJson = is_array($input['config_template']) 
        ? json_encode($input['config_template'], JSON_UNESCAPED_UNICODE) 
        : $input['config_template'];
    
    // Insert template
    $db->execute(
        "INSERT INTO bot_profile_templates 
        (`key`, category, name_th, name_en, description_th, description_en, config_template, icon, sort_order, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $input['key'],
            $input['category'],
            $input['name_th'],
            $input['name_en'],
            $input['description_th'] ?? null,
            $input['description_en'] ?? null,
            $configJson,
            $input['icon'] ?? null,
            $input['sort_order'] ?? 0,
            $input['is_active'] ?? 1
        ]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Template created successfully',
        'data' => ['id' => $db->lastInsertId()]
    ]);
}

/**
 * PUT - Update template (admin only)
 */
function handlePut($db) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Template ID is required'
        ]);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON input'
        ]);
        return;
    }
    
    // Build update query dynamically
    $updates = [];
    $params = [];
    
    $allowedFields = [
        'key', 'category', 'name_th', 'name_en', 
        'description_th', 'description_en', 'config_template',
        'icon', 'sort_order', 'is_active'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[] = "`$field` = ?";
            
            if ($field === 'config_template' && is_array($input[$field])) {
                $params[] = json_encode($input[$field], JSON_UNESCAPED_UNICODE);
            } else {
                $params[] = $input[$field];
            }
        }
    }
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No fields to update'
        ]);
        return;
    }
    
    $params[] = $id;
    
    $sql = "UPDATE bot_profile_templates SET " . implode(', ', $updates) . " WHERE id = ?";
    $db->execute($sql, $params);
    
    echo json_encode([
        'success' => true,
        'message' => 'Template updated successfully'
    ]);
}

/**
 * DELETE - Soft delete template (admin only)
 */
function handleDelete($db) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Template ID is required'
        ]);
        return;
    }
    
    // Soft delete
    $db->execute("UPDATE bot_profile_templates SET is_active = 0 WHERE id = ?", [$id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Template deleted successfully'
    ]);
}
