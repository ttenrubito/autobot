<?php
/**
 * Admin Deposits API
 * Handles CRUD operations for deposit management
 * 
 * Endpoints:
 * GET    /api/admin/deposits.php - List all deposits
 * GET    /api/admin/deposits.php?id={id} - Get deposit details
 * POST   /api/admin/deposits.php - Create deposit
 * PUT    /api/admin/deposits.php?id={id} - Update deposit
 * DELETE /api/admin/deposits.php?id={id} - Delete deposit
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
    function getDepositsColumns()
    {
        global $db;
        try {
            $columns = $db->query("SHOW COLUMNS FROM deposits");
            $columnNames = array_column($columns, 'Field');
            return $columnNames;
        } catch (Exception $e) {
            return [];
        }
    }

    $columns = getDepositsColumns();
    $hasProductName = in_array('product_name', $columns);
    $hasItemName = in_array('item_name', $columns);

    // GET - List or Get Single
    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;

        if ($id) {
            // Get single deposit with details
            $nameColumn = $hasProductName ? 'product_name' : ($hasItemName ? 'item_name' : 'product_name');

            $deposit = $db->queryOne(
                "SELECT d.*, 
                        d.{$nameColumn} as item_name,
                        COALESCE(c.display_name, c.full_name, 'ไม่ระบุ') as customer_name,
                        c.profile_picture as customer_avatar
                 FROM deposits d
                 LEFT JOIN customers c ON d.customer_id = c.id
                 WHERE d.id = ?",
                [$id]
            );

            if (!$deposit) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Deposit not found']);
                exit;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => $deposit
            ]);
        } else {
            // List all deposits with pagination and filters
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = (int) ($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $perPage;
            $status = $_GET['status'] ?? '';
            $search = $_GET['search'] ?? '';

            $nameColumn = $hasProductName ? 'd.product_name' : ($hasItemName ? 'd.item_name' : 'd.product_name');

            $where = [];
            $params = [];

            if ($status) {
                $where[] = "d.status = ?";
                $params[] = $status;
            }

            if ($search) {
                $where[] = "(d.deposit_no LIKE ? OR c.display_name LIKE ? OR c.full_name LIKE ? OR {$nameColumn} LIKE ?)";
                $searchParam = "%{$search}%";
                $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
            }

            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

            // Get total count
            $countResult = $db->queryOne(
                "SELECT COUNT(*) as total FROM deposits d
                 LEFT JOIN customers c ON d.customer_id = c.id
                 {$whereClause}",
                $params
            );
            $total = $countResult['total'] ?? 0;

            // Get deposits
            $deposits = $db->query(
                "SELECT d.id, d.deposit_no, d.customer_id, d.status, d.deposit_amount,
                        {$nameColumn} as item_name,
                        d.created_at, d.expires_at,
                        COALESCE(c.display_name, c.full_name, 'ไม่ระบุ') as customer_name,
                        c.profile_picture as customer_avatar
                 FROM deposits d
                 LEFT JOIN customers c ON d.customer_id = c.id
                 {$whereClause}
                 ORDER BY d.created_at DESC
                 LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );

            // Get summary
            $summary = $db->queryOne(
                "SELECT 
                    COUNT(CASE WHEN status = 'deposited' THEN 1 END) as deposited_count,
                    COUNT(CASE WHEN status = 'picked_up' THEN 1 END) as picked_up_count,
                    SUM(CASE WHEN status = 'deposited' THEN deposit_amount ELSE 0 END) as deposited_amount
                 FROM deposits"
            );

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => $deposits,
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

    // POST - Create Deposit
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        $required = ['customer_id', 'item_type', 'item_name', 'deposit_amount'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                exit;
            }
        }

        // Generate deposit number
        $lastDeposit = $db->queryOne(
            "SELECT deposit_no FROM deposits ORDER BY id DESC LIMIT 1"
        );
        $nextNum = 1;
        if ($lastDeposit && preg_match('/DEP(\d+)/', $lastDeposit['deposit_no'], $matches)) {
            $nextNum = (int) $matches[1] + 1;
        }
        $depositNo = 'DEP' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

        // Determine column names based on schema
        $nameColumn = $hasProductName ? 'product_name' : 'item_name';
        $descColumn = in_array('product_description', $columns) ? 'product_description' : 'item_description';

        // ✅ Get shop_owner_id from AdminAuth (logged-in admin user)
        $shopOwnerId = AdminAuth::id();

        // Insert deposit with shop_owner_id for data isolation
        $sql = "INSERT INTO deposits (
                    deposit_no, customer_id, shop_owner_id, {$nameColumn}, {$descColumn},
                    item_type, deposit_amount, status, expected_pickup_date, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'deposited', ?, ?, NOW())";

        $db->execute($sql, [
            $depositNo,
            $input['customer_id'],
            $shopOwnerId,  // ✅ shop_owner_id for data isolation
            $input['item_name'],
            $input['item_description'] ?? null,
            $input['item_type'],
            $input['deposit_amount'],
            $input['expected_pickup_date'] ?? null,
            $input['notes'] ?? null
        ]);

        $depositId = $db->lastInsertId();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกรายการฝากสำเร็จ',
            'data' => [
                'id' => $depositId,
                'deposit_no' => $depositNo
            ]
        ]);
    }

    // PUT - Update Deposit
    elseif ($method === 'PUT') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Deposit ID required']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $updates = [];
        $params = [];

        $allowedFields = ['status', 'deposit_amount', 'notes', 'expected_pickup_date'];
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updates[] = "{$field} = ?";
                $params[] = $input[$field];
            }
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit;
        }

        $params[] = $id;
        $db->execute(
            "UPDATE deposits SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ?",
            $params
        );

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'อัพเดทสำเร็จ']);
    }

    // DELETE - Delete Deposit
    elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Deposit ID required']);
            exit;
        }

        $db->execute("DELETE FROM deposits WHERE id = ?", [$id]);

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'ลบสำเร็จ']);
    } else {
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
