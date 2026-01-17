<?php
/**
 * Customer Orders API  
 * GET /api/customer/orders - Get all orders
 * GET /api/customer/orders/{id} - Get specific order
 * POST /api/customer/orders - Create new order
 * 
 * Database Schema (orders table):
 * - order_number (not order_no)
 * - order_type: full_payment, installment, savings_completion
 * - subtotal, discount, shipping_fee, total_amount, paid_amount
 * - status: draft, pending_payment, paid, processing, shipped, delivered, cancelled, refunded
 * - payment_status: unpaid, partial, paid, refunded
 * - customer_name, customer_phone, customer_email, customer_platform, customer_avatar
 * 
 * Product info is in order_items table (order_id, product_name, product_code, quantity, unit_price)
 * Installment info is in installments table (total_terms as installment_months)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

// Debug schema endpoint - bypass auth for testing
if (isset($_GET['debug_schema'])) {
    try {
        $pdo = getDB();
        $columns = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM orders");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[$row['Field']] = true;
        }
        // Detect based on order_type column (more reliable)
        $hasOrderType = isset($columns['order_type']);
        echo json_encode([
            'success' => true,
            'schema' => [
                'hasOrderType' => $hasOrderType,
                'hasPaymentType' => isset($columns['payment_type']),
                'hasOrderNumber' => isset($columns['order_number']),
                'hasOrderNo' => isset($columns['order_no']),
                'userColumn' => isset($columns['user_id']) ? 'user_id' : 'customer_id',
                'orderNoCol' => isset($columns['order_no']) ? 'order_no' : 'order_number',
                'typeCol' => $hasOrderType ? 'order_type' : 'payment_type',
                'noteCol' => isset($columns['notes']) ? 'notes' : 'note',
                'columns' => array_keys($columns)
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Verify authentication
$auth = verifyToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $auth['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Parse URI
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));

try {
    $pdo = getDB();

    // Get tenant_id from user (same pattern as payments.php and search-orders.php)
    $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_id = $userRow['tenant_id'] ?? 'default';

    // =========================================================================
    // Dynamic Schema Detection - Support both old and new schema
    // Old schema: order_no, customer_id, payment_type, notes
    // New schema: order_number, user_id, order_type, note
    // Production may have MIXED columns - detect each individually!
    // =========================================================================
    $columns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM orders");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[$row['Field']] = true;
    }

    // Detect each column individually (don't assume all new or all old)
    $userColumn = isset($columns['user_id']) ? 'user_id' : 'customer_id';
    $orderNoCol = isset($columns['order_no']) ? 'order_no' : 'order_number';
    $typeCol = isset($columns['order_type']) ? 'order_type' : 'payment_type';
    $noteCol = isset($columns['notes']) ? 'notes' : 'note';

    // Check if we have order_items table (new schema feature)
    $hasOrderItems = false;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'order_items'");
        $hasOrderItems = $stmt->rowCount() > 0;
    } catch (Exception $e) {
    }

    // Build SELECT columns dynamically based on what exists
    $selectCols = [
        'o.id',
        "o.{$orderNoCol} as order_number",
        "o.{$orderNoCol} as order_no",
        "o.{$typeCol} as order_type",
        "o.{$typeCol} as payment_type",
        'o.total_amount',
        'o.status',
        "o.{$noteCol} as note",
        "o.{$noteCol} as notes",
        'o.created_at',
        'o.updated_at'
    ];

    // Add optional columns if they exist
    $optionalCols = [
        'installment_id',
        'savings_goal_id',
        'subtotal',
        'discount',
        'shipping_fee',
        'paid_amount',
        'payment_status',
        'shipping_name',
        'shipping_phone',
        'shipping_address',
        'tracking_number',
        'shipped_at',
        'delivered_at',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_platform',
        'customer_platform_id',
        'customer_avatar',
        'quantity',
        'unit_price',
        'product_name',
        'product_code',
        'installment_months',
        'shipping_address_id'
    ];

    foreach ($optionalCols as $col) {
        if (isset($columns[$col])) {
            $selectCols[] = "o.{$col}";
        }
    }

    $selectClause = implode(",\n                    ", $selectCols);

    if ($method === 'GET') {
        // Support both path-based /orders/{id} and query param ?id=X
        $order_id = null;
        if (isset($uri_parts[3]) && is_numeric($uri_parts[3])) {
            $order_id = (int) $uri_parts[3];
        } elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $order_id = (int) $_GET['id'];
        }

        if ($order_id) {
            // GET /api/customer/orders/{id} OR ?id=X - Single order detail

            // Check if tenant_id column exists
            $hasTenantId = isset($columns['tenant_id']);

            if ($hasTenantId) {
                $stmt = $pdo->prepare("
                    SELECT 
                        {$selectClause}
                    FROM orders o
                    WHERE o.id = ? AND o.tenant_id = ?
                ");
                $stmt->execute([$order_id, $tenant_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT 
                        {$selectClause}
                    FROM orders o
                    WHERE o.id = ? AND o.{$userColumn} = ?
                ");
                $stmt->execute([$order_id, $user_id]);
            }
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Order not found']);
                exit;
            }

            // Get order items (product details) if table exists
            $items = [];
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'order_items'");
                if ($stmt->rowCount() > 0) {
                    // Check which columns exist in order_items
                    $itemCols = [];
                    $colStmt = $pdo->query("SHOW COLUMNS FROM order_items");
                    while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                        $itemCols[$col['Field']] = true;
                    }

                    // Build SELECT clause with only existing columns
                    $selectItemCols = ['id', 'order_id'];
                    $optionalItemCols = ['product_ref_id', 'product_name', 'product_code', 'quantity', 'unit_price', 'discount', 'total_price', 'subtotal'];
                    foreach ($optionalItemCols as $col) {
                        if (isset($itemCols[$col])) {
                            $selectItemCols[] = $col;
                        }
                    }

                    $stmt = $pdo->prepare("SELECT " . implode(', ', $selectItemCols) . " FROM order_items WHERE order_id = ?");
                    $stmt->execute([$order_id]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {
                // order_items table might not exist
                $items = [];
            }
            $order['items'] = $items;

            // Build product_name and product_code from first item (for backward compatibility)
            if (!empty($items) && !empty($items[0]['product_name'])) {
                $order['product_name'] = $items[0]['product_name'];
                $order['product_code'] = $items[0]['product_code'] ?? '';
                $order['quantity'] = array_sum(array_column($items, 'quantity'));
            } else {
                // Keep values from orders table if already set, otherwise use defaults
                $order['product_name'] = $order['product_name'] ?? '-';
                $order['product_code'] = $order['product_code'] ?? '';
                $order['quantity'] = $order['quantity'] ?? 1;
            }

            // Get installment info if order_type is installment
            $order['installment_months'] = 0;
            $order['installment_schedule'] = null;
            if ($order['order_type'] === 'installment' && !empty($order['installment_id'])) {
                $stmt = $pdo->prepare("
                    SELECT total_terms, amount_per_term, paid_terms, status as installment_status
                    FROM installments
                    WHERE id = ?
                ");
                $stmt->execute([$order['installment_id']]);
                $inst = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($inst) {
                    $order['installment_months'] = (int) $inst['total_terms'];
                    $order['installment_info'] = $inst;
                }

                // Get installment schedules
                $stmt = $pdo->prepare("
                    SELECT id, period_number, due_date, amount, paid_amount, status
                    FROM installment_schedules
                    WHERE installment_id = ?
                    ORDER BY period_number ASC
                ");
                $stmt->execute([$order['installment_id']]);
                $order['installment_schedule'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Get payments for this order
            $stmt = $pdo->prepare("
                SELECT 
                    id, payment_no, amount, payment_method, status, verified_at, created_at
                FROM payments
                WHERE order_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$order_id]);
            $order['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $order]);

        } else {
            // GET /api/customer/orders - List all orders
            $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 20;
            $offset = ($page - 1) * $limit;

            $status = isset($_GET['status']) ? $_GET['status'] : null;
            $order_type = isset($_GET['order_type']) ? $_GET['order_type'] : null;

            // Build query - use tenant_id (same pattern as search-orders.php)
            // Check if tenant_id column exists using direct query (more reliable)
            $colCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'tenant_id'");
            $hasTenantId = $colCheck->rowCount() > 0;

            if ($hasTenantId) {
                $where = ["o.tenant_id = ?"];
                $params = [$tenant_id];
            } else {
                // No tenant_id column - show all orders (same as search-orders.php fallback)
                $where = ["1=1"];
                $params = [];
            }

            if ($status) {
                $where[] = 'o.status = ?';
                $params[] = $status;
            }

            if ($order_type) {
                $where[] = "o.{$typeCol} = ?";
                $params[] = $order_type;
            }

            $where_clause = implode(' AND ', $where);

            // Get total count
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM orders o
                WHERE $where_clause
            ");
            $stmt->execute($params);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Check if order_items table exists for product info
            $hasOrderItems = false;
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'order_items'");
                $hasOrderItems = $stmt->rowCount() > 0;
            } catch (Exception $e) {
            }

            // Build product info subqueries if order_items exists
            $productSubqueries = '';
            if ($hasOrderItems) {
                $productSubqueries = ",
                    (SELECT oi.product_name FROM order_items oi WHERE oi.order_id = o.id LIMIT 1) as product_name_items,
                    (SELECT oi.product_code FROM order_items oi WHERE oi.order_id = o.id LIMIT 1) as product_code_items,
                    (SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id) as quantity_items";
            }

            // Get orders
            $stmt = $pdo->prepare("
                SELECT 
                    {$selectClause}
                    {$productSubqueries}
                FROM orders o
                WHERE $where_clause
                ORDER BY o.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Process orders - normalize field names
            foreach ($orders as &$order) {
                // Use product info from order_items if available, else from order itself
                if ($hasOrderItems && !empty($order['product_name_items'])) {
                    $order['product_name'] = $order['product_name_items'];
                    $order['product_code'] = $order['product_code_items'] ?? '';
                    $order['quantity'] = $order['quantity_items'] ?? 1;
                } else {
                    $order['product_name'] = $order['product_name'] ?? '-';
                    $order['product_code'] = $order['product_code'] ?? '';
                    $order['quantity'] = $order['quantity'] ?? 1;
                }
                // Clean up temp fields
                unset($order['product_name_items'], $order['product_code_items'], $order['quantity_items']);
                $order['quantity'] = (int) ($order['quantity'] ?? 0);
                $order['installment_months'] = 0;

                if ($order['order_type'] === 'installment' && !empty($order['installment_id'])) {
                    $stmt2 = $pdo->prepare("SELECT total_terms FROM installments WHERE id = ?");
                    $stmt2->execute([$order['installment_id']]);
                    $inst = $stmt2->fetch(PDO::FETCH_ASSOC);
                    if ($inst) {
                        $order['installment_months'] = (int) $inst['total_terms'];
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'orders' => $orders,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => (int) $total,
                        'total_pages' => ceil($total / $limit)
                    ]
                ]
            ]);
        }

    } elseif ($method === 'POST') {
        // Check for action parameter
        $action = $_POST['action'] ?? $_GET['action'] ?? null;

        if ($action === 'update') {
            // POST /api/customer/orders?action=update - Update existing order
            updateOrder($pdo, $user_id, $tenant_id);
        } else {
            // POST /api/customer/orders - Create new order
            createOrder($pdo, $user_id, $userColumn);
        }

    } elseif ($method === 'PUT' || $method === 'PATCH') {
        // PUT/PATCH /api/customer/orders/{id} - Update existing order
        updateOrder($pdo, $user_id, $tenant_id);

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("Orders API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Create new order with order_items
 * Supports both old schema (order_no, payment_type, notes) and new schema (order_number, order_type, note)
 */
