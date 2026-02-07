<?php
/**
 * Search Customer Profiles API
 * GET /api/customer/search-customers.php?q=search_term
 * 
 * Returns matching customer profiles for autocomplete
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

if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $pdo = getDB();
    
    // Get tenant_id from user (same pattern as cases.php)
    $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_id = $user['tenant_id'] ?? 'default';
    
    // Search customer profiles
    $searchTerm = '%' . $query . '%';
    
    // ✅ Filter by user's channels (customer_channels table)
    // Get user's channel IDs first
    $channelStmt = $pdo->prepare("SELECT id FROM customer_channels WHERE user_id = ? AND status = 'active'");
    $channelStmt->execute([$user_id]);
    $userChannels = $channelStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($userChannels)) {
        // User has no channels - return empty
        echo json_encode(['success' => true, 'data' => [], 'message' => 'No channels configured']);
        exit;
    }
    
    // Build placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($userChannels), '?'));
    
    // Filter customer_profiles by user's channel_ids
    $sql = "
        SELECT 
            cp.id,
            cp.platform,
            cp.platform_user_id,
            cp.display_name,
            cp.full_name,
            COALESCE(cp.avatar_url, cp.profile_pic_url) as avatar_url,
            cp.phone,
            cp.email,
            cp.last_active_at,
            cp.channel_id
        FROM customer_profiles cp
        WHERE cp.channel_id IN ($placeholders)
        AND (
            cp.display_name LIKE ? 
            OR cp.full_name LIKE ?
            OR cp.phone LIKE ?
            OR cp.platform_user_id LIKE ?
            OR cp.email LIKE ?
        )
        ORDER BY cp.last_active_at DESC
        LIMIT 10
    ";
    
    // Combine params: channel_ids + search terms
    $params = array_merge($userChannels, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $customers
    ]);
    
} catch (Exception $e) {
    error_log("Search customers error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการค้นหา'
    ]);
}
