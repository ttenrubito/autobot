<?php
/**
 * Customer Payments API
 * GET /api/customer/payments - Get all payments
 * GET /api/customer/payments/{id} - Get specific payment
 * POST /api/customer/payments/notify - Submit payment notification (upload slip)
 * 
 * Database Schema (payments table):
 * - id, user_id, customer_profile_id
 * - payment_type: full, installment, savings, unknown
 * - reference_type: order, installment_contract, savings_account, unknown
 * - reference_id, order_id, installment_id, savings_goal_id
 * - amount, payment_method, status (pending, verified, rejected, refunded)
 * - slip_image_url, ocr_data, payment_ref, sender_name, transfer_time
 * - customer_name, customer_phone, customer_platform, customer_avatar
 * - verified_by, verified_at, rejection_reason
 * - note, admin_note, created_at, updated_at
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

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

// Get tenant_id for this user
$pdo_init = getDB();
$stmt = $pdo_init->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_row = $stmt->fetch(PDO::FETCH_ASSOC);
$tenant_id = $user_row['tenant_id'] ?? 'default';

// Parse payment ID from URI path (/payments/34) or query string (?id=34)
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
$payment_id = null;

// First check query string (used by JS frontend)
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $payment_id = (int) $_GET['id'];
} else {
    // Fallback to URI path parsing
    foreach ($uri_parts as $i => $part) {
        if ($part === 'payments' && isset($uri_parts[$i + 1]) && is_numeric($uri_parts[$i + 1])) {
            $payment_id = (int) $uri_parts[$i + 1];
            break;
        }
    }
}

try {
    $pdo = getDB();

    if ($method === 'GET') {
        if ($payment_id) {
            // GET /api/customer/payments/{id} - Single payment detail
            $stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    p.payment_no,
                    p.order_id,
                    p.customer_id,
                    p.tenant_id,
                    p.amount,
                    p.payment_type,
                    p.payment_method,
                    p.installment_period,
                    p.current_period,
                    p.status,
                    p.slip_image,
                    p.slip_image as slip_image_url,
                    p.payment_details,
                    p.payment_date,
                    p.payment_date as transfer_time,
                    p.verified_by,
                    p.verified_at,
                    p.rejection_reason,
                    p.source,
                    p.created_at,
                    p.updated_at,
                    -- Customer profile info
                    cp.display_name as customer_display_name,
                    cp.full_name as customer_full_name,
                    cp.platform as customer_platform,
                    cp.platform_user_id as customer_platform_id,
                    COALESCE(cp.avatar_url, cp.profile_pic_url) as customer_avatar,
                    cp.phone as customer_phone
                FROM payments p
                LEFT JOIN customer_profiles cp ON 
                    cp.platform_user_id = COALESCE(p.platform_user_id, JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.external_user_id')))
                    AND cp.platform = COALESCE(p.platform, JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.platform')), 'line')
                WHERE p.id = ? AND p.tenant_id = ?
            ");
            $stmt->execute([$payment_id, $tenant_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Payment not found']);
                exit;
            }

            // Parse payment_details
            $details = [];
            if ($payment['payment_details']) {
                $details = json_decode($payment['payment_details'], true) ?: [];
            }

            // Get customer name
            $payment['customer_name'] = $payment['customer_full_name']
                ?? $payment['customer_display_name']
                ?? ($details['customer_name'] ?? null)
                ?? ($details['sender_name'] ?? null)
                ?? 'ไม่ระบุลูกค้า';

            // Extract OCR/bank info
            $payment['bank_name'] = $details['bank_name'] ?? ($details['ocr_result']['bank'] ?? null);
            $payment['payment_ref'] = $details['payment_ref'] ?? ($details['ocr_result']['ref'] ?? null);
            $payment['sender_name'] = $details['sender_name'] ?? ($details['ocr_result']['sender_name'] ?? null);
            $payment['receiver_name'] = $details['receiver_name'] ?? ($details['ocr_result']['receiver_name'] ?? null);

            // Ensure customer_platform is set
            if (empty($payment['customer_platform']) && !empty($details['platform'])) {
                $payment['customer_platform'] = $details['platform'];
            }

            // If customer_avatar is null, try to get from customer_profiles using external_user_id (like cases.php does)
            if (empty($payment['customer_avatar'])) {
                $external_user_id_for_profile = $details['external_user_id'] ?? null;
                $platform_for_profile = $details['platform'] ?? $payment['customer_platform'] ?? null;

                if (!empty($external_user_id_for_profile) && !empty($platform_for_profile)) {
                    $stmt = $pdo->prepare("
                        SELECT 
                            display_name,
                            full_name,
                            COALESCE(avatar_url, profile_pic_url) as avatar_url,
                            phone
                        FROM customer_profiles 
                        WHERE platform_user_id = ? AND platform = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$external_user_id_for_profile, $platform_for_profile]);
                    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($profile) {
                        $payment['customer_avatar'] = $profile['avatar_url'];
                        // Also update customer_name if not already set
                        if (empty($payment['customer_name']) || $payment['customer_name'] === 'ไม่ระบุลูกค้า') {
                            $payment['customer_name'] = $profile['full_name'] ?? $profile['display_name'] ?? $payment['customer_name'];
                        }
                        if (empty($payment['customer_phone']) && !empty($profile['phone'])) {
                            $payment['customer_phone'] = $profile['phone'];
                        }
                    }
                }
            }

            // Get related order info
            if ($payment['order_id']) {
                $stmt = $pdo->prepare("
                    SELECT 
                        o.order_number,
                        o.order_number as order_no,
                        o.total_amount as order_total,
                        o.status as order_status
                    FROM orders o
                    WHERE o.id = ?
                ");
                $stmt->execute([$payment['order_id']]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($order) {
                    $payment['order_no'] = $order['order_no'];
                    $payment['order_number'] = $order['order_number'];
                    $payment['product_name'] = null;
                    $payment['order_total'] = $order['order_total'];
                    $payment['order_status'] = $order['order_status'];
                }
            }

            // Get chat messages related to this payment (from chat_sessions + chat_messages)
            $payment['chat_messages'] = [];

            // Get external_user_id from: 1) payment_details, 2) customer_profiles
            $external_user_id = $details['external_user_id'] ?? $payment['customer_platform_id'] ?? null;
            $channel_id = $details['channel_id'] ?? null;

            if (!empty($external_user_id)) {
                // If no channel_id, find it from chat_sessions
                if (empty($channel_id)) {
                    $stmt = $pdo->prepare("
                        SELECT id as session_id, channel_id
                        FROM chat_sessions
                        WHERE external_user_id = ?
                        ORDER BY updated_at DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$external_user_id]);
                    $session = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    // First find the chat_session with channel_id
                    $stmt = $pdo->prepare("
                        SELECT id as session_id, channel_id
                        FROM chat_sessions
                        WHERE channel_id = ? AND external_user_id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$channel_id, $external_user_id]);
                    $session = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                if ($session) {
                    // Get recent messages from chat_messages table
                    $stmt = $pdo->prepare("
                        SELECT 
                            role,
                            text as message_text,
                            created_at as sent_at,
                            CASE WHEN role IN ('assistant', 'system') THEN 'bot' ELSE 'customer' END as sender_type
                        FROM chat_messages
                        WHERE session_id = ?
                        ORDER BY created_at DESC
                        LIMIT 20
                    ");
                    $stmt->execute([$session['session_id']]);
                    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Reverse to show oldest first
                    $payment['chat_messages'] = array_reverse($messages);
                }
            }

            // Clean up internal fields
            unset($payment['customer_full_name'], $payment['customer_display_name']);

            echo json_encode(['success' => true, 'data' => $payment]);

        } else {
            // GET /api/customer/payments - List all payments
            $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 20;
            $offset = ($page - 1) * $limit;

            $status = isset($_GET['status']) ? $_GET['status'] : null;
            $payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : null;

            // Build query - filter by tenant_id (shop's payments)
            $where = ['p.tenant_id = ?'];
            $params = [$tenant_id];

            if ($status) {
                $where[] = 'p.status = ?';
                $params[] = $status;
            }

            if ($payment_type) {
                $where[] = 'p.payment_type = ?';
                $params[] = $payment_type;
            }

            $where_clause = implode(' AND ', $where);

            // Get total count
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM payments p
                WHERE $where_clause
            ");
            $stmt->execute($params);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get payments with order info - join with customer_profiles
            $stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    p.payment_no,
                    p.order_id,
                    p.amount,
                    p.payment_type,
                    p.payment_method,
                    p.installment_period,
                    p.current_period,
                    p.status,
                    COALESCE(p.slip_image, JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.slip_image_url'))) as slip_image,
                    COALESCE(p.slip_image, JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.slip_image_url'))) as slip_image_url,
                    p.payment_date,
                    p.payment_date as transfer_time,
                    p.payment_details,
                    p.verified_at,
                    p.created_at,
                    p.customer_id,
                    p.source,
                    -- Get order info via subquery
                    (SELECT o.order_number FROM orders o WHERE o.id = p.order_id) as order_no,
                    (SELECT o.order_number FROM orders o WHERE o.id = p.order_id) as order_number,
                    -- Get customer info from customer_profiles
                    cp.display_name as customer_display_name,
                    cp.full_name as customer_full_name,
                    cp.platform as customer_platform,
                    cp.platform_user_id as customer_platform_id,
                    COALESCE(cp.avatar_url, cp.profile_pic_url) as customer_avatar,
                    cp.phone as customer_phone,
                    NULL as product_name
                FROM payments p
                LEFT JOIN customer_profiles cp ON 
                    cp.platform_user_id = COALESCE(p.platform_user_id, JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.external_user_id')))
                    AND cp.platform = COALESCE(p.platform, JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.platform')), 'line')
                WHERE $where_clause
                ORDER BY p.id DESC
                LIMIT ? OFFSET ?
            ");
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Process payments for display
            foreach ($payments as &$payment) {
                // Parse payment_details for extra info (OCR data, chat context)
                $details = [];
                if ($payment['payment_details']) {
                    $details = json_decode($payment['payment_details'], true) ?: [];
                }

                // Get customer name: prefer profile, then OCR, then fallback
                $payment['customer_name'] = $payment['customer_full_name']
                    ?? $payment['customer_display_name']
                    ?? ($details['customer_name'] ?? null)
                    ?? ($details['sender_name'] ?? null)
                    ?? 'ไม่ระบุลูกค้า';

                // Ensure customer_platform is set
                if (empty($payment['customer_platform']) && !empty($details['platform'])) {
                    $payment['customer_platform'] = $details['platform'];
                }

                // If customer_avatar is null, try to get from customer_profiles using external_user_id (like cases.php does)
                if (empty($payment['customer_avatar'])) {
                    $external_user_id_for_profile = $details['external_user_id'] ?? null;
                    $platform_for_profile = $details['platform'] ?? $payment['customer_platform'] ?? null;

                    if (!empty($external_user_id_for_profile) && !empty($platform_for_profile)) {
                        $profileStmt = $pdo->prepare("
                            SELECT 
                                display_name,
                                full_name,
                                COALESCE(avatar_url, profile_pic_url) as avatar_url,
                                phone
                            FROM customer_profiles 
                            WHERE platform_user_id = ? AND platform = ?
                            LIMIT 1
                        ");
                        $profileStmt->execute([$external_user_id_for_profile, $platform_for_profile]);
                        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

                        if ($profile) {
                            $payment['customer_avatar'] = $profile['avatar_url'];
                            // Also update customer_name if not already set properly
                            if (empty($payment['customer_name']) || $payment['customer_name'] === 'ไม่ระบุลูกค้า') {
                                $payment['customer_name'] = $profile['full_name'] ?? $profile['display_name'] ?? $payment['customer_name'];
                            }
                            if (empty($payment['customer_phone']) && !empty($profile['phone'])) {
                                $payment['customer_phone'] = $profile['phone'];
                            }
                        }
                    }
                }

                // Extract OCR data for display
                $payment['bank_name'] = $details['bank_name'] ?? ($details['ocr_result']['bank'] ?? null);
                $payment['payment_ref'] = $details['payment_ref'] ?? ($details['ocr_result']['ref'] ?? null);
                $payment['sender_name'] = $details['sender_name'] ?? ($details['ocr_result']['sender_name'] ?? null);

                // Clean up internal fields
                unset($payment['customer_full_name'], $payment['customer_display_name']);
            }
            unset($payment);

            // Get summary counts for this tenant
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified_count,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
                FROM payments
                WHERE tenant_id = ?
            ");
            $stmt->execute([$tenant_id]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'payments' => $payments,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => (int) $total,
                        'total_pages' => ceil($total / $limit)
                    ],
                    'summary' => $summary
                ]
            ]);
        }

    } elseif ($method === 'POST') {
        // POST /api/customer/payments or /notify
        $is_notify = strpos($uri, '/notify') !== false;
        $action = $_GET['action'] ?? null;

        if ($is_notify) {
            // Handle payment notification with slip upload
            submitPaymentNotification($pdo, $user_id);
        } elseif ($action === 'create') {
            // Handle manual payment creation (admin feature)
            createManualPayment($pdo, $user_id);
        } else {
            // Handle other POST actions
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$action) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing action parameter']);
                exit;
            }

            $pid = $input['payment_id'] ?? $_GET['id'] ?? null;
            if (!$pid) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing payment_id']);
                exit;
            }

            // Get payment
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
            $stmt->execute([$pid]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Payment not found']);
                exit;
            }

            switch ($action) {
                case 'approve':
                    approvePayment($pdo, $payment, $user_id);
                    break;
                case 'reject':
                    rejectPayment($pdo, $payment, $input, $user_id);
                    break;
                case 'classify':
                    classifyPayment($pdo, $payment, $input, $user_id);
                    break;
                case 'link_order':
                    linkOrderToPayment($pdo, $payment, $input, $user_id, $tenant_id);
                    break;
                case 'unlink_order':
                    unlinkOrderFromPayment($pdo, $payment, $user_id);
                    break;
                case 'link_repair':
                    linkRepairToPayment($pdo, $payment, $input, $user_id, $tenant_id);
                    break;
                case 'unlink_repair':
                    unlinkRepairFromPayment($pdo, $payment, $user_id);
                    break;
                default:
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'ไม่รองรับการดำเนินการนี้',
                        'hint' => 'รองรับเฉพาะ: approve, reject, classify, link_order, unlink_order, link_repair, unlink_repair'
                    ]);
            }
        }

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("Payments API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Submit payment notification with slip upload
 */
