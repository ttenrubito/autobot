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
        LEFT JOIN customer_profiles cp ON p.platform_user_id = cp.platform_user_id
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
        $payment['channel_id'] = $details['channel_id'] ?? null;
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
    
    // Check conversations
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conversations");
    $convCount = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    // Check chat_events
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM chat_events");
    $chatEventsCount = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    // Check chat_messages
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM chat_messages");
    $chatMsgCount = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    // Check chat_sessions for latest payment user
    $chatSession = null;
    // Use platform_user_id from customer_profiles (like the updated API does)
    $external_user_id = $payments[0]['ocr_external_user_id'] ?? $payments[0]['cp_platform_user_id'] ?? null;
    
    if (!empty($external_user_id)) {
        // First try with channel_id if available
        $channel_id = $payments[0]['channel_id'] ?? null;
        
        if ($channel_id) {
            $stmt = $pdo->prepare("SELECT id, channel_id, external_user_id FROM chat_sessions WHERE channel_id = ? AND external_user_id = ? LIMIT 1");
            $stmt->execute([$channel_id, $external_user_id]);
        } else {
            // Fallback: find by external_user_id only
            $stmt = $pdo->prepare("SELECT id, channel_id, external_user_id FROM chat_sessions WHERE external_user_id = ? ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute([$external_user_id]);
        }
        $chatSession = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get messages count and sample for this session
        if ($chatSession) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM chat_messages WHERE session_id = ?");
            $stmt->execute([$chatSession['id']]);
            $chatSession['messages_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
            
            // Get last 3 messages as sample
            $stmt = $pdo->prepare("
                SELECT role, LEFT(text, 100) as text_preview, created_at 
                FROM chat_messages 
                WHERE session_id = ? 
                ORDER BY created_at DESC 
                LIMIT 3
            ");
            $stmt->execute([$chatSession['id']]);
            $chatSession['sample_messages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    echo json_encode([
        'success' => true,
        'latest_payments' => $payments,
        'customer_profiles' => $profiles,
        'conversations_count' => $convCount,
        'chat_events_count' => $chatEventsCount,
        'chat_messages_count' => $chatMsgCount,
        'latest_payment_session' => $chatSession
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
