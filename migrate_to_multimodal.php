<?php
/**
 * Migrate Products to Multimodal Embeddings
 * 
 * This script creates multimodal embeddings for products using their images.
 * This enables accurate image-to-image search without relying on LLM interpretation.
 * 
 * Usage: php migrate_to_multimodal.php
 * 
 * Prerequisites:
 * - Firebase service account with Firestore access
 * - Vertex AI Multimodal Embedding API enabled
 * - Products must have image_url or primary_image field
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/services/FirestoreVectorService.php';

use Autobot\Services\FirestoreVectorService;

echo "=== Migrating Products to Multimodal Embeddings ===\n\n";

// Initialize Firestore Vector Service
$firestoreVector = new FirestoreVectorService();

// Get products that need multimodal embeddings
// These are products with images that we want to make searchable by image
$products = [
    [
        'ref_id' => 'P-2026-000001',
        'product_code' => 'W-SUB-001',
        'product_name' => 'Rolex Submariner Date 126610LN',
        'name' => 'Rolex Submariner Date 126610LN',
        'brand' => 'Rolex',
        'category' => 'Luxury Watch',
        'image_url' => 'https://storage.googleapis.com/autobot-prod-251215-22549.firebasestorage.app/products/rolex-submariner.jpg'
    ],
    [
        'ref_id' => 'P-2026-000002',
        'product_code' => 'W-DD-002',
        'product_name' => 'Rolex Day-Date 40 Rose Gold',
        'name' => 'Rolex Day-Date 40 Rose Gold',
        'brand' => 'Rolex',
        'category' => 'Luxury Watch',
        'image_url' => 'https://storage.googleapis.com/autobot-prod-251215-22549.firebasestorage.app/products/rolex-daydate.jpg'
    ],
    [
        'ref_id' => 'P-2026-000006',
        'product_code' => 'W-GMT-006',
        'product_name' => 'Rolex GMT-Master II Pepsi',
        'name' => 'Rolex GMT-Master II Pepsi 126710BLRO',
        'brand' => 'Rolex',
        'category' => 'Luxury Watch',
        'image_url' => 'https://storage.googleapis.com/autobot-prod-251215-22549.firebasestorage.app/products/rolex-gmt-pepsi.jpg'
    ],
    [
        'ref_id' => 'P-2026-000008',
        'product_code' => 'W-SEA-008',
        'product_name' => 'Omega Seamaster Planet Ocean 600M',
        'name' => 'Omega Seamaster Planet Ocean 600M',
        'brand' => 'Omega',
        'category' => 'Luxury Watch',
        'image_url' => 'https://storage.googleapis.com/autobot-prod-251215-22549.firebasestorage.app/products/omega-seamaster.jpg'
    ],
    [
        'ref_id' => 'P-2026-000005',
        'product_code' => 'J-CCR-005',
        'product_name' => 'Chanel Coco Crush Ring Yellow Gold',
        'name' => 'Chanel Coco Crush Ring Yellow Gold',
        'brand' => 'Chanel',
        'category' => 'Luxury Jewelry',
        'image_url' => 'https://storage.googleapis.com/autobot-prod-251215-22549.firebasestorage.app/products/chanel-coco-ring.jpg'
    ],
    [
        'ref_id' => 'P-2026-000007',
        'product_code' => 'J-CLR-007',
        'product_name' => 'Cartier Love Ring White Gold Diamonds',
        'name' => 'Cartier Love Ring White Gold Diamonds',
        'brand' => 'Cartier',
        'category' => 'Luxury Jewelry',
        'image_url' => 'https://storage.googleapis.com/autobot-prod-251215-22549.firebasestorage.app/products/cartier-love-ring.jpg'
    ]
];

echo "Found " . count($products) . " products to migrate\n\n";

$success = 0;
$failed = 0;

foreach ($products as $product) {
    echo "Processing: {$product['product_name']} ({$product['ref_id']})... ";
    
    $result = $firestoreVector->storeProductMultimodalEmbedding($product);
    
    if ($result) {
        echo "✅ SUCCESS\n";
        $success++;
    } else {
        echo "❌ FAILED\n";
        $failed++;
    }
    
    // Rate limiting
    sleep(1);
}

echo "\n=== Migration Complete ===\n";
echo "Success: {$success}\n";
echo "Failed: {$failed}\n";
echo "\nNow image search will use multimodal embeddings for better accuracy!\n";
