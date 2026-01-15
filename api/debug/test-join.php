<?php
/**
 * Debug: Test JOIN between payments and customer_profiles
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if (($_GET['debug_key'] ?? '') !== 'autobot2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$paymentId = $_GET['payment_id'] ?? 35;
$pdo = getDB();

// Check payments table columns
$stmt = $pdo->query("DESCRIBE payments");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get payment with platform_user_id
$stmt = $pdo->prepare("SELECT id, payment_no, platform_user_id, customer_id FROM payments WHERE id = ?");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

// Test the actual JOIN query
$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.payment_no,
        p.platform_user_id as payment_platform_user_id,
        cp.platform_user_id as profile_platform_user_id,
        cp.display_name,
        cp.full_name,
        COALESCE(cp.avatar_url, cp.profile_pic_url) as avatar_url,
        cp.platform
    FROM payments p
    LEFT JOIN customer_profiles cp ON p.platform_user_id = cp.platform_user_id
    WHERE p.id = ?
");
$stmt->execute([$paymentId]);
$joinResult = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'payments_has_platform_user_id_column' => in_array('platform_user_id', $columns),
    'payment_data' => $payment,
    'join_result' => $joinResult
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
