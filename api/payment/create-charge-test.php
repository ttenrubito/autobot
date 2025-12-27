<?php
/**
 * Create Charge (Test Mode - No Auth Required)
 * For testing only
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/OmiseClient.php';
require_once __DIR__ . '/../../includes/Response.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $db = Database::getInstance();
    $omise = new OmiseClient();
    
    // Get user_id from query string
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 2;
    
    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    
    $amount = $input['amount'] ?? 0;
    $description = $input['description'] ?? 'Test payment';
    
    if ($amount < 20) {
        Response::error('Amount must be at least 20 baht', 400);
    }
    
    // Get default payment method
    $paymentMethod = $db->queryOne(
        "SELECT * FROM payment_methods WHERE user_id = ? AND is_default = TRUE LIMIT 1",
        [$userId]
    );
    
    if (!$paymentMethod) {
        Response::error('No default payment method found. Please add a card first.', 404);
    }
    
    // Create charge
    $charge = $omise->createCharge(
        $amount,
        'THB',
        $paymentMethod['omise_customer_id'],
        $paymentMethod['omise_card_id'],
        $description
    );
    
    if (!$charge || !isset($charge['id'])) {
        throw new Exception('Failed to create charge');
    }
    
    $chargeId = $charge['id'];
    $status = $charge['status'] ?? 'pending';
    $chargeAmount = ($charge['amount'] ?? 0) / 100;
    
    // Save transaction
    $db->execute(
        "INSERT INTO transactions 
         (invoice_id, payment_method_id, omise_charge_id, amount, currency, status, created_at)
         VALUES (NULL, ?, ?, ?, 'THB', ?, NOW())",
        [$paymentMethod['id'], $chargeId, $chargeAmount, $status]
    );
    
    $transactionId = $db->lastInsertId();
    
    Response::success([
        'transaction_id' => $transactionId,
        'omise_charge_id' => $chargeId,
        'amount' => $chargeAmount,
        'status' => $status,
        'charge_details' => $charge
    ], 'Charge created successfully');
    
} catch (Exception $e) {
    error_log("Create Charge Test Error: " . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
