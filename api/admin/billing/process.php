<?php
/**
 * Admin Billing API - Process Subscriptions Manually
 * POST /api/admin/billing/process
 * 
 * Processes all subscriptions that are due for billing today
 */

require_once __DIR__ . '/../../../includes/AdminAuth.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Response.php';
require_once __DIR__ . '/../../../includes/OmiseClient.php';

// Require admin authentication
AdminAuth::require();

try {
    $db = Database::getInstance();
    $omise = new OmiseClient();
    
    // Get all active subscriptions that are due for billing today or past due
    $dueSubscriptions = $db->query("
        SELECT s.*, u.email, u.full_name, 
               pm.omise_customer_id, pm.omise_card_id,
               pm.card_brand, pm.card_last4,
               p.name as package_name, p.monthly_price, p.billing_period_days
        FROM subscriptions s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN payment_methods pm ON u.id = pm.user_id AND pm.is_default = 1
        LEFT JOIN subscription_plans p ON s.plan_id = p.id
        WHERE s.status = 'active'
        AND s.next_billing_date <= CURDATE()
    ");
    
    $results = [
        'total' => count($dueSubscriptions),
        'successful' => 0,
        'failed' => 0,
        'details' => []
    ];
    
    foreach ($dueSubscriptions as $sub) {
        // reset invoice id for this iteration to avoid leaking previous value into catch
        $invoiceId = null;

        $detail = [
            'user_id' => $sub['user_id'],
            'email' => $sub['email'],
            'package' => $sub['package_name'],
            'amount' => $sub['monthly_price']
        ];
        
        try {
            // 0) Check if invoice already exists for this billing period
            $existingInvoice = $db->queryOne(
                'SELECT id, status FROM invoices
                  WHERE user_id = :user_id
                    AND subscription_id = :subscription_id
                    AND billing_period_start = :start
                    AND billing_period_end = :end
                  LIMIT 1',
                [
                    ':user_id' => $sub['user_id'],
                    ':subscription_id' => $sub['id'],
                    ':start' => $sub['current_period_start'],
                    ':end' => $sub['current_period_end'],
                ]
            );

            if ($existingInvoice) {
                // Invoice already exists for this period - skip
                $detail['status'] = 'skipped_existing_invoice';
                $detail['invoice_id'] = $existingInvoice['id'];
                $detail['invoice_status'] = $existingInvoice['status'];
                $results['details'][] = $detail;
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
                [$sub['user_id'], $sub['id'], $sub['current_period_start']]
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
                    
                    $detail['status'] = 'suspended';
                    $detail['reason'] = "Payment overdue - {$daysOverdue} days. Subscription suspended.";
                    $detail['pending_count'] = count($pendingInvoices);
                    $detail['oldest_invoice'] = $oldestPending['invoice_number'];
                    $results['details'][] = $detail;
                    continue;
                } else {
                    $detail['status'] = 'skipped_pending';
                    $detail['reason'] = "Pending invoice exists within grace period ({$daysOverdue} days)";
                    $detail['pending_count'] = count($pendingInvoices);
                    $detail['oldest_invoice'] = $oldestPending['invoice_number'];
                    $results['details'][] = $detail;
                    continue;
                }
            }
            
            // 1) Create invoice first (always create, even without payment method)
            $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad($sub['user_id'], 5, '0', STR_PAD_LEFT) . '-' . $sub['id'];

            // Calculate due date: billing_period_end + grace_period (3 days)
            // This is more logical than fixed +7 days from today
            $gracePeriodDays = 3;
            $dueDate = date('Y-m-d', strtotime($sub['current_period_end'] . ' +' . $gracePeriodDays . ' days'));

            $db->execute('INSERT INTO invoices (user_id, invoice_number, subscription_id, amount, tax, total, status, billing_period_start, billing_period_end, due_date, created_at) VALUES (:user_id, :invoice_number, :subscription_id, :amount, :tax, :total, :status, :billing_period_start, :billing_period_end, :due_date, :created_at)', [
                ':user_id' => $sub['user_id'],
                ':invoice_number' => $invoiceNumber,
                ':subscription_id' => $sub['id'],
                ':amount' => $sub['monthly_price'],
                ':tax' => 0,
                ':total' => $sub['monthly_price'],
                ':status' => 'pending',
                ':billing_period_start' => $sub['current_period_start'],
                ':billing_period_end' => $sub['current_period_end'],
                ':due_date' => $dueDate,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
            $invoiceId = $db->lastInsertId();
            
            // 2) Check if customer has saved payment method
            if (!$sub['omise_customer_id']) {
                // No saved card - leave invoice as pending for manual payment (PromptPay)
                $detail['status'] = 'pending_manual_payment';
                $detail['message'] = 'Invoice created - awaiting manual payment (PromptPay)';
                $detail['invoice_number'] = $invoiceNumber;
                $detail['invoice_id'] = $invoiceId;
                $detail['due_date'] = date('Y-m-d', strtotime('+7 days'));
                
                // Update subscription period but keep status as active
                // Customer can still use service until due date
                $billingPeriodDays = $sub['billing_period_days'] ?? 30;
                $newPeriodStart = $sub['current_period_end'];
                $newPeriodEnd = date('Y-m-d', strtotime($newPeriodStart . ' +' . $billingPeriodDays . ' days'));
                $newBillingDate = date('Y-m-d', strtotime($newPeriodEnd . ' +1 day'));

                $db->execute('UPDATE subscriptions SET current_period_start = :start, current_period_end = :end, next_billing_date = :next, updated_at = :updated_at WHERE id = :id', [
                    ':start' => $newPeriodStart,
                    ':end' => $newPeriodEnd,
                    ':next' => $newBillingDate,
                    ':updated_at' => date('Y-m-d H:i:s'),
                    ':id' => $sub['id'],
                ]);
                
                $results['details'][] = $detail;
                continue; // Skip to next subscription
            }

            // 3) Customer has saved card - attempt auto charge
            $description = "Subscription: {$sub['package_name']} - {$invoiceNumber}";
            $chargeResult = $omise->createCharge(
                $sub['monthly_price'],
                'THB',
                $sub['omise_customer_id'],
                $sub['omise_card_id'],
                $description
            );

            if ($chargeResult['status'] === 'successful' || $chargeResult['status'] === 'pending') {
                // Auto charge successful
                $db->execute(
                    'INSERT INTO transactions (invoice_id, payment_method_id, omise_charge_id, amount, currency, status, error_message, metadata, created_at) VALUES (:invoice_id, :payment_method_id, :omise_charge_id, :amount, :currency, :status, :error_message, :metadata, :created_at)',
                    [
                        ':invoice_id' => $invoiceId,
                        ':payment_method_id' => $sub['payment_method_id'] ?? null,
                        ':omise_charge_id' => $chargeResult['id'],
                        ':amount' => $sub['monthly_price'],
                        ':currency' => 'THB',
                        ':status' => $chargeResult['status'],
                        ':error_message' => null,
                        ':metadata' => json_encode([
                            'subscription_id' => $sub['id'],
                            'user_id' => $sub['user_id'],
                            'plan_id' => $sub['plan_id'] ?? null,
                            'invoice_id' => $invoiceId,
                            'payment_method' => 'credit_card'
                        ], JSON_UNESCAPED_UNICODE),
                        ':created_at' => date('Y-m-d H:i:s'),
                    ]
                );

                // Mark invoice as paid
                $db->execute('UPDATE invoices SET status = :status, paid_at = :paid_at WHERE id = :id', [
                    ':status' => 'paid',
                    ':paid_at' => date('Y-m-d H:i:s'),
                    ':id' => $invoiceId,
                ]);

                // Update subscription billing period
                $billingPeriodDays = $sub['billing_period_days'] ?? 30;
                $newPeriodStart = $sub['current_period_end'];
                $newPeriodEnd = date('Y-m-d', strtotime($newPeriodStart . ' +' . $billingPeriodDays . ' days'));
                $newBillingDate = date('Y-m-d', strtotime($newPeriodEnd . ' +1 day'));

                $db->execute('UPDATE subscriptions SET current_period_start = :start, current_period_end = :end, next_billing_date = :next, updated_at = :updated_at WHERE id = :id', [
                    ':start' => $newPeriodStart,
                    ':end' => $newPeriodEnd,
                    ':next' => $newBillingDate,
                    ':updated_at' => date('Y-m-d H:i:s'),
                    ':id' => $sub['id'],
                ]);

                $detail['status'] = 'success';
                $detail['payment_method'] = 'credit_card_auto';
                $detail['invoice_number'] = $invoiceNumber;
                $detail['transaction_id'] = $chargeResult['id'];
                $results['successful']++;

            } else {
                throw new Exception('Charge failed: ' . ($chargeResult['failure_message'] ?? 'Unknown error'));
            }

        } catch (Exception $e) {
            // Mark subscription as paused
            $db->execute('UPDATE subscriptions SET status = :status, updated_at = :updated_at WHERE id = :id', [
                ':status' => 'paused',
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => $sub['id'],
            ]);

            // Update invoice status
            if (!empty($invoiceId)) {
                $db->execute('UPDATE invoices SET status = :status WHERE id = :id', [
                    ':status' => 'failed',
                    ':id' => $invoiceId,
                ]);
            }

            $detail['status'] = 'failed';
            $detail['error'] = $e->getMessage();
            $results['failed']++;

            // Log error
            error_log("Billing failed for user {$sub['user_id']}: " . $e->getMessage());
        }
        
        $results['details'][] = $detail;
    }
    
    Response::success($results, 'Billing process completed');
    
} catch (Exception $e) {
    error_log("Billing process error: " . $e->getMessage());
    Response::error('Failed to process billing: ' . $e->getMessage(), 500);
}
?>
