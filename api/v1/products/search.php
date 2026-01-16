<?php
/**
 * Mock Product Search API
 * 
 * POST /api/v1/products/search
 * 
 * This is a MOCK implementation for testing purposes.
 * Will be replaced when Data team provides the real API.
 * 
 * @date 2026-01-16
 * @version 1.0 (mock)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Only POST allowed']]);
    exit;
}

// Parse request body
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$keyword = trim($input['keyword'] ?? '');
$productCode = trim($input['product_code'] ?? '');
$refIds = $input['ref_ids'] ?? [];
$filters = $input['filters'] ?? [];
$sort = $input['sort'] ?? ['by' => 'relevance', 'order' => 'desc'];
$page = $input['page'] ?? ['limit' => 20];
$limit = (int) ($page['limit'] ?? 20);

// Validate: at least one search criteria required
if (empty($keyword) && empty($productCode) && empty($refIds)) {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'code' => 'INVALID_REQUEST',
            'message' => 'keyword, product_code, or ref_ids is required',
            'details' => []
        ]
    ]);
    exit;
}

// =====================================================
// MOCK PRODUCT DATA
// =====================================================
$mockProducts = [
    [
        'ref_id' => 'P-2026-000001',
        'product_code' => 'ROL-DAY-001',
        'title' => 'Rolex Day-Date 36mm Yellow Gold',
        'brand' => 'Rolex',
        'description' => 'นาฬิกา Rolex Day-Date ทองคำแท้ 18K สภาพสวย 95% อุปกรณ์ครบกล่อง ใบเซอร์',
        'price' => 850000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1547996160-81dfa63595aa?w=400',
        'image_count' => 6,
        'updated_at' => '2026-01-15T10:30:00Z',
        'attributes' => ['color' => 'gold', 'material' => '18k gold', 'size' => '36mm', 'condition' => '95%']
    ],
    [
        'ref_id' => 'P-2026-000002',
        'product_code' => 'ROL-SUB-002',
        'title' => 'Rolex Submariner Date Black Dial',
        'brand' => 'Rolex',
        'description' => 'Rolex Submariner หน้าปัดดำ สายเหล็ก สภาพสวย 90% กล่องใบ',
        'price' => 420000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1523170335258-f5ed11844a49?w=400',
        'image_count' => 5,
        'updated_at' => '2026-01-14T14:20:00Z',
        'attributes' => ['color' => 'black', 'material' => 'stainless steel', 'size' => '40mm', 'condition' => '90%']
    ],
    [
        'ref_id' => 'P-2026-000003',
        'product_code' => 'TAG-CAR-001',
        'title' => 'Tag Heuer Carrera Chronograph',
        'brand' => 'Tag Heuer',
        'description' => 'Tag Heuer Carrera Chronograph สายหนังน้ำตาล สภาพ 85%',
        'price' => 89000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1524592094714-0f0654e20314?w=400',
        'image_count' => 4,
        'updated_at' => '2026-01-13T09:15:00Z',
        'attributes' => ['color' => 'brown', 'material' => 'leather strap', 'size' => '42mm', 'condition' => '85%']
    ],
    [
        'ref_id' => 'P-2026-000004',
        'product_code' => 'OMG-SEA-001',
        'title' => 'Omega Seamaster Planet Ocean 600M',
        'brand' => 'Omega',
        'description' => 'Omega Seamaster Planet Ocean สายยาง สภาพใหม่ 98%',
        'price' => 195000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1548171915-e79a380a2a4b?w=400',
        'image_count' => 6,
        'updated_at' => '2026-01-12T16:45:00Z',
        'attributes' => ['color' => 'blue', 'material' => 'rubber strap', 'size' => '43.5mm', 'condition' => '98%']
    ],
    [
        'ref_id' => 'P-2026-000005',
        'product_code' => 'GUC-MAR-001',
        'title' => 'GUCCI Marmont Mini Bag Black',
        'brand' => 'GUCCI',
        'description' => 'กระเป๋า GUCCI Marmont Mini หนังแท้สีดำ สภาพสวย 90%',
        'price' => 45900,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=400',
        'image_count' => 5,
        'updated_at' => '2026-01-11T11:30:00Z',
        'attributes' => ['color' => 'black', 'material' => 'leather', 'size' => 'mini', 'condition' => '90%']
    ],
    [
        'ref_id' => 'P-2026-000006',
        'product_code' => 'LV-NVF-001',
        'title' => 'Louis Vuitton Neverfull MM Damier',
        'brand' => 'Louis Vuitton',
        'description' => 'กระเป๋า LV Neverfull MM ลาย Damier สภาพ 85% ใช้งานได้ปกติ',
        'price' => 38500,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1590874103328-eac38a683ce7?w=400',
        'image_count' => 4,
        'updated_at' => '2026-01-10T13:00:00Z',
        'attributes' => ['color' => 'brown', 'material' => 'canvas', 'size' => 'MM', 'condition' => '85%']
    ],
    [
        'ref_id' => 'P-2026-000007',
        'product_code' => 'DIA-RNG-001',
        'title' => 'แหวนเพชรแท้ 1 กะรัต ทองขาว',
        'brand' => 'Custom',
        'description' => 'แหวนเพชรแท้ น้ำหนัก 1.05 ct น้ำ D VVS1 ตัวเรือนทองขาว 18K',
        'price' => 289000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1605100804763-247f67b3557e?w=400',
        'image_count' => 8,
        'updated_at' => '2026-01-09T10:00:00Z',
        'attributes' => ['color' => 'white gold', 'material' => '18k white gold', 'carat' => '1.05ct', 'clarity' => 'VVS1']
    ],
    [
        'ref_id' => 'P-2026-000008',
        'product_code' => 'DIA-NCK-001',
        'title' => 'สร้อยคอเพชร Tennis Necklace',
        'brand' => 'Custom',
        'description' => 'สร้อยคอเพชรแท้ รวม 5 กะรัต ตัวเรือนทองขาว 18K ความยาว 16 นิ้ว',
        'price' => 450000,
        'currency' => 'THB',
        'availability' => 'reserved',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?w=400',
        'image_count' => 5,
        'updated_at' => '2026-01-08T14:30:00Z',
        'attributes' => ['color' => 'white gold', 'material' => '18k white gold', 'total_carat' => '5ct', 'length' => '16 inch']
    ],
    [
        'ref_id' => 'P-2026-000009',
        'product_code' => 'GLD-BRC-001',
        'title' => 'กำไลทองคำแท้ 96.5% ลายโซ่',
        'brand' => 'Thai Gold',
        'description' => 'กำไลทองคำแท้ 96.5% น้ำหนัก 2 บาท ลายโซ่คลาสสิก',
        'price' => 68000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?w=400',
        'image_count' => 4,
        'updated_at' => '2026-01-07T09:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => '96.5% gold', 'weight' => '2 baht']
    ],
    [
        'ref_id' => 'P-2026-000010',
        'product_code' => 'HRM-BKN-001',
        'title' => 'Hermes Birkin 25 Togo Black GHW',
        'brand' => 'Hermes',
        'description' => 'กระเป๋า Hermes Birkin 25 หนัง Togo สีดำ อะไหล่ทอง สภาพ 92%',
        'price' => 680000,
        'currency' => 'THB',
        'availability' => 'sold',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=400',
        'image_count' => 10,
        'updated_at' => '2026-01-06T15:00:00Z',
        'attributes' => ['color' => 'black', 'material' => 'Togo leather', 'size' => '25cm', 'hardware' => 'gold', 'condition' => '92%']
    ],
];

// =====================================================
// SEARCH LOGIC
// =====================================================
$results = [];

foreach ($mockProducts as $product) {
    $match = false;

    // Match by ref_ids
    if (!empty($refIds)) {
        if (in_array($product['ref_id'], $refIds, true)) {
            $match = true;
        }
    }

    // Match by product_code
    if (!empty($productCode)) {
        if (
            stripos($product['product_code'], $productCode) !== false ||
            stripos($product['ref_id'], $productCode) !== false
        ) {
            $match = true;
        }
    }

    // Match by keyword
    if (!empty($keyword)) {
        $searchText = strtolower($product['title'] . ' ' . $product['brand'] . ' ' . $product['description'] . ' ' . $product['product_code']);
        $keywordLower = strtolower($keyword);

        // Check if any word in keyword appears in searchText
        $words = explode(' ', $keywordLower);
        foreach ($words as $word) {
            if (strlen($word) >= 2 && strpos($searchText, $word) !== false) {
                $match = true;
                break;
            }
        }
    }

    if (!$match)
        continue;

    // Apply filters
    if (!empty($filters['brand'])) {
        $brandMatch = false;
        foreach ($filters['brand'] as $brand) {
            if (stripos($product['brand'], $brand) !== false) {
                $brandMatch = true;
                break;
            }
        }
        if (!$brandMatch)
            continue;
    }

    if (!empty($filters['availability'])) {
        if (!in_array($product['availability'], $filters['availability'])) {
            continue;
        }
    }

    if (isset($filters['price_min']) && $filters['price_min'] > 0) {
        if ($product['price'] < $filters['price_min'])
            continue;
    }

    if (isset($filters['price_max']) && $filters['price_max'] > 0) {
        if ($product['price'] > $filters['price_max'])
            continue;
    }

    $results[] = $product;
}

// Apply sorting
$sortBy = $sort['by'] ?? 'relevance';
$sortOrder = $sort['order'] ?? 'desc';

if ($sortBy === 'price') {
    usort($results, function ($a, $b) use ($sortOrder) {
        return $sortOrder === 'asc' ? $a['price'] - $b['price'] : $b['price'] - $a['price'];
    });
} elseif ($sortBy === 'updated_at') {
    usort($results, function ($a, $b) use ($sortOrder) {
        $aTime = strtotime($a['updated_at']);
        $bTime = strtotime($b['updated_at']);
        return $sortOrder === 'asc' ? $aTime - $bTime : $bTime - $aTime;
    });
}

// Apply limit
$results = array_slice($results, 0, $limit);

// Return response
echo json_encode([
    'data' => $results,
    'page' => [
        'limit' => $limit
    ],
    '_mock' => true,
    '_note' => 'This is mock data. Will be replaced by Data team API.'
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
