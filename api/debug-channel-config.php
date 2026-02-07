<?php
/**
 * Debug Channel Config - Check LINE access token
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

try {
    $pdo = getDB();
    
    // Get all channels with config info
    $stmt = $pdo->query("
        SELECT 
            id, 
            name, 
            platform,
            is_active,
            JSON_EXTRACT(config, '$.line_channel_id') as line_channel_id,
            CASE 
                WHEN JSON_EXTRACT(config, '$.line_channel_access_token') IS NOT NULL 
                     AND CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(config, '$.line_channel_access_token'))) > 10 
                THEN CONCAT(LEFT(JSON_UNQUOTE(JSON_EXTRACT(config, '$.line_channel_access_token')), 20), '...')
                ELSE 'MISSING'
            END as access_token_preview,
            CHAR_LENGTH(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(config, '$.line_channel_access_token')), '')) as token_length
        FROM customer_channels
        ORDER BY id
    ");
    $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'channels' => $channels
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
