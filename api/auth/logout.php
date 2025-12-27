<?php
/**
 * Logout API Endpoint
 * POST /api/auth/logout
 */

// Explicitly load dependencies so this endpoint works both via api/index.php and direct access
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Auth.php';

try {
    $userId = Auth::id();

    if ($userId) {
        $db = Database::getInstance();

        // Log activity (best-effort)
        try {
            $db->execute(
                "INSERT INTO activity_logs (user_id, action, ip_address, user_agent, created_at)
                 VALUES (?, 'logout', ?, ?, NOW())",
                [$userId, Auth::getIpAddress(), Auth::getUserAgent()]
            );
        } catch (Exception $logErr) {
            error_log('Logout activity log failed: ' . $logErr->getMessage());
        }
    }

    // Destroy session
    Auth::logout();

    Response::success(null, 'Logout successful');

} catch (Exception $e) {
    error_log("Logout Error: " . $e->getMessage());
    Response::error('Logout failed', 500);
}
