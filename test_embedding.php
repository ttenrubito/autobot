<?php
/**
 * Test Embedding Generation
 */

require_once __DIR__ . '/includes/services/FirestoreVectorService.php';

use Autobot\Services\FirestoreVectorService;

echo "🧠 Testing Embedding Generation...\n\n";

try {
    $service = new FirestoreVectorService();
    
    $testText = "นาฬิกา Rolex Submariner สีดำ หน้าปัดสีดำ";
    echo "Test text: {$testText}\n\n";
    
    $embedding = $service->generateEmbedding($testText);
    
    if (!empty($embedding)) {
        echo "✅ Embedding generated successfully!\n";
        echo "Vector dimensions: " . count($embedding) . "\n";
        echo "First 5 values: [" . implode(', ', array_map(fn($v) => round($v, 4), array_slice($embedding, 0, 5))) . ", ...]\n";
    } else {
        echo "❌ Failed to generate embedding\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
