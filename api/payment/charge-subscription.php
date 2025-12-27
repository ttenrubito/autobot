<?php
/**
 * Charge Subscription API (For Cron Job)
 * POST /api/payment/charge-subscription.php
 * 
 * Purpose: ตัดเงินอัตโนมัติสำหรับ subscriptions ที่ถึงวันตัดรอบ
 * This should be called by cron job daily
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Cron-Key');

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
    
    // Verify cron key (simple security)
    $cronKey = $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    $expectedKey = getenv('CRON_SECRET_KEY') ?: 'cron_secret_key_change_this';
    
    if ($cronKey !== $expectedKey) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid cron key']);
        exit;
    }
    
    $db = Database::getInstance();
    $omise = new OmiseClient();
    
    // Find subscriptions due for billing today
    $today = date('Y-m-d');
    $subscriptions = $db->query(
        "SELECT s.*, u.email, u.full_name, sp.name as plan_name, sp.monthly_price
         FROM subscriptions s
         JOIN users u ON s.user_id = u.id
         JOIN subscription_plans sp ON s.plan_id = sp.id
         WHERE s.next_billing_date = ?
         AND s.status IN ('trial', 'active')
         AND s.auto_renew = TRUE",
        [$today]
    );
    
    $results = [
        'total' => count($subscriptions),
        'successful' => 0,
        'failed' => 0,
        'details' => []
    ];
    
    foreach ($subscriptions as $sub) {
        // Get payment method
        $paymentMethod = $db->queryOne(
            "SELECT * FROM payment_methods WHERE user_id = ? AND is_default = TRUE LIMIT 1",
            [$sub['user_id']]
        );
        
        if (!$paymentMethod) {
            $results['failed']++;
            $results['details'][] = [
                'user_id' => $sub['user_id'],
                'email' => $sub['email'],
                'status' => 'failed',
                'reason' => 'No payment method found'
            ];
            
            // Update subscription status to paused
            $db->execute(
                "UPDATE subscriptions SET status = 'paused' WHERE id = ?",
                [$sub['id']]
            );
            continue;
        }
        
        // Calculate amount
        $amount = (float)$sub['monthly_price'];
        
        // Generate invoice number
        $year = date('Y');
        $month = date('m');
        $count = $db->queryOne(
            "SELECT COUNT(*) as count FROM invoices WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?",
            [$year, $month]
        );
        $invoiceNumber = sprintf('INV-%s%s-%04d', $year, $month, $count['count'] + 1);
        
        try {
            // Charge via Omise
            $charge = $omise->createCharge(
                $amount,
                'thb',
                $paymentMethod['omise_customer_id'],
                $paymentMethod['omise_card_id'],
                'Monthly subscription - ' . $sub['plan_name']
            );
            
            if ($charge['paid']) {
                // Success!
                $db->beginTransaction();
                
                try {
                    // Create invoice
                    $db->execute(
                        "INSERT INTO invoices (invoice_number, user_id, subscription_id, amount, tax, total, currency, status, paid_at, billing_period_start, billing_period_end)
                         VALUES (?, ?, ?, ?, 0, ?, 'THB', 'paid', NOW(), ?, ?)",
                        [
                            $invoiceNumber,
                            $sub['user_id'],
                            $sub['id'],
                            $amount,
                            $amount,
                            date('Y-m-01'),  // billing period start (1st of month)
                            date('Y-m-t')    // billing period end (last day of month)
                        ]
                    );
                    
                    $invoiceId = $db->lastInsertId();
                    
                    // Add invoice item
                    $db->execute(
                        "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, amount)
                         VALUES (?, ?, 1, ?, ?)",
                        [$invoiceId, $sub['plan_name'] . ' - Monthly Subscription', $amount, $amount]
                    );
                    
                    // Record transaction
                    $db->execute(
                        "INSERT INTO transactions (invoice_id, payment_method_id, omise_charge_id, amount, currency, status)
                         VALUES (?, ?, ?, ?, 'THB', 'successful')",
                        [$invoiceId, $paymentMethod['id'], $charge['id'], $amount]
                    );
                    
                    // Update subscription
                    // If currently on trial, move to active
                    $newStatus = ($sub['status'] === 'trial') ? 'active' : 'active';
                    
                    // Calculate next billing date (1st of next month)
                    $nextBilling = date('Y-m-01', strtotime('+1 month'));
                    
                    $db->execute(
                        "UPDATE subscriptions 
                         SET status = ?,
                             trial_used = TRUE,
                             next_billing_date = ?,
                             current_period_start = ?,
                             current_period_end = ?,
                             updated_at = NOW()
                         WHERE id = ?",
                        [
                            $newStatus,
                            $nextBilling,
                            date('Y-m-01'),
                            date('Y-m-t'),
                            $sub['id']
                        ]
                    );
                    
                    // Update user trial status
                    $db->execute(
                        "UPDATE users SET trial_days_remaining = 0 WHERE id = ?",
                        [$sub['user_id']]
                    );
                    
                    $db->commit();
                    
                    $results['successful']++;
                    $results['details'][] = [
                        'user_id' => $sub['user_id'],
                        'email' => $sub['email'],
                        'status' => 'success',
                        'amount' => $amount,
                        'invoice_number' => $invoiceNumber,
                        'charge_id' => $charge['id']
                    ];
                    
                } catch (Exception $e) {
                    $db->rollback();
                    throw $e;
                }
                
            } else {
                // Charge failed
                throw new Exception('Charge not paid: ' . ($charge['failure_message'] ?? 'Unknown error'));
            }
            
        } catch (Exception $e) {
            // Charge failed
            $results['failed']++;
            $results['details'][] = [
                'user_id' => $sub['user_id'],
                'email' => $sub['email'],
                'status' => 'failed',
                'reason' => $e->getMessage()
            ];
            
            // Create failed invoice
            $db->execute(
                "INSERT INTO invoices (invoice_number, user_id, subscription_id, amount, tax, total, currency, status, billing_period_start, billing_period_end)
                 VALUES (?, ?, ?, ?, 0, ?, 'THB', 'failed', ?, ?)",
                [
                    $invoiceNumber,
                    $sub['user_id'],
                    $sub['id'],
                    $amount,
                    $amount,
                    date('Y-m-01'),
                    date('Y-m-t')
                ]
            );
            
            // Pause subscription
            $db->execute(
                "UPDATE subscriptions SET status = 'paused', updated_at = NOW() WHERE id = ?",
                [$sub['id']]
            );
            
            // TODO: Send email notification to customer
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Billing cycle completed',
        'data' => $results
    ]);
    
} catch (Exception $e) {
    error_log("Charge Subscription Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
