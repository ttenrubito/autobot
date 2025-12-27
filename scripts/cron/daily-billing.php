#!/usr/bin/env php
<?php
/**
 * Daily Billing Cron Job
 * Run every day at 2:00 AM
 * 
 * Crontab entry:
 * 0 2 * * * /usr/bin/php /opt/lampp/htdocs/autobot/scripts/cron/daily-billing.php
 */

// Set working directory
chdir(__DIR__ . '/../..');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/OmiseClient.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting daily billing cycle...\n";

try {
    $db = new Database();
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
    
    echo "Found " . count($subscriptions) . " subscriptions to bill\n";
    
    $successful = 0;
    $failed = 0;
    
    foreach ($subscriptions as $sub) {
        echo "\nProcessing user: {$sub['email']} (ID: {$sub['user_id']})\n";
        
        // Get payment method
        $paymentMethod = $db->queryOne(
            "SELECT * FROM payment_methods WHERE user_id = ? AND is_default = TRUE LIMIT 1",
            [$sub['user_id']]
        );
        
        if (!$paymentMethod) {
            echo "  ❌ No payment method found - Pausing subscription\n";
            $db->execute("UPDATE subscriptions SET status = 'paused' WHERE id = ?", [$sub['id']]);
            $failed++;
            continue;
        }
        
        $amount = (float)$sub['monthly_price'];
        
        // 🔒 SAFEGUARD 1: Check if invoice already exists for this period
        $billingStart = date('Y-m-01');
        $billingEnd = date('Y-m-t');
        
        $existingInvoice = $db->queryOne(
            "SELECT id FROM invoices 
             WHERE user_id = ? 
             AND subscription_id = ? 
             AND billing_period_start = ? 
             AND billing_period_end = ?",
            [$sub['user_id'], $sub['id'], $billingStart, $billingEnd]
        );
        
        if ($existingInvoice) {
            echo "  ⚠️  Invoice already exists for this period - SKIPPING\n";
            continue;
        }
        
        // 🔒 SAFEGUARD 2: Check for ANY pending invoices from previous periods
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
            
            echo "  ⏳ Found " . count($pendingInvoices) . " pending invoice(s) from previous period(s)\n";
            echo "     Oldest: {$oldestPending['invoice_number']} ({$daysOverdue} days old)\n";
            
            // Grace period: 7 days
            if ($daysOverdue > 7) {
                echo "  ⏸️  SUSPENDING subscription - Payment overdue ({$daysOverdue} days)\n";
                $db->execute(
                    "UPDATE subscriptions SET status = 'suspended', updated_at = NOW() WHERE id = ?",
                    [$sub['id']]
                );
            } else {
                echo "  ⏳ Skipping new invoice - Pending invoice within grace period\n";
            }
            
            $failed++;
            continue; // Skip to next subscription
        }
        
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
            echo "  💳 Charging {$amount} THB...\n";
            $charge = $omise->createCharge(
                $amount,
                'thb',
                $paymentMethod['omise_customer_id'],
                $paymentMethod['omise_card_id'],
                'Monthly subscription - ' . $sub['plan_name']
            );
            
            if ($charge['paid']) {
                echo "  ✅ Charge successful: {$charge['id']}\n";
                
                $db->beginTransaction();
                
                try {
                    // 🔒 SAFEGUARD 2: Use try-catch to handle duplicate key errors
                    // Create invoice
                    $db->execute(
                        "INSERT INTO invoices (invoice_number, user_id, subscription_id, amount, tax, total, currency, status, paid_at, billing_period_start, billing_period_end)
                         VALUES (?, ?, ?, ?, 0, ?, 'THB', 'paid', NOW(), ?, ?)",
                        [$invoiceNumber, $sub['user_id'], $sub['id'], $amount, $amount, $billingStart, $billingEnd]
                    );
                    
                    $invoiceId = $db->lastInsertId();
                    
                } catch (PDOException $e) {
                    // If duplicate key error (code 23000), skip this subscription
                    if ($e->getCode() == 23000) {
                        echo "  ⚠️  Duplicate invoice detected (database constraint) - SKIPPING\n";
                        $db->rollback();
                        continue 2; // Skip to next subscription in outer loop
                    }
                    throw $e; // Re-throw other errors
                }
                
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
                $newStatus = ($sub['status'] === 'trial') ? 'active' : 'active';
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
                    [$newStatus, $nextBilling, date('Y-m-01'), date('Y-m-t'), $sub['id']]
                );
                
                // Update user
                $db->execute("UPDATE users SET trial_days_remaining = 0 WHERE id = ?", [$sub['user_id']]);
                
                $db->commit();
                
                echo "  📧 TODO: Send success email to {$sub['email']}\n";
                $successful++;
                
            } else {
                throw new Exception('Charge not paid');
            }
            
        } catch (Exception $e) {
            echo "  ❌ Charge failed: " . $e->getMessage() . "\n";
            
            // Create failed invoice
            $db->execute(
                "INSERT INTO invoices (invoice_number, user_id, subscription_id, amount, tax, total, currency, status, billing_period_start, billing_period_end)
                 VALUES (?, ?, ?, ?, 0, ?, 'THB', 'failed', ?, ?)",
                [$invoiceNumber, $sub['user_id'], $sub['id'], $amount, $amount, date('Y-m-01'), date('Y-m-t')]
            );
            
            // Pause subscription
            $db->execute("UPDATE subscriptions SET status = 'paused', updated_at = NOW() WHERE id = ?", [$sub['id']]);
            
            echo "  📧 TODO: Send payment failed email to {$sub['email']}\n";
            $failed++;
        }
    }
    
    echo "\n=== Billing Cycle Complete ===\n";
    echo "✅ Successful: {$successful}\n";
    echo "❌ Failed: {$failed}\n";
    echo "Total processed: " . count($subscriptions) . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] Done\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
