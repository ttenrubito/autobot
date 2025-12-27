<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = getDbConnection();

// Check channels for user_id = 3
$stmt = $db->prepare("
    SELECT 
        id,
        name,
        platform,
        SUBSTRING(inbound_api_key, 1, 30) as api_key_preview,
        is_active,
        bot_profile_id,
        created_at
    FROM customer_channels
    WHERE user_id = 3
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute();
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Channels for User 3 ===\n\n";
foreach ($channels as $ch) {
    echo "ID: {$ch['id']}\n";
    echo "Name: {$ch['name']}\n";
    echo "Platform: {$ch['platform']}\n";
    echo "API Key: {$ch['api_key_preview']}...\n";
    echo "Active: " . ($ch['is_active'] ? 'YES' : 'NO') . "\n";
    echo "Bot Profile ID: {$ch['bot_profile_id']}\n";
    echo "Created: {$ch['created_at']}\n";
    echo "---\n";
}

echo "\n=== Bot Profiles for User 3 ===\n\n";
$stmt2 = $db->prepare("
    SELECT id, name, handler_key, is_active  
    FROM customer_bot_profiles
    WHERE user_id = 3
    ORDER BY id
");
$stmt2->execute();
$profiles = $stmt2->fetchAll(PDO::FETCH_ASSOC);

foreach ($profiles as $p) {
    echo "ID: {$p['id']} | Name: {$p['name']} | Handler: {$p['handler_key']} | Active: " . ($p['is_active'] ? 'YES' : 'NO') . "\n";
}
