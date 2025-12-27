<?php
/**
 * Invoice Detail API Endpoint
 * GET /api/billing/invoice-detail.php?id={invoiceId}
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();

    if (!$userId) {
        Response::error('Authentication failed', 401);
    }

    $invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($invoiceId <= 0) {
        Response::error('Invalid invoice id', 400);
    }

    // Get invoice basic info
    $invoice = $db->queryOne(
        "SELECT id, invoice_number, amount, tax, total, currency, status,
                billing_period_start, billing_period_end, due_date, paid_at, created_at
         FROM invoices
         WHERE id = ? AND user_id = ?
         LIMIT 1",
        [$invoiceId, $userId]
    );

    if (!$invoice) {
        Response::error('Invoice not found', 404);
    }

    // Optionally load invoice items if table exists
    try {
        $items = $db->query(
            "SELECT id, description, quantity, unit_price, total
             FROM invoice_items
             WHERE invoice_id = ?
             ORDER BY id ASC",
            [$invoiceId]
        );
    } catch (Exception $e) {
        // If invoice_items table not available, just return empty array
        $items = [];
    }

    $response = [
        'invoice' => $invoice,
        'items'   => $items,
    ];

    Response::success($response);

} catch (Exception $e) {
    error_log('Invoice Detail Error: ' . $e->getMessage());
    Response::error('Failed to get invoice details', 500);
}
