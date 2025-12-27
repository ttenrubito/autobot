<?php
/**
 * Create Charge API Endpoint
 * POST /api/payment/create-charge
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/OmiseClient.php';
require_once __DIR__ . '/../../includes/Validator.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    $validator = new Validator();
    
    $amount = $input['amount'] ?? 0;
    $description = $input['description'] ?? 'Test payment';
    $paymentMethodId = $input['payment_method_id'] ?? null;
    
    $validator->required('amount', $amount, 'Amount');
    
    if ($validator->fails()) {
        Response::validationError($validator->getErrors());
    }
    
    // Get payment method
    if ($paymentMethodId) {
        $paymentMethod = $db->queryOne(
            "SELECT * FROM payment_methods WHERE id = ? AND user_id = ? LIMIT 1",
            [$paymentMethodId, $userId]
        );
    } else {
        // Get default payment method
        $paymentMethod = $db->queryOne(
            "SELECT * FROM payment_methods WHERE user_id = ? AND is_default = TRUE LIMIT 1",
            [$userId]
        );
    }
    
    if (!$paymentMethod) {
        Response::error('No payment method found', 404);
    }
    
    $omise = new OmiseClient();
    
    // Create charge
    $charge = $omise->createCharge(
        $amount,
        'THB',
        $paymentMethod['omise_customer_id'],
        $paymentMethod['omise_card_id'],
        $description
    );
    
    if (!$charge || !isset($charge['id'])) {
        Response::error('Failed to create charge', 500);
    }
    
    $chargeId = $charge['id'];
    $status = $charge['status'] ?? 'pending';
    $chargeAmount = ($charge['amount'] ?? 0) / 100; // Convert from satang to baht
    
    // Save transaction
    $db->execute(
        "INSERT INTO transactions 
         (user_id, amount, currency, status, omise_charge_id, transaction_type, description, created_at)
         VALUES (?, ?, 'THB', ?, ?, 'payment', ?, NOW())",
        [$userId, $chargeAmount, $status, $chargeId, $description]
    );
    
    $transactionId = $db->lastInsertId();
    
    // Log activity
    $db->execute(
        "INSERT INTO activity_logs (user_id, action, resource_type, resource_id, ip_address, user_agent) 
         VALUES (?, 'create_charge', 'transaction', ?, ?, ?)",
        [$userId, $transactionId, Auth::getIpAddress(), Auth::getUserAgent()]
    );
    
    Response::success([
        'transaction_id' => $transactionId,
        'omise_charge_id' => $chargeId,
        'amount' => $chargeAmount,
        'status' => $status,
        'charge_details' => $charge
    ], 'Charge created successfully');
    
} catch (Exception $e) {
    error_log("Create Charge Error: " . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
