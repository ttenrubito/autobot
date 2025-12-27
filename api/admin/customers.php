<?php
/**
 * Admin Customers API
 * Handles CRUD operations for customer management
 * 
 * Endpoints:
 * GET    /api/admin/customers.php - List all customers
 * GET    /api/admin/customers.php?id={id} - Get customer details
 * POST   /api/admin/customers.php - Create customer
 * PUT    /api/admin/customers.php?id={id} - Update customer
 * DELETE /api/admin/customers.php?id={id} - Delete customer
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
    require_once __DIR__ . '/../../includes/Response.php';
    require_once __DIR__ . '/../../includes/AdminAuth.php';

    // Use shared admin JWT auth
    AdminAuth::require();

    $db = Database::getInstance();
    $method = $_SERVER['REQUEST_METHOD'];
    
    // GET - List or Get Single
    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;
        
        if ($id) {
            // Get single customer with details
            $customer = $db->queryOne(
                "SELECT id, email, full_name, phone, company_name, status, created_at, last_login
                 FROM users WHERE id = ?",
                [$id]
            );
            
            if (!$customer) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Customer not found']);
                exit;
            }
            
            // Get customer's services
            $services = $db->query(
                "SELECT cs.*, st.name as service_type_name
                 FROM customer_services cs
                 JOIN service_types st ON cs.service_type_id = st.id
                 WHERE cs.user_id = ?",
                [$id]
            );
            
            // Get customer's subscriptions
            $subscription = $db->queryOne(
                "SELECT s.*, sp.name as plan_name
                 FROM subscriptions s
                 JOIN subscription_plans sp ON s.plan_id = sp.id
                 WHERE s.user_id = ? AND s.status = 'active'",
                [$id]
            );
            
            // Get customer's invoices summary
            $invoicesSummary = $db->queryOne(
                "SELECT 
                    COUNT(*) as total_invoices,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) as total_paid
                 FROM invoices WHERE user_id = ?",
                [$id]
            );
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => [
                    'customer' => $customer,
                    'services' => $services,
                    'subscription' => $subscription,
                    'invoicesSummary' => $invoicesSummary
                ]
            ]);
        } else {
            // List all customers with pagination and search
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset = ($page - 1) * $perPage;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            
            $whereClauses = [];
            $params = [];
            
            if ($search) {
                $whereClauses[] = "(email LIKE ? OR full_name LIKE ? OR company_name LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if ($status) {
                $whereClauses[] = "status = ?";
                $params[] = $status;
            }
            
            $whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
            
            // Count total
            $total = $db->queryOne(
                "SELECT COUNT(*) as count FROM users $whereSQL",
                $params
            );
            
            // Get customers
            $customers = $db->query(
                "SELECT 
                    u.id, u.email, u.full_name, u.company_name, u.status, u.created_at,
                    (SELECT COUNT(*) FROM customer_services WHERE user_id = u.id) as services_count,
                    sp.name as plan_name
                 FROM users u
                 LEFT JOIN subscriptions s ON u.id = s.user_id AND s.status = 'active'
                 LEFT JOIN subscription_plans sp ON s.plan_id = sp.id
                 $whereSQL
                 ORDER BY u.created_at DESC
                 LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => [
                    'customers' => $customers,
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
    
    // POST - Create Customer
    elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $full_name = $data['full_name'] ?? '';
        $phone = $data['phone'] ?? '';
        $company_name = $data['company_name'] ?? '';
        
        // Validation
        if (!$email || !$password || !$full_name) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email, password and full name are required']);
            exit;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid email format']);
            exit;
        }
        
        // Check if email already exists
        if ($db->exists('users', 'email = ?', [$email])) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            exit;
        }
        
        // Hash password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert customer
        $db->execute(
            "INSERT INTO users (email, password_hash, full_name, phone, company_name, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'active', NOW())",
            [$email, $password_hash, $full_name, $phone, $company_name]
        );
        
        $customerId = $db->lastInsertId();
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Customer created successfully',
            'data' => ['id' => $customerId]
        ]);
    }
    
    // PUT - Update Customer
    elseif ($method === 'PUT') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Customer ID required']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $updates = [];
        $params = [];
        
        if (isset($data['full_name'])) {
            $updates[] = "full_name = ?";
            $params[] = $data['full_name'];
        }
        if (isset($data['phone'])) {
            $updates[] = "phone = ?";
            $params[] = $data['phone'];
        }
        if (isset($data['company_name'])) {
            $updates[] = "company_name = ?";
            $params[] = $data['company_name'];
        }
        if (isset($data['status'])) {
            $updates[] = "status = ?";
            $params[] = $data['status'];
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit;
        }
        
        $params[] = $id;
        $db->execute(
            "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?",
            $params
        );
        
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);
    }
    
    // DELETE - Delete Customer
    elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Customer ID required']);
            exit;
        }
        
        // Soft delete: set status to cancelled
        $db->execute(
            "UPDATE users SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
            [$id]
        );
        
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Customer deleted successfully']);
    }
    
} catch (Exception $e) {
    error_log("Admin Customers API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
