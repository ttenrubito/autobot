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

// Parse URI for payment ID
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
$payment_id = null;
foreach ($uri_parts as $i => $part) {
    if ($part === 'payments' && isset($uri_parts[$i + 1]) && is_numeric($uri_parts[$i + 1])) {
        $payment_id = (int)$uri_parts[$i + 1];
        break;
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
                LEFT JOIN customer_profiles cp ON p.customer_id = cp.id
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
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
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
                    p.slip_image,
                    p.slip_image as slip_image_url,
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
                LEFT JOIN customer_profiles cp ON p.customer_id = cp.id
                WHERE $where_clause
                ORDER BY p.created_at DESC
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
                        'total' => (int)$total,
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
                default:
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid action']);
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
function submitPaymentNotification($pdo, $user_id) {
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
function createManualPayment($pdo, $user_id) {
    // Handle file upload (slip image)
    $slip_url = null;
    if (isset($_FILES['slip_image']) && $_FILES['slip_image']['error'] === UPLOAD_ERR_OK) {
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
        
        // Determine order_id, installment_id, savings_goal_id based on reference_type
        $order_id = null;
        $installment_id = null;
        $savings_goal_id = null;
        
        if ($reference_id > 0) {
            switch ($reference_type) {
                case 'order':
                    $order_id = $reference_id;
                    break;
                case 'installment_contract':
                    $installment_id = $reference_id;
                    break;
                case 'savings_account':
                    $savings_goal_id = $reference_id;
                    break;
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO payments (
                payment_no,
                user_id,
                tenant_id,
                customer_profile_id,
                payment_type,
                reference_type,
                reference_id,
                order_id,
                installment_id,
                savings_goal_id,
                amount,
                payment_method,
                slip_image_url,
                status,
                customer_name,
                customer_phone,
                customer_platform,
                customer_platform_id,
                customer_avatar,
                note,
                source,
                transfer_time,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
        ");
        
        $stmt->execute([
            $payment_no,
            $user_id,
            $tenant_id,
            $customer_profile_id ?: null,
            $payment_type,
            $reference_type,
            $reference_id ?: null,
            $order_id,
            $installment_id,
            $savings_goal_id,
            $amount,
            $payment_method,
            $slip_url,
            $customer_name,
            $customer_phone,
            $customer_platform,
            $platform_user_id,
            $customer_avatar,
            $note ?: null,
            $source
        ]);
        
        $payment_id = $pdo->lastInsertId();
        
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
function approvePayment($pdo, $payment, $admin_id) {
    try {
        $pdo->beginTransaction();
        
        // Update payment status
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET status = 'verified', verified_by = ?, verified_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$admin_id, $payment['id']]);
        
        // Update order paid_amount if linked
        if ($payment['order_id']) {
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET paid_amount = paid_amount + ?,
                    payment_status = CASE 
                        WHEN paid_amount + ? >= total_amount THEN 'paid'
                        ELSE 'partial'
                    END,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$payment['amount'], $payment['amount'], $payment['order_id']]);
        }
        
        $pdo->commit();
        
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
function rejectPayment($pdo, $payment, $input, $admin_id) {
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
