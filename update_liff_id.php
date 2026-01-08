<?php
/**
 * LIFF ID Updater
 * Update LIFF ID for campaigns after creating LIFF App in LINE Login Channel
 */

require_once __DIR__ . '/includes/Database.php';

echo "🔧 LIFF ID Updater\n";
echo "==================\n\n";

// Get database connection
try {
    $db = Database::getInstance()->getPdo();
    echo "✅ Connected to database\n\n";
} catch (Exception $e) {
    die("❌ Database connection failed: " . $e->getMessage() . "\n");
}

// Step 1: Show current campaigns
echo "📋 Current Active Campaigns:\n";
echo "----------------------------\n";

$stmt = $db->query("
    SELECT id, code, name, liff_id, is_active
    FROM campaigns
    WHERE is_active = 1
    ORDER BY created_at DESC
");

$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($campaigns)) {
    echo "⚠️  No active campaigns found\n\n";
    exit;
}

foreach ($campaigns as $campaign) {
    $liffStatus = $campaign['liff_id'] 
        ? "✅ {$campaign['liff_id']}" 
        : "❌ NOT SET";
    
    echo sprintf(
        "ID: %d | Code: %s | Name: %s\nLIFF ID: %s\n\n",
        $campaign['id'],
        $campaign['code'],
        $campaign['name'],
        $liffStatus
    );
}

// Step 2: Interactive update
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
echo "💡 Instructions:\n";
echo "1. Create LIFF App in LINE Login Channel\n";
echo "2. Get LIFF ID (format: 1234567890-AbCdEfGh)\n";
echo "3. Run this script to update database\n\n";

echo "Enter Campaign ID to update (or 'q' to quit): ";
$campaignId = trim(fgets(STDIN));

if ($campaignId === 'q') {
    echo "\n👋 Goodbye!\n";
    exit;
}

if (!is_numeric($campaignId)) {
    die("\n❌ Invalid Campaign ID\n");
}

// Verify campaign exists
$stmt = $db->prepare("SELECT id, code, name FROM campaigns WHERE id = ?");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    die("\n❌ Campaign not found\n");
}

echo "\n✅ Selected Campaign:\n";
echo "   ID: {$campaign['id']}\n";
echo "   Code: {$campaign['code']}\n";
echo "   Name: {$campaign['name']}\n\n";

echo "Enter LIFF ID (format: 1234567890-AbCdEfGh): ";
$liffId = trim(fgets(STDIN));

// Validate LIFF ID format
if (!preg_match('/^\d{10}-[A-Za-z0-9]{8,}$/', $liffId)) {
    die("\n❌ Invalid LIFF ID format. Should be like: 1234567890-AbCdEfGh\n");
}

echo "\n🔄 Updating database...\n";

try {
    $stmt = $db->prepare("UPDATE campaigns SET liff_id = ? WHERE id = ?");
    $stmt->execute([$liffId, $campaignId]);
    
    echo "✅ LIFF ID updated successfully!\n\n";
    
    // Show updated campaign
    $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
    $stmt->execute([$campaignId]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "📋 Updated Campaign:\n";
    echo "-------------------\n";
    echo "Code: {$updated['code']}\n";
    echo "Name: {$updated['name']}\n";
    echo "LIFF ID: {$updated['liff_id']}\n\n";
    
    // Generate LIFF URL
    $liffUrl = "https://liff.line.me/{$liffId}?campaign=" . urlencode($updated['code']);
    
    echo "🔗 LIFF URL:\n";
    echo "{$liffUrl}\n\n";
    
    echo "✅ Done! Users will now see LIFF URL when they ask for campaigns.\n";
    
} catch (Exception $e) {
    die("\n❌ Update failed: " . $e->getMessage() . "\n");
}
