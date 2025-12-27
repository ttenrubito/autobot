<?php
/**
 * Payment Transactions API Endpoint
 * GET /api/billing/transactions
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    
    // Get limit parameter
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

    $transactions = $db->query(
        "SELECT 
            t.id,
            t.amount,
            t.currency,
            t.status,
            t.omise_charge_id,
            t.created_at,
            i.invoice_number,
            pm.card_brand,
            pm.card_last4
         FROM transactions t
         INNER JOIN invoices i ON t.invoice_id = i.id
         LEFT JOIN payment_methods pm ON t.payment_method_id = pm.id
         WHERE i.user_id = ?
         ORDER BY t.created_at DESC
         LIMIT ?",
        [$userId, $limit]
    );

    Response::success($transactions);

} catch (Exception $e) {
    error_log("Transactions Error: " . $e->getMessage());
    Response::error('Failed to get transactions', 500);
}
