<?php
/**
 * Image Search API
 * 
 * Flow:
 * 1. Receive image URL + vision data
 * 2. (Future) Call vector search API to get similar ref_ids
 * 3. Call NPD product search with ref_ids
 * 4. Return matched products
 * 
 * For now: Uses vision labels + keywords to search
 * 
 * @version 2.0
 * @date 2026-01-06
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';

// Vector Search API Configuration (pending API key)
define('VECTOR_API_BASE_URL', getenv('VECTOR_API_BASE_URL') ?: '');
define('VECTOR_API_KEY', getenv('VECTOR_API_KEY') ?: '');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate request
    if (empty($input['image_url'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'image_url is required']);
        exit;
    }
    
    $imageUrl = $input['image_url'];
    $visionData = $input['vision'] ?? [];
    
    Logger::info('Image search request', [
        'image_url' => $imageUrl,
        'has_vision_data' => !empty($visionData)
    ]);
    
    // Step 1: Try vector search if configured
    $refIds = [];
    if (VECTOR_API_KEY && VECTOR_API_BASE_URL) {
        $vectorResult = callVectorSearch($imageUrl);
        if ($vectorResult['success']) {
            $refIds = $vectorResult['ref_ids'] ?? [];
            Logger::info('Vector search returned', ['ref_ids_count' => count($refIds)]);
        }
    }
    
    // Step 2: If no vector results, extract keywords from vision data
    $keywords = [];
    if (empty($refIds)) {
        $keywords = extractKeywordsFromVision($visionData);
        Logger::info('Extracted keywords from vision', ['keywords' => $keywords]);
    }
    
    // Step 3: Search products
    $products = [];
    
    if (!empty($refIds)) {
        // Search by ref_ids from vector search
        $products = searchProductsByRefIds($refIds);
    } elseif (!empty($keywords)) {
        // Search by keywords extracted from vision
        $products = searchProductsByKeywords($keywords);
    }
    
    // Format response
    $response = [
        'success' => true,
        'data' => [
            'products' => $products,
            'count' => count($products),
            'search_method' => !empty($refIds) ? 'vector' : 'vision_keywords',
            'keywords_used' => $keywords,
            'ref_ids_used' => $refIds
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    Logger::error('Image Search Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Call vector search API
 */
function callVectorSearch(string $imageUrl): array {
    $url = VECTOR_API_BASE_URL . '/search';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'image_url' => $imageUrl,
            'top_k' => 20
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . VECTOR_API_KEY
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || $httpCode !== 200) {
        Logger::error('Vector search failed', ['error' => $error, 'http_code' => $httpCode]);
        return ['success' => false];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false];
    }
    
    // Expected response: { "results": [{ "ref_id": "...", "score": 0.95 }, ...] }
    $refIds = array_column($data['results'] ?? [], 'ref_id');
    
    return ['success' => true, 'ref_ids' => $refIds];
}

/**
 * Extract keywords from Google Vision data
 */
function extractKeywordsFromVision(array $visionData): array {
    $keywords = [];
    
    // Priority 1: Web entities (usually most accurate for products)
    if (!empty($visionData['web_entities'])) {
        foreach ($visionData['web_entities'] as $entity) {
            if (is_string($entity)) {
                $keywords[] = $entity;
            } elseif (isset($entity['description'])) {
                $keywords[] = $entity['description'];
            }
        }
    }
    
    // Priority 2: Labels / Top descriptions
    $labels = $visionData['labels'] ?? $visionData['top_descriptions'] ?? [];
    foreach ($labels as $label) {
        if (is_string($label) && !in_array($label, $keywords)) {
            $keywords[] = $label;
        }
    }
    
    // Priority 3: Text detected (OCR)
    if (!empty($visionData['text'])) {
        // Extract potential brand names or model numbers
        $text = $visionData['text'];
        $potentialKeywords = extractBrandsFromText($text);
        foreach ($potentialKeywords as $kw) {
            if (!in_array($kw, $keywords)) {
                $keywords[] = $kw;
            }
        }
    }
    
    // Limit to most relevant
    return array_slice($keywords, 0, 10);
}

/**
 * Extract brand names from OCR text
 */
function extractBrandsFromText(string $text): array {
    $brands = [
        'Rolex', 'Omega', 'TAG Heuer', 'Patek Philippe', 'Audemars Piguet',
        'Cartier', 'Bulgari', 'Tiffany', 'Van Cleef', 'Chopard',
        'Louis Vuitton', 'Gucci', 'Chanel', 'Hermès', 'Prada',
        'Dior', 'Fendi', 'Balenciaga', 'Celine', 'Bottega Veneta'
    ];
    
    $found = [];
    $textLower = mb_strtolower($text, 'UTF-8');
    
    foreach ($brands as $brand) {
        if (mb_stripos($textLower, mb_strtolower($brand, 'UTF-8')) !== false) {
            $found[] = $brand;
        }
    }
    
    return $found;
}

