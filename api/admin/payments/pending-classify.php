<?php
/**
 * API: Get Pending Payments for Classification
 * 
 * Returns payments that need admin classification
 * Filter: pending, no_match, auto_matched, manual_matched, all
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Response.php';
require_once __DIR__ . '/../../../includes/admin_auth.php';

// CORS & Method check
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
    exit;
}

// Require admin auth
if (!isAdminLoggedIn()) {
    Response::error('Unauthorized', 401);
    exit;
}

$db = Database::getInstance();
$filter = $_GET['filter'] ?? 'pending';
$limit = min(intval($_GET['limit'] ?? 50), 100);

try {
    // Build WHERE clause based on filter
    $whereClause = "WHERE p.status = 'pending'"; // Only show unverified payments
    
    switch ($filter) {
        case 'pending':
            $whereClause .= " AND (p.match_status IS NULL OR p.match_status = 'pending')";
            break;
        case 'no_match':
            $whereClause .= " AND p.match_status = 'no_match'";
            break;
        case 'auto_matched':
            $whereClause .= " AND p.match_status = 'auto_matched'";
            break;
        case 'manual_matched':
            $whereClause .= " AND p.match_status = 'manual_matched'";
            break;
        case 'all':
            // No additional filter
            break;
        default:
            $whereClause .= " AND (p.match_status IS NULL OR p.match_status = 'pending')";
    }
    
    $sql = "
        SELECT 
            p.id,
            p.payment_no,
            p.customer_id,
            p.order_id,
            p.amount,
            p.slip_image,
            p.status,
            p.payment_details,
            p.classified_as,
            p.match_status,
            p.match_attempts,
            p.linked_pawn_payment_id,
            p.created_at,
            p.updated_at,
            c.name as customer_name,
            c.platform,
            c.platform_user_id
        FROM payments p
        LEFT JOIN customers c ON p.customer_id = c.id
        $whereClause
        ORDER BY p.created_at DESC
        LIMIT ?
    ";
    
    $payments = $db->query($sql, [$limit]);
    
    Response::success([
        'payments' => $payments,
        'filter' => $filter,
        'count' => count($payments)
    ]);
    
} catch (Exception $e) {
    Response::error('Database error: ' . $e->getMessage(), 500);
}
