<?php
/**
 * Manual Billing Trigger API (Admin Only)
 * POST /api/admin/trigger-billing.php
 * 
 * Purpose: ให้ admin กดรัน billing cycle manually ก่อนตั้ง cron
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/Database.php';
    require_once __DIR__ . '/../../includes/OmiseClient.php';
    
    // Verify admin authentication
    $token = null;
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }
    
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $tokenData = json_decode(base64_decode($token), true);
    if (!$tokenData || ($tokenData['type'] ?? null) !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        exit;
    }
    
    // Get request data (optional: force billing for specific date)
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetDate = $data['target_date'] ?? date('Y-m-d');
    
    $db = Database::getInstance();
    $omise = new OmiseClient();
    
    // Log the trigger
    error_log("Manual billing triggered by admin ID: {$tokenData['admin_id']} for date: {$targetDate}");
    
    // Find subscriptions due for billing on target date
    $subscriptions = $db->query(
        "SELECT s.*, u.email, u.full_name, sp.name as plan_name, sp.monthly_price
         FROM subscriptions s
         JOIN users u ON s.user_id = u.id
         JOIN subscription_plans sp ON s.plan_id = sp.id
         WHERE s.next_billing_date = ?
         AND s.status IN ('trial', 'active')
         AND s.auto_renew = TRUE",
        [$targetDate]
    );
    
    $results = [
        'processed' => 0,
        'successful' => 0,
        'failed' => 0,
        'pending' => 0,
        'skipped' => 0,
        'details' => []
    ];
    
    // Pre-calc billing period for this run (month of targetDate)
    $billingStart = date('Y-m-01', strtotime($targetDate));
    $billingEnd   = date('Y-m-t', strtotime($targetDate));
    $year         = date('Y', strtotime($targetDate));
    $month        = date('m', strtotime($targetDate));

    foreach ($subscriptions as $sub) {
        $results['processed']++;

        $amount = (float)$sub['monthly_price'];

        // Idempotency: check if invoice already exists for this user+sub+period
        $existingInvoice = $db->queryOne(
            "SELECT id, status FROM invoices 
             WHERE user_id = ? AND subscription_id = ? 
             AND billing_period_start = ? AND billing_period_end = ?",
            [$sub['user_id'], $sub['id'], $billingStart, $billingEnd]
        );

        if ($existingInvoice) {
            $results['skipped']++;
            $results['details'][] = [
                'user' => $sub['email'],
                'status' => 'skipped',
                'reason' => 'Invoice already exists for this period',
                'invoice_id' => $existingInvoice['id'],
                'invoice_status' => $existingInvoice['status']
            ];
            continue;
        }

        // Check for ANY pending invoices from previous periods
        $pendingInvoices = $db->query(
            "SELECT id, invoice_number, total, created_at, due_date
             FROM invoices 
             WHERE user_id = ? 
             AND subscription_id = ?
             AND status = 'pending'
             AND created_at < ?
             ORDER BY created_at ASC",
            [$sub['user_id'], $sub['id'], $billingStart]
        );
        
        if (count($pendingInvoices) > 0) {
            $oldestPending = $pendingInvoices[0];
            $createdDate = new DateTime($oldestPending['created_at']);
            $now = new DateTime();
            $daysOverdue = $createdDate->diff($now)->days;
            
            // Grace period: 7 days
            if ($daysOverdue > 7) {
                // Suspend subscription
                $db->execute(
                    "UPDATE subscriptions SET status = 'suspended', updated_at = NOW() WHERE id = ?",
                    [$sub['id']]
                );
                
                $results['skipped']++;
                $results['details'][] = [
                    'user' => $sub['email'],
                    'status' => 'suspended',
                    'reason' => "Payment overdue - {$daysOverdue} days. Subscription suspended.",
                    'pending_count' => count($pendingInvoices),
                    'oldest_invoice' => $oldestPending['invoice_number']
                ];
            } else {
                $results['skipped']++;
                $results['details'][] = [
                    'user' => $sub['email'],
                    'status' => 'skipped',
                    'reason' => "Pending invoice exists within grace period ({$daysOverdue} days)",
                    'pending_count' => count($pendingInvoices),
                    'oldest_invoice' => $oldestPending['invoice_number']
                ];
            }
            
            continue; // Skip to next subscription
        }

        // Generate next invoice number for the month (simple sequential per year-month)
        $count = $db->queryOne(
            "SELECT COUNT(*) as count FROM invoices WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?",
            [$year, $month]
        );
        $invoiceNumber = sprintf('INV-%s%s-%04d', $year, $month, ((int)($count['count'] ?? 0)) + 1);

        // Load default payment method (if any)
        $paymentMethod = $db->queryOne(
            "SELECT * FROM payment_methods WHERE user_id = ? AND is_default = TRUE LIMIT 1",
            [$sub['user_id']]
        );

        // Case 1: ไม่มีบัตร / ไม่มี default payment method → ออกใบแจ้งหนี้ pending สำหรับการโอน/QR
        if (!$paymentMethod) {
            try {
                $db->beginTransaction();

                // Create pending invoice (ยังไม่จ่าย)
                $db->execute(
                    "INSERT INTO invoices (invoice_number, user_id, subscription_id, amount, tax, total, currency, status, billing_period_start, billing_period_end, created_at)
                     VALUES (?, ?, ?, ?, 0, ?, 'THB', 'pending', ?, ?, NOW())",
                    [$invoiceNumber, $sub['user_id'], $sub['id'], $amount, $amount, $billingStart, $billingEnd]
                );

                $invoiceId = $db->lastInsertId();

                // Add invoice item for the subscription
                $db->execute(
                    "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, amount)
                     VALUES (?, ?, 1, ?, ?)",
                    [$invoiceId, $sub['plan_name'] . ' - Monthly Subscription', $amount, $amount]
                );

                // Update subscription period butคงสถานะ active/trial ตามเดิม (post-paid)
                $db->execute(
                    "UPDATE subscriptions 
                     SET current_period_start = ?, current_period_end = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$billingStart, $billingEnd, $sub['id']]
                );

                $db->commit();

                $results['pending']++;
                $results['details'][] = [
                    'user' => $sub['email'],
                    'status' => 'pending',
                    'reason' => 'No payment method - pending invoice created',
                    'amount' => $amount,
                    'invoice' => $invoiceNumber
                ];
            } catch (Exception $e) {
                $db->rollBack();
                $results['failed']++;
                $results['details'][] = [
                    'user' => $sub['email'],
                    'status' => 'failed',
                    'reason' => 'Error creating pending invoice: ' . $e->getMessage()
                ];
            }

            continue;
        }

        // Case 2: มี default card → เรียก Omise เพื่อเก็บเงิน
        try {
            $charge = $omise->createCharge(
                $amount,
                'thb',
                $paymentMethod['omise_customer_id'],
                $paymentMethod['omise_card_id'],
                'Monthly subscription - ' . $sub['plan_name']
            );

            if (!isset($charge['paid']) || !$charge['paid']) {
                throw new Exception('Charge not paid');
            }

            $db->beginTransaction();

            // Create paid invoice
            $db->execute(
                "INSERT INTO invoices (invoice_number, user_id, subscription_id, amount, tax, total, currency, status, paid_at, billing_period_start, billing_period_end, created_at)
                 VALUES (?, ?, ?, ?, 0, ?, 'THB', 'paid', NOW(), ?, ?, NOW())",
                [$invoiceNumber, $sub['user_id'], $sub['id'], $amount, $amount, $billingStart, $billingEnd]
            );

            $invoiceId = $db->lastInsertId();

            // Add invoice item
            $db->execute(
                "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, amount)
                 VALUES (?, ?, 1, ?, ?)",
                [$invoiceId, $sub['plan_name'] . ' - Monthly Subscription', $amount, $amount]
            );

            // Record successful transaction
            $db->execute(
                "INSERT INTO transactions (invoice_id, payment_method_id, omise_charge_id, amount, currency, status)
                 VALUES (?, ?, ?, ?, 'THB', 'successful')",
                [$invoiceId, $paymentMethod['id'], $charge['id'], $amount]
            );

            // Set next billing date to first day of next month
            $nextBilling = date('Y-m-01', strtotime('+1 month', strtotime($targetDate)));

            $db->execute(
                "UPDATE subscriptions 
                 SET status = 'active', trial_used = TRUE, next_billing_date = ?, 
                     current_period_start = ?, current_period_end = ?, updated_at = NOW()
                 WHERE id = ?",
                [$nextBilling, $billingStart, $billingEnd, $sub['id']]
            );

            // Clear remaining trial days when first successful charge happens
            $db->execute("UPDATE users SET trial_days_remaining = 0 WHERE id = ?", [$sub['user_id']]);

            $db->commit();

            $results['successful']++;
            $results['details'][] = [
                'user' => $sub['email'],
                'status' => 'success',
                'amount' => $amount,
                'invoice' => $invoiceNumber,
                'charge_id' => $charge['id']
            ];
        } catch (Exception $e) {
            // On charge failure: create failed invoice and pause subscription
            $results['failed']++;
            $results['details'][] = [
                'user' => $sub['email'],
                'status' => 'failed',
                'reason' => $e->getMessage()
            ];

            try {
                $db->execute(
                    "INSERT INTO invoices (invoice_number, user_id, subscription_id, amount, tax, total, currency, status, billing_period_start, billing_period_end, created_at)
                     VALUES (?, ?, ?, ?, 0, ?, 'THB', 'failed', ?, ?, NOW())",
                    [$invoiceNumber, $sub['user_id'], $sub['id'], $amount, $amount, $billingStart, $billingEnd]
                );
            } catch (Exception $inner) {
                // log but do not break the whole loop
                error_log('Failed to create failed invoice: ' . $inner->getMessage());
            }

            $db->execute("UPDATE subscriptions SET status = 'paused', updated_at = NOW() WHERE id = ?", [$sub['id']]);
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Manual billing completed',
        'data' => $results
    ]);
    
} catch (Exception $e) {
    error_log("Manual Billing Trigger Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
