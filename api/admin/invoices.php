<?php
/**
 * Admin Invoices API
 * Handles CRUD operations for invoice management
 * 
 * Endpoints:
 * GET    /api/admin/invoices.php - List all invoices
 * GET    /api/admin/invoices.php?id={id} - Get invoice details
 * POST   /api/admin/invoices.php - Create invoice
 * PUT    /api/admin/invoices.php?id={id} - Update invoice
 * POST   /api/admin/invoices.php?id={id}&action=send - Send invoice email
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
    require_once __DIR__ . '/../../includes/AdminAuth.php';

    // Use standard admin session auth used by other admin APIs
    AdminAuth::require();

    $db = Database::getInstance();
    $method = $_SERVER['REQUEST_METHOD'];
    
    // GET - List or Get Single
    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;
        
        if ($id) {
            // Get single invoice with items and transactions
            $invoice = $db->queryOne(
                "SELECT 
                    i.*,
                    u.email as customer_email,
                    u.full_name as customer_name,
                    u.company_name
                 FROM invoices i
                 JOIN users u ON i.user_id = u.id
                 WHERE i.id = ?",
                [$id]
            );
            
            if (!$invoice) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Invoice not found']);
                exit;
            }
            
            // Get invoice items
            $items = $db->query(
                "SELECT * FROM invoice_items WHERE invoice_id = ?",
                [$id]
            );
            
            // Get transactions
            $transactions = $db->query(
                "SELECT * FROM transactions WHERE invoice_id = ? ORDER BY created_at DESC",
                [$id]
            );
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => [
                    'invoice' => $invoice,
                    'items' => $items,
                    'transactions' => $transactions
                ]
            ]);
        } else {
            // List all invoices
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset = ($page - 1) * $perPage;
            $status = $_GET['status'] ?? '';
            $search = $_GET['search'] ?? '';
            
            $whereClauses = [];
            $params = [];
            
            if ($status) {
                $whereClauses[] = "i.status = ?";
                $params[] = $status;
            }
            
            if ($search) {
                $whereClauses[] = "(i.invoice_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
            
            // Count total
            $total = $db->queryOne(
                "SELECT COUNT(*) as count 
                 FROM invoices i
                 JOIN users u ON i.user_id = u.id
                 $whereSQL",
                $params
            );
            
            // Get invoices
            $invoices = $db->query(
                "SELECT 
                    i.id,
                    i.invoice_number,
                    i.amount,
                    i.tax,
                    i.total,
                    i.status,
                    i.due_date,
                    i.paid_at,
                    i.created_at,
                    u.full_name as customer_name,
                    u.email as customer_email
                 FROM invoices i
                 JOIN users u ON i.user_id = u.id
                 $whereSQL
                 ORDER BY i.created_at DESC
                 LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => [
                    'invoices' => $invoices,
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
    
    // POST - Create Invoice or Send Email
    elseif ($method === 'POST') {
        $id = $_GET['id'] ?? null;
        $action = $_GET['action'] ?? null;
        
        // Send invoice email
        if ($id && $action === 'send') {
            $invoice = $db->queryOne(
                "SELECT i.*, u.email, u.full_name 
                 FROM invoices i
                 JOIN users u ON i.user_id = u.id
                 WHERE i.id = ?",
                [$id]
            );
            
            if (!$invoice) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Invoice not found']);
                exit;
            }
            
            // TODO: Implement email sending
            // For now, just return success
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Invoice email sent successfully (feature coming soon)'
            ]);
            exit;
        }
        
        // Create new invoice
        $data = json_decode(file_get_contents('php://input'), true);
        
        $user_id = $data['user_id'] ?? '';
        $amount = $data['amount'] ?? 0;
        $tax = $data['tax'] ?? 0;
        $items = $data['items'] ?? [];
        $due_date = $data['due_date'] ?? null;
        
        if (!$user_id || !$amount || empty($items)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID, amount and items are required']);
            exit;
        }
        
        // Generate invoice number
        $year = date('Y');
        $month = date('m');
        $count = $db->queryOne(
            "SELECT COUNT(*) as count FROM invoices WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?",
            [$year, $month]
        );
        $invoiceNumber = sprintf('INV-%s%s-%04d', $year, $month, $count['count'] + 1);
        
        // Calculate total
        $total = $amount + $tax;
        
        // Begin transaction
        $db->beginTransaction();
        
        try {
            // Insert invoice
            $db->execute(
                "INSERT INTO invoices (invoice_number, user_id, amount, tax, total, currency, status, due_date, created_at)
                 VALUES (?, ?, ?, ?, ?, 'THB', 'pending', ?, NOW())",
                [$invoiceNumber, $user_id, $amount, $tax, $total, $due_date]
            );
            
            $invoiceId = $db->lastInsertId();
            
            // Insert invoice items
            foreach ($items as $item) {
                $db->execute(
                    "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, amount)
                     VALUES (?, ?, ?, ?, ?)",
                    [
                        $invoiceId,
                        $item['description'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['amount']
                    ]
                );
            }
            
            $db->commit();
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => [
                    'id' => $invoiceId,
                    'invoice_number' => $invoiceNumber
                ]
            ]);
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    }
    
    // PUT - Update Invoice
    elseif ($method === 'PUT') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invoice ID required']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $updates = [];
        $params = [];
        
        if (isset($data['status'])) {
            $updates[] = "status = ?";
            $params[] = $data['status'];
            
            // If marking as paid, set paid_at
            if ($data['status'] === 'paid') {
                $updates[] = "paid_at = NOW()";
            }
        }
        if (isset($data['due_date'])) {
            $updates[] = "due_date = ?";
            $params[] = $data['due_date'];
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit;
        }
        
        $params[] = $id;
        $db->execute(
            "UPDATE invoices SET " . implode(', ', $updates) . " WHERE id = ?",
            $params
        );
        
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Invoice updated successfully']);
    }
    
} catch (Exception $e) {
    error_log("Admin Invoices API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
