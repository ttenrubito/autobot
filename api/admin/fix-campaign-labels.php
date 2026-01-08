<?php
/**
 * Admin Migration Endpoint - Fix Campaign Labels
 * One-time fix for DEMO2026 campaign required_documents
 */

header('Content-Type: text/html; charset=utf-8');

// Simple security check
$secret = $_GET['secret'] ?? '';
if ($secret !== 'fix_demo2026_labels_now') {
    http_response_code(403);
    die('❌ Forbidden. Invalid secret.');
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

$db = Database::getInstance()->getPdo();

echo "<!DOCTYPE html><html><head><title>Fix Campaign Labels</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4;}";
echo ".success{color:#4ec9b0;} .error{color:#f48771;} .info{color:#9cdcfe;}</style></head><body>";

echo "<h1>🔧 Fixing Campaign DEMO2026 Labels</h1>";

try {
    // Check current state
    echo "<h2 class='info'>📊 Current State:</h2>";
    $stmt = $db->prepare("SELECT id, code, name, required_documents FROM campaigns WHERE code = 'DEMO2026'");
    $stmt->execute();
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$campaign) {
        echo "<p class='error'>❌ Campaign DEMO2026 not found!</p>";
        exit;
    }
    
    echo "<pre>ID: {$campaign['id']}\nCode: {$campaign['code']}\nName: {$campaign['name']}\n";
    echo "Current required_documents:\n" . json_encode(json_decode($campaign['required_documents']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    
    // New configuration
    $newRequiredDocs = [
        [
            'type' => 'id_card',
            'label' => 'บัตรประชาชน',
            'required' => true,
            'accept' => 'image/*'
        ],
        [
            'type' => 'house_registration',
            'label' => 'ทะเบียนบ้าน',
            'required' => false,
            'accept' => 'image/*,application/pdf'
        ]
    ];
    
    echo "<h2 class='info'>🔄 Updating to:</h2>";
    echo "<pre>" . json_encode($newRequiredDocs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    
    // Update
    $stmt = $db->prepare("UPDATE campaigns SET required_documents = ? WHERE code = 'DEMO2026'");
    $result = $stmt->execute([json_encode($newRequiredDocs, JSON_UNESCAPED_UNICODE)]);
    
    if ($result) {
        echo "<h2 class='success'>✅ Update Successful!</h2>";
        
        // Verify
        $stmt = $db->prepare("SELECT required_documents FROM campaigns WHERE code = 'DEMO2026'");
        $stmt->execute();
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<h2 class='info'>✓ Verified New State:</h2>";
        echo "<pre>" . json_encode(json_decode($updated['required_documents']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        
        echo "<h2 class='success'>🎉 Campaign Fix Completed!</h2>";
        echo "<p><strong>Next Steps:</strong></p>";
        echo "<ol>";
        echo "<li>Test LIFF Form: <a href='https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026' target='_blank'>Open LIFF</a></li>";
        echo "<li>Should now show: <strong>บัตรประชาชน</strong> and <strong>ทะเบียนบ้าน</strong></li>";
        echo "<li>Upload a test document</li>";
        echo "<li>Check Admin Panel: <a href='https://autobot.boxdesign.in.th/line-applications.php' target='_blank'>Open Admin</a></li>";
        echo "<li>Documents should now appear in the detail view</li>";
        echo "</ol>";
        
    } else {
        echo "<p class='error'>❌ Update failed!</p>";
    }
    
} catch (Exception $e) {
    echo "<h2 class='error'>❌ Error:</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}

echo "</body></html>";
