<?php
/**
 * Sync Products to Firestore Vector Database
 * 
 * This script:
 * 1. Fetches products from ProductSearchService (mock API)
 * 2. Generates embeddings for each product
 * 3. Stores embeddings in Firestore products_vectors collection
 * 
 * Run: php scripts/sync_products_to_firestore.php
 * 
 * Prerequisites:
 * - config/firebase-service-account.json configured
 * - Vertex AI API enabled in Google Cloud
 * - Firestore database created
 * 
 * @date 2026-01-25
 */

require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/services/FirestoreVectorService.php';
require_once __DIR__ . '/../includes/services/ProductSearchService.php';

use Autobot\Services\FirestoreVectorService;

echo "=== Sync Products to Firestore Vector Database ===\n\n";

// Initialize Firestore service
try {
    $firestoreVector = new FirestoreVectorService();
    echo "✅ Firestore service initialized\n";
} catch (Exception $e) {
    echo "❌ Failed to initialize Firestore: " . $e->getMessage() . "\n";
    exit(1);
}

// Health check
echo "\n--- Checking Firestore connection ---\n";
$health = $firestoreVector->healthCheck();
if ($health['ok']) {
    echo "✅ Firestore is accessible\n";
    echo "   Project ID: " . $health['project_id'] . "\n";
    echo "   Collection: " . $health['collection'] . "\n";
} else {
    echo "❌ Firestore health check failed: " . ($health['error'] ?? 'Unknown error') . "\n";
    exit(1);
}

// Get all products from ProductSearchService
echo "\n--- Fetching products ---\n";

// Get sample products (mock data)
$allProducts = [];

// Get products by various categories
$categories = ['นาฬิกา', 'Rolex', 'Omega', 'แหวน', 'สร้อย', 'เพชร'];
foreach ($categories as $category) {
    $products = ProductSearchService::searchByKeyword($category, 10);
    foreach ($products as $product) {
        $refId = $product['ref_id'] ?? $product['product_code'] ?? null;
        if ($refId && !isset($allProducts[$refId])) {
            $allProducts[$refId] = $product;
        }
    }
}

echo "Found " . count($allProducts) . " unique products\n";

if (empty($allProducts)) {
    echo "⚠️ No products found. Make sure ProductSearchService has data.\n";
    exit(0);
}

// Sync to Firestore
echo "\n--- Syncing to Firestore ---\n";
$result = $firestoreVector->batchStoreEmbeddings(array_values($allProducts));

echo "\n=== Sync Complete ===\n";
echo "✅ Success: " . $result['success'] . " products\n";
if ($result['failed'] > 0) {
    echo "❌ Failed: " . $result['failed'] . " products\n";
}

// Test vector search
echo "\n--- Testing Vector Search ---\n";
$testQueries = [
    'นาฬิกา Rolex หน้าปัดสีทอง',
    'แหวนเพชรแท้',
    'สร้อยคอทองคำ'
];

foreach ($testQueries as $query) {
    echo "\nQuery: \"{$query}\"\n";
    $searchResult = $firestoreVector->searchSimilar($query, 3);
    
    if ($searchResult['ok'] && !empty($searchResult['product_ids'])) {
        echo "  Found " . count($searchResult['product_ids']) . " results:\n";
        foreach ($searchResult['product_ids'] as $refId) {
            $score = $searchResult['scores'][$refId] ?? 0;
            echo "    - {$refId} (similarity: " . round($score * 100, 1) . "%)\n";
        }
    } else {
        echo "  No results found\n";
    }
}

echo "\n✅ All done!\n";
