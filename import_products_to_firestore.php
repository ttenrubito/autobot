<?php
/**
 * Import Products to Firestore Vector Search
 * 
 * Usage:
 *   php import_products_to_firestore.php
 *   php import_products_to_firestore.php --limit=100
 *   php import_products_to_firestore.php --test
 * 
 * Prerequisites:
 * 1. Firebase project created
 * 2. Service account JSON at config/firebase-service-account.json
 * 3. Vertex AI API enabled
 * 
 * @date 2026-01-25
 */

require_once __DIR__ . '/includes/services/FirestoreVectorService.php';
require_once __DIR__ . '/includes/services/ProductSearchService.php';
require_once __DIR__ . '/includes/Logger.php';

use Autobot\Services\FirestoreVectorService;

echo "========================================\n";
echo "🔥 Import Products to Firestore Vector Search\n";
echo "========================================\n\n";

// Parse arguments
$args = getopt('', ['limit::', 'test']);
$limit = isset($args['limit']) ? (int)$args['limit'] : 1000;
$isTest = isset($args['test']);

if ($isTest) {
    echo "🧪 TEST MODE - checking connection only\n\n";
}

// Initialize service
try {
    $vectorService = new FirestoreVectorService();
    
    // Health check
    echo "1️⃣ Checking Firestore connection...\n";
    $health = $vectorService->healthCheck();
    
    if (!$health['ok']) {
        echo "❌ Firestore connection failed!\n";
        echo "   Error: " . ($health['error'] ?? 'Unknown') . "\n";
        echo "\n📋 Make sure:\n";
        echo "   - config/firebase-service-account.json exists\n";
        echo "   - Firestore is enabled in Firebase Console\n";
        echo "   - Service account has Firestore permissions\n";
        exit(1);
    }
    
    echo "✅ Connected to project: {$health['project_id']}\n";
    echo "   Collection: {$health['collection']}\n\n";

    if ($isTest) {
        echo "\n✅ Connection test passed!\n";
        echo "\nNext steps:\n";
        echo "1. Run without --test to import products\n";
        echo "2. php import_products_to_firestore.php --limit=10\n";
        exit(0);
    }

    // Get products from ProductSearchService (mock data)
    echo "2️⃣ Fetching products from ProductSearchService...\n";
    
    // Get sample products (in production, this would come from Data Team API)
    $products = [];
    
    // Try to get from ProductSearchService if method exists
    if (method_exists('ProductSearchService', 'getAllProducts')) {
        $products = ProductSearchService::getAllProducts($limit);
    }
    
    if (empty($products)) {
        echo "⚠️ No products found from ProductSearchService\n";
        echo "   Using sample data for demonstration...\n\n";
        
        // Sample products for testing
        $products = [
            [
                'ref_id' => 'P-2026-000001',
                'product_code' => 'R-SUB-001',
                'name' => 'Rolex Submariner Date 126610LN',
                'brand' => 'Rolex',
                'category' => 'watches',
                'description' => 'นาฬิกา Rolex รุ่น Submariner Date สีดำ หน้าปัดสีดำ ขนาด 41mm สภาพดีมาก พร้อมกล่องและใบรับประกัน'
            ],
            [
                'ref_id' => 'P-2026-000002',
                'product_code' => 'R-DAY-002',
                'name' => 'Rolex Day-Date 40 Rose Gold',
                'brand' => 'Rolex',
                'category' => 'watches',
                'description' => 'นาฬิกา Rolex รุ่น Day-Date วัสดุ Rose Gold 18K หน้าปัดสีเขียวมิ้นท์ ขนาด 40mm สายแบบ President'
            ],
            [
                'ref_id' => 'P-2026-000003',
                'product_code' => 'LV-BAG-001',
                'name' => 'Louis Vuitton Neverfull MM Monogram',
                'brand' => 'Louis Vuitton',
                'category' => 'bags',
                'description' => 'กระเป๋า Louis Vuitton รุ่น Neverfull ไซส์ MM ลาย Monogram Canvas ซับในสีแดง สภาพ 90%'
            ],
            [
                'ref_id' => 'P-2026-000004',
                'product_code' => 'HE-BAG-001',
                'name' => 'Hermès Birkin 30 Togo Noir',
                'brand' => 'Hermès',
                'category' => 'bags',
                'description' => 'กระเป๋า Hermès รุ่น Birkin ขนาด 30cm หนัง Togo สีดำ อะไหล่สีทอง พร้อมกล่องและใบรับประกัน'
            ],
            [
                'ref_id' => 'P-2026-000005',
                'product_code' => 'CH-RING-001',
                'name' => 'Chanel Coco Crush Ring Yellow Gold',
                'brand' => 'Chanel',
                'category' => 'jewelry',
                'description' => 'แหวน Chanel รุ่น Coco Crush ทองคำ 18K สีเหลือง ลายคิลท์ ไซส์ 52 พร้อมกล่อง'
            ],
            [
                'ref_id' => 'P-2026-000006',
                'product_code' => 'R-GMT-001',
                'name' => 'Rolex GMT-Master II Pepsi 126710BLRO',
                'brand' => 'Rolex',
                'category' => 'watches',
                'description' => 'นาฬิกา Rolex รุ่น GMT-Master II สี Pepsi (น้ำเงิน-แดง) ขอบเซรามิก สายจูบิลี่ ขนาด 40mm'
            ],
            [
                'ref_id' => 'P-2026-000007',
                'product_code' => 'CA-RING-001',
                'name' => 'Cartier Love Ring White Gold Diamonds',
                'brand' => 'Cartier',
                'category' => 'jewelry',
                'description' => 'แหวน Cartier รุ่น Love ทองคำขาว 18K ฝังเพชร 6 เม็ด ไซส์ 54 พร้อมใบรับประกัน'
            ],
            [
                'ref_id' => 'P-2026-000008',
                'product_code' => 'OM-SEA-001',
                'name' => 'Omega Seamaster Planet Ocean 600M',
                'brand' => 'Omega',
                'category' => 'watches',
                'description' => 'นาฬิกา Omega รุ่น Seamaster Planet Ocean สีส้ม ขนาด 43.5mm ตัวเรือนสแตนเลส กันน้ำ 600m'
            ],
        ];
    }
    
    echo "   Found " . count($products) . " products\n\n";

    // Import to Firestore
    echo "3️⃣ Importing products to Firestore (generating embeddings)...\n";
    echo "   This may take a while depending on the number of products...\n\n";
    
    $result = $vectorService->batchStoreEmbeddings($products);
    
    echo "\n========================================\n";
    echo "✅ Import Complete!\n";
    echo "========================================\n";
    echo "   Success: {$result['success']} products\n";
    echo "   Failed:  {$result['failed']} products\n";
    echo "\n";
    
    if ($result['failed'] > 0) {
        echo "⚠️ Some products failed to import.\n";
        echo "   Check logs for details.\n\n";
    }

    // Test search
    echo "4️⃣ Testing vector search...\n\n";
    
    $testQueries = [
        'นาฬิกา Rolex สีดำ',
        'กระเป๋าแบรนด์เนม',
        'แหวนเพชร Cartier'
    ];
    
    foreach ($testQueries as $query) {
        echo "   Query: \"{$query}\"\n";
        $searchResult = $vectorService->searchSimilar($query, 3);
        
        if ($searchResult['ok'] && !empty($searchResult['product_ids'])) {
            foreach ($searchResult['product_ids'] as $refId) {
                $score = $searchResult['scores'][$refId] ?? 0;
                echo "   → {$refId} (score: " . round($score, 3) . ")\n";
            }
        } else {
            echo "   → No results (vector index may need time to build)\n";
        }
        echo "\n";
    }

    echo "========================================\n";
    echo "🎉 Setup Complete!\n";
    echo "========================================\n";
    echo "\nNext steps:\n";
    echo "1. Check Firebase Console for imported documents\n";
    echo "2. Verify vector index is created (may take a few minutes)\n";
    echo "3. Test search via chatbot\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
