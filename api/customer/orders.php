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
 * Installment info is in installment_contracts + installment_payments tables
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

    // ✅ FIX: Use shop_owner_id for data isolation instead of tenant_id
    // shop_owner_id = logged-in user_id (each shop owner only sees their own orders)

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
        'shipping_address_id',
        'platform_user_id',
        'platform',
        'shipping_method',
        'deposit_amount',
        'deposit_expiry'
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

            // ✅ FIX: Use shop_owner_id for data isolation
            $hasShopOwnerId = isset($columns['shop_owner_id']);

            if ($hasShopOwnerId) {
                $stmt = $pdo->prepare("
                    SELECT 
                        {$selectClause}
                    FROM orders o
                    WHERE o.id = ? AND o.shop_owner_id = ?
                ");
                $stmt->execute([$order_id, $user_id]);
            } else {
                // Fallback to user_id column
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
                    $optionalItemCols = ['product_ref_id', 'product_name', 'product_code', 'quantity', 'unit_price', 'discount', 'total', 'subtotal', 'product_metadata', 'product_image'];
                    foreach ($optionalItemCols as $col) {
                        if (isset($itemCols[$col])) {
                            $selectItemCols[] = $col;
                        }
                    }

                    $stmt = $pdo->prepare("SELECT " . implode(', ', $selectItemCols) . " FROM order_items WHERE order_id = ?");
                    $stmt->execute([$order_id]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Extract image_url from product_metadata OR product_image column
                    foreach ($items as &$item) {
                        // Priority 1: product_image column (direct URL)
                        if (!empty($item['product_image'])) {
                            $item['product_image_url'] = $item['product_image'];
                        }
                        // Priority 2: product_metadata JSON field
                        elseif (!empty($item['product_metadata'])) {
                            $metadata = json_decode($item['product_metadata'], true);
                            if ($metadata) {
                                $item['product_image_url'] = $metadata['image_url'] ?? null;
                            }
                        }
                    }
                    unset($item);
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
            // Get installment info if order_type is installment
            // ค้นหาได้ทั้ง installment_id หรือ order_id (fallback ถ้า link ขาด)
            $order['installment_months'] = 0;
            $order['installment_schedule'] = null;
            $order['installment_contract_id'] = null;
            
            if ($order['order_type'] === 'installment') {
                // Try new table first (installment_contracts)
                // ค้นหาทั้ง installment_id และ order_id เผื่อ link ขาด
                
                // ✅ Backward compatible: check if interest columns exist
                $hasInterestCols = false;
                try {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM installment_contracts LIKE 'interest_rate'");
                    $hasInterestCols = $colCheck->rowCount() > 0;
                } catch (Exception $e) {}
                
                // Build SELECT with or without interest columns
                $selectCols = "id, total_periods as total_terms, amount_per_period as amount_per_term, 
                           paid_periods as paid_terms, status as installment_status, next_due_date,
                           financed_amount, product_price, paid_amount as contract_paid_amount";
                if ($hasInterestCols) {
                    $selectCols .= ", interest_rate, total_interest";
                }
                
                $stmt = $pdo->prepare("
                    SELECT {$selectCols}
                    FROM installment_contracts
                    WHERE id = ? OR order_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$order['installment_id'] ?? 0, $order['id']]);
                $inst = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // ✅ If interest columns don't exist, calculate from amounts
                if ($inst && !$hasInterestCols) {
                    $productPrice = (float)($inst['product_price'] ?? 0);
                    $financedAmount = (float)($inst['financed_amount'] ?? 0);
                    $inst['total_interest'] = $financedAmount - $productPrice;
                    $inst['interest_rate'] = $productPrice > 0 ? round(($inst['total_interest'] / $productPrice) * 100, 2) : 3;
                }
                
                if ($inst) {
                    $contractId = $inst['id'];
                    $order['installment_contract_id'] = $contractId;
                    $order['installment_months'] = (int) $inst['total_terms'];
                    $order['installment_info'] = $inst;

                    // Self-healing: ถ้า orders.installment_id ไม่มี แต่หา contract ได้ → update ให้ถูกต้อง
                    if (empty($order['installment_id']) && $contractId) {
                        try {
                            $stmtUpdate = $pdo->prepare("UPDATE orders SET installment_id = ? WHERE id = ?");
                            $stmtUpdate->execute([$contractId, $order['id']]);
                            $order['installment_id'] = $contractId; // Update local copy too
                            error_log("[SELF_HEAL] Fixed orders.installment_id for order #{$order['id']} -> contract #{$contractId}");
                        } catch (Exception $e) {
                            // Ignore update errors
                        }
                    }

                    // Get installment payments from new table
                    // Note: installment_payments doesn't have paid_amount column
                    // paid_amount = amount when status = 'paid', otherwise 0
                    $stmt = $pdo->prepare("
                        SELECT id, period_number, due_date, amount, 
                               CASE WHEN status = 'paid' THEN amount ELSE 0 END as paid_amount, 
                               status
                        FROM installment_payments
                        WHERE contract_id = ?
                        ORDER BY period_number ASC
                    ");
                    $stmt->execute([$contractId]);
                    $order['installment_schedule'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
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
            
            // =========================================================================
            // ✅ Real-time paid_amount calculation from verified payments
            // If stored paid_amount is 0 but there are verified payments, calculate it
            // =========================================================================
            $storedPaid = (float) ($order['paid_amount'] ?? 0);
            $calculatedPaid = 0;
            $pendingPaid = 0;
            
            foreach ($order['payments'] as $p) {
                $amt = (float) ($p['amount'] ?? 0);
                if ($p['status'] === 'verified') {
                    $calculatedPaid += $amt;
                } else {
                    $pendingPaid += $amt;
                }
            }
            
            // Use the higher of stored or calculated (in case of sync issues)
            $order['paid_amount'] = max($storedPaid, $calculatedPaid);
            $order['paid_amount_verified'] = $calculatedPaid;
            $order['paid_amount_pending'] = $pendingPaid;
            
            // Calculate remaining and payment progress
            $totalAmount = (float) ($order['total_amount'] ?? 0);
            $order['remaining_amount'] = max(0, $totalAmount - $order['paid_amount']);
            $order['payment_progress'] = $totalAmount > 0 
                ? round(($order['paid_amount'] / $totalAmount) * 100, 1) 
                : 0;
            
            // Auto-fix: Update stored paid_amount if calculation is higher
            if ($calculatedPaid > $storedPaid && $calculatedPaid > 0) {
                try {
                    $stmtFix = $pdo->prepare("UPDATE orders SET paid_amount = ? WHERE id = ?");
                    $stmtFix->execute([$calculatedPaid, $order_id]);
                    error_log("[SELF_HEAL] Updated orders.paid_amount for #{$order_id}: {$storedPaid} -> {$calculatedPaid}");
                } catch (Exception $e) {
                    // Ignore update errors
                }
            }

            // ✅ Get customer profile if platform_user_id exists
            if (!empty($order['platform_user_id'])) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT 
                            id, display_name, full_name, phone, email,
                            COALESCE(profile_pic_url, avatar_url) as avatar_url,
                            platform, platform_user_id
                        FROM customer_profiles
                        WHERE platform_user_id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$order['platform_user_id']]);
                    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($profile) {
                        $order['customer_profile'] = $profile;
                        // Also populate top-level fields for convenience
                        if (empty($order['customer_name'])) {
                            $order['customer_name'] = $profile['display_name'] ?? $profile['full_name'] ?? '';
                        }
                        if (empty($order['customer_avatar'])) {
                            $order['customer_avatar'] = $profile['avatar_url'] ?? '';
                        }
                    }
                } catch (Exception $e) {
                    // customer_profiles table might not exist
                }
            }

            // ✅ Get shipping address if shipping_address_id exists
            if (!empty($order['shipping_address_id'])) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT 
                            id, recipient_name, phone, address_line1, address_line2,
                            subdistrict, district, province, postal_code, country,
                            address_type, is_default, additional_info
                        FROM customer_addresses
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$order['shipping_address_id']]);
                    $address = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($address) {
                        $order['shipping_address_detail'] = $address;
                        // Build full address string if not already set
                        if (empty($order['shipping_address'])) {
                            $addressParts = array_filter([
                                $address['address_line1'],
                                $address['address_line2'],
                                $address['subdistrict'],
                                $address['district'],
                                $address['province'],
                                $address['postal_code']
                            ]);
                            $order['shipping_address'] = implode(', ', $addressParts);
                        }
                        // Populate shipping contact if not set
                        if (empty($order['shipping_name'])) {
                            $order['shipping_name'] = $address['recipient_name'];
                        }
                        if (empty($order['shipping_phone'])) {
                            $order['shipping_phone'] = $address['phone'];
                        }
                    }
                } catch (Exception $e) {
                    // customer_addresses table might not exist
                }
            }

            // ✅ Extract product_image_url from first item for top-level access
            if (!empty($items) && !empty($items[0]['product_image_url'])) {
                $order['product_image_url'] = $items[0]['product_image_url'];
            }
            
            // ✅ Fallback: If no image from order_items, try to get from products table
            if (empty($order['product_image_url'])) {
                try {
                    $productCode = $order['product_code'] ?? null;
                    $productRefId = $order['product_ref_id'] ?? null;
                    
                    if ($productCode || $productRefId) {
                        $imgStmt = $pdo->prepare("
                            SELECT image_url 
                            FROM products 
                            WHERE (product_code = ? OR id = ?) 
                            AND image_url IS NOT NULL AND image_url != ''
                            LIMIT 1
                        ");
                        $imgStmt->execute([$productCode, $productRefId]);
                        $productRow = $imgStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($productRow && !empty($productRow['image_url'])) {
                            $order['product_image_url'] = $productRow['image_url'];
                        }
                    }
                } catch (Exception $e) {
                    // products table might not have image_url column
                }
            }
            
            // ✅ Fallback 2: Try to get image from linked Case
            // First check case_id field, then try to parse from note
            $caseIdForImage = $order['case_id'] ?? null;
            
            // Parse case_id from note if not set (e.g., "จากเคส #67")
            if (empty($caseIdForImage) && !empty($order['note'])) {
                if (preg_match('/(?:เคส|case)\s*#?(\d+)/i', $order['note'], $matches)) {
                    $caseIdForImage = (int)$matches[1];
                }
            }
            if (empty($caseIdForImage) && !empty($order['notes'])) {
                if (preg_match('/(?:เคส|case)\s*#?(\d+)/i', $order['notes'], $matches)) {
                    $caseIdForImage = (int)$matches[1];
                }
            }
            
            if (empty($order['product_image_url']) && !empty($caseIdForImage)) {
                try {
                    // ✅ FIX: product_image_url is stored in `slots` JSON column, not as a direct column
                    // slots = {"product_image_url": "https://...", ...}
                    $caseStmt = $pdo->prepare("
                        SELECT slots 
                        FROM cases 
                        WHERE id = ? 
                        AND slots IS NOT NULL
                        LIMIT 1
                    ");
                    $caseStmt->execute([$caseIdForImage]);
                    $caseRow = $caseStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($caseRow && !empty($caseRow['slots'])) {
                        $slots = json_decode($caseRow['slots'], true);
                        if (!empty($slots['product_image_url'])) {
                            $order['product_image_url'] = $slots['product_image_url'];
                        }
                    }
                } catch (Exception $e) {
                    // cases table might not exist or query failed
                }
            }

            echo json_encode(['success' => true, 'data' => $order]);

        } else {
            // GET /api/customer/orders - List all orders
            $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 20;
            $offset = ($page - 1) * $limit;

            $status = isset($_GET['status']) ? $_GET['status'] : null;
            $order_type = isset($_GET['order_type']) ? $_GET['order_type'] : null;

            // ✅ FIX: Use shop_owner_id for data isolation
            $hasShopOwnerId = isset($columns['shop_owner_id']);

            if ($hasShopOwnerId) {
                $where = ["o.shop_owner_id = ?"];
                $params = [$user_id];
            } else {
                // Fallback to user_id/customer_id column
                $where = ["o.{$userColumn} = ?"];
                $params = [$user_id];
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

            // Get orders - JOIN with customer_profiles for name/avatar
            // Note: Join via platform_user_id + platform (since orders stores these columns)
            $customerJoinCols = ",
                    COALESCE(cp.display_name, cp.full_name, CONCAT('ลูกค้า #', o.id)) as customer_display_name,
                    COALESCE(cp.profile_pic_url, cp.avatar_url) as customer_avatar_url,
                    cp.platform as cp_platform";

            $stmt = $pdo->prepare("
                SELECT 
                    {$selectClause}
                    {$productSubqueries}
                    {$customerJoinCols}
                FROM orders o
                LEFT JOIN customer_profiles cp ON o.platform_user_id = cp.platform_user_id 
                    AND o.platform = cp.platform
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
                    // Try new table first (installment_contracts)
                    // ✅ Fetch financed_amount and paid_amount for correct progress display
                    $stmt2 = $pdo->prepare("
                        SELECT total_periods as total_terms, 
                               financed_amount, 
                               paid_amount as contract_paid_amount,
                               product_price,
                               paid_periods
                        FROM installment_contracts 
                        WHERE id = ? OR order_id = ?
                    ");
                    $stmt2->execute([$order['installment_id'], $order['id']]);
                    $inst = $stmt2->fetch(PDO::FETCH_ASSOC);
                    if ($inst) {
                        $order['installment_months'] = (int) $inst['total_terms'];
                        // ✅ Add installment amounts for correct progress calculation
                        $order['financed_amount'] = (float) ($inst['financed_amount'] ?? 0);
                        $order['contract_paid_amount'] = (float) ($inst['contract_paid_amount'] ?? 0);
                        $order['product_price'] = (float) ($inst['product_price'] ?? 0);
                        $order['paid_periods'] = (int) ($inst['paid_periods'] ?? 0);
                        // Calculate service fee
                        $order['service_fee'] = $order['financed_amount'] - $order['product_price'];
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
            // ✅ FIX: Use user_id as shop_owner_id (no separate tenant_id)
            updateOrder($pdo, $user_id, $user_id);
        } else {
            // POST /api/customer/orders - Create new order
            createOrder($pdo, $user_id, $userColumn);
        }

    } elseif ($method === 'PUT' || $method === 'PATCH') {
        // PUT/PATCH /api/customer/orders/{id} - Update existing order
        updateOrder($pdo, $user_id, $user_id);

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

    // Get order type early to validate installment fields
    $orderType = $input['order_type'] ?? ($input['payment_type'] ?? 'full_payment');

    // Installment: No down payment required - just split into 3 periods + 3% fee
    $downPayment = floatval($input['down_payment'] ?? 0);

    // Generate order number
    $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    // Prepare data
    $productCode = trim($input['product_code'] ?? '');
    $productImage = trim($input['product_image'] ?? '');
    // $orderType already set above for validation
    $customerName = trim($input['customer_name'] ?? '');
    $customerPhone = trim($input['customer_phone'] ?? '');
    $note = trim($input['note'] ?? ($input['notes'] ?? ''));

    // ✅ Platform & Customer identification for joining with customer_profiles
    // Priority: 1) Query from cases table if from_case provided, 2) Input params
    $platformUserIdInput = '';
    $platformInput = '';

    // Debug: log raw input for from_case
    error_log("[CREATE_ORDER] DEBUG raw input: from_case=" . json_encode($input['from_case'] ?? 'NOT_SET') .
        ", external_user_id=" . json_encode($input['external_user_id'] ?? 'NOT_SET') .
        ", source=" . json_encode($input['source'] ?? 'NOT_SET') .
        ", send_message=" . json_encode($input['send_message'] ?? 'NOT_SET') .
        ", customer_message_len=" . strlen($input['customer_message'] ?? ''));

    $fromCaseId = intval($input['from_case'] ?? 0);

    if ($fromCaseId > 0) {
        // Query external_user_id and platform from cases table (source of truth)
        $stmtCase = $pdo->prepare("SELECT external_user_id, platform, customer_platform FROM cases WHERE id = ?");
        $stmtCase->execute([$fromCaseId]);
        $caseData = $stmtCase->fetch(PDO::FETCH_ASSOC);

        if ($caseData) {
            $platformUserIdInput = trim($caseData['external_user_id'] ?? '');
            $platformInput = trim($caseData['platform'] ?? $caseData['customer_platform'] ?? '');
            error_log("[CREATE_ORDER] Got from cases table (case_id={$fromCaseId}): external_user_id={$platformUserIdInput}, platform={$platformInput}");
        }
    }

    // Fallback to input params if not found from cases
    if (empty($platformUserIdInput)) {
        $platformUserIdInput = trim($input['external_user_id'] ?? ($input['platform_user_id'] ?? ''));
    }
    if (empty($platformInput)) {
        $platformInput = trim($input['source'] ?? ($input['platform'] ?? 'chatbot'));
    }

    error_log("[CREATE_ORDER] Final values: platformUserIdInput={$platformUserIdInput}, platformInput={$platformInput}, from_case={$fromCaseId}");

    // ✅ Lookup customer_profile_id from platform_user_id for order matching
    $customerProfileId = null;
    if (!empty($platformUserIdInput)) {
        $stmtProfile = $pdo->prepare("SELECT id FROM customer_profiles WHERE platform_user_id = ? LIMIT 1");
        $stmtProfile->execute([$platformUserIdInput]);
        $profileRow = $stmtProfile->fetch(PDO::FETCH_ASSOC);
        if ($profileRow) {
            $customerProfileId = (int) $profileRow['id'];
            error_log("[CREATE_ORDER] Found customer_profile_id={$customerProfileId} for platform_user_id={$platformUserIdInput}");
        } else {
            error_log("[CREATE_ORDER] No customer_profile found for platform_user_id={$platformUserIdInput}");
        }
    }

    // Map source to platform enum values
    if (in_array($platformInput, ['facebook', 'line', 'web', 'instagram', 'manual'])) {
        $platformForDb = $platformInput;
    } else {
        $platformForDb = 'web'; // default for chatbot/web orders
    }

    // Calculate unit price - ensure it's never 0 or invalid
    $unitPrice = ($quantity > 0) ? ($totalAmount / $quantity) : $totalAmount;
    if ($unitPrice <= 0) {
        $unitPrice = $totalAmount; // fallback
    }

    // Deposit-specific fields
    $depositAmount = floatval($input['deposit_amount'] ?? 0);
    $depositExpiry = $input['deposit_expiry'] ?? null;

    // Bank account for push message
    $bankAccountKey = trim($input['bank_account'] ?? '');
    
    // ✅ Map bank account key to actual bank details
    $bankAccountMap = [
        'scb_1' => "ธนาคารไทยพาณิชย์\nชื่อบัญชี: บจก เพชรวิบวับ\nเลขบัญชี: 165-301-4242",
        'kbank_1' => "ธนาคารกสิกรไทย\nชื่อบัญชี: บจก.เฮงเฮงโฮลดิ้ง\nเลขบัญชี: 800-002-9282",
        'bay_1' => "ธนาคารกรุงศรี\nชื่อบัญชี: บจก.เฮงเฮงโฮลดิ้ง\nเลขบัญชี: 800-002-9282",
    ];
    $bankAccount = $bankAccountMap[$bankAccountKey] ?? $bankAccountKey;

    // Installment-specific fields  
    // $downPayment already set above for validation
    $installmentMonths = intval($input['installment_months'] ?? 3);

    // Shipping fields
    $shippingMethod = trim($input['shipping_method'] ?? 'pickup');
    $shippingAddress = trim($input['shipping_address'] ?? '');
    $shippingAddressId = intval($input['shipping_address_id'] ?? 0);
    $shippingFee = floatval($input['shipping_fee'] ?? 0);
    $trackingNumber = trim($input['tracking_number'] ?? '');

    // Determine if we have order_type (new) or payment_type (old)
    $hasOrderTypeCol = isset($columns['order_type']);

    // ✅ Store original order type for logic (deposit handling, push messages, etc.)
    $originalOrderType = $orderType;

    // ✅ Check what values are allowed in payment_type ENUM
    // Production may only have ('full','installment') - we need to handle deposit/savings gracefully
    $allowedPaymentTypes = ['full', 'installment']; // Default for old schema
    try {
        $enumStmt = $pdo->query("SHOW COLUMNS FROM orders WHERE Field = 'payment_type'");
        $enumInfo = $enumStmt->fetch(PDO::FETCH_ASSOC);
        if ($enumInfo && preg_match_all("/'([^']+)'/", $enumInfo['Type'], $matches)) {
            $allowedPaymentTypes = $matches[1];
        }
    } catch (Exception $e) {
        // Use default
    }

    // Map payment_type values based on which column exists
    if ($hasOrderTypeCol) {
        // Has order_type column - use new values
        if ($orderType === 'full')
            $orderType = 'full_payment';
        if ($orderType === 'savings')
            $orderType = 'savings_completion';
        // Keep deposit and installment as-is (assuming ENUM was extended)
        // If ENUM doesn't support deposit, fallback to full_payment
        if ($originalOrderType === 'deposit' && !in_array('deposit', $allowedPaymentTypes)) {
            $orderType = 'full_payment';
            error_log("[CREATE_ORDER] order_type ENUM doesn't support 'deposit', using 'full_payment'. Original: {$originalOrderType}");
        }
    } else {
        // Has payment_type column - use old values  
        if ($orderType === 'full_payment')
            $orderType = 'full';
        if ($orderType === 'savings_completion')
            $orderType = 'full';
        
        // ✅ FIX: If ENUM doesn't support deposit/savings, use 'full' for DB but keep original for logic
        if (!in_array($orderType, $allowedPaymentTypes)) {
            error_log("[CREATE_ORDER] payment_type '{$orderType}' not in ENUM, using 'full' for DB. Original: {$originalOrderType}");
            $orderType = 'full';
        }
    }

    // Determine initial status based on order type
    // IMPORTANT: orders.status ENUM on production only has:
    // 'pending', 'processing', 'shipped', 'delivered', 'cancelled', 'paid'
    // Use order_type to distinguish deposit/installment, not status
    $initialStatus = 'pending';
    $paidAmount = 0;
    $paymentStatus = 'unpaid';

    // ✅ Use originalOrderType for logic (deposit/installment handling)
    // because $orderType may have been mapped to 'full' for old ENUM compatibility
    if ($originalOrderType === 'deposit') {
        // Deposit order: partially paid, waiting for balance
        // ✅ Now ENUM supports 'deposit' - use it directly
        $orderType = 'deposit';
        $initialStatus = 'pending'; // Use 'pending' (production ENUM compatible)
        $paidAmount = 0; // Will be updated when payment is approved
        $paymentStatus = 'unpaid';
        // Note: deposit_amount and deposit_expiry are now saved in their own columns
    } elseif ($originalOrderType === 'installment') {
        // Installment order: down payment received, waiting for installments
        // Status stays 'pending', order_type = 'installment' distinguishes it
        $initialStatus = 'pending';
        $paidAmount = $downPayment;
        $paymentStatus = $downPayment > 0 ? 'partial' : 'unpaid';
    }

    try {
        $pdo->beginTransaction();

        if ($hasOrderItems) {
            // Has order_items table - insert into both tables
            // IMPORTANT: unit_price is required (no default value on production)
            $insertCols = [
                $orderNoCol,
                $userColumn,
                $typeCol,
                'quantity',
                'unit_price',
                'total_amount',
                'status'
            ];
            $insertVals = ['?', '?', '?', '?', '?', '?', '?'];
            $insertParams = [$orderNumber, $user_id, $orderType, $quantity, $unitPrice, $totalAmount, $initialStatus];

            // ✅ Add shop_owner_id for data isolation (logged-in user = shop owner)
            if (isset($columns['shop_owner_id'])) {
                $insertCols[] = 'shop_owner_id';
                $insertVals[] = '?';
                $insertParams[] = $user_id;
            }

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

            // ✅ Add customer_profile_id for payment auto-matching
            if (isset($columns['customer_profile_id']) && $customerProfileId > 0) {
                $insertCols[] = 'customer_profile_id';
                $insertVals[] = '?';
                $insertParams[] = $customerProfileId;
                error_log("[CREATE_ORDER] Adding customer_profile_id={$customerProfileId} to INSERT");
            }

            // ✅ Add platform_user_id and platform for joining with customer_profiles
            if (isset($columns['platform_user_id']) && !empty($platformUserIdInput)) {
                $insertCols[] = 'platform_user_id';
                $insertVals[] = '?';
                $insertParams[] = $platformUserIdInput;
            }
            if (isset($columns['platform']) && !empty($platformForDb)) {
                $insertCols[] = 'platform';
                $insertVals[] = '?';
                $insertParams[] = $platformForDb;
            }
            // Also add source column if it exists (older schema)
            if (isset($columns['source'])) {
                $insertCols[] = 'source';
                $insertVals[] = '?';
                $insertParams[] = $platformInput ?: 'chatbot';
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
            if (isset($columns['installment_months']) && $originalOrderType === 'installment') {
                $insertCols[] = 'installment_months';
                $insertVals[] = '?';
                $insertParams[] = $installmentMonths;
            }
            if (isset($columns['down_payment']) && $originalOrderType === 'installment') {
                $insertCols[] = 'down_payment';
                $insertVals[] = '?';
                $insertParams[] = $downPayment;
            }
            // Deposit-specific columns
            if (isset($columns['deposit_amount']) && $originalOrderType === 'deposit') {
                $insertCols[] = 'deposit_amount';
                $insertVals[] = '?';
                $insertParams[] = $depositAmount;
            }
            if (isset($columns['deposit_expiry']) && $originalOrderType === 'deposit' && $depositExpiry) {
                $insertCols[] = 'deposit_expiry';
                $insertVals[] = '?';
                $insertParams[] = $depositExpiry;
            }
            // Shipping-specific columns
            if (isset($columns['shipping_method'])) {
                $insertCols[] = 'shipping_method';
                $insertVals[] = '?';
                $insertParams[] = $shippingMethod;
            }
            if (isset($columns['shipping_address_id']) && $shippingAddressId > 0) {
                $insertCols[] = 'shipping_address_id';
                $insertVals[] = '?';
                $insertParams[] = $shippingAddressId;
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

            // Insert order item with product image
            $productMetadata = json_encode([
                'image_url' => $productImage ?: null,
                'product_code' => $productCode ?: null,
                'original_name' => $productName,
                'captured_at' => date('Y-m-d H:i:s')
            ]);

            // Check which columns exist for product image
            $itemColCheck = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'product_image'");
            $hasImageCol = $itemColCheck->rowCount() > 0;
            
            $metaColCheck = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'product_metadata'");
            $hasMetadataCol = $metaColCheck->rowCount() > 0;

            if ($hasImageCol) {
                // ✅ Use product_image column directly (production schema)
                $stmt = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, product_name, product_code, quantity, unit_price, discount, total, product_image, created_at
                    ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, NOW())
                ");
                $stmt->execute([
                    $orderId,
                    $productName,
                    $productCode ?: null,
                    $quantity,
                    $unitPrice,
                    $totalAmount,
                    $productImage ?: null
                ]);
            } elseif ($hasMetadataCol) {
                $stmt = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, product_name, product_code, quantity, unit_price, discount, total, product_metadata, created_at
                    ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, NOW())
                ");
                $stmt->execute([
                    $orderId,
                    $productName,
                    $productCode ?: null,
                    $quantity,
                    $unitPrice,
                    $totalAmount,
                    $productMetadata
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, product_name, product_code, quantity, unit_price, discount, total, created_at
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
            }
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

            // ✅ Add platform_user_id and platform for joining with customer_profiles
            if (isset($columns['platform_user_id']) && !empty($platformUserIdInput)) {
                $insertCols[] = 'platform_user_id';
                $insertVals[] = '?';
                $insertParams[] = $platformUserIdInput;
            }
            if (isset($columns['platform']) && !empty($platformForDb)) {
                $insertCols[] = 'platform';
                $insertVals[] = '?';
                $insertParams[] = $platformForDb;
            }
            // Also add source column if it exists (older schema)
            if (isset($columns['source'])) {
                $insertCols[] = 'source';
                $insertVals[] = '?';
                $insertParams[] = $platformInput ?: 'chatbot';
            }

            $sql = "INSERT INTO orders (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($insertParams);

            $orderId = $pdo->lastInsertId();
        }

        // =========================================================================
        // Create Installment Contract (if installment order)
        // ใช้ installment_contracts table (ใหม่) แทน installments (เก่า)
        // Spec: 3 งวด ภายใน 60 วัน, ค่าธรรมเนียม 3% ตลอดสัญญา
        // 
        // ตัวอย่าง 10,000 บาท:
        // - งวด 1 (Day 0): floor(10000/3) + 300 = 3,633 บาท
        // - งวด 2 (Day 30): floor(10000/3) = 3,333 บาท
        // - งวด 3 (Day 60): 3,334 บาท (เศษ + รับของ)
        // =========================================================================
        $contractId = null;
        if ($originalOrderType === 'installment' && $orderId) {
            try {
                // Check if installment_contracts table exists
                $hasContracts = false;
                try {
                    $stmtCheck = $pdo->query("SHOW TABLES LIKE 'installment_contracts'");
                    $hasContracts = $stmtCheck->rowCount() > 0;
                } catch (Exception $e) {
                }

                if ($hasContracts) {
                    // ไม่มีดาวน์ - ผ่อนเต็มจำนวน
                    // Service fee: 3% รวมในงวดแรก
                    $serviceFeeRate = 0.03;
                    $serviceFee = round($totalAmount * $serviceFeeRate);
                    $totalPeriods = 3;

                    // Calculate installment amounts:
                    // ตัวอย่าง 10,000 บาท:
                    // งวด 1 = floor(10000/3) + 3% = 3,333 + 300 = 3,633 บาท
                    // งวด 2 = floor(10000/3) = 3,333 บาท
                    // งวด 3 = floor(10000/3) + เศษ = 3,333 + 1 = 3,334 บาท
                    $basePerPeriod = floor($totalAmount / $totalPeriods);
                    $remainder = $totalAmount - ($basePerPeriod * $totalPeriods);

                    $p1 = $basePerPeriod + $serviceFee;  // Period 1: includes service fee
                    $p2 = $basePerPeriod;                 // Period 2: base amount
                    $p3 = $basePerPeriod + $remainder;    // Period 3: base + remainder

                    $grandTotal = $totalAmount + $serviceFee;
                    $avgPerPeriod = $grandTotal / $totalPeriods;

                    // Generate contract number
                    $contractNo = 'INS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

                    // Get customer info for the contract
                    $customerNameForContract = $customerName ?: '';
                    $customerPhoneForContract = $customerPhone ?: '';

                    // Due dates: Day 0, 30, 60
                    $startDate = date('Y-m-d');
                    $nextDueDate = date('Y-m-d'); // งวดแรก = วันนี้

                    // ✅ FIX: channel_id is NOT NULL in DB, must have a value
                    // Get channelId - already set from case lookup or fallback above
                    $contractChannelId = $channelId ?? null;
                    if (!$contractChannelId) {
                        // Fallback: get first active channel for this platform
                        $stmtCh = $pdo->prepare("SELECT id FROM customer_channels WHERE status = 'active' ORDER BY id ASC LIMIT 1");
                        $stmtCh->execute();
                        $chRow = $stmtCh->fetch(PDO::FETCH_ASSOC);
                        $contractChannelId = $chRow ? (int)$chRow['id'] : 1; // Ultimate fallback to 1
                    }

                    // Insert into installment_contracts
                    // down_payment = 0 (ไม่มีดาวน์), financed_amount = grandTotal (ยอดผ่อน + fee)
                    // ✅ FIX: Match columns with actual table schema from dump
                    // Schema has: contract_no, customer_id, order_id, tenant_id, product_ref_id, product_name, product_price,
                    //             customer_name, customer_phone, customer_avatar, platform, down_payment, financed_amount,
                    //             total_periods, paid_periods, amount_per_period, paid_amount, status, start_date, next_due_date,
                    //             last_payment_date, completed_at, admin_notes, created_at, updated_at, 
                    //             platform_user_id, channel_id, external_user_id
                    
                    // Check which columns exist
                    $contractCols = [];
                    $colStmt = $pdo->query("SHOW COLUMNS FROM installment_contracts");
                    while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                        $contractCols[$col['Field']] = true;
                    }
                    
                    // Build dynamic INSERT based on available columns
                    $insertCols = ['contract_no', 'tenant_id', 'customer_id', 'order_id', 'product_name', 'product_price', 
                                   'down_payment', 'financed_amount', 'total_periods', 'amount_per_period', 
                                   'paid_periods', 'paid_amount', 'status', 'start_date', 'next_due_date', 'created_at', 'updated_at'];
                    $insertVals = ['?', "'default'", '?', '?', '?', '?', 
                                   '0', '?', '?', '?', 
                                   '0', '0', "'active'", '?', '?', 'NOW()', 'NOW()'];
                    $insertParams = [
                        $contractNo,
                        $user_id,  // customer_id = shop owner (for FK constraint)
                        $orderId,
                        $productName,
                        $totalAmount,
                        $grandTotal,
                        $totalPeriods,
                        $avgPerPeriod,
                        $startDate,
                        $nextDueDate
                    ];
                    
                    // Add optional columns if they exist
                    if (isset($contractCols['product_ref_id'])) {
                        $insertCols[] = 'product_ref_id';
                        $insertVals[] = '?';
                        $insertParams[] = $productCode ?: '';
                    }
                    if (isset($contractCols['customer_name'])) {
                        $insertCols[] = 'customer_name';
                        $insertVals[] = '?';
                        $insertParams[] = $customerNameForContract ?: '';
                    }
                    if (isset($contractCols['customer_phone'])) {
                        $insertCols[] = 'customer_phone';
                        $insertVals[] = '?';
                        $insertParams[] = $customerPhoneForContract ?: '';
                    }
                    if (isset($contractCols['platform'])) {
                        $insertCols[] = 'platform';
                        $insertVals[] = '?';
                        $insertParams[] = $platformForDb ?: 'web';
                    }
                    if (isset($contractCols['platform_user_id'])) {
                        $insertCols[] = 'platform_user_id';
                        $insertVals[] = '?';
                        $insertParams[] = $platformUserIdInput ?: '';
                    }
                    if (isset($contractCols['external_user_id'])) {
                        $insertCols[] = 'external_user_id';
                        $insertVals[] = '?';
                        $insertParams[] = $platformUserIdInput ?: '';
                    }
                    if (isset($contractCols['channel_id']) && $contractChannelId) {
                        $insertCols[] = 'channel_id';
                        $insertVals[] = '?';
                        $insertParams[] = $contractChannelId;
                    }
                    
                    // ✅ Add interest/fee info for audit trail
                    if (isset($contractCols['interest_rate'])) {
                        $insertCols[] = 'interest_rate';
                        $insertVals[] = '?';
                        $insertParams[] = $serviceFeeRate * 100; // 0.03 -> 3
                    }
                    if (isset($contractCols['interest_type'])) {
                        $insertCols[] = 'interest_type';
                        $insertVals[] = '?';
                        $insertParams[] = 'flat'; // one-time fee
                    }
                    if (isset($contractCols['total_interest'])) {
                        $insertCols[] = 'total_interest';
                        $insertVals[] = '?';
                        $insertParams[] = $serviceFee; // total fee amount
                    }
                    
                    $sql = "INSERT INTO installment_contracts (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
                    error_log("[CREATE_ORDER] Installment contract SQL: " . $sql);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($insertParams);

                    $contractId = $pdo->lastInsertId();

                    // Check if installment_payments table exists for payment schedule
                    $hasPayments = false;
                    try {
                        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'installment_payments'");
                        $hasPayments = $stmtCheck->rowCount() > 0;
                    } catch (Exception $e) {
                    }

                    if ($hasPayments) {
                        // Create 3 payment schedule records with correct amounts
                        // นโยบายร้าน ฮ.เฮง เฮง: งวด 1 = Day 0, งวด 2 = Day 30, งวด 3 = Day 60 (รับของ)
                        $dueDateDays = [1 => 0, 2 => 30, 3 => 60];
                        $payments = [
                            1 => $p1,  // Period 1: includes service fee
                            2 => $p2,  // Period 2: base amount
                            3 => $p3   // Period 3: base + remainder
                        ];

                        // Check if payment_no column exists in installment_payments
                        $hasPaymentNoCol = false;
                        try {
                            $colCheck = $pdo->query("SHOW COLUMNS FROM installment_payments LIKE 'payment_no'");
                            $hasPaymentNoCol = $colCheck->rowCount() > 0;
                        } catch (Exception $e) {}

                        // Generate payment records
                        foreach ($payments as $periodNum => $amount) {
                            $daysToAdd = $dueDateDays[$periodNum] ?? 0;
                            $dueDate = date('Y-m-d', strtotime("+{$daysToAdd} days"));

                            if ($hasPaymentNoCol) {
                                $paymentNo = $contractNo . '-P' . $periodNum;
                                $stmt = $pdo->prepare("
                                    INSERT INTO installment_payments (
                                        contract_id, payment_no, period_number, amount, due_date, status, created_at
                                    ) VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                                ");
                                $stmt->execute([$contractId, $paymentNo, $periodNum, $amount, $dueDate]);
                            } else {
                                // ✅ FIX: Schema doesn't have payment_no - use minimal columns
                                $stmt = $pdo->prepare("
                                    INSERT INTO installment_payments (
                                        contract_id, period_number, amount, due_date, status, created_at
                                    ) VALUES (?, ?, ?, ?, 'pending', NOW())
                                ");
                                $stmt->execute([$contractId, $periodNum, $amount, $dueDate]);
                            }
                        }

                        error_log("[CREATE_ORDER] Created {$totalPeriods} installment_payments for contract #{$contractId}");
                    }

                    // Update order with contract_id
                    if (isset($columns['installment_id'])) {
                        $stmt = $pdo->prepare("UPDATE orders SET installment_id = ? WHERE id = ?");
                        $stmt->execute([$contractId, $orderId]);
                    }

                    error_log("[CREATE_ORDER] Created installment_contract #{$contractId} ({$contractNo}) for order #{$orderId}");

                    // ✅ FIX: Store period amounts for push notification
                    $period1Total = $p1;  // Period 1 amount with service fee
                    $period2Amount = $p2; // Period 2 base amount
                    $period3Amount = $p3; // Period 3 with remainder
                }
            } catch (Exception $instEx) {
                error_log("Installment contract creation error for order {$orderNumber}: " . $instEx->getMessage());
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

        // Determine platform info - use platformUserIdInput from cases if available
        $pushPlatformUserId = $platformUserIdInput;
        $pushPlatform = $platformForDb;

        // Get channel_id for push message
        $channelId = null;
        if ($fromCaseId > 0) {
            // Get channel_id from the case
            $stmtChannel = $pdo->prepare("SELECT channel_id FROM cases WHERE id = ?");
            $stmtChannel->execute([$fromCaseId]);
            $caseChannel = $stmtChannel->fetch(PDO::FETCH_ASSOC);
            $channelId = $caseChannel['channel_id'] ?? null;
            error_log("[CREATE_ORDER] Got channel_id from case {$fromCaseId}: " . ($channelId ?? 'NULL'));
        }

        // Fallback: get first active channel for this platform if no channel from case
        if (!$channelId && !empty($pushPlatform)) {
            $stmt = $pdo->prepare("SELECT id FROM customer_channels WHERE type = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$pushPlatform]);
            $channel = $stmt->fetch(PDO::FETCH_ASSOC);
            $channelId = $channel ? (int) $channel['id'] : null;
            error_log("[CREATE_ORDER] Fallback channel lookup for {$pushPlatform}: " . ($channelId ?? 'NULL'));
        }

        // Debug: log push variables
        error_log("[CREATE_ORDER] PUSH DEBUG: pushPlatformUserId={$pushPlatformUserId}, pushPlatform={$pushPlatform}, channelId=" . ($channelId ?? 'NULL') . ", fromCaseId={$fromCaseId}");

        // ✅ Check if custom message is provided - skip auto push and use custom message instead
        $hasCustomMessage = !empty(trim($input['customer_message'] ?? '')) && !empty($input['send_message']);
        
        // Send push if we have platform_user_id AND no custom message provided
        if (!empty($pushPlatformUserId) && !empty($pushPlatform) && $channelId && !$hasCustomMessage) {
            error_log("[CREATE_ORDER] PUSH: Sending push notification to {$pushPlatform}:{$pushPlatformUserId} via channel {$channelId}");
            try {
                require_once __DIR__ . '/../../includes/services/PushNotificationService.php';
                require_once __DIR__ . '/../../includes/Database.php';
                $pushService = new PushNotificationService(Database::getInstance());

                // Build notification data based on order type
                $notificationData = [
                    'product_name' => $productName,
                    'total_amount' => number_format($totalAmount, 0),
                    'order_number' => $orderNumber,
                    'bank_account' => $bankAccount,
                ];

                // Add type-specific data
                if ($originalOrderType === 'installment' && isset($period1Total)) {
                    // Calculate due dates - นโยบาย ฮ.เฮง เฮง: Day 0, Day 30, Day 60 (รับของ)
                    $period1Due = date('d/m/Y'); // Day 0 (วันนี้)
                    $period2Due = date('d/m/Y', strtotime('+30 days')); // Day 30
                    $period3Due = date('d/m/Y', strtotime('+60 days')); // Day 60 -> รับของ

                    $notificationData['total_periods'] = 3;
                    $notificationData['period_1_amount'] = number_format($period1Total, 0);
                    $notificationData['period_1_due'] = $period1Due;
                    $notificationData['period_2_amount'] = number_format($period2Amount ?? 0, 0);
                    $notificationData['period_2_due'] = $period2Due;
                    $notificationData['period_3_amount'] = number_format($period3Amount ?? 0, 0);
                    $notificationData['period_3_due'] = $period3Due;
                } elseif (in_array($originalOrderType, ['savings', 'savings_completion'])) {
                    $notificationData['target_amount'] = number_format($totalAmount, 0);
                    $notificationData['current_balance'] = '0';
                } elseif ($originalOrderType === 'deposit') {
                    $notificationData['deposit_amount'] = number_format($depositAmount, 0);
                    // ✅ FIX: Always provide deposit_expiry (use default if not set)
                    $notificationData['deposit_expiry'] = $depositExpiry ?: date('d/m/Y', strtotime('+7 days'));
                }

                // ✅ DEBUG: Log notification data being sent
                error_log("[CREATE_ORDER] PUSH NOTIFICATION: orderType={$originalOrderType}, templateKey=order_created_{$originalOrderType}");
                error_log("[CREATE_ORDER] PUSH DATA: " . json_encode($notificationData));

                // Send notification using template
                // ✅ Use originalOrderType for push template selection
                $result = $pushService->sendOrderCreated(
                    $pushPlatform,
                    $pushPlatformUserId,
                    $originalOrderType,  // Use original type for correct template
                    $notificationData,
                    (int) $channelId
                );

                $messageSent = $result['success'] ?? false;

                if (!$messageSent) {
                    error_log("Auto push failed for order {$orderNumber}: " . ($result['error'] ?? 'Unknown error'));
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
        
        // ✅ Replace {{ORDER_NUMBER}} placeholder with actual order number
        if (!empty($customerMessage)) {
            $customerMessage = str_replace('{{ORDER_NUMBER}}', $orderNumber, $customerMessage);
        }

        // ✅ FIX: Send custom message if provided (removed !$messageSent condition - custom message takes priority)
        if ($sendMessage && !empty($customerMessage) && !empty($pushPlatformUserId) && $channelId) {
            try {
                require_once __DIR__ . '/../../includes/services/PushMessageService.php';
                $pushMsgService = new \App\Services\PushMessageService($pdo);

                $result = $pushMsgService->send(
                    $pushPlatform,
                    $pushPlatformUserId,
                    $customerMessage,
                    (int) $channelId
                );

                $customMessageSent = $result['success'] ?? false;

                if (!$customMessageSent) {
                    error_log("Custom push message failed for order {$orderNumber}: " . ($result['error'] ?? 'Unknown error'));
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
        // ✅ Only update fields that exist in the table
        if (!isset($columns[$field])) {
            continue;
        }
        
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
