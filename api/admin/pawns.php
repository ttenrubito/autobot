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
    function getPawnsColumns()
    {
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
                        COALESCE(cp.display_name, cp.full_name, 'ไม่ระบุ') as customer_name,
                        COALESCE(cp.avatar_url, cp.profile_pic_url) as customer_avatar
                 FROM pawns p
                 LEFT JOIN customer_profiles cp ON p.platform_user_id = cp.platform_user_id 
                    AND cp.platform = COALESCE(p.platform, 'line')
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
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = (int) ($_GET['limit'] ?? 20);
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
                $where[] = "(p.pawn_no LIKE ? OR cp.display_name LIKE ? OR cp.full_name LIKE ? OR p.item_description LIKE ?)";
                $searchParam = "%{$search}%";
                $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
            }

            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

            // Get total count
            $countResult = $db->queryOne(
                "SELECT COUNT(*) as total FROM pawns p
                 LEFT JOIN customer_profiles cp ON p.platform_user_id = cp.platform_user_id 
                    AND cp.platform = COALESCE(p.platform, 'line')
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
                        COALESCE(cp.display_name, cp.full_name, 'ไม่ระบุ') as customer_name,
                        COALESCE(cp.avatar_url, cp.profile_pic_url) as customer_avatar
                 FROM pawns p
                 LEFT JOIN customer_profiles cp ON p.platform_user_id = cp.platform_user_id 
                    AND cp.platform = COALESCE(p.platform, 'line')
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
        $required = ['customer_id', 'item_type', 'item_name', 'loan_amount'];
        foreach ($required as $field) {
            if (empty($input[$field]) && $input[$field] !== 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                exit;
            }
        }

        // Default interest rate to 2% per business requirement
        $interestRate = $input['interest_rate'] ?? 2.00;

        // Generate pawn number
        $lastPawn = $db->queryOne(
            "SELECT pawn_no FROM pawns ORDER BY id DESC LIMIT 1"
        );
        $nextNum = 1;
        if ($lastPawn && preg_match('/PWN(\d+)/', $lastPawn['pawn_no'], $matches)) {
            $nextNum = (int) $matches[1] + 1;
        }
        $pawnNo = 'PWN' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

        // Calculate due date - default 30 days (monthly interest cycle)
        $dueDate = $input['due_date'] ?? null;
        if (!$dueDate) {
            $months = (int) ($input['period_months'] ?? 1);
            $dueDate = date('Y-m-d', strtotime("+{$months} months"));
        }

        // Check which columns exist for compatibility
        $hasWarrantyNo = in_array('warranty_no', $columns);
        $hasProductRefId = in_array('product_ref_id', $columns);
        $hasOriginalOrderId = in_array('original_order_id', $columns);
        $hasItemName = in_array('item_name', $columns);

        // Build dynamic column list
        $insertCols = "pawn_no, customer_id, {$categoryCol}, item_description, {$loanCol}, interest_rate, due_date, status, notes, created_at";
        $insertVals = "?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW()";
        $insertParams = [
            $pawnNo,
            $input['customer_id'],
            $input['item_type'],
            $input['item_name'] . ($input['item_description'] ? ' - ' . $input['item_description'] : ''),
            $input['loan_amount'],
            $interestRate,
            $dueDate,
            $input['notes'] ?? null
        ];

        // Add optional business fields if columns exist
        if ($hasItemName) {
            $insertCols .= ", item_name";
            $insertVals .= ", ?";
            $insertParams[] = $input['item_name'];
        }
        if ($hasWarrantyNo && !empty($input['warranty_no'])) {
            $insertCols .= ", warranty_no";
            $insertVals .= ", ?";
            $insertParams[] = $input['warranty_no'];
        }
        if ($hasProductRefId && !empty($input['product_ref_id'])) {
            $insertCols .= ", product_ref_id";
            $insertVals .= ", ?";
            $insertParams[] = $input['product_ref_id'];
        }
        if ($hasOriginalOrderId && !empty($input['original_order_id'])) {
            $insertCols .= ", original_order_id";
            $insertVals .= ", ?";
            $insertParams[] = $input['original_order_id'];
        }

        // Insert pawn
        $sql = "INSERT INTO pawns ({$insertCols}) VALUES ({$insertVals})";
        $db->execute($sql, $insertParams);

        $pawnId = $db->lastInsertId();

        // Calculate monthly interest for notification
        $monthlyInterest = round((float) $input['loan_amount'] * ($interestRate / 100), 2);

        // Send push notification to customer
        try {
            // Get customer profile info with channel lookup
            $customer = $db->queryOne(
                "SELECT cp.platform, cp.platform_user_id, cp.display_name, cp.tenant_id,
                        cc.id as channel_id
                 FROM customer_profiles cp 
                 LEFT JOIN customer_channels cc ON cc.tenant_id = cp.tenant_id 
                    AND cc.platform = cp.platform AND cc.status = 'active'
                 WHERE cp.id = ?
                 LIMIT 1",
                [$input['customer_id']]
            );

            if ($customer && $customer['platform_user_id']) {
                require_once __DIR__ . '/../../includes/services/PushNotificationService.php';
                $pushService = new PushNotificationService($db);

                $pushService->sendPawnCreated(
                    $customer['platform'],
                    $customer['platform_user_id'],
                    [
                        'pawn_no' => $pawnNo,
                        'item_name' => $input['item_name'],
                        'loan_amount' => $input['loan_amount'],
                        'interest_rate' => $interestRate,
                        'monthly_interest' => $monthlyInterest,
                        'due_date' => $dueDate
                    ],
                    $customer['channel_id']
                );
            }
        } catch (Exception $e) {
            error_log("Pawn notification error: " . $e->getMessage());
            // Don't fail the pawn creation if notification fails
        }

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'บันทึกรายการจำนำสำเร็จ',
            'data' => [
                'id' => $pawnId,
                'pawn_no' => $pawnNo,
                'due_date' => $dueDate,
                'interest_rate' => $interestRate,
                'monthly_interest' => $monthlyInterest
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
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

    // ==================== ADDITIONAL POST ACTIONS ====================
    // Handle action parameter for POST requests
    if ($method === 'POST' && isset($_GET['action'])) {
        $action = $_GET['action'];
        $input = json_decode(file_get_contents('php://input'), true);

        if ($action === 'verify-interest') {
            // Admin verifies interest payment
            $pawnPaymentId = $input['payment_id'] ?? null;
            $status = $input['status'] ?? 'verified'; // verified or rejected

            if (!$pawnPaymentId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Payment ID required']);
                exit;
            }

            // Get payment info
            $payment = $db->queryOne(
                "SELECT pp.*, p.pawn_no, p.item_description, p.platform_user_id, p.platform, p.channel_id
                 FROM pawn_payments pp
                 JOIN pawns p ON pp.pawn_id = p.id
                 WHERE pp.id = ?",
                [$pawnPaymentId]
            );

            if (!$payment) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Payment not found']);
                exit;
            }

            if ($status === 'verified') {
                // Update payment status
                $db->execute(
                    "UPDATE pawn_payments SET status = 'verified', verified_at = NOW() WHERE id = ?",
                    [$pawnPaymentId]
                );

                // Calculate new due date and update pawn
                $months = (int) ($input['months'] ?? 1);
                $newDueDate = date('Y-m-d', strtotime("+{$months} months", strtotime($payment['period_end'] ?? 'now')));

                // Update pawn due date and extension count
                $db->execute(
                    "UPDATE pawns SET 
                        due_date = ?, 
                        status = 'active',
                        extension_count = COALESCE(extension_count, 0) + 1,
                        updated_at = NOW() 
                     WHERE id = ?",
                    [$newDueDate, $payment['pawn_id']]
                );

                // Send push notification
                if ($payment['platform_user_id']) {
                    try {
                        require_once __DIR__ . '/../../includes/services/PushNotificationService.php';
                        $pushService = new PushNotificationService($db);
                        $pushService->sendPawnInterestVerified(
                            $payment['platform'] ?? 'line',
                            $payment['platform_user_id'],
                            [
                                'pawn_no' => $payment['pawn_no'],
                                'amount' => $payment['amount'],
                                'months' => $months,
                                'new_due_date' => $newDueDate
                            ],
                            $payment['channel_id']
                        );
                    } catch (Exception $e) {
                        error_log("Pawn interest notification error: " . $e->getMessage());
                    }
                }

                echo json_encode(['success' => true, 'message' => 'อนุมัติต่อดอกสำเร็จ', 'new_due_date' => $newDueDate]);
            } else {
                // Reject payment
                $db->execute(
                    "UPDATE pawn_payments SET status = 'rejected', rejection_reason = ? WHERE id = ?",
                    [$input['reason'] ?? 'ไม่อนุมัติ', $pawnPaymentId]
                );
                echo json_encode(['success' => true, 'message' => 'ปฏิเสธการต่อดอก']);
            }
            exit;
        }

        if ($action === 'verify-redemption') {
            // Admin verifies redemption payment
            $pawnPaymentId = $input['payment_id'] ?? null;
            $status = $input['status'] ?? 'verified';

            if (!$pawnPaymentId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Payment ID required']);
                exit;
            }

            // Get payment info
            $payment = $db->queryOne(
                "SELECT pp.*, p.id as pawn_id, p.pawn_no, p.product_name, p.product_description, p.pawn_amount,
                        p.platform_user_id, p.platform, p.channel_id, p.case_id, p.external_user_id
                 FROM pawn_payments pp
                 JOIN pawns p ON pp.pawn_id = p.id
                 WHERE pp.id = ?",
                [$pawnPaymentId]
            );

            if (!$payment) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Payment not found']);
                exit;
            }

            if ($status === 'verified') {
                // Update payment status
                $db->execute(
                    "UPDATE pawn_payments SET status = 'verified', verified_at = NOW(), verified_by = ? WHERE id = ?",
                    [$_SESSION['admin_user_id'] ?? 1, $pawnPaymentId]
                );

                // Update pawn status to redeemed
                $db->execute(
                    "UPDATE pawns SET 
                        status = 'redeemed',
                        redeemed_at = NOW(),
                        updated_at = NOW() 
                     WHERE id = ?",
                    [$payment['pawn_id']]
                );

                // ✅ Auto-close related case on successful redemption
                if (!empty($payment['case_id'])) {
                    $db->execute(
                        "UPDATE cases SET 
                            status = 'closed', 
                            resolved_at = NOW(),
                            resolution = 'ไถ่ถอนสำเร็จ - ลูกค้าได้รับสินค้าคืน'
                         WHERE id = ?",
                        [$payment['case_id']]
                    );
                }
                
                // Also close any open pawn_redemption case for this pawn
                $db->execute(
                    "UPDATE cases SET 
                        status = 'closed', 
                        resolved_at = NOW(),
                        resolution = 'ไถ่ถอนสำเร็จ - สลิปผ่านการตรวจสอบ'
                     WHERE case_type = 'pawn_redemption' 
                       AND status = 'open'
                       AND subject LIKE ?",
                    ['%' . $payment['pawn_no'] . '%']
                );

                // Send push notification to customer
                if ($payment['platform_user_id'] || $payment['external_user_id']) {
                    try {
                        require_once __DIR__ . '/../../includes/services/PushMessageService.php';
                        $pushService = new \App\Services\PushMessageService(getDB());
                        
                        $platformUserId = $payment['external_user_id'] ?: $payment['platform_user_id'];
                        $productName = $payment['product_name'] ?? $payment['product_description'] ?? 'สินค้า';
                        $loanAmount = $payment['pawn_amount'] ?? $payment['principal_amount'] ?? 0;
                        
                        $message = "🎉 ไถ่ถอนสำเร็จค่ะ!\n\n"
                            . "📋 รหัสจำนำ: {$payment['pawn_no']}\n"
                            . "📦 สินค้า: {$productName}\n"
                            . "💰 เงินต้น: ฿" . number_format($loanAmount, 0) . "\n"
                            . "💵 ยอดชำระ: ฿" . number_format($payment['amount'] ?? 0, 0) . "\n\n"
                            . "✅ กรุณานัดหมายรับสินค้าคืนที่ร้านค่ะ 😊";
                        
                        $pushService->sendMessage(
                            $payment['platform'] ?? 'line',
                            $platformUserId,
                            $payment['channel_id'],
                            $message
                        );
                    } catch (Exception $e) {
                        error_log("Pawn redemption notification error: " . $e->getMessage());
                    }
                }

                echo json_encode(['success' => true, 'message' => 'อนุมัติไถ่ถอนสำเร็จ กรุณานัดลูกค้ารับสินค้า']);
            } else {
                // Reject redemption
                $db->execute(
                    "UPDATE pawn_payments SET status = 'rejected', rejection_reason = ? WHERE id = ?",
                    [$input['reason'] ?? 'ไม่อนุมัติ', $pawnPaymentId]
                );
                echo json_encode(['success' => true, 'message' => 'ปฏิเสธการไถ่ถอน']);
            }
            exit;
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