function createOrder($pdo, $user_id, $userColumn = 'user_id')
{
    $input = json_decode(file_get_contents('php://input'), true);

    // Detect schema - check each column individually
    $columns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM orders");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[$row['Field']] = true;
    }

    // Detect each column individually
    $orderNoCol = isset($columns['order_no']) ? 'order_no' : 'order_number';
    $typeCol = isset($columns['order_type']) ? 'order_type' : 'payment_type';
    $noteCol = isset($columns['notes']) ? 'notes' : 'note';

    // Check if we have order_items table
    $hasOrderItems = false;
    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'order_items'");
        $hasOrderItems = $stmtCheck->rowCount() > 0;
    } catch (Exception $e) {
    }

    // Validate required fields
    $productName = trim($input['product_name'] ?? '');
    $totalAmount = floatval($input['total_amount'] ?? 0);
    $quantity = intval($input['quantity'] ?? 1);

    // Ensure quantity is at least 1
    if ($quantity < 1) {
        $quantity = 1;
    }

    if (empty($productName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุชื่อสินค้า']);
        return;
    }

    if ($totalAmount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุยอดเงิน']);
        return;
    }

    // Generate order number
    $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    // Prepare data
    $productCode = trim($input['product_code'] ?? '');
    $orderType = $input['order_type'] ?? ($input['payment_type'] ?? 'full_payment');
    $customerName = trim($input['customer_name'] ?? '');
    $customerPhone = trim($input['customer_phone'] ?? '');
    $note = trim($input['note'] ?? ($input['notes'] ?? ''));

    // Calculate unit price - ensure it's never 0 or invalid
    $unitPrice = ($quantity > 0) ? ($totalAmount / $quantity) : $totalAmount;
    if ($unitPrice <= 0) {
        $unitPrice = $totalAmount; // fallback
    }

    // Deposit-specific fields
    $depositAmount = floatval($input['deposit_amount'] ?? 0);
    $depositExpiry = $input['deposit_expiry'] ?? null;

    // Installment-specific fields  
    $downPayment = floatval($input['down_payment'] ?? 0);
    $installmentMonths = intval($input['installment_months'] ?? 3);

    // Shipping fields
    $shippingMethod = trim($input['shipping_method'] ?? 'pickup');
    $shippingAddress = trim($input['shipping_address'] ?? '');
    $shippingFee = floatval($input['shipping_fee'] ?? 0);
    $trackingNumber = trim($input['tracking_number'] ?? '');

    // Determine if we have order_type (new) or payment_type (old)
    $hasOrderTypeCol = isset($columns['order_type']);

    // Map payment_type values based on which column exists
    if ($hasOrderTypeCol) {
        // Has order_type column - use new values
        if ($orderType === 'full')
            $orderType = 'full_payment';
        if ($orderType === 'savings')
            $orderType = 'savings_completion';
        // Keep deposit and installment as-is
    } else {
        // Has payment_type column - use old values  
        if ($orderType === 'full_payment')
            $orderType = 'full';
        if ($orderType === 'savings_completion')
            $orderType = 'full';
        // Keep deposit and installment as-is
    }

    // Determine initial status based on order type
    $initialStatus = 'pending';
    $paidAmount = 0;
    $paymentStatus = 'unpaid';

    if ($orderType === 'deposit') {
        // Deposit order: partially paid, waiting for balance
        $initialStatus = 'pending_balance';
        $paidAmount = $depositAmount;
        $paymentStatus = $depositAmount > 0 ? 'partial' : 'unpaid';
        // Append deposit info to notes
        $depositInfo = "\n\n--- มัดจำ ---\nยอดมัดจำ: " . number_format($depositAmount, 2) . " บาท";
        if ($depositExpiry) {
            $depositInfo .= "\nกันสินค้าถึง: " . $depositExpiry;
        }
        $note .= $depositInfo;
    } elseif ($orderType === 'installment') {
        // Installment order: down payment received, waiting for installments
        $initialStatus = 'pending_installment';
        $paidAmount = $downPayment;
        $paymentStatus = $downPayment > 0 ? 'partial' : 'unpaid';
    }

    try {
        $pdo->beginTransaction();

        if ($hasOrderItems) {
            // Has order_items table - insert into both tables
            $insertCols = [
                $orderNoCol,
                $userColumn,
                $typeCol,
                'total_amount',
                'status'
            ];
            $insertVals = ['?', '?', '?', '?', '?'];
            $insertParams = [$orderNumber, $user_id, $orderType, $totalAmount, $initialStatus];

            // ✅ Add product_name to orders table (for backward compatibility & search)
            if (isset($columns['product_name'])) {
                $insertCols[] = 'product_name';
                $insertVals[] = '?';
                $insertParams[] = $productName ?: 'สินค้า';
            }
            if (isset($columns['product_code'])) {
                $insertCols[] = 'product_code';
                $insertVals[] = '?';
                $insertParams[] = $productCode ?: null;
            }

            // Add optional columns if they exist
            if (isset($columns['subtotal'])) {
                $insertCols[] = 'subtotal';
                $insertVals[] = '?';
                $insertParams[] = $totalAmount;
            }
            if (isset($columns['paid_amount'])) {
                $insertCols[] = 'paid_amount';
                $insertVals[] = '?';
                $insertParams[] = $paidAmount;
            }
            if (isset($columns['payment_status'])) {
                $insertCols[] = 'payment_status';
                $insertVals[] = '?';
                $insertParams[] = $paymentStatus;
            }
            if (isset($columns['customer_name'])) {
                $insertCols[] = 'customer_name';
                $insertVals[] = '?';
                $insertParams[] = $customerName ?: null;
            }
            if (isset($columns['customer_phone'])) {
                $insertCols[] = 'customer_phone';
                $insertVals[] = '?';
                $insertParams[] = $customerPhone ?: null;
            }
            // Installment-specific columns
            if (isset($columns['installment_months']) && $orderType === 'installment') {
                $insertCols[] = 'installment_months';
                $insertVals[] = '?';
                $insertParams[] = $installmentMonths;
            }
            if (isset($columns['down_payment']) && $orderType === 'installment') {
                $insertCols[] = 'down_payment';
                $insertVals[] = '?';
                $insertParams[] = $downPayment;
            }
            // Shipping-specific columns
            if (isset($columns['shipping_method'])) {
                $insertCols[] = 'shipping_method';
                $insertVals[] = '?';
                $insertParams[] = $shippingMethod;
            }
            if (isset($columns['shipping_address']) && $shippingMethod !== 'pickup') {
                $insertCols[] = 'shipping_address';
                $insertVals[] = '?';
                $insertParams[] = $shippingAddress ?: null;
            }
            if (isset($columns['shipping_fee']) && $shippingFee > 0) {
                $insertCols[] = 'shipping_fee';
                $insertVals[] = '?';
                $insertParams[] = $shippingFee;
            }
            if (isset($columns['tracking_number']) && !empty($trackingNumber)) {
                $insertCols[] = 'tracking_number';
                $insertVals[] = '?';
                $insertParams[] = $trackingNumber;
            }
            $insertCols[] = $noteCol;
            $insertVals[] = '?';
            $insertParams[] = $note ?: null;
            $insertCols[] = 'created_at';
            $insertVals[] = 'NOW()';
            $insertCols[] = 'updated_at';
            $insertVals[] = 'NOW()';

            $sql = "INSERT INTO orders (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($insertParams);

            $orderId = $pdo->lastInsertId();

            // Insert order item
            $stmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id, product_name, product_code, quantity, unit_price, discount, total_price, created_at
                ) VALUES (?, ?, ?, ?, ?, 0, ?, NOW())
            ");
            $stmt->execute([
                $orderId,
                $productName,
                $productCode ?: null,
                $quantity,
                $unitPrice,
                $totalAmount
            ]);
        } else {
            // No order_items table - product info in orders table directly
            // IMPORTANT: unit_price is required (no default value on production)
            $insertCols = [
                $orderNoCol,
                $userColumn,
                $typeCol,
                'product_name',
                'quantity',
                'unit_price',
                'total_amount',
                'status',
                $noteCol,
                'created_at',
                'updated_at'
            ];
            $insertVals = ['?', '?', '?', '?', '?', '?', '?', '?', '?', 'NOW()', 'NOW()'];
            $insertParams = [
                $orderNumber,
                $user_id,
                $orderType,
                $productName,
                $quantity,
                $unitPrice,
                $totalAmount,
                'pending',
                $note ?: null
            ];

            // Add optional columns if they exist
            if (isset($columns['product_code'])) {
                $insertCols[] = 'product_code';
                $insertVals[] = '?';
                $insertParams[] = $productCode ?: null;
            }

            $sql = "INSERT INTO orders (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($insertParams);

            $orderId = $pdo->lastInsertId();
        }

        // =========================================================================
        // Create Installment Records (if installment order)
        // Spec: 3 งวด, ค่าดำเนินการ 3% ตลอดสัญญา (ไม่ใช่ต่อเดือน)
        // 
        // ตัวอย่าง 10,000 บาท:
        // - งวดที่ 1: 3,500 + 300 (3%) = 3,800 บาท
        // - งวดที่ 2: 3,500 บาท
        // - งวดที่ 3: 3,000 บาท (ยอดคงเหลือ)
        // =========================================================================
        $installmentId = null;
        if ($orderType === 'installment' && $orderId) {
            try {
                // Check if installments table exists
                $hasInstallments = false;
                try {
                    $stmtCheck = $pdo->query("SHOW TABLES LIKE 'installments'");
                    $hasInstallments = $stmtCheck->rowCount() > 0;
                } catch (Exception $e) {
                }

                if ($hasInstallments) {
                    $remaining = $totalAmount - $downPayment;

                    // Service fee: 3% TOTAL (not per month!)
                    $serviceFeeRate = 0.03;
                    $serviceFee = round($remaining * $serviceFeeRate);
                    $months = 3;

                    // Calculate installment amounts following spec:
                    // - Period 1 & 2: equal amounts (rounded up to nearest 500)
                    // - Period 3: remaining balance
                    // - Service fee added to period 1
                    $baseAmount = $remaining / 3;
                    $p1 = ceil($baseAmount / 500) * 500;  // Round up to nearest 500
                    $p2 = $p1;
                    $p3 = $remaining - $p1 - $p2;

                    // Adjust if period 3 becomes negative
                    if ($p3 < 0) {
                        $p1 = ceil($remaining / 3);
                        $p2 = ceil($remaining / 3);
                        $p3 = $remaining - $p1 - $p2;
                        if ($p3 < 0) {
                            $p2 += $p3;
                            $p3 = 0;
                        }
                    }

                    // Period 1 includes service fee
                    $period1Total = $p1 + $serviceFee;
                    $grandTotal = $remaining + $serviceFee;

                    // For installments table compatibility
                    $avgPayment = $grandTotal / $months;  // Average for monthly_payment field

                    // Generate installment number
                    $installmentNo = 'INS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

                    // Insert main installment record
                    $stmt = $pdo->prepare("
                        INSERT INTO installments (
                            installment_no, order_id, customer_id, tenant_id,
                            product_name, product_code, total_amount, down_payment,
                            remaining_amount, total_terms, interest_rate,
                            monthly_payment, total_interest, grand_total,
                            status, created_at, updated_at
                        ) VALUES (?, ?, ?, 'default', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
                    ");
                    $stmt->execute([
                        $installmentNo,
                        $orderId,
                        $user_id,
                        $productName,
                        $productCode ?: null,
                        $totalAmount,
                        $downPayment,
                        $remaining,
                        $months,
                        $serviceFeeRate * 100, // Store as percentage (3%)
                        $avgPayment,
                        $serviceFee,  // Total service fee (not monthly interest)
                        $grandTotal
                    ]);

                    $installmentId = $pdo->lastInsertId();

                    // Check if installment_payments table exists for payment schedule
                    $hasPayments = false;
                    try {
                        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'installment_payments'");
                        $hasPayments = $stmtCheck->rowCount() > 0;
                    } catch (Exception $e) {
                    }

                    if ($hasPayments) {
                        // Create 3 payment schedule records with correct amounts
                        $payments = [
                            1 => $period1Total,  // Period 1: includes service fee
                            2 => $p2,            // Period 2: base amount
                            3 => $p3             // Period 3: remaining
                        ];

                        foreach ($payments as $termNum => $amount) {
                            $dueDate = date('Y-m-d', strtotime("+{$termNum} month"));
                            $stmt = $pdo->prepare("
                                INSERT INTO installment_payments (
                                    installment_id, term_number, amount, due_date, status, created_at
                                ) VALUES (?, ?, ?, ?, 'pending', NOW())
                            ");
                            $stmt->execute([$installmentId, $termNum, $amount, $dueDate]);
                        }
                    }

                    // Update order with installment_id
                    if (isset($columns['installment_id'])) {
                        $stmt = $pdo->prepare("UPDATE orders SET installment_id = ? WHERE id = ?");
                        $stmt->execute([$installmentId, $orderId]);
                    }
                }
            } catch (Exception $instEx) {
                error_log("Installment creation error for order {$orderNumber}: " . $instEx->getMessage());
                // Don't fail the order if installment creation fails
            }
        }

        $pdo->commit();

        // =========================================================================
        // Auto Push Message to Customer
        // Send notification based on order type with proper template
        // =========================================================================
        $messageSent = false;
        $customerId = $input['customer_id'] ?? null;

        // Only send if customer_id provided (has platform info)
        if ($customerId) {
            try {
                // Get customer's platform info
                $stmt = $pdo->prepare("SELECT platform, platform_user_id FROM customer_profiles WHERE id = ?");
                $stmt->execute([(int) $customerId]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($customer && !empty($customer['platform_user_id'])) {
                    // Get channel_id from customer_services
                    $stmt = $pdo->prepare("SELECT id FROM customer_services WHERE user_id = ? AND platform = ? LIMIT 1");
                    $stmt->execute([$user_id, $customer['platform']]);
                    $channel = $stmt->fetch(PDO::FETCH_ASSOC);
                    $channelId = $channel ? (int) $channel['id'] : null;

                    require_once __DIR__ . '/../../includes/services/PushNotificationService.php';
                    $pushService = new PushNotificationService(Database::getInstance());

                    // Build notification data based on order type
                    $notificationData = [
                        'product_name' => $productName,
                        'total_amount' => number_format($totalAmount, 0),
                        'order_number' => $orderNumber,
                    ];

                    // Add type-specific data
                    if ($orderType === 'installment' && isset($period1Total)) {
                        // Calculate due dates
                        $period1Due = date('d/m/Y', strtotime('+1 month'));

                        $notificationData['total_periods'] = 3;
                        $notificationData['period_1_amount'] = number_format($period1Total, 0);
                        $notificationData['period_1_due'] = $period1Due;
                        $notificationData['period_2_amount'] = number_format($p2 ?? 0, 0);
                        $notificationData['period_3_amount'] = number_format($p3 ?? 0, 0);
                    } elseif (in_array($orderType, ['savings', 'savings_completion'])) {
                        $notificationData['target_amount'] = number_format($totalAmount, 0);
                        $notificationData['current_balance'] = '0';
                    } elseif ($orderType === 'deposit') {
                        $notificationData['deposit_amount'] = number_format($depositAmount, 0);
                        if ($depositExpiry) {
                            $notificationData['deposit_expiry'] = $depositExpiry;
                        }
                    }

                    // Send notification using template
                    $result = $pushService->sendOrderCreated(
                        $customer['platform'],
                        $customer['platform_user_id'],
                        $orderType,
                        $notificationData,
                        $channelId
                    );

                    $messageSent = $result['success'] ?? false;

                    if (!$messageSent) {
                        error_log("Auto push failed for order {$orderNumber}: " . ($result['error'] ?? 'Unknown error'));
                    }
                }
            } catch (Exception $pushEx) {
                // Don't fail the order if push fails
                error_log("Push message error for order {$orderNumber}: " . $pushEx->getMessage());
            }
        }

        // Also send custom message if provided (legacy support)
        $customMessageSent = false;
        $sendMessage = !empty($input['send_message']);
        $customerMessage = trim($input['customer_message'] ?? '');

        if ($sendMessage && !empty($customerMessage) && $customerId && !$messageSent) {
            try {
                $stmt = $pdo->prepare("SELECT platform, platform_user_id FROM customer_profiles WHERE id = ?");
                $stmt->execute([(int) $customerId]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($customer && !empty($customer['platform_user_id'])) {
                    // Get channel_id
                    $stmt = $pdo->prepare("SELECT id FROM customer_services WHERE user_id = ? AND platform = ? LIMIT 1");
                    $stmt->execute([$user_id, $customer['platform']]);
                    $channelRow = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($channelRow) {
                        require_once __DIR__ . '/../../includes/services/PushMessageService.php';
                        $pushService = new \App\Services\PushMessageService($pdo);

                        $result = $pushService->send(
                            $customer['platform'],
                            $customer['platform_user_id'],
                            $customerMessage,
                            (int) $channelRow['id']
                        );

                        $customMessageSent = $result['success'] ?? false;
                    }
                }
            } catch (Exception $pushEx) {
                error_log("Custom push message error for order {$orderNumber}: " . $pushEx->getMessage());
            }
        }

        // Return success (with order_no alias for frontend compatibility)
        echo json_encode([
            'success' => true,
            'message' => ($messageSent || $customMessageSent) ? 'สร้างคำสั่งซื้อและส่งข้อความแจ้งลูกค้าแล้ว' : 'สร้างคำสั่งซื้อเรียบร้อย',
            'data' => [
                'id' => $orderId,
                'order_number' => $orderNumber,
                'order_no' => $orderNumber,
                'message_sent' => $messageSent || $customMessageSent,
                'auto_notification' => $messageSent
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Create order error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถสร้างคำสั่งซื้อได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Update existing order
 */
function updateOrder($pdo, $user_id, $tenant_id)
{
    // Get order ID from POST or query param
    $order_id = $_POST['id'] ?? $_GET['id'] ?? null;

    if (!$order_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุ ID คำสั่งซื้อ']);
        return;
    }

    $order_id = (int) $order_id;

    // ✅ Dynamic schema detection - check which columns exist
    $columns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM orders");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[$row['Field']] = true;
    }

    $hasTenantId = isset($columns['tenant_id']);
    $hasUserId = isset($columns['user_id']);

    // Check if order exists and belongs to this user/tenant
    if ($hasTenantId && $hasUserId) {
        // Both exist - use user_id as primary (more reliable)
        $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE id = ? AND user_id = ?");
        $stmt->execute([$order_id, $user_id]);
    } elseif ($hasUserId) {
        $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE id = ? AND user_id = ?");
        $stmt->execute([$order_id, $user_id]);
    } elseif ($hasTenantId) {
        $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$order_id, $tenant_id]);
    } else {
        // Fallback - just check order exists
        $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบคำสั่งซื้อนี้']);
        return;
    }

    // Get update fields from POST
    $updates = [];
    $params = [];

    // Allowed fields to update
    $allowedFields = [
        'product_name' => 'string',
        'product_code' => 'string',
        'quantity' => 'int',
        'unit_price' => 'decimal',
        'total_amount' => 'decimal',
        'status' => 'string',
        'customer_name' => 'string',
        'customer_phone' => 'string',
        'customer_email' => 'string',
        'shipping_name' => 'string',
        'shipping_phone' => 'string',
        'shipping_address' => 'string',
        'tracking_number' => 'string',
        'notes' => 'string',
        'note' => 'string'
    ];

    // Valid status values
    $validStatuses = ['draft', 'pending', 'pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];

    foreach ($allowedFields as $field => $type) {
        if (isset($_POST[$field])) {
            // Validate status
            if ($field === 'status' && !in_array($_POST[$field], $validStatuses)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'สถานะไม่ถูกต้อง']);
                return;
            }

            $value = $_POST[$field];

            switch ($type) {
                case 'int':
                    $value = (int) $value;
                    break;
                case 'decimal':
                    $value = (float) $value;
                    break;
                case 'string':
                default:
                    $value = trim($value);
                    if ($value === '')
                        $value = null;
                    break;
            }

            $updates[] = "{$field} = ?";
            $params[] = $value;
        }
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ไม่มีข้อมูลที่ต้องการแก้ไข']);
        return;
    }

    // Add updated_at
    $updates[] = "updated_at = NOW()";
    $params[] = $order_id;

    try {
        $sql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'message' => 'บันทึกการแก้ไขเรียบร้อย',
            'data' => ['id' => $order_id]
        ]);

    } catch (Exception $e) {
        error_log("Update order error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถแก้ไขคำสั่งซื้อได้: ' . $e->getMessage()
        ]);
    }
}
