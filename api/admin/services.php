<?php
/**
 * Admin Services API
 * Handles CRUD operations for service management
 * 
 * Endpoints:
 * GET    /api/admin/services.php - List all services
 * GET    /api/admin/services.php?id={id} - Get service details
 * POST   /api/admin/services.php - Create service
 * PUT    /api/admin/services.php?id={id} - Update service
 * DELETE /api/admin/services.php?id={id} - Delete service
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/Database.php';
    require_once __DIR__ . '/../../includes/JWT.php';
    require_once __DIR__ . '/../../includes/Response.php';
    require_once __DIR__ . '/../../includes/AdminAuth.php';
    
    // Verify admin authentication
    AdminAuth::require();
    
    $db = Database::getInstance();
    $method = $_SERVER['REQUEST_METHOD'];
    
    // GET - List or Get Single
    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;
        
        if ($id) {
            // Get single service with usage stats
            $service = $db->queryOne(
                "SELECT 
                    cs.*,
                    u.email as customer_email,
                    u.full_name as customer_name,
                    st.name as service_type_name,
                    st.code as service_type_code
                 FROM customer_services cs
                 JOIN users u ON cs.user_id = u.id
                 JOIN service_types st ON cs.service_type_id = st.id
                 WHERE cs.id = ?",
                [$id]
            );
            
            if (!$service) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Service not found']);
                exit;
            }
            
            // Get usage logs (last 30 days)
            $usageLogs = $db->query(
                "SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as requests,
                    SUM(request_count) as total_requests
                 FROM api_usage_logs
                 WHERE customer_service_id = ?
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY date DESC",
                [$id]
            );
            
            // Get chat logs count (for bot services)
            $chatLogs = $db->queryOne(
                "SELECT COUNT(*) as count
                 FROM bot_chat_logs
                 WHERE customer_service_id = ?",
                [$id]
            );
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => [
                    'service' => $service,
                    'usageLogs' => $usageLogs,
                    'chatLogsCount' => (int)$chatLogs['count']
                ]
            ]);
        } else {
            // List all services
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset = ($page - 1) * $perPage;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            $serviceType = $_GET['service_type'] ?? '';
            
            $whereClauses = [];
            $params = [];
            
            if ($search) {
                $whereClauses[] = "(cs.service_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if ($status) {
                $whereClauses[] = "cs.status = ?";
                $params[] = $status;
            }
            
            if ($serviceType) {
                $whereClauses[] = "st.code = ?";
                $params[] = $serviceType;
            }
            
            $whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
            
            // Count total
            $total = $db->queryOne(
                "SELECT COUNT(*) as count 
                 FROM customer_services cs
                 JOIN users u ON cs.user_id = u.id
                 JOIN service_types st ON cs.service_type_id = st.id
                 $whereSQL",
                $params
            );
            
            // Get services
            $services = $db->query(
                "SELECT 
                    cs.id,
                    cs.service_name,
                    cs.platform,
                    cs.status,
                    cs.created_at,
                    u.full_name as customer_name,
                    u.email as customer_email,
                    st.name as service_type_name,
                    st.code as service_type_code,
                    (
                        (SELECT COUNT(*) FROM bot_chat_logs WHERE customer_service_id = cs.id) +
                        (SELECT COUNT(*) FROM api_usage_logs WHERE customer_service_id = cs.id)
                    ) as usage_count
                 FROM customer_services cs
                 JOIN users u ON cs.user_id = u.id
                 JOIN service_types st ON cs.service_type_id = st.id
                 $whereSQL
                 ORDER BY cs.created_at DESC
                 LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => [
                    'services' => $services,
                    'pagination' => [
                        'total' => (int)$total['count'],
                        'page' => $page,
                        'perPage' => $perPage,
                        'totalPages' => ceil($total['count'] / $perPage)
                    ]
                ]
            ]);
        }
    }
    
    // POST - Create Service
    elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $user_id = $data['user_id'] ?? '';
        $service_type_id = $data['service_type_id'] ?? '';
        $service_name = $data['service_name'] ?? '';
        $platform = $data['platform'] ?? null;
        
        if (!$user_id || !$service_type_id || !$service_name) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID, service type ID and service name are required']);
            exit;
        }
        
        // Generate API key
        $api_key = bin2hex(random_bytes(16));
        
        // Insert service
        $db->execute(
            "INSERT INTO customer_services (user_id, service_type_id, service_name, platform, api_key, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'active', NOW())",
            [$user_id, $service_type_id, $service_name, $platform, $api_key]
        );
        
        $serviceId = $db->lastInsertId();
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Service created successfully',
            'data' => [
                'id' => $serviceId,
                'api_key' => $api_key
            ]
        ]);
    }
    
    // PUT - Update Service
    elseif ($method === 'PUT') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Service ID required']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $updates = [];
        $params = [];
        
        if (isset($data['service_name'])) {
            $updates[] = "service_name = ?";
            $params[] = $data['service_name'];
        }
        if (isset($data['platform'])) {
            $updates[] = "platform = ?";
            $params[] = $data['platform'];
        }
        if (isset($data['status'])) {
            $updates[] = "status = ?";
            $params[] = $data['status'];
        }
        if (isset($data['config'])) {
            $updates[] = "config = ?";
            $params[] = json_encode($data['config']);
        }
        
        // Regenerate API key if requested
        if (isset($data['regenerate_key']) && $data['regenerate_key']) {
            $updates[] = "api_key = ?";
            $params[] = bin2hex(random_bytes(16));
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit;
        }
        
        $params[] = $id;
        $db->execute(
            "UPDATE customer_services SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?",
            $params
        );
        
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Service updated successfully']);
    }
    
    // DELETE - Delete Service
    elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Service ID required']);
            exit;
        }
        
        // Delete service and related logs (CASCADE)
        $db->execute("DELETE FROM customer_services WHERE id = ?", [$id]);
        
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Service deleted successfully']);
    }
    
} catch (Exception $e) {
    error_log("Admin Services API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
