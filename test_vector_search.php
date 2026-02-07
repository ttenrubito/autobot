<?php
/**
 * Test Vector Search
 */

require_once __DIR__ . '/includes/services/FirestoreVectorService.php';

use Autobot\Services\FirestoreVectorService;

echo "🔍 Testing Vector Search...\n\n";

try {
    $service = new FirestoreVectorService();
    
    $testQueries = [
        'นาฬิกา Rolex สีดำ',
        'กระเป๋า Louis Vuitton',
        'แหวนเพชร',
    ];
    
    foreach ($testQueries as $query) {
        echo "Query: \"{$query}\"\n";
        $result = $service->searchSimilar($query, 3);
        
        if ($result['ok'] && !empty($result['product_ids'])) {
            echo "✅ Found " . count($result['product_ids']) . " results:\n";
            foreach ($result['product_ids'] as $refId) {
                $score = $result['scores'][$refId] ?? 0;
                echo "   → {$refId} (score: " . round($score, 3) . ")\n";
            }
        } else {
            echo "❌ No results - " . ($result['error'] ?? 'unknown error') . "\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
