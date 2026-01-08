<?php
/**
 * Debug: Check Campaign Configuration
 * Public endpoint to verify campaigns and LIFF IDs
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/Database.php';

try {
    $db = Database::getInstance()->getPdo();
    
    // Get active campaigns
    $stmt = $db->query("
        SELECT 
            id, 
            code, 
            name, 
            liff_id,
            is_active,
            start_date,
            end_date
        FROM campaigns 
        WHERE is_active = 1
        ORDER BY created_at DESC
        LIMIT 10
    ");
    
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check what bot query sees
    $stmt2 = $db->query("
        SELECT id, code, name, liff_id
        FROM campaigns
        WHERE is_active = 1
            AND (start_date IS NULL OR start_date <= CURDATE())
            AND (end_date IS NULL OR end_date >= CURDATE())
        ORDER BY created_at DESC
        LIMIT 5
    ");
    
    $botCampaigns = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total_active_campaigns' => count($campaigns),
        'bot_visible_campaigns' => count($botCampaigns),
        'all_campaigns' => $campaigns,
        'bot_query_result' => $botCampaigns,
        'liff_status' => [
            'with_liff' => count(array_filter($campaigns, fn($c) => !empty($c['liff_id']))),
            'without_liff' => count(array_filter($campaigns, fn($c) => empty($c['liff_id'])))
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
