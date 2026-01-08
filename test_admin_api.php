<?php
// Test script to check admin API response
require_once 'config.php';
require_once 'includes/Database.php';

$db = Database::getInstance()->getPdo();

// Get latest application
$stmt = $db->prepare("SELECT id, application_no FROM line_applications ORDER BY id DESC LIMIT 1");
$stmt->execute();
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if ($app) {
    echo "Latest Application:\n";
    echo "  ID: {$app['id']}\n";
    echo "  No: {$app['application_no']}\n\n";
    
    // Get documents for this application
    $stmt = $db->prepare("SELECT * FROM application_documents WHERE application_id = ? ORDER BY id DESC");
    $stmt->execute([$app['id']]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Documents count: " . count($docs) . "\n\n";
    
    foreach ($docs as $doc) {
        echo "Document ID {$doc['id']}:\n";
        echo "  Type: {$doc['document_type']}\n";
        echo "  Label: " . ($doc['document_label'] ?? 'NULL') . "\n";
        echo "  Filename: " . ($doc['file_name'] ?? $doc['original_filename'] ?? 'NULL') . "\n";
        echo "  File Path: " . ($doc['file_path'] ?? 'NULL') . "\n";
        echo "  GCS Path: " . ($doc['gcs_path'] ?? 'NULL') . "\n";
        echo "  Uploaded: {$doc['uploaded_at']}\n";
        echo "\n";
    }
} else {
    echo "No applications found\n";
}