/**
 * Search products by ref_ids (calls NPD proxy)
 */
function searchProductsByRefIds(array $refIds): array {
    $npdSearchPath = __DIR__ . '/npd-search.php';
    
    // Make internal request
    $payload = [
        'ref_ids' => $refIds,
        'page' => ['limit' => 20]
    ];
    
    return callNpdSearchInternal($payload);
}

/**
 * Search products by keywords (calls NPD proxy)
 */
function searchProductsByKeywords(array $keywords): array {
    if (empty($keywords)) return [];
    
    // Use first keyword as primary search
    $primaryKeyword = $keywords[0];
    
    $payload = [
        'keyword' => $primaryKeyword,
        'page' => ['limit' => 20]
    ];
    
    return callNpdSearchInternal($payload);
}

/**
 * Internal call to NPD search
 */
function callNpdSearchInternal(array $payload): array {
    // Include and call NPD search directly (mock for now)
    // In production, this would be an HTTP call or direct function call
    
    // Load mock products for now
    $mockProducts = getMockProducts();
    
    $results = [];
    
    if (!empty($payload['ref_ids'])) {
        foreach ($mockProducts as $product) {
            if (in_array($product['ref_id'], $payload['ref_ids'])) {
                $results[] = $product;
            }
        }
    } elseif (!empty($payload['keyword'])) {
        $keyword = mb_strtolower($payload['keyword'], 'UTF-8');
        foreach ($mockProducts as $product) {
            $searchText = mb_strtolower(
                $product['title'] . ' ' . $product['brand'] . ' ' . ($product['description'] ?? ''),
                'UTF-8'
            );
            if (mb_strpos($searchText, $keyword) !== false) {
                $results[] = $product;
            }
        }
    }
    
    $limit = (int)($payload['page']['limit'] ?? 20);
    return array_slice($results, 0, $limit);
}

/**
 * Get mock products (same as npd-search.php)
 */
function getMockProducts(): array {
    return [
        [
            'ref_id' => 'P-2026-000001',
            'product_code' => 'ROLEX-SUB-001',
            'title' => 'Rolex Submariner Date 116610LN',
            'brand' => 'Rolex',
            'description' => 'นาฬิกา Rolex Submariner สภาพสวย พร้อมกล่องและใบเซอร์',
            'price' => 450000,
            'currency' => 'THB',
            'availability' => 'in_stock',
            'thumbnail_url' => 'https://example.com/images/rolex-sub-001.jpg',
            'image_count' => 6
        ],
        [
            'ref_id' => 'P-2026-000002',
            'product_code' => 'ROLEX-DJ-001',
            'title' => 'Rolex Datejust 41 126334',
            'brand' => 'Rolex',
            'description' => 'นาฬิกา Rolex Datejust หน้าปัดน้ำเงิน Jubilee',
            'price' => 380000,
            'currency' => 'THB',
            'availability' => 'in_stock',
            'thumbnail_url' => 'https://example.com/images/rolex-dj-001.jpg',
            'image_count' => 5
        ],
        [
            'ref_id' => 'P-2026-000003',
            'product_code' => 'OMEGA-SM-001',
            'title' => 'Omega Seamaster Diver 300M',
            'brand' => 'Omega',
            'description' => 'นาฬิกา Omega Seamaster 42mm สภาพ 95%',
            'price' => 180000,
            'currency' => 'THB',
            'availability' => 'in_stock',
            'thumbnail_url' => 'https://example.com/images/omega-sm-001.jpg',
            'image_count' => 4
        ],
        [
            'ref_id' => 'P-2026-000004',
            'product_code' => 'GUCCI-BAG-001',
            'title' => 'Gucci Marmont Mini Bag',
            'brand' => 'Gucci',
            'description' => 'กระเป๋า Gucci Marmont สีดำ หนังแท้ สภาพ 90%',
            'price' => 45000,
            'currency' => 'THB',
            'availability' => 'in_stock',
            'thumbnail_url' => 'https://example.com/images/gucci-bag-001.jpg',
            'image_count' => 8
        ],
        [
            'ref_id' => 'P-2026-000005',
            'product_code' => 'CARTIER-RING-001',
            'title' => 'Cartier Love Ring White Gold',
            'brand' => 'Cartier',
            'description' => 'แหวน Cartier Love ทองคำขาว 18K ไซส์ 52',
            'price' => 65000,
            'currency' => 'THB',
            'availability' => 'in_stock',
            'thumbnail_url' => 'https://example.com/images/cartier-ring-001.jpg',
            'image_count' => 4
        ]
    ];
}
