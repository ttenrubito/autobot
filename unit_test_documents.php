<?php
/**
 * UNIT TEST - Document Upload & Display Flow
 * Test ทุกจุดตั้งแต่ LIFF upload จนถึง Admin display
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/GoogleCloudStorage.php';

$db = Database::getInstance()->getPdo();

// Output style
$GREEN = "\033[0;32m";
$RED = "\033[0;31m";
$YELLOW = "\033[1;33m";
$NC = "\033[0m"; // No Color

$passed = 0;
$failed = 0;

function pass($msg) {
    global $passed, $GREEN, $NC;
    echo "{$GREEN}✅ PASS{$NC}: $msg\n";
    $passed++;
}

function fail($msg, $detail = '') {
    global $failed, $RED, $NC;
    echo "{$RED}❌ FAIL{$NC}: $msg\n";
    if ($detail) echo "   Detail: $detail\n";
    $failed++;
}

function info($msg) {
    global $YELLOW, $NC;
    echo "{$YELLOW}ℹ️  INFO{$NC}: $msg\n";
}

echo "🧪 UNIT TEST - Document Upload Flow\n";
echo "=====================================\n\n";

// ============================================================================
// TEST 1: Database Schema
// ============================================================================
echo "Test 1: Database Schema Check\n";
echo "------------------------------\n";

$stmt = $db->query("SHOW COLUMNS FROM application_documents");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
$columnNames = array_column($columns, 'Field');

// Check required columns
$requiredColumns = ['id', 'application_id', 'document_type', 'document_label', 'file_name', 'gcs_path', 'gcs_signed_url'];
foreach ($requiredColumns as $col) {
    if (in_array($col, $columnNames)) {
        pass("Column exists: $col");
    } else {
        fail("Missing column: $col");
    }
}
echo "\n";

// ============================================================================
// TEST 2: Campaign Configuration
// ============================================================================
echo "Test 2: Campaign Configuration\n";
echo "-------------------------------\n";

$stmt = $db->query("SELECT id, code, name, required_documents FROM campaigns WHERE code = 'DEMO2026'");
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if ($campaign) {
    pass("Campaign DEMO2026 exists (ID: {$campaign['id']})");
    
    $docs = json_decode($campaign['required_documents'], true);
    if ($docs && is_array($docs) && count($docs) > 0) {
        pass("Campaign has required_documents config (" . count($docs) . " types)");
        
        foreach ($docs as $idx => $doc) {
            if (isset($doc['label']) && !empty($doc['label'])) {
                pass("Document type '{$doc['type']}' has label: '{$doc['label']}'");
            } else {
                fail("Document type '{$doc['type']}' has EMPTY label", "This will cause LIFF to show generic 'เอกสาร'");
            }
        }
    } else {
        fail("Campaign has NO required_documents or invalid JSON");
    }
} else {
    fail("Campaign DEMO2026 not found!");
}
echo "\n";

// ============================================================================
// TEST 3: Simulate LIFF Upload (API Test)
// ============================================================================
echo "Test 3: Simulate Document Upload API\n";
echo "-------------------------------------\n";

// Create test application
info("Creating test application...");
$testLineUserId = 'TEST_USER_' . time();
$testAppNo = 'TEST_APP_' . time();

$stmt = $db->prepare("
    INSERT INTO line_applications (
        application_no, campaign_id, campaign_name, line_user_id, 
        line_display_name, status, submitted_at
    ) VALUES (?, ?, ?, ?, ?, ?, NOW())
");

try {
    $stmt->execute([
        $testAppNo,
        $campaign['id'],
        $campaign['name'],
        $testLineUserId,
        'Test User',
        'DOC_PENDING'
    ]);
    $testApplicationId = $db->lastInsertId();
    pass("Test application created (ID: $testApplicationId)");
} catch (Exception $e) {
    fail("Failed to create test application", $e->getMessage());
    exit(1);
}

// Simulate document upload
info("Simulating document upload...");
$testDocType = 'id_card';
$testDocLabel = 'บัตรประชาชน'; // THIS IS CRITICAL!
$testFileName = 'test_id_card.jpg';
$testGcsPath = 'documents/test/test_id_card.jpg';
$testSignedUrl = 'https://storage.googleapis.com/test-signed-url';

$stmt = $db->prepare("
    INSERT INTO application_documents (
        application_id,
        document_type,
        document_label,
        file_name,
        file_size,
        mime_type,
        gcs_path,
        gcs_signed_url,
        uploaded_by,
        uploaded_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

try {
    $stmt->execute([
        $testApplicationId,
        $testDocType,
        $testDocLabel,  // ← CRITICAL FIELD
        $testFileName,
        12345,
        'image/jpeg',
        $testGcsPath,
        $testSignedUrl,
        $testLineUserId
    ]);
    $testDocId = $db->lastInsertId();
    pass("Document inserted with label '$testDocLabel' (ID: $testDocId)");
} catch (Exception $e) {
    fail("Failed to insert document", $e->getMessage());
}
echo "\n";

// ============================================================================
// TEST 4: Verify Document Label Saved
// ============================================================================
echo "Test 4: Verify Document Label in Database\n";
echo "------------------------------------------\n";

$stmt = $db->prepare("SELECT * FROM application_documents WHERE id = ?");
$stmt->execute([$testDocId]);
$savedDoc = $stmt->fetch(PDO::FETCH_ASSOC);

if ($savedDoc) {
    pass("Document retrieved from database");
    
    if ($savedDoc['document_label'] === $testDocLabel) {
        pass("document_label matches: '{$savedDoc['document_label']}'");
    } else {
        fail("document_label MISMATCH!", "Expected: '$testDocLabel', Got: '{$savedDoc['document_label']}'");
    }
    
    if (!empty($savedDoc['gcs_path'])) {
        pass("gcs_path is set: {$savedDoc['gcs_path']}");
    } else {
        fail("gcs_path is NULL");
    }
} else {
    fail("Document not found in database!");
}
echo "\n";

// ============================================================================
// TEST 5: Simulate Admin API Query
// ============================================================================
echo "Test 5: Simulate Admin API Query\n";
echo "---------------------------------\n";

info("Fetching application with documents (as admin API does)...");

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
$stmt->execute([$testApplicationId]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if ($app) {
    pass("Application fetched");
} else {
    fail("Application not found");
}

// Get documents
$stmt = $db->prepare("
    SELECT * FROM application_documents WHERE application_id = ? ORDER BY uploaded_at ASC
");
$stmt->execute([$testApplicationId]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($documents) > 0) {
    pass("Documents fetched: " . count($documents) . " document(s)");
    
    foreach ($documents as $doc) {
        if (!empty($doc['document_label'])) {
            pass("Document has label: '{$doc['document_label']}'");
        } else {
            fail("Document label is EMPTY/NULL", "Admin panel will NOT display this document properly");
        }
    }
} else {
    fail("NO documents found for application!");
}
echo "\n";

// ============================================================================
// TEST 6: Simulate Admin Panel Rendering
// ============================================================================
echo "Test 6: Simulate Admin Panel Rendering\n";
echo "---------------------------------------\n";

info("Simulating JavaScript renderDocuments() function...");

function renderDocuments($documents) {
    if (!$documents || count($documents) === 0) {
        return '<p>ไม่มีเอกสาร</p>';
    }
    
    $html = '';
    foreach ($documents as $doc) {
        $label = $doc['document_label'] ?? $doc['document_type'] ?? 'ไม่ระบุ';
        $filename = $doc['file_name'] ?? $doc['original_filename'] ?? 'unknown';
        $size = $doc['file_size'] ?? 0;
        
        $html .= "📄 {$label}\n";
        $html .= "   File: {$filename} (" . round($size/1024, 2) . " KB)\n";
    }
    return $html;
}

$rendered = renderDocuments($documents);
if (strpos($rendered, 'บัตรประชาชน') !== false) {
    pass("Rendered HTML contains Thai label 'บัตรประชาชน'");
    info("Preview:\n" . $rendered);
} else {
    fail("Rendered HTML does NOT contain Thai label!", "Output: " . substr($rendered, 0, 100));
}
echo "\n";

// ============================================================================
// TEST 7: Check API Endpoint File
// ============================================================================
echo "Test 7: Check API Endpoint\n";
echo "--------------------------\n";

$apiFile = __DIR__ . '/api/lineapp/documents.php';
if (file_exists($apiFile)) {
    pass("API endpoint exists: $apiFile");
    
    $apiContent = file_get_contents($apiFile);
    if (strpos($apiContent, 'document_label') !== false) {
        pass("API code includes 'document_label' handling");
    } else {
        fail("API code does NOT mention 'document_label'", "Upload will not save labels!");
    }
} else {
    fail("API endpoint NOT found: $apiFile");
}
echo "\n";

// ============================================================================
// TEST 8: Check LIFF Form File
// ============================================================================
echo "Test 8: Check LIFF Form\n";
echo "-----------------------\n";

$liffFile = __DIR__ . '/liff/application-form.html';
if (file_exists($liffFile)) {
    pass("LIFF form exists: $liffFile");
    
    $liffContent = file_get_contents($liffFile);
    if (strpos($liffContent, 'document_label') !== false) {
        pass("LIFF code includes 'document_label' in upload");
    } else {
        fail("LIFF code does NOT send 'document_label'", "API won't receive label!");
    }
    
    if (strpos($liffContent, 'renderDocumentFields') !== false) {
        pass("LIFF has dynamic document rendering");
    } else {
        fail("LIFF missing renderDocumentFields function");
    }
} else {
    fail("LIFF form NOT found: $liffFile");
}
echo "\n";

// ============================================================================
// CLEANUP
// ============================================================================
echo "Cleanup: Removing test data\n";
echo "----------------------------\n";

try {
    $db->prepare("DELETE FROM application_documents WHERE id = ?")->execute([$testDocId]);
    $db->prepare("DELETE FROM line_applications WHERE id = ?")->execute([$testApplicationId]);
    pass("Test data cleaned up");
} catch (Exception $e) {
    fail("Cleanup failed", $e->getMessage());
}
echo "\n";

// ============================================================================
// SUMMARY
// ============================================================================
echo "=====================================\n";
echo "TEST SUMMARY\n";
echo "=====================================\n";
echo "✅ Passed: $passed\n";
echo "❌ Failed: $failed\n";
echo "\n";

if ($failed === 0) {
    echo "{$GREEN}🎉 ALL TESTS PASSED!{$NC}\n";
    echo "\n";
    echo "System is ready to deploy.\n";
    echo "\n";
    echo "Next steps:\n";
    echo "  1. Deploy: gcloud run deploy autobot --source=. --region=asia-southeast1\n";
    echo "  2. Run migration: ./run_migration_api.sh\n";
    echo "  3. Test LIFF upload\n";
    echo "  4. Verify in admin panel\n";
    exit(0);
} else {
    echo "{$RED}⚠️  {$failed} TEST(S) FAILED!{$NC}\n";
    echo "\n";
    echo "DO NOT DEPLOY until all tests pass!\n";
    echo "\n";
    echo "Fix the issues above and run again:\n";
    echo "  php unit_test_documents.php\n";
    exit(1);
}
