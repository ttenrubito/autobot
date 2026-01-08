<?php
/**
 * NPD Product Search Proxy API
 * 
 * Proxies product search requests to NPD backend API
 * Supports: keyword search, ref_id search, product_code search
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

// NPD API Configuration
define('NPD_API_BASE_URL', getenv('NPD_API_BASE_URL') ?: 'https://npd-api.example.com');
define('NPD_API_KEY', getenv('NPD_API_KEY') ?: '');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate request - at least one search criteria required
    if (empty($input['keyword']) && empty($input['ref_ids']) && empty($input['product_code'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'At least one search criteria required: keyword, ref_ids, or product_code'
        ]);
        exit;
    }
    
    // Check if NPD API is configured
    if (!NPD_API_KEY || NPD_API_KEY === '') {
        // Fall back to mock search if NPD not configured
        Logger::info('NPD API not configured, using mock search');
        handleMockSearch($input);
        exit;
    }
    
    // Call NPD API
    $response = callNpdApi('/v1/products/search', $input);
    
    if ($response['success']) {
        echo json_encode([
            'success' => true,
            'data' => $response['data'],
            'source' => 'npd'
        ]);
    } else {
        // Fall back to mock on error
        Logger::error('NPD API error, falling back to mock', ['error' => $response['error']]);
        handleMockSearch($input);
    }
    
} catch (Exception $e) {
    Logger::error('NPD Proxy Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Call NPD API
 */
function callNpdApi(string $endpoint, array $payload): array {
    $url = NPD_API_BASE_URL . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . NPD_API_KEY
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => $error];
    }
    
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => "HTTP {$httpCode}", 'body' => $response];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'Invalid JSON response'];
    }
    
    return ['success' => true, 'data' => $data['data'] ?? []];
}

/**
 * Mock search fallback
 */
function handleMockSearch(array $input): void {
    // Load mock products
    $mockProducts = getMockProducts();
    
    $results = [];
    $keyword = mb_strtolower(trim($input['keyword'] ?? ''), 'UTF-8');
    $refIds = $input['ref_ids'] ?? [];
    $productCode = $input['product_code'] ?? '';
    
    foreach ($mockProducts as $product) {
        $match = false;
        $score = 0;
        
        // Match by ref_ids
        if (!empty($refIds) && in_array($product['ref_id'], $refIds)) {
            $match = true;
            $score = 100;
        }
        
        // Match by product_code
        if ($productCode && strcasecmp($product['product_code'], $productCode) === 0) {
            $match = true;
            $score = 100;
        }
        
        // Match by keyword
        if ($keyword) {
            $searchText = mb_strtolower(
                $product['title'] . ' ' . 
                $product['brand'] . ' ' . 
                ($product['description'] ?? '') . ' ' .
                $product['product_code'],
                'UTF-8'
            );
            
            if (mb_strpos($searchText, $keyword) !== false) {
                $match = true;
                $score = 50;
            }
        }
        
        // Apply filters
        if ($match && !empty($input['filters'])) {
            $filters = $input['filters'];
            
            // Brand filter
            if (!empty($filters['brand'])) {
                $brandMatch = false;
                foreach ((array)$filters['brand'] as $brand) {
                    if (strcasecmp($product['brand'], $brand) === 0) {
                        $brandMatch = true;
                        break;
                    }
                }
                if (!$brandMatch) $match = false;
            }
            
            // Availability filter
            if (!empty($filters['availability'])) {
                if (!in_array($product['availability'], (array)$filters['availability'])) {
                    $match = false;
                }
            }
            
            // Price range filter
            if (isset($filters['price_min']) && $product['price'] < $filters['price_min']) {
                $match = false;
            }
            if (isset($filters['price_max']) && $product['price'] > $filters['price_max']) {
                $match = false;
            }
        }
        
        if ($match) {
            $product['_score'] = $score;
            $results[] = $product;
        }
    }
    
    // Sort by score
    usort($results, fn($a, $b) => $b['_score'] - $a['_score']);
    
    // Apply limit
    $limit = (int)($input['page']['limit'] ?? 20);
    $results = array_slice($results, 0, $limit);
    
    // Remove score from output
    foreach ($results as &$r) {
        unset($r['_score']);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $results,
        'source' => 'mock',
        'total' => count($results)
    ]);
}

/**
 * Get mock products
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
            'image_count' => 6,
            'updated_at' => '2026-01-06T10:00:00Z',
            'attributes' => ['color' => 'black', 'material' => 'stainless steel']
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
            'image_count' => 5,
            'updated_at' => '2026-01-06T09:00:00Z',
            'attributes' => ['color' => 'blue', 'material' => 'stainless steel']
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
            'image_count' => 4,
            'updated_at' => '2026-01-05T15:00:00Z',
            'attributes' => ['color' => 'blue', 'material' => 'stainless steel']
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
            'image_count' => 8,
            'updated_at' => '2026-01-04T12:00:00Z',
            'attributes' => ['color' => 'black', 'material' => 'leather']
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
            'image_count' => 4,
            'updated_at' => '2026-01-03T10:00:00Z',
            'attributes' => ['color' => 'white gold', 'material' => '18k gold', 'size' => '52']
        ],
        [
            'ref_id' => 'P-2026-000006',
            'product_code' => 'TAG-F1-001',
            'title' => 'TAG Heuer Formula 1 Chronograph',
            'brand' => 'TAG Heuer',
            'description' => 'นาฬิกา TAG Heuer Formula 1 43mm Quartz',
            'price' => 55000,
            'currency' => 'THB',
            'availability' => 'reserved',
            'thumbnail_url' => 'https://example.com/images/tag-f1-001.jpg',
            'image_count' => 5,
            'updated_at' => '2026-01-02T08:00:00Z',
            'attributes' => ['color' => 'black', 'material' => 'stainless steel']
        ],
        [
            'ref_id' => 'P-2026-000007',
            'product_code' => 'LV-WALLET-001',
            'title' => 'Louis Vuitton Zippy Wallet Damier',
            'brand' => 'Louis Vuitton',
            'description' => 'กระเป๋าสตางค์ LV ลาย Damier Ebene สภาพ 85%',
            'price' => 25000,
            'currency' => 'THB',
            'availability' => 'in_stock',
            'thumbnail_url' => 'https://example.com/images/lv-wallet-001.jpg',
            'image_count' => 6,
            'updated_at' => '2026-01-01T14:00:00Z',
            'attributes' => ['color' => 'brown', 'material' => 'canvas']
        ],
        [
            'ref_id' => 'P-2026-000008',
            'product_code' => 'BUDDHA-GOLD-001',
            'title' => 'พระสมเด็จวัดระฆัง ทองคำแท้',
            'brand' => 'วัดระฆัง',
            'description' => 'พระสมเด็จวัดระฆัง เลี่ยมทองคำแท้ 90% พร้อมใบรับรอง',
            'price' => 120000,
            'currency' => 'THB',
            'availability' => 'in_stock',
            'thumbnail_url' => 'https://example.com/images/buddha-gold-001.jpg',
            'image_count' => 4,
            'updated_at' => '2025-12-30T10:00:00Z',
            'attributes' => ['material' => 'gold 90%', 'temple' => 'วัดระฆัง']
        ]
    ];
}
