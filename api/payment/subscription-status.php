<?php
/**
 * Subscription Status API
 * GET /api/payment/subscription-status.php
 * 
 * Purpose: ดูสถานะ subscription และวันที่เหลือ
 * Returns: trial days remaining, next billing date, plan info
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';

Auth::require();

try {
    $db = Database::getInstance();
    $user_id = Auth::id();
    
    // Get active subscription
    $subscription = $db->queryOne(
        "SELECT s.*, sp.name as plan_name, sp.monthly_price, sp.description
         FROM subscriptions s
         JOIN subscription_plans sp ON s.plan_id = sp.id
         WHERE s.user_id = ? AND s.status IN ('trial', 'active', 'paused')
         ORDER BY s.created_at DESC
         LIMIT 1",
        [$user_id]
    );
    
    // IMPORTANT: Absence of subscription is not a routing error.
    // Return 200 with has_subscription=false to keep UI logic simple.
    if (!$subscription) {
        Response::success([
            'has_subscription' => false,
            'status' => null,
        ]);
        exit;
    }
    
    // Calculate trial days remaining
    $trial_days_remaining = 0;
    if ($subscription['status'] === 'trial' && $subscription['trial_end_date']) {
        $today = new DateTime();
        $trial_end = new DateTime($subscription['trial_end_date']);
        $interval = $today->diff($trial_end);
        $trial_days_remaining = max(0, $interval->days + 1); // +1 to include today
        
        // Update user's trial_days_remaining
        $db->execute(
            "UPDATE users SET trial_days_remaining = ? WHERE id = ?",
            [$trial_days_remaining, $user_id]
        );
    }
    
    // Get payment method info
    $payment_method = $db->queryOne(
        "SELECT card_brand, card_last4, card_expiry_month, card_expiry_year
         FROM payment_methods
         WHERE user_id = ? AND is_default = TRUE
         LIMIT 1",
        [$user_id]
    );
    
    // Get subscription status and return with Response helper
    Response::success([
        'has_subscription' => true,
        'status' => $subscription['status'],
        'plan_name' => $subscription['plan_name'],
        'plan_description' => $subscription['description'],
        'monthly_price' => (float)$subscription['monthly_price'],
        'trial_days_remaining' => (int)$trial_days_remaining,
        'trial_end_date' => $subscription['trial_end_date'],
        'current_period_start' => $subscription['current_period_start'],
        'current_period_end' => $subscription['current_period_end'],
        'next_billing_date' => $subscription['next_billing_date'],
        'auto_renew' => (bool)$subscription['auto_renew'],
        'cancelled_at' => $subscription['cancelled_at'],
        'payment_method' => $payment_method ? [
            'card_brand' => $payment_method['card_brand'],
            'card_last4' => $payment_method['card_last4'],
            'card_expiry' => sprintf('%02d/%04d', $payment_method['card_expiry_month'], $payment_method['card_expiry_year'])
        ] : null
    ]);
    
} catch (Exception $e) {
    error_log("Subscription Status Error: " . $e->getMessage());
    Response::error('Failed to get subscription status', 500);
}
