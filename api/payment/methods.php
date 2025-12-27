<?php
/**
 * Payment Methods List API Endpoint
 * GET /api/payment/methods
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();

    $methods = $db->query(
        "SELECT id, card_brand, card_last4, card_expiry_month, card_expiry_year, 
                is_default, created_at
         FROM payment_methods
         WHERE user_id = ?
         ORDER BY is_default DESC, created_at DESC",
        [$userId]
    );

    Response::success($methods);

} catch (Exception $e) {
    error_log("Payment Methods Error: " . $e->getMessage());
    Response::error('Failed to get payment methods', 500);
}
