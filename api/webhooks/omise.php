<?php
/**
 * Omise Webhook Handler
 * POST /api/webhooks/omise
 * 
 * Handles payment events from Omise
 */

// Log all incoming requests
$rawInput = file_get_contents('php://input');
$webhookData = json_decode($rawInput, true);

// Log webhook for debugging
error_log("Omise Webhook Received: " . $rawInput);

try {
    $db = Database::getInstance();
    
    // Verify webhook signature (if OMISE_WEBHOOK_SECRET is set)
    $webhookSecret = getenv('OMISE_WEBHOOK_SECRET');
    if ($webhookSecret) {
        $signature = $_SERVER['HTTP_X_OMISE_SIGNATURE'] ?? '';
        $expectedSignature = hash_hmac('sha256', $rawInput, $webhookSecret);
        
        if ($signature !== $expectedSignature) {
            error_log("Omise Webhook: Invalid signature");
            Response::error('Invalid signature', 401);
        }
    }
    
    if (!$webhookData || !isset($webhookData['key'])) {
        Response::error('Invalid webhook data', 400);
    }
    
    $eventType = $webhookData['key'];
    $eventData = $webhookData['data'] ?? [];
    
    // Log the webhook event
    $db->execute(
        "INSERT INTO webhook_logs (provider, event_type, payload, created_at) 
         VALUES ('omise', ?, ?, NOW())",
        [$eventType, json_encode($webhookData)]
    );
    
    // Handle different event types
    switch ($eventType) {
        case 'charge.create':
case 'charge.complete':
            handleChargeEvent($db, $eventData, $eventType);
            break;
            
        case 'charge.failed':
        case 'charge.reverse':
        case 'charge.refund':
            handleChargeFailure($db, $eventData, $eventType);
            break;
            
        case 'customer.update':
            handleCustomerUpdate($db, $eventData);
            break;
            
        case 'transfer.create':
        case 'transfer.paid':
            // Handle transfer events if needed
            break;
            
        default:
            error_log("Omise Webhook: Unhandled event type: " . $eventType);
    }
    
    Response::success(['received' => true], 'Webhook processed');
    
} catch (Exception $e) {
    error_log("Omise Webhook Error: " . $e->getMessage());
    Response::error('Webhook processing failed', 500);
}

/**
 * Handle successful charge events
 */
function handleChargeEvent($db, $data, $eventType) {
    $chargeId = $data['id'] ?? null;
    $amount = ($data['amount'] ?? 0) / 100; // Convert from satang to baht
    $currency = $data['currency'] ?? 'THB';
    $status = $data['status'] ?? 'unknown';
    $paid = $data['paid'] ?? false;
    $customerId = $data['customer'] ?? null;
    
    if (!$chargeId) {
        return;
    }
    
    error_log("Omise Webhook: Processing charge {$chargeId}, status: {$status}, paid: " . ($paid ? 'true' : 'false'));
    
    // Find transaction by charge_id (for both credit card and PromptPay)
    $transaction = $db->queryOne(
        "SELECT * FROM transactions WHERE omise_charge_id = ? LIMIT 1",
        [$chargeId]
    );
    
    if (!$transaction) {
        error_log("Omise Webhook: Transaction not found for charge: {$chargeId}");
        
        // For backward compatibility: Try finding user by customer_id
        if ($customerId) {
            $payment = $db->queryOne(
                "SELECT user_id FROM payment_methods WHERE omise_customer_id = ? LIMIT 1",
                [$customerId]
            );
            
            if ($payment) {
                $userId = $payment['user_id'];
                
                // Create new transaction
                $db->execute(
                    "INSERT INTO transactions 
                     (invoice_id, payment_method_id, omise_charge_id, amount, currency, status, created_at)
                     VALUES (NULL, NULL, ?, ?, ?, ?, NOW())",
                    [$chargeId, $amount, $currency, $status]
                );
            }
        }
        return;
    }
    
    // Update transaction status
    if ($paid && ($status === 'successful' || $status === 'complete')) {
        $db->execute(
            "UPDATE transactions 
             SET status = 'successful', 
                 metadata = JSON_SET(metadata, '$.webhook_received_at', NOW())
             WHERE omise_charge_id = ?",
            [$chargeId]
        );
        
        error_log("Omise Webhook: Transaction {$chargeId} marked as successful");
        
        // Update invoice status if linked
        if ($transaction['invoice_id']) {
            $db->execute(
                "UPDATE invoices 
                 SET status = 'paid', paid_at = NOW() 
                 WHERE id = ? AND status IN ('pending', 'failed')",
                [$transaction['invoice_id']]
            );
            
            error_log("Omise Webhook: Invoice {$transaction['invoice_id']} marked as paid");
            
            // Log activity
            $metadata = json_decode($transaction['metadata'], true);
            $userId = $metadata['user_id'] ?? null;
            
            if ($userId) {
                $db->execute(
                    "INSERT INTO activity_logs (user_id, action, resource_type, resource_id, details, ip_address, user_agent) 
                     VALUES (?, 'webhook_payment_complete', 'invoice', ?, ?, 'omise-webhook', 'Omise Webhook')",
                    [$userId, $transaction['invoice_id'], json_encode(['charge_id' => $chargeId, 'amount' => $amount])]
                );
            }
        }
    } else {
        // Update status to pending or other
        $db->execute(
            "UPDATE transactions 
             SET status = ?, updated_at = NOW()
             WHERE omise_charge_id = ?",
            [$status, $chargeId]
        );
    }
}

/**
 * Handle failed charge events
 */
function handleChargeFailure($db, $data, $eventType) {
    $chargeId = $data['id'] ?? null;
    $failureMessage = $data['failure_message'] ?? '';
    
    if (!$chargeId) {
        return;
    }
    
    error_log("Omise Webhook: Processing failure for charge {$chargeId}: {$failureMessage}");
    
    // Find transaction
    $transaction = $db->queryOne(
        "SELECT * FROM transactions WHERE omise_charge_id = ? LIMIT 1",
        [$chargeId]
    );
    
    if (!$transaction) {
        error_log("Omise Webhook: Transaction not found for failed charge: {$chargeId}");
        return;
    }
    
    // Update transaction status
    $db->execute(
        "UPDATE transactions 
         SET status = 'failed',
             error_message = ?,
             updated_at = NOW()
         WHERE omise_charge_id = ?",
        [$failureMessage, $chargeId]
    );
    
    error_log("Omise Webhook: Transaction {$chargeId} marked as failed");
    
    // Update invoice status if linked
    if ($transaction['invoice_id']) {
        $db->execute(
            "UPDATE invoices 
             SET status = 'failed' 
             WHERE id = ? AND status = 'pending'",
            [$transaction['invoice_id']]
        );
        
        error_log("Omise Webhook: Invoice {$transaction['invoice_id']} marked as failed");
    }
}

/**
 * Handle customer update events
 */
function handleCustomerUpdate($db, $data) {
    $customerId = $data['id'] ?? null;
    
    if (!$customerId) {
        return;
    }
    
    // Update customer data if needed
    // This is a placeholder - implement based on your requirements
    error_log("Omise Webhook: Customer updated: " . $customerId);
}