function submitPaymentNotification($pdo, $user_id)
{
    // Handle file upload (slip image)
    $slip_url = null;
    if (isset($_FILES['slip_image']) && $_FILES['slip_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../public/uploads/slips/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_ext = pathinfo($_FILES['slip_image']['name'], PATHINFO_EXTENSION);
        $filename = 'slip_' . $user_id . '_' . time() . '.' . $file_ext;
        $filepath = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['slip_image']['tmp_name'], $filepath)) {
            $slip_url = '/public/uploads/slips/' . $filename;
        }
    }

    // Get form data
    $order_id = $_POST['order_id'] ?? null;
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
    $payment_type = $_POST['payment_type'] ?? 'full';
    $note = trim($_POST['note'] ?? '');

    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุจำนวนเงิน']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO payments (
                user_id,
                order_id,
                amount,
                payment_type,
                payment_method,
                slip_image_url,
                status,
                note,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
        ");

        $stmt->execute([
            $user_id,
            $order_id ?: null,
            $amount,
            $payment_type,
            $payment_method,
            $slip_url,
            $note ?: null
        ]);

        $payment_id = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'บันทึกการแจ้งชำระเงินเรียบร้อย',
            'data' => [
                'id' => $payment_id,
                'slip_url' => $slip_url
            ]
        ]);

    } catch (Exception $e) {
        error_log("Submit payment error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถบันทึกข้อมูลได้'
        ]);
    }
}

