<?php
/**
 * Get Pending Invoices for PromptPay Payment
 * GET /api/payment/pending-invoices.php
 * 
 * Returns list of unpaid invoices for the current user
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    // Get all pending AND failed invoices for this user
    // Failed invoices should also be payable via PromptPay
    $invoices = $db->query(
        "SELECT 
            i.id,
            i.invoice_number,
            i.amount,
            i.tax,
            i.total,
            i.currency,
            i.status,
            i.billing_period_start,
            i.billing_period_end,
            i.due_date,
            i.created_at,
            sp.name as plan_name
         FROM invoices i
         LEFT JOIN subscriptions s ON i.subscription_id = s.id
         LEFT JOIN subscription_plans sp ON s.plan_id = sp.id
         WHERE i.user_id = ? 
         AND i.status IN ('pending', 'failed')
         ORDER BY i.due_date ASC, i.created_at DESC",
        [$userId]
    );
    
    // Format dates and add overdue flag
    foreach ($invoices as &$invoice) {
        $dueDate = new DateTime($invoice['due_date']);
        $now = new DateTime();
        $invoice['is_overdue'] = $dueDate < $now;
        
        // Calculate days until/past due
        $interval = $now->diff($dueDate);
        if ($invoice['is_overdue']) {
            $invoice['days_overdue'] = $interval->days;
        } else {
            $invoice['days_until_due'] = $interval->days;
        }
    }
    
    Response::success($invoices);
    
} catch (Exception $e) {
    error_log("Pending Invoices Error: " . $e->getMessage());
    Response::error('Failed to load pending invoices', 500);
}
