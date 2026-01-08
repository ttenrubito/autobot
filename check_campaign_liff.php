<?php
/**
 * Check Campaign LIFF ID Configuration
 */

require_once __DIR__ . '/includes/Database.php';

echo "🔍 Checking Campaign LIFF Configuration\n";
echo "========================================\n\n";

try {
    $db = Database::getInstance()->getPdo();
    
    // Check campaigns
    $stmt = $db->query("
        SELECT 
            id, 
            code, 
            name, 
            liff_id,
            is_active,
            start_date,
            end_date,
            created_at
        FROM campaigns 
        WHERE is_active = 1
        ORDER BY created_at DESC
        LIMIT 10
    ");
    
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📋 Active Campaigns:\n";
    echo "-------------------\n";
    
    if (empty($campaigns)) {
        echo "❌ No active campaigns found!\n";
        exit(1);
    }
    
    foreach ($campaigns as $campaign) {
        $liffStatus = !empty($campaign['liff_id']) 
            ? "✅ " . $campaign['liff_id'] 
            : "❌ NOT SET (NULL or empty)";
        
        $dateStatus = "";
        if ($campaign['start_date']) {
            $dateStatus .= "Start: {$campaign['start_date']} ";
        }
        if ($campaign['end_date']) {
            $dateStatus .= "End: {$campaign['end_date']}";
        }
        
        echo "\n";
        echo "ID: {$campaign['id']}\n";
        echo "Code: {$campaign['code']}\n";
        echo "Name: {$campaign['name']}\n";
        echo "LIFF ID: {$liffStatus}\n";
        echo "Active: " . ($campaign['is_active'] ? "YES ✅" : "NO ❌") . "\n";
        if ($dateStatus) {
            echo "Dates: {$dateStatus}\n";
        }
        echo "Created: {$campaign['created_at']}\n";
        
        // Generate LIFF URL if LIFF ID exists
        if (!empty($campaign['liff_id'])) {
            $liffUrl = "https://liff.line.me/{$campaign['liff_id']}?campaign=" . urlencode($campaign['code']);
            echo "📱 LIFF URL: {$liffUrl}\n";
        }
    }
    
    echo "\n";
    echo "========================================\n";
    echo "📊 Summary:\n";
    echo "Total active campaigns: " . count($campaigns) . "\n";
    
    $withLiff = array_filter($campaigns, fn($c) => !empty($c['liff_id']));
    $withoutLiff = array_filter($campaigns, fn($c) => empty($c['liff_id']));
    
    echo "With LIFF ID: " . count($withLiff) . " ✅\n";
    echo "Without LIFF ID: " . count($withoutLiff) . " ❌\n";
    
    echo "\n";
    
    // Check what the bot will actually query
    echo "🤖 Bot Query Test (what showCampaignList() sees):\n";
    echo "---------------------------------------------------\n";
    
    $stmt = $db->query("
        SELECT id, code, name, description, liff_id, line_rich_menu_id
        FROM campaigns
        WHERE is_active = 1
            AND (start_date IS NULL OR start_date <= CURDATE())
            AND (end_date IS NULL OR end_date >= CURDATE())
        ORDER BY created_at DESC
        LIMIT 5
    ");
    
    $botCampaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Campaigns Bot will show: " . count($botCampaigns) . "\n\n";
    
    foreach ($botCampaigns as $campaign) {
        echo "Code: {$campaign['code']}\n";
        echo "LIFF ID: " . ($campaign['liff_id'] ?: '❌ EMPTY') . "\n";
        echo "Will show LIFF URL: " . (!empty($campaign['liff_id']) ? "YES ✅" : "NO ❌") . "\n";
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