/**
 * Create manual payment (admin adds payment manually)
 */
function createManualPayment($pdo, $user_id)
{
    // Handle file upload (slip image) - upload to Google Cloud Storage
    $slip_url = null;
    if (isset($_FILES['slip_image']) && $_FILES['slip_image']['error'] === UPLOAD_ERR_OK) {
        try {
            require_once __DIR__ . '/../../includes/GoogleCloudStorage.php';

            $gcs = GoogleCloudStorage::getInstance();
            $fileContent = file_get_contents($_FILES['slip_image']['tmp_name']);
            $fileName = $_FILES['slip_image']['name'];
            $mimeType = $_FILES['slip_image']['type'] ?: 'image/jpeg';

            $result = $gcs->uploadFile(
                $fileContent,
                $fileName,
                $mimeType,
                'slips/manual',  // folder in GCS bucket
                [
                    'user_id' => $user_id,
                    'source' => 'manual_payment',
                    'uploaded_at' => date('c')
                ]
            );

            if ($result['success']) {
                $slip_url = $result['url'];  // Public GCS URL
            } else {
                error_log("GCS upload failed: " . ($result['error'] ?? 'Unknown error'));
            }
        } catch (Exception $e) {
            error_log("GCS upload exception: " . $e->getMessage());
            // Fallback to local storage if GCS fails
            $upload_dir = __DIR__ . '/../../public/uploads/slips/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_ext = pathinfo($_FILES['slip_image']['name'], PATHINFO_EXTENSION);
            $filename = 'slip_manual_' . $user_id . '_' . time() . '.' . $file_ext;
            $filepath = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['slip_image']['tmp_name'], $filepath)) {
                $slip_url = '/public/uploads/slips/' . $filename;
            }
        }
    }

    // Get form data
    $payment_type = $_POST['payment_type'] ?? 'full';
    $customer_profile_id = intval($_POST['customer_profile_id'] ?? 0);
    $reference_id = intval($_POST['reference_id'] ?? 0);
    $reference_type = $_POST['reference_type'] ?? 'order';
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
    $note = trim($_POST['note'] ?? '');
    $source = $_POST['source'] ?? 'manual';

    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุจำนวนเงิน']);
        return;
    }

    try {
        // Get tenant_id from user
        $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $tenant_id = $userRow['tenant_id'] ?? 'default';

        // Get customer info if customer_profile_id provided
        $customer_name = null;
        $customer_phone = null;
        $customer_platform = null;
        $customer_avatar = null;
        $platform_user_id = null;

        if ($customer_profile_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM customer_profiles WHERE id = ?");
            $stmt->execute([$customer_profile_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($customer) {
                $customer_name = $customer['display_name'] ?? $customer['full_name'];
                $customer_phone = $customer['phone'];
                $customer_platform = $customer['platform'];
                $customer_avatar = $customer['avatar_url'];
                $platform_user_id = $customer['platform_user_id'];
            }
        }

        // Generate payment_no
        $payment_no = 'PAY-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

        // Determine order_id based on reference_type
        $order_id = null;
        if ($reference_id > 0 && $reference_type === 'order') {
            $order_id = $reference_id;
        }

        // Build payment_details JSON with customer info and other metadata
        $payment_details = json_encode([
            'customer_profile_id' => $customer_profile_id ?: null,
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'customer_platform' => $customer_platform,
            'customer_avatar' => $customer_avatar,
            'external_user_id' => $platform_user_id,
            'platform' => $customer_platform,
            'reference_type' => $reference_type,
            'reference_id' => $reference_id ?: null,
            'note' => $note,
            'source' => $source,
            'submitted_at' => date('c')
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO payments (
                payment_no,
                order_id,
                customer_id,
                tenant_id,
                payment_type,
                amount,
                payment_method,
                slip_image,
                payment_details,
                payment_date,
                status,
                verified_by,
                verified_at,
                source,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'verified', ?, NOW(), ?, NOW(), NOW())
        ");

        $stmt->execute([
            $payment_no,
            $order_id,
            $customer_profile_id ?: null,  // customer_id references customer_profiles.id
            $tenant_id,
            $payment_type,
            $amount,
            $payment_method,
            $slip_url,
            $payment_details,
            $user_id,  // verified_by = current user (auto-approve)
            $source
        ]);

        $payment_id = $pdo->lastInsertId();

        // ✅ Update order status if linked to an order (manual payment is auto-verified)
        if ($order_id) {
            // Get current order info
            $stmt = $pdo->prepare("SELECT total_amount, paid_amount, remaining_amount FROM orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($order) {
                $currentPaid = (float) ($order['paid_amount'] ?? 0);
                $newPaidAmount = $currentPaid + $amount;
                $totalAmount = (float) ($order['total_amount'] ?? 0);
                $newRemainingAmount = max(0, $totalAmount - $newPaidAmount);
                $isPaidInFull = $newPaidAmount >= $totalAmount;

                $stmt = $pdo->prepare("
                    UPDATE orders 
                    SET paid_amount = ?,
                        remaining_amount = ?,
                        payment_status = CASE 
                            WHEN ? >= total_amount THEN 'paid'
                            WHEN ? > 0 THEN 'partial'
                            ELSE 'pending'
                        END,
                        status = CASE 
                            WHEN ? >= total_amount THEN 'paid'
                            WHEN status IN ('pending', 'draft', 'pending_payment') THEN 'processing'
                            ELSE status
                        END,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $newPaidAmount,
                    $newRemainingAmount,
                    $newPaidAmount,
                    $newPaidAmount,
                    $newPaidAmount,
                    $order_id
                ]);

                error_log("[Payment] Order #$order_id updated: paid=$newPaidAmount, remaining=$newRemainingAmount, full=" . ($isPaidInFull ? 'yes' : 'no'));
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'บันทึกรายการชำระเงินเรียบร้อย',
            'data' => [
                'id' => $payment_id,
                'payment_no' => $payment_no,
                'slip_url' => $slip_url
            ]
        ]);

    } catch (Exception $e) {
        error_log("Create manual payment error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถบันทึกข้อมูลได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Approve payment
 */
function approvePayment($pdo, $payment, $admin_id)
{
    try {
        $pdo->beginTransaction();

        // Update payment status
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET status = 'verified', verified_by = ?, verified_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$admin_id, $payment['id']]);

        // Update order paid_amount and status if linked
        if ($payment['order_id']) {
            // First get current order info
            $stmt = $pdo->prepare("SELECT total_amount, paid_amount FROM orders WHERE id = ?");
            $stmt->execute([$payment['order_id']]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($order) {
                $newPaidAmount = ($order['paid_amount'] ?? 0) + $payment['amount'];
                $isPaidInFull = $newPaidAmount >= $order['total_amount'];

                $stmt = $pdo->prepare("
                    UPDATE orders 
                    SET paid_amount = ?,
                        payment_status = CASE 
                            WHEN ? >= total_amount THEN 'paid'
                            ELSE 'partial'
                        END,
                        status = CASE 
                            WHEN ? >= total_amount THEN 'paid'
                            WHEN status = 'pending' OR status = 'draft' OR status = 'pending_payment' THEN 'processing'
                            ELSE status
                        END,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$newPaidAmount, $newPaidAmount, $newPaidAmount, $payment['order_id']]);
            }
        }

        $pdo->commit();

        // ✅ Send push notification to customer
        sendPaymentApprovalNotification($pdo, $payment, 'approved');

        echo json_encode([
            'success' => true,
            'message' => 'อนุมัติการชำระเงินเรียบร้อย'
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Approve payment error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถอนุมัติได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Reject payment
 */
function rejectPayment($pdo, $payment, $input, $admin_id)
{
    $reason = trim($input['reason'] ?? '');

    try {
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET status = 'rejected', 
                verified_by = ?, 
                verified_at = NOW(),
                rejection_reason = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$admin_id, $reason ?: null, $payment['id']]);

        // ✅ Send push notification to customer
        sendPaymentApprovalNotification($pdo, $payment, 'rejected', $reason);

        echo json_encode([
            'success' => true,
            'message' => 'ปฏิเสธการชำระเงินเรียบร้อย'
        ]);

    } catch (Exception $e) {
        error_log("Reject payment error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถปฏิเสธได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Link order to payment (manual matching)
 */
function linkOrderToPayment($pdo, $payment, $input, $admin_id, $tenant_id)
{
    $order_id = $input['order_id'] ?? null;

    if (!$order_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุคำสั่งซื้อ']);
        return;
    }

    try {
        // Verify order exists and belongs to same tenant
        $stmt = $pdo->prepare("SELECT id, order_number, total_amount, paid_amount, remaining_amount, status FROM orders WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$order_id, $tenant_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'ไม่พบคำสั่งซื้อ']);
            return;
        }

        // ✅ Check if order is already fully paid
        $totalAmount = (float) ($order['total_amount'] ?? 0);
        $currentPaid = (float) ($order['paid_amount'] ?? 0);
        if ($totalAmount > 0 && $currentPaid >= $totalAmount) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'คำสั่งซื้อนี้ชำระครบแล้ว ไม่สามารถเชื่อมโยงการชำระเงินเพิ่มได้'
            ]);
            return;
        }

        // Update payment with order_id
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET order_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$order_id, $payment['id']]);

        // ✅ If payment is verified, update order paid_amount and status
        if ($payment['status'] === 'verified') {
            $paymentAmount = (float) ($payment['amount'] ?? 0);
            $newPaidAmount = $currentPaid + $paymentAmount;
            $newRemainingAmount = max(0, $totalAmount - $newPaidAmount);

            $stmt = $pdo->prepare("
                UPDATE orders 
                SET paid_amount = ?,
                    remaining_amount = ?,
                    payment_status = CASE 
                        WHEN ? >= total_amount THEN 'paid'
                        WHEN ? > 0 THEN 'partial'
                        ELSE 'pending'
                    END,
                    status = CASE 
                        WHEN ? >= total_amount THEN 'paid'
                        WHEN status IN ('pending', 'draft', 'pending_payment') THEN 'processing'
                        ELSE status
                    END,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $newPaidAmount,
                $newRemainingAmount,
                $newPaidAmount,
                $newPaidAmount,
                $newPaidAmount,
                $order_id
            ]);

            error_log("[Payment] Linked order #$order_id: paid=$newPaidAmount, remaining=$newRemainingAmount");
        }

        echo json_encode([
            'success' => true,
            'message' => 'เชื่อมโยงคำสั่งซื้อ ' . $order['order_number'] . ' เรียบร้อย'
        ]);

    } catch (Exception $e) {
        error_log("Link order error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถเชื่อมโยงได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Unlink order from payment
 */
function unlinkOrderFromPayment($pdo, $payment, $admin_id)
{
    try {
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET order_id = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$payment['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'ยกเลิกการเชื่อมโยงเรียบร้อย'
        ]);

    } catch (Exception $e) {
        error_log("Unlink order error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถยกเลิกได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Classify and approve payment with type selection
 */
function classifyPayment($pdo, $payment, $input, $admin_id)
{
    try {
        // Validate required fields
        $payment_type = $input['payment_type'] ?? null;

        if (!$payment_type) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'กรุณาเลือกประเภทการชำระเงิน',
                'field' => 'payment_type'
            ]);
            return;
        }

        // Validate payment_type value
        $valid_types = ['full', 'installment', 'savings', 'savings_deposit', 'deposit', 'unknown'];
        if (!in_array($payment_type, $valid_types)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ประเภทการชำระเงินไม่ถูกต้อง',
                'field' => 'payment_type',
                'hint' => 'ประเภทที่รองรับ: ' . implode(', ', $valid_types)
            ]);
            return;
        }

        // Normalize savings_deposit to savings
        if ($payment_type === 'savings_deposit') {
            $payment_type = 'savings';
        }

        // Get optional fields
        $classification_notes = trim($input['classification_notes'] ?? '');
        $current_period = isset($input['current_period']) ? (int) $input['current_period'] : null;
        $installment_period = isset($input['installment_period']) ? (int) $input['installment_period'] : null;

        // Validate installment fields if payment_type is installment
        if ($payment_type === 'installment') {
            if (!$current_period || $current_period < 1) {
                $current_period = 1;
            }
            if (!$installment_period || $installment_period < 1) {
                $installment_period = 3; // default 3 installments
            }
            if ($current_period > $installment_period) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'งวดปัจจุบันต้องไม่เกินจำนวนงวดทั้งหมด',
                    'field' => 'current_period'
                ]);
                return;
            }
        }

        // Build update query
        $update_fields = [
            'payment_type' => $payment_type,
            'status' => 'verified',
            'verified_by' => $admin_id,
            'verified_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Add installment info if applicable
        if ($payment_type === 'installment') {
            $update_fields['current_period'] = $current_period;
            $update_fields['installment_period'] = $installment_period;
        }

        // Add classification notes to admin_note if provided
        if ($classification_notes) {
            $existing_note = $payment['admin_note'] ?? '';
            $update_fields['admin_note'] = trim($existing_note . "\n[Classification] " . $classification_notes);
        }

        // Build SQL
        $set_parts = [];
        $values = [];
        foreach ($update_fields as $field => $value) {
            $set_parts[] = "`$field` = ?";
            $values[] = $value;
        }
        $values[] = $payment['id'];

        $sql = "UPDATE payments SET " . implode(', ', $set_parts) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        // Build success message
        $type_labels = [
            'full' => 'จ่ายเต็ม',
            'installment' => 'ผ่อนชำระ',
            'savings' => 'ออมเงิน',
            'deposit' => 'มัดจำ'
        ];
        $type_label = $type_labels[$payment_type] ?? $payment_type;

        $success_msg = "อนุมัติและจัดประเภทเป็น \"$type_label\" เรียบร้อย";
        if ($payment_type === 'installment') {
            $success_msg .= " (งวดที่ $current_period/$installment_period)";
        }

        echo json_encode([
            'success' => true,
            'message' => $success_msg,
            'data' => [
                'payment_id' => $payment['id'],
                'payment_type' => $payment_type,
                'status' => 'verified',
                'current_period' => $current_period,
                'installment_period' => $installment_period
            ]
        ]);

    } catch (Exception $e) {
        error_log("Classify payment error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถบันทึกได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Link repair to payment
 */
function linkRepairToPayment($pdo, $payment, $input, $admin_id, $tenant_id)
{
    try {
        $repair_id = $input['repair_id'] ?? null;

        if (!$repair_id) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'กรุณาเลือกงานซ่อมที่ต้องการเชื่อมโยง',
                'field' => 'repair_id'
            ]);
            return;
        }

        // Verify repair exists
        $stmt = $pdo->prepare("
            SELECT id, repair_no, item_name, customer_name, final_cost, status
            FROM repairs 
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$repair_id, $tenant_id]);
        $repair = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$repair) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'ไม่พบงานซ่อมที่ระบุ'
            ]);
            return;
        }

        // Update payment with repair reference
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET repair_id = ?,
                reference_type = 'repair',
                reference_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$repair_id, $repair_id, $payment['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'เชื่อมโยงงานซ่อม ' . $repair['repair_no'] . ' เรียบร้อย',
            'data' => [
                'payment_id' => $payment['id'],
                'repair_id' => $repair_id,
                'repair_no' => $repair['repair_no'],
                'item_name' => $repair['item_name']
            ]
        ]);

    } catch (Exception $e) {
        error_log("Link repair error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถเชื่อมโยงได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Unlink repair from payment
 */
function unlinkRepairFromPayment($pdo, $payment, $admin_id)
{
    try {
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET repair_id = NULL,
                reference_type = CASE 
                    WHEN order_id IS NOT NULL THEN 'order'
                    ELSE 'unknown'
                END,
                reference_id = order_id,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$payment['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'ยกเลิกการเชื่อมโยงงานซ่อมเรียบร้อย'
        ]);

    } catch (Exception $e) {
        error_log("Unlink repair error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถยกเลิกได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Send push notification to customer after payment approval/rejection
 * 
 * @param PDO $pdo Database connection
 * @param array $payment Payment data
 * @param string $action 'approved' or 'rejected'
 * @param string|null $reason Rejection reason (only for rejected)
 */
function sendPaymentApprovalNotification($pdo, $payment, $action, $reason = null)
{
    try {
        // Get customer profile info from payment_details or customer_id
        $paymentDetails = is_string($payment['payment_details'] ?? null)
            ? json_decode($payment['payment_details'], true)
            : ($payment['payment_details'] ?? []);

        $platform = $paymentDetails['platform'] ?? $paymentDetails['customer_platform'] ?? null;
        $platformUserId = $paymentDetails['external_user_id'] ?? $paymentDetails['platform_user_id'] ?? null;

        // If not in payment_details, try to get from customer_profiles via customer_id
        if ((!$platform || !$platformUserId) && !empty($payment['customer_id'])) {
            $stmt = $pdo->prepare("SELECT platform, platform_user_id FROM customer_profiles WHERE id = ?");
            $stmt->execute([$payment['customer_id']]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($profile) {
                $platform = $platform ?: $profile['platform'];
                $platformUserId = $platformUserId ?: $profile['platform_user_id'];
            }
        }

        // Cannot send notification without platform info
        if (!$platform || !$platformUserId) {
            error_log("[PaymentNotification] Cannot send - no platform/user_id for payment #{$payment['id']}");
            return;
        }

        // Skip web platform (no push for web users)
        if ($platform === 'web') {
            error_log("[PaymentNotification] Skipping web platform for payment #{$payment['id']}");
            return;
        }

        // Load PushNotificationService
        require_once __DIR__ . '/../../includes/services/PushNotificationService.php';
        require_once __DIR__ . '/../../includes/Database.php';

        $db = Database::getInstance();
        $pushService = new PushNotificationService($db);

        // Build payment data for notification
        $paymentData = [
            'payment_no' => $payment['payment_no'] ?? '-',
            'amount' => number_format((float) ($payment['amount'] ?? 0), 2),
            'payment_date' => $payment['payment_date'] ?? date('Y-m-d'),
            'reason' => $reason ?: 'ไม่ระบุ'
        ];

        // Send notification based on action
        if ($action === 'approved') {
            $result = $pushService->sendPaymentVerified($platform, $platformUserId, $paymentData);
        } else {
            $result = $pushService->sendPaymentRejected($platform, $platformUserId, $paymentData);
        }

        if ($result['success']) {
            error_log("[PaymentNotification] Sent {$action} notification to {$platform}:{$platformUserId}");
        } else {
            error_log("[PaymentNotification] Failed to send: " . ($result['error'] ?? 'Unknown error'));
        }

    } catch (Throwable $e) {
        // Don't fail the main flow, just log the error
        error_log("[PaymentNotification] Error: " . $e->getMessage());
    }
}
