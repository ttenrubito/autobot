<?php
/**
 * Invoices List API Endpoint
 * GET /api/billing/invoices
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();

    $invoices = $db->query(
        "SELECT id, invoice_number, amount, tax, total, currency, status, 
                billing_period_start, billing_period_end, due_date, paid_at, created_at
         FROM invoices
         WHERE user_id = ?
         ORDER BY created_at DESC",
        [$userId]
    );

    Response::success($invoices);

} catch (Exception $e) {
    error_log("Invoices List Error: " . $e->getMessage());
    Response::error('Failed to get invoices', 500);
}
