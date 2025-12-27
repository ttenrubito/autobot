<?php
// filepath: /opt/lampp/htdocs/autobot/api/products/search.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/Logger.php';

// Load mock products database
$MOCK_PRODUCTS = require __DIR__ . '/../mock-data/products.php';

/**
 * Advanced Product Search with Multi-Criteria Filtering
 * Supports: keyword, category, brand, price range, attributes
 */
function searchProducts($query, $filters, $products) {
    $results = [];
    $queryLower = mb_strtolower(trim($query), 'UTF-8');
    
    foreach ($products as $product) {
        $score = 0;
        
        // 1. Keyword Matching (name, brand, tags, SKU, description)
        if ($queryLower !== '') {
            $nameLower = mb_strtolower($product['name'], 'UTF-8');
            $skuLower = mb_strtolower($product['sku'], 'UTF-8');
            $descLower = mb_strtolower($product['description'] ?? '', 'UTF-8');
            $brandLower = mb_strtolower($product['brand'], 'UTF-8');
            
            // Exact SKU match = highest priority
            if ($skuLower === $queryLower) {
                $score += 100;
            }
            // SKU contains query
            elseif (mb_strpos($skuLower, $queryLower) !== false) {
                $score += 50;
            }
            
            // Name match
            if (mb_strpos($nameLower, $queryLower) !== false) {
                $score += 30;
            }
            
            // Brand match
            if (mb_strpos($brandLower, $queryLower) !== false) {
                $score += 25;
            }
            
            // Tags match
            foreach ($product['tags'] as $tag) {
                $tagLower = mb_strtolower($tag, 'UTF-8');
                if (mb_strpos($tagLower, $queryLower) !== false || 
                    mb_strpos($queryLower, $tagLower) !== false) {
                    $score += 15;
                    break; // Only count once
                }
            }
            
            // Description match
            if (mb_strpos($descLower, $queryLower) !== false) {
                $score += 5;
            }
        } else {
            // No query = all products match initially
            $score = 1;
        }
        
        // Skip if no keyword match found
        if ($score === 0) {
            continue;
        }
        
        // 2. Category Filter
        if (!empty($filters['category'])) {
            if ($product['category'] !== $filters['category']) {
                continue; // Hard filter - must match
            }
            $score += 10;
        }
        
        // 3. Brand Filter
        if (!empty($filters['brand'])) {
            if (mb_strtolower($product['brand'], 'UTF-8') !== mb_strtolower($filters['brand'], 'UTF-8')) {
                continue; // Hard filter - must match
            }
            $score += 10;
        }
        
        // 4. Price Range Filter
        if (isset($filters['min_price']) && $product['price'] < $filters['min_price']) {
            continue;
        }
        if (isset($filters['max_price']) && $product['price'] > $filters['max_price']) {
            continue;
        }
        
        // 5. Attribute Filters (color, gender, size, etc.)
        if (!empty($filters['attributes']) && is_array($filters['attributes'])) {
            $attributeMatch = true;
            foreach ($filters['attributes'] as $attrKey => $attrValue) {
                if (!isset($product['attributes'][$attrKey]) || 
                    $product['attributes'][$attrKey] != $attrValue) {
                    $attributeMatch = false;
                    break;
                }
            }
            if (!$attributeMatch) {
                continue; // Hard filter - all attributes must match
            }
            $score += (count($filters['attributes']) * 5); // Bonus for attribute matches
        }
        
        // 6. Stock Filter (optional)
        if (isset($filters['in_stock']) && $filters['in_stock'] === true) {
            if (empty($product['in_stock'])) {
                continue;
            }
        }
        
        // Add to results with score
        $result = $product;
        $result['match_score'] = $score;
        $results[] = $result;
    }
    
    // Sort by relevance score (descending)
    usort($results, function($a, $b) {
        return $b['match_score'] - $a['match_score'];
    });
    
    return $results;
}

/**
 * Extract facets from results for filter suggestions
 */
function extractFacets($products) {
    $facets = [
        'brands' => [],
        'categories' => [],
        'colors' => [],
        'price_ranges' => [
            'min' => PHP_INT_MAX,
            'max' => 0
        ]
    ];
    
    foreach ($products as $product) {
        // Brands
        if (!in_array($product['brand'], $facets['brands'])) {
            $facets['brands'][] = $product['brand'];
        }
        
        // Categories
        if (!in_array($product['category'], $facets['categories'])) {
            $facets['categories'][] = $product['category'];
        }
        
        // Colors
        if (isset($product['attributes']['color'])) {
            $color = $product['attributes']['color'];
            if (!in_array($color, $facets['colors'])) {
                $facets['colors'][] = $color;
            }
        }
        
        // Price range
        if ($product['price'] < $facets['price_ranges']['min']) {
            $facets['price_ranges']['min'] = $product['price'];
        }
        if ($product['price'] > $facets['price_ranges']['max']) {
            $facets['price_ranges']['max'] = $product['price'];
        }
    }
    
    sort($facets['brands']);
    sort($facets['categories']);
    sort($facets['colors']);
    
    return $facets;
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: $_POST;
    
    Logger::info('Product Search API called', ['data' => $data]);
    
    // Extract search parameters
    $query = trim($data['q'] ?? $data['product_name'] ?? '');
    $limit = (int)($data['limit'] ?? 5);
    $limit = max(1, min(50, $limit)); // Between 1-50
    
    // Build filters
    $filters = [];
    if (!empty($data['category'])) {
        $filters['category'] = $data['category'];
    }
    if (!empty($data['brand'])) {
        $filters['brand'] = $data['brand'];
    }
    if (isset($data['min_price'])) {
        $filters['min_price'] = (float)$data['min_price'];
    }
    if (isset($data['max_price'])) {
        $filters['max_price'] = (float)$data['max_price'];
    }
    if (!empty($data['attributes']) && is_array($data['attributes'])) {
        $filters['attributes'] = $data['attributes'];
    }
    if (isset($data['in_stock'])) {
        $filters['in_stock'] = (bool)$data['in_stock'];
    }
    
    // Perform search
    $allResults = searchProducts($query, $filters, $MOCK_PRODUCTS);
    $totalResults = count($allResults);
    
    // Limit results
    $limitedResults = array_slice($allResults, 0, $limit);
    
    // Extract facets from all results (for filter suggestions)
    $facets = extractFacets($allResults);
    
    // Remove internal scoring from response
    foreach ($limitedResults as &$product) {
        unset($product['match_score']);
    }
    
    echo json_encode([
        'ok' => true,
        'data' => [
            'products' => $limitedResults,
            'total' => $totalResults,
            'returned' => count($limitedResults),
            'facets' => $facets,
            'query' => $query,
            'filters_applied' => $filters
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    Logger::error('Product search error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Internal server error',
        'data' => ['products' => [], 'total' => 0]
    ], JSON_UNESCAPED_UNICODE);
}
