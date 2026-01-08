<?php
// Deep Debug - Document Upload Flow
header('Content-Type: text/html; charset=utf-8');
require_once 'config.php';
require_once 'includes/Database.php';

$db = Database::getInstance()->getPdo();
?>
<!DOCTYPE html>
<html>
<head><title>Deep Debug - Documents</title>
<style>
body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4;}
.section{background:#2d2d2d;padding:15px;margin:10px 0;border-left:3px solid #007acc;}
.error{color:#f48771;} .success{color:#4ec9b0;} .warning{color:#dcdcaa;}
table{width:100%;border-collapse:collapse;margin:10px 0;}
th,td{border:1px solid #444;padding:8px;text-align:left;}
th{background:#333;}
</style>
</head>
<body>
<h1>🔍 Deep Debug - Document Upload Flow</h1>

<?php
// 1. Check Campaign Configuration
echo "<div class='section'><h2>1️⃣ Campaign Configuration</h2>";
$stmt = $db->query("SELECT id, code, name, required_documents FROM campaigns WHERE code = 'DEMO2026'");
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if ($campaign) {
    echo "<p class='success'>✅ Campaign found: {$campaign['name']}</p>";
    echo "<h3>Required Documents Config:</h3>";
    $docs = json_decode($campaign['required_documents'], true);
    echo "<pre>" . json_encode($docs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    
    if (empty($docs)) {
        echo "<p class='error'>❌ ERROR: required_documents is empty!</p>";
    } else {
        foreach ($docs as $doc) {
            if (empty($doc['label'])) {
                echo "<p class='error'>❌ ERROR: Label is empty for type '{$doc['type']}'</p>";
            } else {
                echo "<p class='success'>✅ Label OK: {$doc['label']}</p>";
            }
        }
    }
} else {
    echo "<p class='error'>❌ Campaign DEMO2026 not found!</p>";
}
echo "</div>";

// 2. Check Recent Applications
echo "<div class='section'><h2>2️⃣ Recent Applications</h2>";
$stmt = $db->query("
    SELECT id, application_no, line_display_name, status, created_at 
    FROM line_applications 
    ORDER BY id DESC 
    LIMIT 5
");
$apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($apps) > 0) {
    echo "<table><tr><th>ID</th><th>Application No</th><th>User</th><th>Status</th><th>Created</th></tr>";
    foreach ($apps as $app) {
        echo "<tr><td>{$app['id']}</td><td>{$app['application_no']}</td><td>{$app['line_display_name']}</td><td>{$app['status']}</td><td>{$app['created_at']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warning'>⚠️ No applications found</p>";
}
echo "</div>";

// 3. Check Documents Table Schema
echo "<div class='section'><h2>3️⃣ Documents Table Schema</h2>";
$stmt = $db->query("SHOW COLUMNS FROM application_documents");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
foreach ($columns as $col) {
    $highlight = in_array($col['Field'], ['gcs_path', 'gcs_signed_url', 'gcs_signed_url_expires_at']) ? ' class="success"' : '';
    echo "<tr{$highlight}><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td><td>{$col['Default']}</td></tr>";
}
echo "</table>";

// Check if GCS columns exist
$hasGcsPath = false;
foreach ($columns as $col) {
    if ($col['Field'] === 'gcs_path') {
        $hasGcsPath = true;
        break;
    }
}

if ($hasGcsPath) {
    echo "<p class='success'>✅ GCS columns exist</p>";
} else {
    echo "<p class='error'>❌ GCS columns missing! Run migration.</p>";
}
echo "</div>";

// 4. Check Actual Documents
echo "<div class='section'><h2>4️⃣ Actual Documents in Database</h2>";
$stmt = $db->query("
    SELECT 
        ad.*,
        la.application_no,
        la.line_display_name
    FROM application_documents ad
    LEFT JOIN line_applications la ON ad.application_id = la.id
    ORDER BY ad.id DESC
    LIMIT 10
");
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($docs) > 0) {
    echo "<p class='success'>✅ Found " . count($docs) . " documents</p>";
    echo "<table style='font-size:12px;'>";
    echo "<tr><th>ID</th><th>App No</th><th>Type</th><th>Label</th><th>Filename</th><th>GCS Path</th><th>Has URL</th><th>Uploaded</th></tr>";
    foreach ($docs as $doc) {
        $hasUrl = !empty($doc['gcs_signed_url']) ? '✅' : '❌';
        $gcsPath = !empty($doc['gcs_path']) ? substr($doc['gcs_path'], 0, 40) . '...' : '<span class="error">NULL</span>';
        echo "<tr>";
        echo "<td>{$doc['id']}</td>";
        echo "<td>{$doc['application_no']}</td>";
        echo "<td>{$doc['document_type']}</td>";
        echo "<td>" . ($doc['document_label'] ?? '<span class="warning">NULL</span>') . "</td>";
        echo "<td>" . ($doc['file_name'] ?? $doc['original_filename'] ?? 'NULL') . "</td>";
        echo "<td>{$gcsPath}</td>";
        echo "<td>{$hasUrl}</td>";
        echo "<td>{$doc['uploaded_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>❌ NO DOCUMENTS FOUND IN DATABASE!</p>";
    echo "<p class='warning'>This means uploads are failing to save to database.</p>";
}
echo "</div>";

// 5. Check Admin API Response
if (count($apps) > 0) {
    $testAppId = $apps[0]['id'];
    echo "<div class='section'><h2>5️⃣ Admin API Response Test</h2>";
    echo "<p>Testing with Application ID: {$testAppId}</p>";
    
    $stmt = $db->prepare("
        SELECT 
            la.*,
            c.name as campaign_name,
            c.form_config,
            c.required_documents
        FROM line_applications la
        JOIN campaigns c ON la.campaign_id = c.id
        WHERE la.id = ?
    ");
    $stmt->execute([$testAppId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get documents
    $stmt = $db->prepare("SELECT * FROM application_documents WHERE application_id = ? ORDER BY uploaded_at ASC");
    $stmt->execute([$testAppId]);
    $appDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Documents count for this application: <strong>" . count($appDocs) . "</strong></p>";
    
    if (count($appDocs) > 0) {
        echo "<p class='success'>✅ Admin API would return documents</p>";
        echo "<pre>";
        foreach ($appDocs as $d) {
            echo "- {$d['document_type']} ({$d['document_label']})\n";
        }
        echo "</pre>";
    } else {
        echo "<p class='error'>❌ Admin API would return EMPTY documents array</p>";
    }
    echo "</div>";
}

// 6. Test Document Upload Endpoint
echo "<div class='section'><h2>6️⃣ Upload Endpoint Test</h2>";
echo "<p>Check if endpoint exists and GCS is configured:</p>";

if (file_exists(__DIR__ . '/api/lineapp/documents.php')) {
    echo "<p class='success'>✅ Upload endpoint exists: api/lineapp/documents.php</p>";
} else {
    echo "<p class='error'>❌ Upload endpoint missing!</p>";
}

if (file_exists(__DIR__ . '/includes/GoogleCloudStorage.php')) {
    echo "<p class='success'>✅ GCS helper exists</p>";
} else {
    echo "<p class='error'>❌ GCS helper missing!</p>";
}

if (file_exists(__DIR__ . '/config/gcp/service-account.json')) {
    echo "<p class='success'>✅ Service account file exists</p>";
} else {
    echo "<p class='error'>❌ Service account missing!</p>";
}

echo "</div>";

// 7. Recommendations
echo "<div class='section'><h2>7️⃣ Issue Analysis</h2>";

$issues = [];
$fixes = [];

if (empty($docs) || empty($docs[0]['label'])) {
    $issues[] = "Campaign labels are empty";
    $fixes[] = "Run: curl 'https://autobot.boxdesign.in.th/api/admin/fix-campaign-labels.php?secret=fix_demo2026_labels_now'";
}

if (count($docs) === 0 && count($apps) > 0) {
    $issues[] = "Documents not saving to database after upload";
    $fixes[] = "Check browser console for upload errors";
    $fixes[] = "Check if GCS upload is failing (permissions issue)";
}

if (!$hasGcsPath) {
    $issues[] = "GCS columns missing from database";
    $fixes[] = "Run migration script: ./run_migration_api.sh";
}

if (count($issues) > 0) {
    echo "<h3 class='error'>❌ Issues Found:</h3><ul>";
    foreach ($issues as $issue) {
        echo "<li>{$issue}</li>";
    }
    echo "</ul>";
    
    echo "<h3 class='warning'>🔧 Fixes:</h3><ol>";
    foreach ($fixes as $fix) {
        echo "<li>{$fix}</li>";
    }
    echo "</ol>";
} else {
    echo "<p class='success'>✅ No obvious issues detected</p>";
}

echo "</div>";
?>

</body>
</html>
