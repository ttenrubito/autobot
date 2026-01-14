<?php
/**
 * Debug: Check payment data with customer profiles
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();
    
    // Get latest payment with customer profile
    $stmt = $pdo->query("
        SELECT 
            p.id,
            p.payment_no,
            p.customer_id,
            p.slip_image,
            p.payment_details,
            cp.id as cp_id,
            cp.display_name as cp_display_name,
            cp.platform as cp_platform,
            cp.platform_user_id as cp_platform_user_id,
            cp.avatar_url as cp_avatar_url,
            cp.profile_pic_url as cp_profile_pic_url
        FROM payments p
        LEFT JOIN customer_profiles cp ON p.customer_id = cp.id
        ORDER BY p.id DESC
        LIMIT 3
    ");
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process each payment
    foreach ($payments as &$payment) {
        // Parse payment_details
        $details = json_decode($payment['payment_details'] ?? '{}', true);
        $payment['ocr_sender_name'] = $details['sender_name'] ?? null;
        $payment['ocr_external_user_id'] = $details['external_user_id'] ?? null;
        $payment['payment_details'] = 'HIDDEN'; // Don't expose full details
    }
    
    // Get customer_profiles summary
    $stmt = $pdo->query("
        SELECT id, platform, platform_user_id, display_name, 
               CASE WHEN avatar_url IS NOT NULL AND avatar_url != '' THEN 'YES' ELSE 'NO' END as has_avatar_url,
               tenant_id
        FROM customer_profiles 
        ORDER BY id DESC 
        LIMIT 5
    ");
    $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'latest_payments' => $payments,
        'customer_profiles' => $profiles
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
