<?php
/**
 * Admin Repairs API
 * Handles CRUD operations for repair management
 * 
 * Endpoints:
 * GET    /api/admin/repairs.php - List all repairs
 * GET    /api/admin/repairs.php?id={id} - Get repair details
 * POST   /api/admin/repairs.php - Create repair
 * PUT    /api/admin/repairs.php?id={id} - Update repair
 * DELETE /api/admin/repairs.php?id={id} - Delete repair
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
    function getRepairsColumns()
    {
        global $db;
        try {
            $columns = $db->query("SHOW COLUMNS FROM repairs");
            $columnNames = array_column($columns, 'Field');
            return $columnNames;
        } catch (Exception $e) {
            return [];
        }
    }

    $columns = getRepairsColumns();
    $hasItemType = in_array('item_type', $columns);
    $hasCategory = in_array('category', $columns);
    $hasEstimatedCost = in_array('estimated_cost', $columns);
    $hasQuotedAmount = in_array('quoted_amount', $columns);

    // Column mappings
    $categoryCol = $hasItemType ? 'item_type' : ($hasCategory ? 'category' : 'item_type');
    $costCol = $hasEstimatedCost ? 'estimated_cost' : ($hasQuotedAmount ? 'quoted_amount' : 'estimated_cost');

    // GET - List or Get Single
    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;

        if ($id) {
            // Get single repair with details
            $repair = $db->queryOne(
                "SELECT r.*, 
                        r.{$costCol} as estimated_cost,
                        r.{$categoryCol} as item_type,
                        COALESCE(cp.display_name, cp.full_name, 'ไม่ระบุ') as customer_name,
                        COALESCE(cp.avatar_url, cp.profile_pic_url) as customer_avatar
                 FROM repairs r
                 LEFT JOIN customer_profiles cp ON r.platform_user_id = cp.platform_user_id 
                    AND cp.platform = COALESCE(r.platform, 'line')
                 WHERE r.id = ?",
                [$id]
            );

            if (!$repair) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Repair not found']);
                exit;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => $repair
            ]);
        } else {
            // List all repairs with pagination and filters
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = (int) ($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $perPage;
            $status = $_GET['status'] ?? '';
            $search = $_GET['search'] ?? '';

            $where = [];
            $params = [];

            if ($status) {
                $where[] = "r.status = ?";
                $params[] = $status;
            }

            if ($search) {
                $where[] = "(r.repair_no LIKE ? OR cp.display_name LIKE ? OR cp.full_name LIKE ? OR r.item_description LIKE ?)";
                $searchParam = "%{$search}%";
                $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
            }

            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

            // Get total count
            $countResult = $db->queryOne(
                "SELECT COUNT(*) as total FROM repairs r
                 LEFT JOIN customer_profiles cp ON r.platform_user_id = cp.platform_user_id 
                    AND cp.platform = COALESCE(r.platform, 'line')
                 {$whereClause}",
                $params
            );
            $total = $countResult['total'] ?? 0;

            // Get repairs
            $repairs = $db->query(
                "SELECT r.id, r.repair_no, r.customer_id, r.status, 
                        r.{$costCol} as estimated_cost, r.{$categoryCol} as item_type,
                        r.item_description, r.issue, r.estimated_completion_date,
                        r.created_at,
                        COALESCE(cp.display_name, cp.full_name, 'ไม่ระบุ') as customer_name,
                        COALESCE(cp.avatar_url, cp.profile_pic_url) as customer_avatar
                 FROM repairs r
                 LEFT JOIN customer_profiles cp ON r.platform_user_id = cp.platform_user_id 
                    AND cp.platform = COALESCE(r.platform, 'line')
                 {$whereClause}
                 ORDER BY r.created_at DESC
                 LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );

            // Get summary
            $summary = $db->queryOne(
                "SELECT 
                    COUNT(CASE WHEN status IN ('pending', 'received', 'diagnosing', 'repairing') THEN 1 END) as active_count,
                    COUNT(CASE WHEN status = 'quoted' THEN 1 END) as awaiting_approval,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                    SUM(CASE WHEN status = 'completed' THEN {$costCol} ELSE 0 END) as total_revenue
                 FROM repairs"
            );

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => $repairs,
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

    // POST - Create Repair
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        $required = ['customer_id', 'item_type', 'item_name', 'issue'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                exit;
            }
        }

        // Generate repair number
        $lastRepair = $db->queryOne(
            "SELECT repair_no FROM repairs ORDER BY id DESC LIMIT 1"
        );
        $nextNum = 1;
        if ($lastRepair && preg_match('/REP(\d+)/', $lastRepair['repair_no'], $matches)) {
            $nextNum = (int) $matches[1] + 1;
        }
        $repairNo = 'REP' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

        // Insert repair - build dynamic SQL based on available columns
        $hasItemBrand = in_array('item_brand', $columns);
        $hasItemModel = in_array('item_model', $columns);
        $hasItemSerial = in_array('item_serial', $columns);
        $hasItemCondition = in_array('item_condition', $columns);
        $hasItemName = in_array('item_name', $columns);

        // Build column and value lists dynamically
        $insertCols = ['repair_no', 'customer_id', $categoryCol, 'item_description', 'issue', $costCol, 'status', 'estimated_completion_date', 'notes', 'created_at'];
        $insertPlaceholders = ['?', '?', '?', '?', '?', '?', "'pending'", '?', '?', 'NOW()'];
        $insertValues = [
            $repairNo,
            $input['customer_id'],
            $input['item_type'],
            $input['item_name'] . ($input['item_description'] ? ' - ' . $input['item_description'] : ''),
            $input['issue'],
            $input['estimated_cost'] ?? 0,
            $input['estimated_completion_date'] ?? null,
            $input['notes'] ?? null
        ];

        // Add item_name if column exists
        if ($hasItemName) {
            $insertCols[] = 'item_name';
            $insertPlaceholders[] = '?';
            $insertValues[] = $input['item_name'];
        }

        // Add optional new fields if columns exist
        if ($hasItemBrand && !empty($input['item_brand'])) {
            $insertCols[] = 'item_brand';
            $insertPlaceholders[] = '?';
            $insertValues[] = $input['item_brand'];
        }
        if ($hasItemModel && !empty($input['item_model'])) {
            $insertCols[] = 'item_model';
            $insertPlaceholders[] = '?';
            $insertValues[] = $input['item_model'];
        }
        if ($hasItemSerial && !empty($input['item_serial'])) {
            $insertCols[] = 'item_serial';
            $insertPlaceholders[] = '?';
            $insertValues[] = $input['item_serial'];
        }
        if ($hasItemCondition && !empty($input['item_condition'])) {
            $insertCols[] = 'item_condition';
            $insertPlaceholders[] = '?';
            $insertValues[] = $input['item_condition'];
        }

        $sql = "INSERT INTO repairs (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertPlaceholders) . ")";

        $db->execute($sql, $insertValues);

        $repairId = $db->lastInsertId();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกรายการซ่อมสำเร็จ',
            'data' => [
                'id' => $repairId,
                'repair_no' => $repairNo
            ]
        ]);
    }

    // PUT - Update Repair
    elseif ($method === 'PUT') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Repair ID required']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $updates = [];
        $params = [];

        $allowedFields = ['status', 'issue', 'estimated_completion_date', 'notes', 'actual_cost', 'completed_date'];
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updates[] = "{$field} = ?";
                $params[] = $input[$field];
            }
        }

        // Handle estimated_cost with schema compatibility
        if (isset($input['estimated_cost'])) {
            $updates[] = "{$costCol} = ?";
            $params[] = $input['estimated_cost'];
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit;
        }

        $params[] = $id;
        $db->execute(
            "UPDATE repairs SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ?",
            $params
        );

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'อัพเดทสำเร็จ']);
    }

    // DELETE - Delete Repair
    elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Repair ID required']);
            exit;
        }

        $db->execute("DELETE FROM repairs WHERE id = ?", [$id]);

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
