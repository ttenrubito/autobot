<?php
/**
 * Debug user and channel mapping
 */

require_once __DIR__ . '/../../includes/Database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    
    // Get users - use correct columns
    $users = $db->query("SELECT id, email FROM users ORDER BY id");
    
    // Get channels - check which columns exist
    $channelCols = $db->query("SHOW COLUMNS FROM customer_channels");
    $colNames = array_column($channelCols, 'Field');
    
    // Build query based on existing columns
    $selectCols = ['id', 'user_id'];
    if (in_array('channel_type', $colNames)) $selectCols[] = 'channel_type';
    if (in_array('platform', $colNames)) $selectCols[] = 'platform';
    if (in_array('channel_name', $colNames)) $selectCols[] = 'channel_name';
    if (in_array('line_channel_id', $colNames)) $selectCols[] = 'line_channel_id';
    
    $channels = $db->query("SELECT " . implode(', ', $selectCols) . " FROM customer_channels WHERE is_deleted = 0 ORDER BY id");
    
    // Get cases simple check
    $casesCheck = $db->query("SELECT id, case_no, user_id, channel_id FROM cases ORDER BY id DESC LIMIT 10");
    
    echo json_encode([
        'users' => $users,
        'channel_columns' => $colNames,
        'channels' => $channels,
        'cases_check' => $casesCheck
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
