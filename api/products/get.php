<?php
// filepath: /opt/lampp/htdocs/autobot/api/products/get.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/Logger.php';

// Use same mock database
require_once __DIR__ . '/search.php';

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: $_GET;
    
    $productCode = trim($data['product_code'] ?? $data['sku'] ?? $data['id'] ?? '');
    
    if (empty($productCode)) {
        echo json_encode([
            'success' => false,
            'error' => 'Missing product code',
            'data' => null
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Search by SKU or product_id
    $found = null;
    foreach ($MOCK_PRODUCTS as $product) {
        if ($product['sku'] === $productCode || $product['product_id'] === $productCode) {
            $found = $product;
            break;
        }
    }
    
    if ($found) {
        echo json_encode([
            'success' => true,
            'data' => $found
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Product not found',
            'data' => null
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    Logger::error('Product get error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
}
