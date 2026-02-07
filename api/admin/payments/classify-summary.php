<?php
/**
 * API: Get Payment Classification Summary
 * 
 * Returns counts of payments by match status
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Response.php';
require_once __DIR__ . '/../../../includes/admin_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
    exit;
}

if (!isAdminLoggedIn()) {
    Response::error('Unauthorized', 401);
    exit;
}

$db = Database::getInstance();

try {
    // Get counts by match_status for pending payments only
    $sql = "
        SELECT 
            COALESCE(match_status, 'pending') as status,
            COUNT(*) as count
        FROM payments
        WHERE status = 'pending'
        GROUP BY COALESCE(match_status, 'pending')
    ";
    
    $results = $db->query($sql, []);
    
    $summary = [
        'pending' => 0,
        'auto_matched' => 0,
        'manual_matched' => 0,
        'no_match' => 0
    ];
    
    foreach ($results as $row) {
        $status = $row['status'];
        if (isset($summary[$status])) {
            $summary[$status] = intval($row['count']);
        }
    }
    
    Response::success([
        'summary' => $summary,
        'total' => array_sum($summary)
    ]);
    
} catch (Exception $e) {
    Response::error('Database error: ' . $e->getMessage(), 500);
}
