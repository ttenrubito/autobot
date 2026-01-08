<?php
/**
 * Debug endpoint to check documents data
 * WARNING: For debugging only - no auth required
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

$db = Database::getInstance()->getPdo();

echo "<!DOCTYPE html><html><head><title>Documents Debug</title><style>
body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4;}
.app{background:#2d2d2d;padding:15px;margin:10px 0;border-left:3px solid #007acc;}
.doc{background:#252526;padding:10px;margin:5px 0;border-left:2px solid #4ec9b0;}
h2{color:#4ec9b0;} h3{color:#dcdcaa;} .label{color:#9cdcfe;} .value{color:#ce9178;}
</style></head><body>";

try {
    // Get latest applications
    $stmt = $db->prepare("
        SELECT id, application_no, campaign_id, status, line_display_name, created_at
        FROM line_applications 
        ORDER BY id DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h1>📄 Documents Debug Report</h1>";
    echo "<p>Total applications found: " . count($applications) . "</p>";
    
    foreach ($applications as $app) {
        echo "<div class='app'>";
        echo "<h2>Application #{$app['id']} - {$app['application_no']}</h2>";
        echo "<p><span class='label'>Status:</span> <span class='value'>{$app['status']}</span> | ";
        echo "<span class='label'>User:</span> <span class='value'>{$app['line_display_name']}</span></p>";
        
        // Get documents for this application
        $stmt = $db->prepare("
            SELECT * FROM application_documents WHERE application_id = ? ORDER BY id DESC
        ");
        $stmt->execute([$app['id']]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>📎 Documents: " . count($docs) . "</h3>";
        
        if (count($docs) > 0) {
            foreach ($docs as $doc) {
                echo "<div class='doc'>";
                echo "<strong>Doc #{$doc['id']}</strong><br>";
                echo "<span class='label'>Type:</span> {$doc['document_type']}<br>";
                echo "<span class='label'>Label:</span> " . ($doc['document_label'] ?? '<em>NULL</em>') . "<br>";
                echo "<span class='label'>Filename:</span> " . ($doc['file_name'] ?? $doc['original_filename'] ?? '<em>NULL</em>') . "<br>";
                echo "<span class='label'>File Path:</span> " . ($doc['file_path'] ?? '<em>NULL</em>') . "<br>";
                echo "<span class='label'>GCS Path:</span> " . ($doc['gcs_path'] ?? '<em>NULL</em>') . "<br>";
                echo "<span class='label'>Size:</span> " . ($doc['file_size'] ?? 0) . " bytes<br>";
                echo "<span class='label'>Uploaded:</span> {$doc['uploaded_at']}<br>";
                echo "</div>";
            }
        } else {
            echo "<p style='color:#ff6b6b;'>❌ No documents found for this application!</p>";
        }
        
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background:#ff0000;color:#fff;padding:20px;'>";
    echo "<h2>❌ Error</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "</div>";
}

echo "</body></html>";
