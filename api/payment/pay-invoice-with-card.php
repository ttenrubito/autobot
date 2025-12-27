<?php
/**
 * Pay Invoice with Saved Card
 * POST /api/payment/pay-invoice-with-card.php
 * 
 * Charges a pending invoice using the customer's saved credit card
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Validator.php';
require_once __DIR__ . '/../../includes/OmiseClient.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    // Read JSON body
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
    
    $validator = new Validator();
    
    $invoiceId = $input['invoice_id'] ?? null;
    
    $validator->required('invoice_id', $invoiceId, 'Invoice ID');
    
    if ($validator->fails()) {
        Response::validationError($validator->getErrors());
    }
    
    // Get and validate invoice
    $invoice = $db->queryOne(
        "SELECT i.*, sp.name as plan_name
         FROM invoices i
         LEFT JOIN subscriptions s ON i.subscription_id = s.id
         LEFT JOIN subscription_plans sp ON s.plan_id = sp.id
         WHERE i.id = ? AND i.user_id = ?
         LIMIT 1",
        [$invoiceId, $userId]
    );
    
    if (!$invoice) {
        Response::error('Invoice not found', 404);
    }
    
    if ($invoice['status'] !== 'pending' && $invoice['status'] !== 'failed') {
        Response::error('Invoice has already been paid or is not available for payment', 400);
    }
    
    // Get user's default payment method
    $paymentMethod = $db->queryOne(
        "SELECT * FROM payment_methods 
         WHERE user_id = ? AND is_default = TRUE 
         LIMIT 1",
        [$userId]
    );
    
    if (!$paymentMethod) {
        Response::error('No default payment method found. Please add a card first.', 404);
    }
    
    $omise = new OmiseClient();
    
    // Create charge
    $description = "Invoice #{$invoice['invoice_number']}";
    if ($invoice['plan_name']) {
        $description .= " - {$invoice['plan_name']}";
    }
    
    $chargeResult = $omise->createCharge(
        $invoice['total'],
        $invoice['currency'] ?? 'THB',
        $paymentMethod['omise_customer_id'],
        $paymentMethod['omise_card_id'],
        $description
    );
    
    if (!$chargeResult || !isset($chargeResult['id'])) {
        Response::error('Failed to create charge', 500);
    }
    
    $chargeId = $chargeResult['id'];
    $status = $chargeResult['status'] ?? 'pending';
    $paid = $chargeResult['paid'] ?? false;
    
    // Save transaction
    $db->execute(
        "INSERT INTO transactions 
         (invoice_id, payment_method_id, omise_charge_id, amount, currency, status, metadata, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [
            $invoiceId,
            $paymentMethod['id'],
            $chargeId,
            $invoice['total'],
            $invoice['currency'] ?? 'THB',
            $status,
            json_encode([
                'payment_type' => 'credit_card',
                'user_id' => $userId,
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoice['invoice_number'],
                'description' => $description
            ])
        ]
    );
    
    $transactionId = $db->lastInsertId();
    
    // If charge successful, update invoice
    if ($paid && ($status === 'successful' || $status === 'pending')) {
        $db->execute(
            "UPDATE invoices 
             SET status = 'paid', paid_at = NOW() 
             WHERE id = ?",
            [$invoiceId]
        );
        
        // Log activity
        $db->execute(
            "INSERT INTO activity_logs (user_id, action, resource_type, resource_id, ip_address, user_agent) 
             VALUES (?, 'pay_invoice_card', 'invoice', ?, ?, ?)",
            [$userId, $invoiceId, Auth::getIpAddress(), Auth::getUserAgent()]
        );
    } else {
        // Update invoice to failed if charge failed
        $db->execute(
            "UPDATE invoices SET status = 'failed' WHERE id = ?",
            [$invoiceId]
        );
    }
    
    Response::success([
        'transaction_id' => $transactionId,
        'charge_id' => $chargeId,
        'invoice_id' => $invoiceId,
        'status' => $status,
        'paid' => $paid,
        'amount' => $invoice['total']
    ], $paid ? 'Invoice paid successfully' : 'Payment processing');
    
} catch (Exception $e) {
    error_log("Pay Invoice with Card Error: " . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
