<?php
/**
 * Test Firebase Connection
 */

require_once __DIR__ . '/includes/services/FirestoreVectorService.php';

use Autobot\Services\FirestoreVectorService;

echo "🔥 Testing Firestore Connection...\n\n";

try {
    $service = new FirestoreVectorService();
    $health = $service->healthCheck();
    
    echo "Project ID: " . ($health['project_id'] ?? 'N/A') . "\n";
    echo "Collection: " . ($health['collection'] ?? 'N/A') . "\n";
    echo "HTTP Code: " . ($health['http_code'] ?? 'N/A') . "\n";
    echo "Status: " . ($health['ok'] ? '✅ Connected!' : '❌ Failed') . "\n";
    
    if (!empty($health['error'])) {
        echo "Error: " . $health['error'] . "\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
