<?php
/**
 * Test Google Cloud Storage Integration
 */

require_once __DIR__ . '/../includes/GoogleCloudStorage.php';

echo "🧪 Testing Google Cloud Storage Integration\n";
echo "===========================================\n\n";

try {
    // Test 1: Initialize GCS
    echo "1️⃣ Testing GCS Initialization...\n";
    $gcs = GoogleCloudStorage::getInstance();
    echo "✅ GCS initialized successfully\n\n";
    
    // Test 2: Upload a test file
    echo "2️⃣ Testing File Upload...\n";
    $testContent = "This is a test file created at " . date('Y-m-d H:i:s');
    $result = $gcs->uploadFile(
        $testContent,
        'test-file.txt',
        'text/plain',
        'test',
        ['test' => 'true', 'timestamp' => time()]
    );
    
    if ($result['success']) {
        echo "✅ File uploaded successfully\n";
        echo "   Path: {$result['path']}\n";
        echo "   Bucket: {$result['bucket']}\n";
        echo "   Signed URL: " . substr($result['signed_url'], 0, 80) . "...\n\n";
        
        // Test 3: Check if file exists
        echo "3️⃣ Testing File Existence...\n";
        $exists = $gcs->fileExists($result['path']);
        echo $exists ? "✅ File exists\n\n" : "❌ File not found\n\n";
        
        // Test 4: Generate new signed URL
        echo "4️⃣ Testing Signed URL Generation...\n";
        $signedUrl = $gcs->generateSignedUrl($result['path'], '+1 hour');
        echo "✅ Signed URL generated: " . substr($signedUrl, 0, 80) . "...\n\n";
        
        // Test 5: Download file
        echo "5️⃣ Testing File Download...\n";
        $downloadResult = $gcs->downloadFile($result['path']);
        if ($downloadResult['success']) {
            echo "✅ File downloaded successfully\n";
            echo "   Content: {$downloadResult['content']}\n\n";
        } else {
            echo "❌ Download failed: {$downloadResult['error']}\n\n";
        }
        
        // Test 6: Delete file
        echo "6️⃣ Testing File Deletion...\n";
        $deleted = $gcs->deleteFile($result['path']);
        echo $deleted ? "✅ File deleted successfully\n\n" : "❌ Delete failed\n\n";
        
    } else {
        echo "❌ Upload failed: {$result['error']}\n\n";
    }
    
    echo "===========================================\n";
    echo "✅ All tests completed!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
