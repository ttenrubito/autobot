<?php
/**
 * Admin Pawns API
 * Handles CRUD operations for pawn management
 * 
 * Endpoints:
 * GET    /api/admin/pawns.php - List all pawns
 * GET    /api/admin/pawns.php?id={id} - Get pawn details
 * POST   /api/admin/pawns.php - Create pawn
 * PUT    /api/admin/pawns.php?id={id} - Update pawn
 * DELETE /api/admin/pawns.php?id={id} - Delete pawn
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
    
    // Detect schema for compatibility
    function getPawnsColumns() {
        global $db;
        try {
            $columns = $db->query("SHOW COLUMNS FROM pawns");
            $columnNames = array_column($columns, 'Field');
            return $columnNames;
        } catch (Exception $e) {
            return [];
        }
    }
    
    $columns = getPawnsColumns();
    $hasItemType = in_array('item_type', $columns);
    $hasCategory = in_array('category', $columns);
    $hasLoanAmount = in_array('loan_amount', $columns);
    $hasPrincipalAmount = in_array('principal_amount', $columns);
    
    // Column mappings
    $categoryCol = $hasItemType ? 'item_type' : ($hasCategory ? 'category' : 'item_type');
    $loanCol = $hasLoanAmount ? 'loan_amount' : ($hasPrincipalAmount ? 'principal_amount' : 'loan_amount');
    
    // GET - List or Get Single
    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;
        
        if ($id) {
            // Get single pawn with details
            $pawn = $db->queryOne(
                "SELECT p.*, 
                        p.{$loanCol} as loan_amount,
                        p.{$categoryCol} as item_type,
                        COALESCE(c.display_name, c.full_name, 'ไม่ระบุ') as customer_name,
                        c.profile_picture as customer_avatar
                 FROM pawns p
                 LEFT JOIN customers c ON p.customer_id = c.id
                 WHERE p.id = ?",
                [$id]
            );
            
            if (!$pawn) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Pawn not found']);
                exit;
            }
            
            // Calculate monthly interest
            $interestRate = $pawn['interest_rate'] ?? 2;
            $loanAmount = $pawn['loan_amount'] ?? 0;
            $pawn['monthly_interest'] = round($loanAmount * ($interestRate / 100), 2);
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => $pawn
            ]);
        } else {
            // List all pawns with pagination and filters
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $perPage;
            $status = $_GET['status'] ?? '';
            $search = $_GET['search'] ?? '';
            
            $where = [];
            $params = [];
            
            if ($status) {
                $where[] = "p.status = ?";
                $params[] = $status;
            }
            
            if ($search) {
                $where[] = "(p.pawn_no LIKE ? OR c.display_name LIKE ? OR c.full_name LIKE ? OR p.item_description LIKE ?)";
                $searchParam = "%{$search}%";
                $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
            }
            
            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
            
            // Get total count
            $countResult = $db->queryOne(
                "SELECT COUNT(*) as total FROM pawns p
                 LEFT JOIN customers c ON p.customer_id = c.id
                 {$whereClause}",
                $params
            );
            $total = $countResult['total'] ?? 0;
            
            // Get pawns
            $pawns = $db->query(
                "SELECT p.id, p.pawn_no, p.customer_id, p.status, 
                        p.{$loanCol} as loan_amount, p.{$categoryCol} as item_type,
                        p.interest_rate, p.item_description, p.due_date,
                        p.created_at,
                        COALESCE(c.display_name, c.full_name, 'ไม่ระบุ') as customer_name,
                        c.profile_picture as customer_avatar
                 FROM pawns p
                 LEFT JOIN customers c ON p.customer_id = c.id
                 {$whereClause}
                 ORDER BY p.created_at DESC
                 LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );
            
            // Calculate monthly interest for each pawn
            foreach ($pawns as &$pawn) {
                $interestRate = $pawn['interest_rate'] ?? 2;
                $loanAmount = $pawn['loan_amount'] ?? 0;
                $pawn['monthly_interest'] = round($loanAmount * ($interestRate / 100), 2);
                
                // Check if overdue
                if ($pawn['due_date']) {
                    $dueDate = new DateTime($pawn['due_date']);
                    $today = new DateTime();
                    $pawn['is_overdue'] = $today > $dueDate && $pawn['status'] === 'active';
                    $pawn['days_until_due'] = $today->diff($dueDate)->format('%r%a');
                }
            }
            
            // Get summary
            $summary = $db->queryOne(
                "SELECT 
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
                    COUNT(CASE WHEN status = 'redeemed' THEN 1 END) as redeemed_count,
                    SUM(CASE WHEN status = 'active' THEN {$loanCol} ELSE 0 END) as active_amount,
                    SUM(CASE WHEN status = 'redeemed' THEN {$loanCol} ELSE 0 END) as redeemed_amount
                 FROM pawns"
            );
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => $pawns,
                'summary' => $summary,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => ceil($total / $perPage)
                ]
            ]);
        }
    }
    
    // POST - Create Pawn
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['customer_id', 'item_type', 'item_name', 'loan_amount', 'interest_rate'];
        foreach ($required as $field) {
            if (empty($input[$field]) && $input[$field] !== 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                exit;
            }
        }
        
        // Generate pawn number
        $lastPawn = $db->queryOne(
            "SELECT pawn_no FROM pawns ORDER BY id DESC LIMIT 1"
        );
        $nextNum = 1;
        if ($lastPawn && preg_match('/PWN(\d+)/', $lastPawn['pawn_no'], $matches)) {
            $nextNum = (int)$matches[1] + 1;
        }
        $pawnNo = 'PWN' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        
        // Calculate due date if not provided
        $dueDate = $input['due_date'] ?? null;
        if (!$dueDate) {
            $months = (int)($input['period_months'] ?? 3);
            $dueDate = date('Y-m-d', strtotime("+{$months} months"));
        }
        
        // Insert pawn
        $sql = "INSERT INTO pawns (
                    pawn_no, customer_id, {$categoryCol}, item_description,
                    {$loanCol}, interest_rate, due_date, status, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())";
        
        $db->execute($sql, [
            $pawnNo,
            $input['customer_id'],
            $input['item_type'],
            $input['item_name'] . ($input['item_description'] ? ' - ' . $input['item_description'] : ''),
            $input['loan_amount'],
            $input['interest_rate'],
            $dueDate,
            $input['notes'] ?? null
        ]);
        
        $pawnId = $db->lastInsertId();
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกรายการจำนำสำเร็จ',
            'data' => [
                'id' => $pawnId,
                'pawn_no' => $pawnNo,
                'due_date' => $dueDate
            ]
        ]);
    }
    
    // PUT - Update Pawn
    elseif ($method === 'PUT') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Pawn ID required']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $updates = [];
        $params = [];
        
        $allowedFields = ['status', 'interest_rate', 'due_date', 'notes'];
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updates[] = "{$field} = ?";
                $params[] = $input[$field];
            }
        }
        
        // Handle loan_amount with schema compatibility
        if (isset($input['loan_amount'])) {
            $updates[] = "{$loanCol} = ?";
            $params[] = $input['loan_amount'];
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit;
        }
        
        $params[] = $id;
        $db->execute(
            "UPDATE pawns SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ?",
            $params
        );
        
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'อัพเดทสำเร็จ']);
    }
    
    // DELETE - Delete Pawn
    elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Pawn ID required']);
            exit;
        }
        
        $db->execute("DELETE FROM pawns WHERE id = ?", [$id]);
        
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'ลบสำเร็จ']);
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
