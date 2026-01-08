<?php
/**
 * Admin Users API
 * Returns list of all users for admin management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/Database.php';
    require_once __DIR__ . '/../../includes/AdminAuth.php';
    
    AdminAuth::require();
    $db = Database::getInstance();
    $useNewAuth = true;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Get parameters
        $search = $_GET['search'] ?? null;
        $status = $_GET['status'] ?? null;
        $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 100;
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
        
        // Build query
        $conditions = [];
        $params = [];
        
        if ($search) {
            $conditions[] = '(email LIKE ? OR full_name LIKE ? OR phone LIKE ?)';
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if ($status) {
            $conditions[] = 'status = ?';
            $params[] = $status;
        }
        
        $whereClause = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';
        
        if ($useNewAuth) {
            // Use Database class
            $countSql = "SELECT COUNT(*) as total FROM users $whereClause";
            $countResult = $db->queryOne($countSql, $params);
            $total = $countResult['total'] ?? 0;
            
            $sql = "SELECT 
                        id,
                        email,
                        full_name,
                        phone,
                        company_name,
                        status,
                        created_at,
                        last_login
                    FROM users 
                    $whereClause
                    ORDER BY id DESC
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $users = $db->query($sql, $params);
            
            echo json_encode([
                'ok' => true,
                'data' => [
                    'users' => $users,
                    'pagination' => [
                        'total' => $total,
                        'limit' => $limit,
                        'offset' => $offset,
                        'has_more' => ($offset + $limit) < $total
                    ]
                ]
            ]);
        } else {
            // Use PDO directly
            $pdo = getDB();
            
            $countSql = "SELECT COUNT(*) as total FROM users $whereClause";
            $stmt = $pdo->prepare($countSql);
            $stmt->execute($params);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            $sql = "SELECT 
                        id,
                        email,
                        full_name,
                        phone,
                        company_name,
                        status,
                        created_at,
                        last_login
                    FROM users 
                    $whereClause
                    ORDER BY id DESC
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'users' => $users,
                    'pagination' => [
                        'total' => $total,
                        'limit' => $limit,
                        'offset' => $offset,
                        'has_more' => ($offset + $limit) < $total
                    ]
                ]
            ]);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log('Admin Users API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage()
    ]);
}
