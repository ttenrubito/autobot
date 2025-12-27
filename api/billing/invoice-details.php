<?php
/**
 * Invoice Details API Endpoint
 * GET /api/billing/invoice/{id}
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    $invoiceId = $_GET['id'] ?? null;

    if (!$invoiceId) {
        Response::error('Invoice ID is required', 400);
    }

    // Get invoice
    $invoice = $db->queryOne(
        "SELECT * FROM invoices WHERE id = ? AND user_id = ? LIMIT 1",
        [$invoiceId, $userId]
    );

    if (!$invoice) {
        Response::notFound('Invoice not found');
    }

    // Get invoice items
    $items = $db->query(
        "SELECT description, quantity, unit_price, amount 
         FROM invoice_items 
         WHERE invoice_id = ?",
        [$invoiceId]
    );

    $invoice['items'] = $items;

    // Get transaction
    $transaction = $db->queryOne(
        "SELECT t.*, pm.card_brand, pm.card_last4
         FROM transactions t
         LEFT JOIN payment_methods pm ON t.payment_method_id = pm.id
         WHERE t.invoice_id = ?
         LIMIT 1",
        [$invoiceId]
    );

    $invoice['transaction'] = $transaction;

    // Return invoice with items embedded (not wrapped in extra object)
    Response::success($invoice);

} catch (Exception $e) {
    error_log("Invoice Details Error: " . $e->getMessage());
    Response::error('Failed to get invoice details', 500);
}
