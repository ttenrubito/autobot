<?php
/**
 * API Endpoint to trigger multimodal embedding migration
 * 
 * Usage: POST /api/admin/migrate-multimodal.php
 * Requires admin authentication
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/services/FirestoreVectorService.php';

use Autobot\Services\FirestoreVectorService;

header('Content-Type: application/json');

// Simple security check (should use proper auth in production)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expectedToken = getenv('ADMIN_API_KEY') ?: 'migrate-multimodal-2026';

if (!str_contains($authHeader, $expectedToken) && ($_GET['key'] ?? '') !== $expectedToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

\Logger::info("[Migration] Starting multimodal embedding migration");

// Initialize Firestore Vector Service
$firestoreVector = new FirestoreVectorService();

// Get products from database or use static list
// Using Unsplash (no rate limit) and direct image URLs
// IMPORTANT: ref_id MUST match mock data in api/v1/products/search.php
$products = [
    [
        // Mock: P-2026-000001 = Rolex Day-Date 36mm Yellow Gold
        'ref_id' => 'P-2026-000001',
        'product_code' => 'ROL-DAY-001',
        'product_name' => 'Rolex Day-Date 36mm Yellow Gold',
        'name' => 'Rolex Day-Date 36mm Yellow Gold',
        'brand' => 'Rolex',
        'category' => 'watch',
        // Unsplash gold watch (matches mock thumbnail_url)
        'image_url' => 'https://images.unsplash.com/photo-1547996160-81dfa63595aa?w=400'
    ],
    [
        // Mock: P-2026-000002 = Rolex Submariner Date Black Dial
        'ref_id' => 'P-2026-000002',
        'product_code' => 'ROL-SUB-002',
        'product_name' => 'Rolex Submariner Date Black Dial',
        'name' => 'Rolex Submariner Date Black Dial',
        'brand' => 'Rolex',
        'category' => 'watch',
        // Unsplash submariner style watch (matches mock thumbnail_url)
        'image_url' => 'https://images.unsplash.com/photo-1523170335258-f5ed11844a49?w=400'
    ],
    [
        // Mock: P-2026-000003 = Tag Heuer Carrera Chronograph
        'ref_id' => 'P-2026-000003',
        'product_code' => 'TAG-CAR-001',
        'product_name' => 'Tag Heuer Carrera Chronograph',
        'name' => 'Tag Heuer Carrera Chronograph',
        'brand' => 'Tag Heuer',
        'category' => 'watch',
        // Unsplash chronograph watch (matches mock thumbnail_url)
        'image_url' => 'https://images.unsplash.com/photo-1524592094714-0f0654e20314?w=400'
    ],
    [
        // Mock: P-2026-000004 = Omega Seamaster Planet Ocean 600M
        'ref_id' => 'P-2026-000004',
        'product_code' => 'OMG-SEA-001',
        'product_name' => 'Omega Seamaster Planet Ocean 600M',
        'name' => 'Omega Seamaster Planet Ocean 600M',
        'brand' => 'Omega',
        'category' => 'watch',
        // Unsplash omega style watch (matches mock thumbnail_url)
        'image_url' => 'https://images.unsplash.com/photo-1548171915-e79a380a2a4b?w=400'
    ],
    [
        // Mock: P-2026-000010 = แหวนเพชรแท้ 1 กะรัต ทองขาว
        'ref_id' => 'P-2026-000010',
        'product_code' => 'DIA-RNG-001',
        'product_name' => 'แหวนเพชรแท้ 1 กะรัต ทองขาว',
        'name' => 'แหวนเพชรแท้ 1 กะรัต ทองขาว',
        'brand' => 'เพชรวิบวับ',
        'category' => 'ring',
        // Unsplash diamond ring (matches mock thumbnail_url)
        'image_url' => 'https://images.unsplash.com/photo-1605100804763-247f67b3557e?w=400'
    ],
    [
        // Mock: P-2026-000012 = แหวนทองคำ 96.5% ลายดอกไม้
        'ref_id' => 'P-2026-000012',
        'product_code' => 'GLD-RNG-001',
        'product_name' => 'แหวนทองคำ 96.5% ลายดอกไม้',
        'name' => 'แหวนทองคำ 96.5% ลายดอกไม้',
        'brand' => 'Thai Gold',
        'category' => 'ring',
        // Unsplash gold ring (matches mock thumbnail_url)
        'image_url' => 'https://images.unsplash.com/photo-1515377905703-c4788e51af15?w=400'
    ],
    [
        // Mock: P-2026-000021 = สร้อยคอทองคำ 96.5% ลายสี่เสา
        'ref_id' => 'P-2026-000021',
        'product_code' => 'GLD-NCK-001',
        'product_name' => 'สร้อยคอทองคำ 96.5% ลายสี่เสา',
        'name' => 'สร้อยคอทองคำ 96.5% ลายสี่เสา',
        'brand' => 'Thai Gold',
        'category' => 'necklace',
        // Unsplash gold necklace (matches mock thumbnail_url)
        'image_url' => 'https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=400'
    ],
    [
        // Mock: P-2026-000022 = สร้อยคอทองคำ ลายโซ่ 1 บาท
        'ref_id' => 'P-2026-000022',
        'product_code' => 'GLD-NCK-002',
        'product_name' => 'สร้อยคอทองคำ ลายโซ่ 1 บาท',
        'name' => 'สร้อยคอทองคำ ลายโซ่ 1 บาท',
        'brand' => 'Thai Gold',
        'category' => 'necklace',
        // Unsplash gold chain necklace (matches mock thumbnail_url)
        'image_url' => 'https://images.unsplash.com/photo-1611085583191-a3b181a88401?w=400'
    ],
    [
        // Mock: P-2026-000030 = กำไลทองคำแท้ 96.5% ลายโซ่
        'ref_id' => 'P-2026-000030',
        'product_code' => 'GLD-BRC-001',
        'product_name' => 'กำไลทองคำแท้ 96.5% ลายโซ่',
        'name' => 'กำไลทองคำแท้ 96.5% ลายโซ่',
        'brand' => 'Thai Gold',
        'category' => 'bracelet',
        // Unsplash gold bracelet (matches mock thumbnail_url)
        'image_url' => 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?w=400'
    ],
    [
        // Mock: P-2026-000100 = GUCCI Marmont Mini Bag Black
        'ref_id' => 'P-2026-000100',
        'product_code' => 'GUC-MAR-001',
        'product_name' => 'GUCCI Marmont Mini Bag Black',
        'name' => 'GUCCI Marmont Mini Bag Black',
        'brand' => 'GUCCI',
        'category' => 'bag',
        // Unsplash black handbag (matches mock thumbnail_url)
        'image_url' => 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=400'
    ]
];

$results = [];
$success = 0;
$failed = 0;

foreach ($products as $product) {
    \Logger::info("[Migration] Processing product", ['ref_id' => $product['ref_id']]);
    
    $result = $firestoreVector->storeProductMultimodalEmbedding($product);
    
    $results[] = [
        'ref_id' => $product['ref_id'],
        'name' => $product['product_name'],
        'success' => $result
    ];
    
    if ($result) {
        $success++;
    } else {
        $failed++;
    }
    
    // Rate limiting - 1 second between requests
    sleep(1);
}

\Logger::info("[Migration] Migration complete", [
    'success' => $success,
    'failed' => $failed
]);

echo json_encode([
    'ok' => true,
    'message' => 'Migration complete',
    'success' => $success,
    'failed' => $failed,
    'results' => $results
], JSON_PRETTY_PRINT);
