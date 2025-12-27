<?php
/**
 * Set Default Payment Card API Endpoint
 * POST /api/payment/set-default.php
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    if (!$userId) {
        error_log("Set Default - Authentication failed: No user ID");
        Response::error('Authentication failed', 401);
    }

    // Read JSON body: { card_id: number }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $paymentId = $data['card_id'] ?? null;

    if (!$paymentId) {
        Response::error('Payment method ID is required', 400);
    }

    // Verify payment method belongs to user
    $payment = $db->queryOne(
        "SELECT * FROM payment_methods WHERE id = ? AND user_id = ? LIMIT 1",
        [$paymentId, $userId]
    );

    if (!$payment) {
        // Use generic error to avoid leaking existence of other users' cards
        Response::error('Payment method not found', 404);
    }

    // Unset all other defaults
    $db->execute(
        "UPDATE payment_methods SET is_default = FALSE WHERE user_id = ?",
        [$userId]
    );

    // Set this card as default
    $db->execute(
        "UPDATE payment_methods SET is_default = TRUE WHERE id = ?",
        [$paymentId]
    );

    // Log activity
    $db->execute(
        "INSERT INTO activity_logs (user_id, action, resource_type, resource_id, ip_address, user_agent) 
         VALUES (?, 'set_default_payment', 'payment_method', ?, ?, ?)",
        [$userId, $paymentId, Auth::getIpAddress(), Auth::getUserAgent()]
    );

    Response::success([
        'id' => $paymentId,
        'card_brand' => $payment['card_brand'],
        'card_last4' => $payment['card_last4']
    ], 'Default payment method updated successfully');

} catch (Exception $e) {
    error_log("Set Default Card Error: " . $e->getMessage());
    Response::error('Failed to set default payment method', 500);
}
