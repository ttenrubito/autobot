<?php
/**
 * Add Payment Card API Endpoint
 * POST /api/payment/add-card
 * Integrates with Omise
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Validator.php';
require_once __DIR__ . '/../../includes/OmiseClient.php';

Auth::require();

try {
    $db = Database::getInstance();
    
    // Debug: Log authentication details
    error_log("Add Card - JWT Token: " . (JWT::getBearerToken() ? "present" : "missing"));
    error_log("Add Card - Auth check: " . (Auth::check() ? "passed" : "failed"));
    
    $userId = Auth::id();
    error_log("Add Card - User ID: " . ($userId ? $userId : "NULL"));
    
    if (!$userId) {
        error_log("Add Card - Authentication failed: No user ID");
        Response::error('Authentication failed', 401);
    }

    // Read JSON body: { omise_token: string, set_default?: bool }
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];

    $validator = new Validator();
    
    // Omise token from Omise.js
    $omiseToken = $input['omise_token'] ?? '';
    $setDefault = isset($input['set_default']) ? (bool)$input['set_default'] : false;

    $validator->required('omise_token', $omiseToken, 'Card token');

    if ($validator->fails()) {
        Response::validationError($validator->getErrors());
    }

    // Get user data from database
    $user = $db->queryOne(
        "SELECT id, email, full_name FROM users WHERE id = ?",
        [$userId]
    );

    if (!$user) {
        Response::error('User not found', 404);
    }

    $omise = new OmiseClient();

    // Check if user has Omise customer ID
    $existingPayment = $db->queryOne(
        "SELECT omise_customer_id FROM payment_methods WHERE user_id = ? LIMIT 1",
        [$userId]
    );

    $omiseCustomerId = $existingPayment['omise_customer_id'] ?? null;

    // Create or update Omise customer
    if (!$omiseCustomerId) {
        // Create new customer
        $customer = $omise->createCustomer(
            $user['email'],
            $user['full_name'] ?? $user['email'],
            $omiseToken
        );
        $omiseCustomerId = $customer['id'];
        $card = $customer['cards']['data'][0];
    } else {
        // Add card to existing customer
        $customer = $omise->addCard($omiseCustomerId, $omiseToken);
        $cards = $customer['cards']['data'];
        $card = end($cards); // Get the last card (newly added)
    }

    // Normalize default flag to integer 0/1 for DB
    $isDefaultValue = $setDefault ? 1 : 0;

    // If set as default, unset other defaults
    if ($setDefault) {
        $db->execute(
            "UPDATE payment_methods SET is_default = 0 WHERE user_id = ?",
            [$userId]
        );
    }

    // Save card to database
    $db->execute(
        "INSERT INTO payment_methods 
         (user_id, omise_customer_id, omise_card_id, card_brand, card_last4, 
          card_expiry_month, card_expiry_year, is_default)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $userId,
            $omiseCustomerId,
            $card['id'],
            $card['brand'],
            $card['last_digits'],
            $card['expiration_month'],
            $card['expiration_year'],
            $isDefaultValue
        ]
    );

    $paymentMethodId = $db->lastInsertId();

    // Log activity
    $db->execute(
        "INSERT INTO activity_logs (user_id, action, resource_type, resource_id, ip_address, user_agent) 
         VALUES (?, 'add_payment_method', 'payment_method', ?, ?, ?)",
        [$userId, $paymentMethodId, Auth::getIpAddress(), Auth::getUserAgent()]
    );

    Response::success([
        'id' => $paymentMethodId,
        'card_brand' => $card['brand'],
        'card_last4' => $card['last_digits']
    ], 'Payment method added successfully');

} catch (Exception $e) {
    error_log("Add Card Error: " . $e->getMessage());
    Response::error('Failed to add payment method', 500);
}
