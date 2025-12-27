<?php
/**
 * Admin API - Assign Subscription Plan to User
 * POST /api/admin/subscriptions/assign.php
 *
 * This endpoint lets an admin assign a subscription plan to a user.
 * Invariant: each user can have at most ONE active subscription.
 */

require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/JWT.php';
require_once __DIR__ . '/../../../includes/Response.php';
require_once __DIR__ . '/../../../includes/AdminAuth.php';

header('Content-Type: application/json');

// Require admin authentication
AdminAuth::require();

try {
    $db = Database::getInstance();

    // Parse JSON body
    $rawInput = file_get_contents('php://input');
    error_log("Assign subscription - Raw input: " . $rawInput);
    
    $input = json_decode($rawInput, true) ?: [];
    error_log("Assign subscription - Parsed input: " . json_encode($input));

    $userId = $input['user_id'] ?? null;
    $planId = $input['plan_id'] ?? null;
    
    error_log("Assign subscription - userId: " . var_export($userId, true) . ", planId: " . var_export($planId, true));

    if (!$userId || !$planId) {
        Response::error('user_id และ plan_id เป็นค่าบังคับ', 400);
    }

    // Validate user exists
    $user = $db->queryOne('SELECT id, email FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        Response::error('ไม่พบบัญชีผู้ใช้ที่ระบุ', 404);
    }

    // Validate plan exists and active, including billing_period_days (default 30 if column missing/null)
    $plan = $db->queryOne(
        'SELECT id, name, monthly_price,
                COALESCE(billing_period_days, 30) AS billing_period_days,
                included_requests, overage_rate, is_active
         FROM subscription_plans WHERE id = ?',
        [$planId]
    );

    if (!$plan) {
        Response::error('ไม่พบแพ็คเกจที่ระบุ', 404);
    }

    if (!(bool)$plan['is_active']) {
        Response::error('ไม่สามารถกำหนดแพ็คเกจที่ถูกปิดใช้งานให้ผู้ใช้ได้', 400);
    }

    $billingDays = (int)$plan['billing_period_days'] ?: 30;

    // Check existing active subscription for this user
    $currentSub = $db->queryOne(
        'SELECT id, plan_id, status FROM subscriptions
         WHERE user_id = ? AND status = "active" LIMIT 1',
        [$userId]
    );

    $db->beginTransaction();

    // If user already has an active subscription, cancel it first
    if ($currentSub) {
        if ((int)$currentSub['plan_id'] === (int)$planId) {
            // Same plan already active -> nothing to change
            $db->commit();
            Response::success([
                'message' => 'ผู้ใช้นี้ใช้งานแพ็คเกจนี้อยู่แล้ว',
                'user_id' => (int)$userId,
                'plan_id' => (int)$planId,
                'unchanged' => true,
            ]);
        }

        // Cancel old subscription according to schema (status = 'cancelled', cancelled_at)
        $db->execute(
            'UPDATE subscriptions
             SET status = "cancelled", cancelled_at = NOW(), updated_at = NOW()
             WHERE id = ?',
            [$currentSub['id']]
        );
    }

    // Create new active subscription aligned with schema (dates only)
    $db->execute(
        'INSERT INTO subscriptions
         (user_id, plan_id, status, current_period_start, current_period_end, next_billing_date, auto_renew, created_at)
         VALUES (?, ?, "active", CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY), DATE_ADD(CURDATE(), INTERVAL ? DAY), 1, NOW())',
        [$userId, $planId, $billingDays, $billingDays]
    );

    $newSubId = $db->lastInsertId();

    $db->commit();

    Response::success([
        'message' => 'กำหนดแพ็คเกจให้ผู้ใช้เรียบร้อยแล้ว',
        'subscription_id' => (int)$newSubId,
        'user_id' => (int)$userId,
        'plan_id' => (int)$planId,
    ], 201);

} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    error_log('Admin Assign Subscription Error: ' . $e->getMessage());
    Response::error('ไม่สามารถกำหนดแพ็คเกจให้ผู้ใช้ได้: ' . $e->getMessage(), 500);
}
