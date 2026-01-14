<?php
/**
 * Search Installment Contracts API
 * GET /api/customer/search-installments.php?q=search_term
 * 
 * Returns matching installment contracts for autocomplete
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
    
    // Search installment contracts
    $searchTerm = '%' . $query . '%';
    
    $stmt = $pdo->prepare("
        SELECT 
            ic.id,
            ic.contract_no,
            ic.product_name,
            ic.total_amount,
            ic.paid_amount,
            ic.remaining_amount,
            ic.status,
            ic.created_at
        FROM installment_contracts ic
        WHERE ic.tenant_id = ?
        AND (
            ic.contract_no LIKE ? 
            OR ic.product_name LIKE ?
            OR CAST(ic.id AS CHAR) LIKE ?
        )
        ORDER BY ic.created_at DESC
        LIMIT 10
    ");
    
    $stmt->execute([$tenant_id, $searchTerm, $searchTerm, $searchTerm]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $contracts
    ]);
    
} catch (Exception $e) {
    error_log("Search installments error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการค้นหา'
    ]);
}
