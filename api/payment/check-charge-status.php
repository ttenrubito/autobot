<?php
/**
 * Check Charge Status
 * GET /api/payment/check-charge-status.php?charge_id=xxx
 * 
 * Retrieves the current status of a charge (used for polling PromptPay payment status)
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/OmiseClient.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    $chargeId = $_GET['charge_id'] ?? '';
    
    if (empty($chargeId)) {
        Response::error('charge_id is required', 400);
    }
    
    // Verify this charge belongs to the current user
    // Check in transactions table
    $transaction = $db->queryOne(
        "SELECT t.*, 
                JSON_EXTRACT(t.metadata, '$.user_id') as meta_user_id
         FROM transactions t
         WHERE t.omise_charge_id = ?
         LIMIT 1",
        [$chargeId]
    );
    
    // If transaction exists, verify it belongs to current user
    if ($transaction) {
        $metaUserId = $transaction['meta_user_id'];
        // Remove quotes from JSON string value
        $metaUserId = trim($metaUserId, '"');
        
        if ($metaUserId != $userId) {
            Response::error('Unauthorized access to charge', 403);
        }
    }
    
    $omise = new OmiseClient();
    
    // Retrieve charge from Omise
    $charge = $omise->retrieveCharge($chargeId);
    
    if (!$charge) {
        Response::error('Charge not found', 404);
    }
    
    $status = $charge['status'] ?? 'unknown';
    $paid = $charge['paid'] ?? false;
    $amount = isset($charge['amount']) ? ($charge['amount'] / 100) : 0;
    
    // Update transaction status in database if it exists
    if ($transaction) {
        $newStatus = 'pending';
        if ($paid && $status === 'successful') {
            $newStatus = 'successful';
            
            // Update invoice status if linked
            if ($transaction['invoice_id']) {
                $db->execute(
                    "UPDATE invoices 
                     SET status = 'paid', paid_at = NOW() 
                     WHERE id = ? AND status = 'pending'",
                    [$transaction['invoice_id']]
                );
                
                // Log invoice payment
                $db->execute(
                    "INSERT INTO activity_logs (user_id, action, resource_type, resource_id, details, ip_address, user_agent) 
                     VALUES (?, 'pay_invoice_promptpay', 'invoice', ?, ?, ?, ?)",
                    [
                        $userId, 
                        $transaction['invoice_id'],
                        json_encode(['charge_id' => $chargeId, 'amount' => $amount]),
                        Auth::getIpAddress(), 
                        Auth::getUserAgent()
                    ]
                );
            }
        } elseif ($status === 'failed' || $status === 'expired') {
            $newStatus = 'failed';
        }
        
        $db->execute(
            "UPDATE transactions 
             SET status = ?,
                 metadata = JSON_SET(metadata, '$.omise_status', ?)
             WHERE omise_charge_id = ?",
            [$newStatus, $status, $chargeId]
        );
    }
    
    Response::success([
        'charge_id' => $chargeId,
        'status' => $status,
        'paid' => $paid,
        'amount' => $amount,
        'currency' => $charge['currency'] ?? 'THB',
        'paid_at' => $charge['paid_at'] ?? null,
        'failure_code' => $charge['failure_code'] ?? null,
        'failure_message' => $charge['failure_message'] ?? null,
        'transaction_id' => $transaction['id'] ?? null
    ]);
    
} catch (Exception $e) {
    error_log("Check Charge Status Error: " . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
