<?php
/**
 * Debug: Check specific payment's platform_user_id
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if (($_GET['debug_key'] ?? '') !== 'autobot2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getDB();
$paymentId = $_GET['payment_id'] ?? 35;

// Get payment with all relevant columns
$stmt = $pdo->prepare("SELECT id, payment_no, customer_id, platform_user_id, tenant_id, payment_details FROM payments WHERE id = ?");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

// Get customer profile if platform_user_id exists
$profile = null;
if ($payment && $payment['platform_user_id']) {
    $stmt = $pdo->prepare("SELECT * FROM customer_profiles WHERE platform_user_id = ?");
    $stmt->execute([$payment['platform_user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Test JOIN query
$joinResult = null;
if ($payment) {
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.payment_no,
            p.platform_user_id,
            p.tenant_id,
            cp.display_name as customer_display_name,
            cp.full_name as customer_full_name,
            cp.platform as customer_platform,
            COALESCE(cp.avatar_url, cp.profile_pic_url) as customer_avatar
        FROM payments p
        LEFT JOIN customer_profiles cp ON p.platform_user_id = cp.platform_user_id
        WHERE p.id = ?
    ");
    $stmt->execute([$paymentId]);
    $joinResult = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get user info for test1@gmail.com
$stmt = $pdo->prepare("SELECT id, email, tenant_id FROM users WHERE email = ?");
$stmt->execute(['test1@gmail.com']);
$testUser = $stmt->fetch(PDO::FETCH_ASSOC);

// Parse details
$details = $payment['payment_details'] ? json_decode($payment['payment_details'], true) : null;

echo json_encode([
    'success' => true,
    'payment' => [
        'id' => $payment['id'],
        'payment_no' => $payment['payment_no'],
        'customer_id' => $payment['customer_id'],
        'platform_user_id' => $payment['platform_user_id'],
        'tenant_id' => $payment['tenant_id'],
        'external_user_id_from_details' => $details['external_user_id'] ?? null
    ],
    'profile' => $profile,
    'join_result' => $joinResult,
    'test_user' => $testUser
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
