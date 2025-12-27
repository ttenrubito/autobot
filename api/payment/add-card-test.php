<?php
/**
 * Add Card (Test Mode - No Auth Required)
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
    
    $omiseToken = $input['omise_token'] ?? '';
    $setDefault = $input['set_default'] ?? true;
    
    if (empty($omiseToken)) {
        Response::error('Omise token is required', 400);
    }
    
    // Get user
    $user = $db->queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
    if (!$user) {
        Response::error('User not found', 404);
    }
    
    // Check if user already has a customer ID
    $existingCustomer = $db->queryOne(
        "SELECT omise_customer_id FROM payment_methods WHERE user_id = ? LIMIT 1",
        [$userId]
    );
    
    if ($existingCustomer && $existingCustomer['omise_customer_id']) {
        // Add card to existing customer
        $customerId = $existingCustomer['omise_customer_id'];
        $result = $omise->addCard($customerId, $omiseToken);
        $cardId = $result['id'];
    } else {
        // Create new customer with card
        $result = $omise->createCustomer(
            $user['email'],
            $user['full_name'] ?? 'Customer',
            $omiseToken
        );
        $customerId = $result['id'];
        $cardId = $result['cards']['data'][0]['id'] ?? null;
    }
    
    if (!$cardId) {
        throw new Exception('Failed to get card ID from Omise');
    }
    
    // Get card details from Omise response
    $card = null;
    if (isset($result['cards']['data'][0])) {
        $card = $result['cards']['data'][0];
    } elseif (isset($result['brand'])) {
        $card = $result;
    }
    
    $cardBrand = $card['brand'] ?? 'Unknown';
    $cardLast4 = $card['last_digits'] ?? '0000';
    $expiryMonth = $card['expiration_month'] ?? 1;
    $expiryYear = $card['expiration_year'] ?? 2025;
    
    // Unset other defaults if this should be default
    if ($setDefault) {
        $db->execute(
            "UPDATE payment_methods SET is_default = FALSE WHERE user_id = ?",
            [$userId]
        );
    }
    
    // Save to database
    $db->execute(
        "INSERT INTO payment_methods 
         (user_id, omise_customer_id, omise_card_id, card_brand, card_last4, 
          card_expiry_month, card_expiry_year, is_default, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [
            $userId,
            $customerId,
            $cardId,
            $cardBrand,
            $cardLast4,
            $expiryMonth,
            $expiryYear,
            $setDefault ? 1 : 0
        ]
    );
    
    $paymentMethodId = $db->lastInsertId();
    
    Response::success([
        'id' => $paymentMethodId,
        'card_brand' => $cardBrand,
        'card_last4' => $cardLast4,
        'is_default' => $setDefault
    ], 'Payment method added successfully');
    
} catch (Exception $e) {
    error_log("Add Card Test Error: " . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
