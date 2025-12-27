<?php
/**
 * Remove Payment Card API Endpoint
 * POST /api/payment/remove-card.php
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/OmiseClient.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    if (!$userId) {
        error_log("Remove Card - Authentication failed: No user ID");
        Response::error('Authentication failed', 401);
    }

    // Read JSON body: support both { card_id } and { payment_method_id }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];

    // Debug logs to inspect incoming payload (remove in production ifไม่ต้องการแล้ว)
    error_log('Remove Card RAW body: ' . $raw);
    error_log('Remove Card decoded data: ' . print_r($data, true));

    $paymentId = $data['card_id'] ?? $data['payment_method_id'] ?? null;
    error_log('Remove Card resolved paymentId: ' . var_export($paymentId, true));
    error_log('Remove Card paymentId is_null: ' . (is_null($paymentId) ? 'yes' : 'no'));

    if (is_null($paymentId) || (string)$paymentId === '' || (int)$paymentId <= 0) {
        Response::error('Payment method ID is required', 400);
    }

    // Get payment method
    $payment = $db->queryOne(
        "SELECT * FROM payment_methods WHERE id = ? AND user_id = ? LIMIT 1",
        [$paymentId, $userId]
    );

    if (!$payment) {
        Response::error('Payment method not found', 404);
    }

    // Use underlying PDO for advanced queries
    $pdo = $db->getPdo();

    // Unlink transactions from this payment method (preserve transaction history)
    // Instead of blocking deletion, we set payment_method_id to NULL
    $stmt = $pdo->prepare("UPDATE transactions SET payment_method_id = NULL WHERE payment_method_id = :id");
    $stmt->execute([':id' => $paymentId]);

    // Delete from Omise (best-effort)
    $omise = new OmiseClient();
    try {
        $omise->deleteCard($payment['omise_customer_id'], $payment['omise_card_id']);
    } catch (Exception $e) {
        error_log("Omise Delete Card Error: " . $e->getMessage());
        // Continue even if Omise deletion fails
    }

    // Delete from database
    $db->execute(
        "DELETE FROM payment_methods WHERE id = ?",
        [$paymentId]
    );

    // Log activity using PDO
    $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, resource_type, resource_id, details, ip_address, user_agent) VALUES (:user_id, :action, :resource_type, :resource_id, :details, :ip_address, :user_agent)");
    $logStmt->execute([
        ':user_id' => $userId,
        ':action' => 'delete_payment_method',
        ':resource_type' => 'payment_method',
        ':resource_id' => $paymentId,
        ':details' => json_encode([
            'card_last4' => $payment['last4'] ?? null,
            'brand' => $payment['brand'] ?? null,
        ]),
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);

    Response::success(null, 'Payment method removed successfully');

} catch (Exception $e) {
    error_log("Remove Card Error: " . $e->getMessage());
    Response::error('Failed to remove payment method', 500);
}
