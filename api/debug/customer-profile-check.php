<?php
/**
 * Debug: Check customer_profiles for payment avatar lookup
 * Usage: /api/debug/customer-profile-check.php?external_user_id=XXX&platform=facebook
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

// Only allow in dev/debug mode
$isDebug = getenv('APP_DEBUG') === 'true' || ($_GET['debug_key'] ?? '') === 'autobot2026';

if (!$isDebug) {
    http_response_code(403);
    echo json_encode(['error' => 'Debug mode not enabled']);
    exit;
}

$externalUserId = $_GET['external_user_id'] ?? '';
$platform = $_GET['platform'] ?? 'facebook';
$paymentId = $_GET['payment_id'] ?? '';

try {
    $pdo = getDB();
    $result = [];
    
    // 1. Check customer_profiles table structure
    $stmt = $pdo->query("DESCRIBE customer_profiles");
    $result['table_structure'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Count total profiles
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM customer_profiles");
    $result['total_profiles'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 3. Count by platform
    $stmt = $pdo->query("SELECT platform, COUNT(*) as count FROM customer_profiles GROUP BY platform");
    $result['profiles_by_platform'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Sample profiles
    $stmt = $pdo->query("SELECT id, platform_user_id, platform, full_name, LEFT(avatar_url, 80) as avatar_preview FROM customer_profiles ORDER BY id DESC LIMIT 10");
    $result['sample_profiles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. If external_user_id provided, search for it
    if ($externalUserId) {
        $stmt = $pdo->prepare("SELECT * FROM customer_profiles WHERE platform_user_id = ? AND platform = ?");
        $stmt->execute([$externalUserId, $platform]);
        $result['matched_profile'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Also try partial match
        $stmt = $pdo->prepare("SELECT id, platform_user_id, platform, full_name FROM customer_profiles WHERE platform_user_id LIKE ?");
        $stmt->execute(['%' . substr($externalUserId, -10) . '%']);
        $result['partial_matches'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 6. If payment_id provided, check payment and its lookup
    if ($paymentId) {
        $stmt = $pdo->prepare("SELECT id, payment_no, customer_id, payment_details FROM payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        $result['payment'] = $payment;
        
        if ($payment && $payment['payment_details']) {
            $details = json_decode($payment['payment_details'], true);
            $result['payment_details_parsed'] = $details;
            
            $extId = $details['external_user_id'] ?? null;
            $plat = $details['platform'] ?? 'facebook';
            
            if ($extId) {
                $stmt = $pdo->prepare("SELECT * FROM customer_profiles WHERE platform_user_id = ? AND platform = ?");
                $stmt->execute([$extId, $plat]);
                $result['profile_lookup_result'] = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
    
    echo json_encode(['success' => true, 'data' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
