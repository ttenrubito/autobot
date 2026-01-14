<?php
/**
 * Search Savings Accounts API
 * GET /api/customer/search-savings.php?q=search_term
 * 
 * Returns matching savings accounts for autocomplete
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

// Verify authentication
$auth = verifyToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $auth['user_id'];
$query = trim($_GET['q'] ?? '');

if (strlen($query) < 1) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $pdo = getDB();
    
    // Get tenant_id from user
    $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_id = $user['tenant_id'] ?? 'default';
    
    // Search savings accounts
    $searchTerm = '%' . $query . '%';
    
    $stmt = $pdo->prepare("
        SELECT 
            sa.id,
            sa.account_no,
            sa.product_name,
            sa.goal_amount,
            sa.current_balance,
            sa.status,
            sa.created_at
        FROM savings_accounts sa
        WHERE sa.tenant_id = ?
        AND (
            sa.account_no LIKE ? 
            OR sa.product_name LIKE ?
            OR CAST(sa.id AS CHAR) LIKE ?
        )
        ORDER BY sa.created_at DESC
        LIMIT 10
    ");
    
    $stmt->execute([$tenant_id, $searchTerm, $searchTerm, $searchTerm]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $accounts
    ]);
    
} catch (Exception $e) {
    error_log("Search savings error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการค้นหา'
    ]);
}
