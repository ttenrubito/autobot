<?php
// filepath: /opt/lampp/htdocs/autobot/api/searchImage.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/Logger.php';

// Load mock products database
$MOCK_PRODUCTS = require __DIR__ . '/mock-data/products.php';

// Include search function from search.php
require_once __DIR__ . '/products/search.php';

/**
 * Extract search criteria from Vision API data
 */
function extractSearchFromVision($vision) {
    $filters = [];
    $query = '';
    
    $labels = $vision['labels'] ?? [];
    $text = $vision['text'] ?? '';
    $webEntities = $vision['web_entities'] ?? [];
    $topDescriptions = $vision['top_descriptions'] ?? [];
    
    $allText = strtolower(implode(' ', array_merge($labels, $topDescriptions, [$text], $webEntities)));
    
    // Extract brand
    $brands = ['rolex', 'omega', 'audemars piguet', 'patek philippe', 'cartier', 'tiffany'];
    foreach ($brands as $brand) {
        if (strpos($allText, $brand) !== false) {
            $filters['brand'] = ucwords($brand);
            $query .= $brand . ' ';
            break;
        }
    }
    
    // Extract category
    if (strpos($allText, 'watch') !== false || strpos($allText, 'นาฬิกา') !== false) {
        $filters['category'] = 'watch';
        $query .= 'watch ';
    } elseif (strpos($allText, 'ring') !== false || strpos($allText, 'แหวน') !== false) {
        $filters['category'] = 'ring';
        $query .= 'ring ';
    } elseif (strpos($allText, 'necklace') !== false || strpos($allText, 'สร้อย') !== false) {
        $filters['category'] = 'necklace';
        $query .= 'necklace ';
    }
    
    // Extract color
    $filters['attributes'] = [];
    $colorMap = [
        'black' => 'black',
        'blue' => 'blue',
        'silver' => 'silver',
        'gold' => 'gold',
        'white' => 'white',
        'green' => 'green',
        'red' => 'red',
        'ดำ' => 'black',
        'น้ำเงิน' => 'blue',
        'เงิน' => 'silver',
        'ทอง' => 'gold',
        'ขาว' => 'white'
    ];
    
    foreach ($colorMap as $keyword => $colorValue) {
        if (strpos($allText, $keyword) !== false) {
            $filters['attributes']['color'] = $colorValue;
            break;
        }
    }
    
    return [
        'query' => trim($query),
        'filters' => $filters
    ];
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    Logger::info('Image Search API called', ['data' => $data]);
    
    $imageUrl = $data['image_url'] ?? null;
    $vision = $data['vision'] ?? [];
    
    if (empty($imageUrl)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Missing image_url',
             'data' => ['candidates' => [], 'products' => []]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Extract search criteria from vision data
    $searchCriteria = extractSearchFromVision($vision);
    
    // Perform product search using extracted criteria
    $results = searchProducts(
        $searchCriteria['query'],
        $searchCriteria['filters'],
        $MOCK_PRODUCTS
    );
    
    // Limit to top 5 results
    $candidates = array_slice($results, 0, 5);
    
    // Remove match_score from response
    foreach ($candidates as &$product) {
        unset($product['match_score']);
    }
    
    echo json_encode([
        'ok' => true,
        'success' => true, // For backward compatibility
        'data' => [
            'products' => $candidates,
            'candidates' => $candidates, // For backward compatibility
            'total' => count($results)
        ],
        'vision_summary' => [
            'labels_count' => count($vision['labels'] ?? []),
            'has_text' => !empty($vision['text']),
            'web_entities_count' => count($vision['web_entities'] ?? []),
            'extracted_query' => $searchCriteria['query'],
            'extracted_filters' => $searchCriteria['filters']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    Logger::error('Image search error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'success' => false,
        'error' => 'Internal server error',
        'data' => ['candidates' => [], 'products' => []]
    ], JSON_UNESCAPED_UNICODE);
}
