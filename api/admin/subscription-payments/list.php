<?php
/**
 * Admin API: List Subscription Payments
 * 
 * GET /api/admin/subscription-payments/list.php
 * 
 * Query params:
 * - status: 'pending', 'verified', 'rejected', or 'all' (default: 'pending')
 * - user_id: Filter by specific user (optional)
 * - limit: Number of results (default: 50)
 * - offset: Pagination offset (default: 0)
 * 
 * Required: Admin session
 */

define('INCLUDE_CHECK', true);
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Send JSON response
function respond($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Check admin authentication
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    respond(['success' => false, 'message' => 'Unauthorized: Admin access required'], 403);
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Parse query params
$status = $_GET['status'] ?? 'pending';
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

try {
    $db = Database::getInstance();
    
    // Build query
    $where = [];
    $params = [];
    
    if ($status !== 'all') {
        $where[] = 'sp.status = ?';
        $params[] = $status;
    }
    
    if ($userId) {
        $where[] = 'sp.user_id = ?';
        $params[] = $userId;
    }
    
    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM subscription_payments sp $whereClause";
    $countResult = $db->queryOne($countSql, $params);
    $total = (int)($countResult['total'] ?? 0);
    
    // Get payments with user info
    $sql = "SELECT 
                sp.id,
                sp.user_id,
                sp.amount,
                sp.slip_url,
                sp.gcs_path,
                sp.status,
                sp.days_added,
                sp.rejection_reason,
                sp.notes,
                sp.created_at,
                sp.verified_at,
                sp.verified_by,
                u.name as user_name,
                u.email as user_email,
                u.company_name,
                s.current_period_end as subscription_end,
                s.status as subscription_status,
                admin.name as verified_by_name
            FROM subscription_payments sp
            JOIN users u ON u.id = sp.user_id
            LEFT JOIN subscriptions s ON s.user_id = sp.user_id
            LEFT JOIN users admin ON admin.id = sp.verified_by
            $whereClause
            ORDER BY 
                CASE sp.status 
                    WHEN 'pending' THEN 0 
                    WHEN 'verified' THEN 1 
                    WHEN 'rejected' THEN 2 
                END,
                sp.created_at DESC
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $payments = $db->query($sql, $params);
    
    // Format response
    $formattedPayments = array_map(function($p) {
        $statusLabels = [
            'pending' => ['label' => 'รอตรวจสอบ', 'color' => 'warning'],
            'verified' => ['label' => 'อนุมัติแล้ว', 'color' => 'success'],
            'rejected' => ['label' => 'ปฏิเสธ', 'color' => 'danger']
        ];
        
        $status = $p['status'] ?? 'pending';
        
        // Calculate subscription days remaining
        $daysRemaining = null;
        if ($p['subscription_end']) {
            $endDate = new DateTime($p['subscription_end']);
            $today = new DateTime();
            $diff = $today->diff($endDate);
            $daysRemaining = $endDate > $today ? (int)$diff->days : -(int)$diff->days;
        }
        
        return [
            'id' => (int)$p['id'],
            'user_id' => (int)$p['user_id'],
            'user_name' => $p['user_name'] ?? '',
            'user_email' => $p['user_email'] ?? '',
            'company_name' => $p['company_name'] ?? '',
            'amount' => floatval($p['amount']),
            'amount_formatted' => '฿' . number_format($p['amount'], 0),
            'slip_url' => $p['slip_url'] ?? '',
            'status' => $status,
            'status_label' => $statusLabels[$status]['label'] ?? $status,
            'status_color' => $statusLabels[$status]['color'] ?? 'secondary',
            'days_added' => (int)($p['days_added'] ?? 0),
            'rejection_reason' => $p['rejection_reason'] ?? null,
            'notes' => $p['notes'] ?? null,
            'created_at' => $p['created_at'],
            'created_at_formatted' => date('d/m/Y H:i', strtotime($p['created_at'])),
            'verified_at' => $p['verified_at'] ?? null,
            'verified_at_formatted' => $p['verified_at'] 
                ? date('d/m/Y H:i', strtotime($p['verified_at'])) 
                : null,
            'verified_by' => $p['verified_by'] ? (int)$p['verified_by'] : null,
            'verified_by_name' => $p['verified_by_name'] ?? null,
            'subscription_end' => $p['subscription_end'] ?? null,
            'subscription_status' => $p['subscription_status'] ?? null,
            'subscription_days_remaining' => $daysRemaining
        ];
    }, $payments);
    
    // Get summary counts
    $summarySql = "SELECT 
                       status,
                       COUNT(*) as count,
                       SUM(amount) as total_amount
                   FROM subscription_payments
                   GROUP BY status";
    $summaryRows = $db->query($summarySql);
    
    $summary = [
        'pending' => ['count' => 0, 'amount' => 0],
        'verified' => ['count' => 0, 'amount' => 0],
        'rejected' => ['count' => 0, 'amount' => 0]
    ];
    
    foreach ($summaryRows as $row) {
        $summary[$row['status']] = [
            'count' => (int)$row['count'],
            'amount' => floatval($row['total_amount'])
        ];
    }
    
    respond([
        'success' => true,
        'data' => [
            'payments' => $formattedPayments,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ],
            'summary' => $summary
        ]
    ]);
    
} catch (Exception $e) {
    Logger::error('[ListPayments] Error', ['error' => $e->getMessage()]);
    
    respond([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], 500);
}
