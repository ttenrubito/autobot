<?php
/**
 * Admin API - Extend Subscription for User
 * POST /api/admin/subscriptions/extend.php
 *
 * This endpoint lets an admin extend a user's subscription by X days.
 * If user has no active subscription, creates one starting from today.
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
    $input = json_decode($rawInput, true) ?: [];

    $userId = $input['user_id'] ?? null;
    $days = (int)($input['days'] ?? 0);
    
    if (!$userId) {
        Response::error('user_id เป็นค่าบังคับ', 400);
    }
    
    if ($days <= 0 || $days > 3650) {
        Response::error('จำนวนวันต้องอยู่ระหว่าง 1-3650 วัน', 400);
    }

    // Validate user exists
    $user = $db->queryOne('SELECT id, email FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        Response::error('ไม่พบบัญชีผู้ใช้ที่ระบุ', 404);
    }

    // Check existing active subscription for this user
    $currentSub = $db->queryOne(
        'SELECT id, plan_id, current_period_end, next_billing_date, status 
         FROM subscriptions
         WHERE user_id = ? AND status = "active" 
         ORDER BY id DESC LIMIT 1',
        [$userId]
    );

    $db->beginTransaction();

    if ($currentSub) {
        // Extend existing subscription
        $currentEnd = $currentSub['current_period_end'] ?? date('Y-m-d');
        
        // If current_period_end is in the past, extend from today
        $baseDate = (strtotime($currentEnd) < time()) ? date('Y-m-d') : $currentEnd;
        
        $newEndDate = date('Y-m-d', strtotime($baseDate . " + {$days} days"));
        
        $db->execute(
            'UPDATE subscriptions
             SET current_period_end = ?, 
                 next_billing_date = ?,
                 updated_at = NOW()
             WHERE id = ?',
            [$newEndDate, $newEndDate, $currentSub['id']]
        );
        
        $db->commit();
        
        Response::success([
            'message' => "ต่ออายุสำเร็จ {$days} วัน",
            'user_id' => (int)$userId,
            'subscription_id' => (int)$currentSub['id'],
            'old_end_date' => $currentEnd,
            'new_end_date' => $newEndDate,
            'days_added' => $days
        ]);
        
    } else {
        // No active subscription - check if there's a plan to use
        // Get the default/starter plan or most basic active plan
        $defaultPlan = $db->queryOne(
            'SELECT id, name, billing_period_days FROM subscription_plans 
             WHERE is_active = 1 
             ORDER BY monthly_price ASC LIMIT 1'
        );
        
        if (!$defaultPlan) {
            $db->rollback();
            Response::error('ไม่พบแพ็คเกจที่สามารถใช้ได้ กรุณากำหนดแพ็คเกจก่อน', 400);
        }
        
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime($startDate . " + {$days} days"));
        
        // Create new subscription
        $db->execute(
            'INSERT INTO subscriptions
             (user_id, plan_id, status, current_period_start, current_period_end, next_billing_date, auto_renew, created_at)
             VALUES (?, ?, "active", ?, ?, ?, 0, NOW())',
            [$userId, $defaultPlan['id'], $startDate, $endDate, $endDate]
        );
        
        $newSubId = $db->lastInsertId();
        
        $db->commit();
        
        Response::success([
            'message' => "สร้าง subscription ใหม่และต่ออายุสำเร็จ {$days} วัน",
            'user_id' => (int)$userId,
            'subscription_id' => (int)$newSubId,
            'plan_id' => (int)$defaultPlan['id'],
            'plan_name' => $defaultPlan['name'],
            'start_date' => $startDate,
            'new_end_date' => $endDate,
            'days_added' => $days
        ]);
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    error_log("Extend subscription error: " . $e->getMessage());
    Response::error('เกิดข้อผิดพลาดภายในระบบ: ' . $e->getMessage(), 500);
}
