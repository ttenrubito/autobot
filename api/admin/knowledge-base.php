<?php
/**
 * Customer Knowledge Base API
 * Manage customer-specific Q&A, products, services, pricing
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
    error_log("Knowledge Base API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * GET - List knowledge base entries for a customer
 */
function handleGet($db) {
    $userId = $_GET['user_id'] ?? null;
    $category = $_GET['category'] ?? null;
    $search = $_GET['search'] ?? null;
    $id = $_GET['id'] ?? null;
    
    if (!$userId && !$id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'user_id or id is required'
        ]);
        return;
    }
    
    // Get single entry by ID
    if ($id) {
        $entry = $db->queryOne(
            "SELECT * FROM customer_knowledge_base WHERE id = ? AND is_deleted = 0",
            [$id]
        );
        
        if (!$entry) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Knowledge entry not found'
            ]);
            return;
        }
        
        // Decode JSON fields
        $entry['keywords'] = json_decode($entry['keywords'] ?? '[]', true);
        $entry['metadata'] = json_decode($entry['metadata'] ?? '{}', true);
        
        echo json_encode([
            'success' => true,
            'data' => ['entry' => $entry]
        ]);
        return;
    }
    
    // List entries for user
    $sql = "SELECT * FROM customer_knowledge_base WHERE user_id = ? AND is_deleted = 0";
    $params = [$userId];
    
    if ($category) {
        $sql .= " AND category = ?";
        $params[] = $category;
    }
    
    if ($search) {
        $sql .= " AND (question LIKE ? OR answer LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $sql .= " ORDER BY priority DESC, created_at DESC";
    
    $entries = $db->query($sql, $params);
    
    // Decode JSON fields
    foreach ($entries as &$row) {
        $row['keywords'] = json_decode($row['keywords'] ?? '[]', true);
        $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'entries' => $entries,
            'count' => count($entries)
        ]
    ]);
}

/**
 * POST - Create new knowledge entry
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
    $required = ['user_id', 'category', 'question', 'answer'];
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
    $validCategories = ['product', 'service', 'pricing', 'faq', 'general'];
    if (!in_array($input['category'], $validCategories)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid category. Must be: product, service, pricing, faq, or general'
        ]);
        return;
    }
    
    // Encode JSON fields
    $keywords = isset($input['keywords']) && is_array($input['keywords']) 
        ? json_encode($input['keywords'], JSON_UNESCAPED_UNICODE)
        : '[]';
    
    $metadata = isset($input['metadata']) && is_array($input['metadata'])
        ? json_encode($input['metadata'], JSON_UNESCAPED_UNICODE)
        : '{}';
    
    // Insert entry
    $db->execute(
        "INSERT INTO customer_knowledge_base 
        (user_id, category, question, answer, keywords, metadata, priority, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $input['user_id'],
            $input['category'],
            $input['question'],
            $input['answer'],
            $keywords,
            $metadata,
            $input['priority'] ?? 0,
            $input['is_active'] ?? 1
        ]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Knowledge entry created successfully',
        'data' => ['id' => $db->lastInsertId()]
    ]);
}

/**
 * PUT - Update knowledge entry
 */
function handlePut($db) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Entry ID is required'
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
        'category', 'question', 'answer', 'keywords', 
        'metadata', 'priority', 'is_active'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[] = "`$field` = ?";
            
            if (($field === 'keywords' || $field === 'metadata') && is_array($input[$field])) {
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
    
    $sql = "UPDATE customer_knowledge_base SET " . implode(', ', $updates) . " WHERE id = ?";
    $db->execute($sql, $params);
    
    echo json_encode([
        'success' => true,
        'message' => 'Knowledge entry updated successfully'
    ]);
}

/**
 * DELETE - Soft delete knowledge entry
 */
function handleDelete($db) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Entry ID is required'
        ]);
        return;
    }
    
    // Soft delete
    $db->execute("UPDATE customer_knowledge_base SET is_deleted = 1 WHERE id = ?", [$id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Knowledge entry deleted successfully'
    ]);
}
