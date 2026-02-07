<?php
/**
 * Test Image Search (Gemini Vision + Vector Search)
 * 
 * Flow:
 * 1. Send image URL → Gemini Vision analyzes
 * 2. Get text description of product
 * 3. Vector Search finds similar products
 */

require_once __DIR__ . '/includes/bot/services/ProductService.php';

use Autobot\Bot\Services\ProductService;

echo "🖼️ Testing Image Search (Gemini Vision + Vector Search)\n";
echo "=========================================================\n\n";

// Test images - use publicly accessible URLs (from Unsplash - free images)
$testImages = [
    'Luxury Watch' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=400',
    'Designer Bag' => 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=400', 
    'Diamond Ring' => 'https://images.unsplash.com/photo-1605100804763-247f67b3557e?w=400',
];

// Mock config with Gemini API key (from production database)
$geminiApiKey = getenv('GEMINI_API_KEY') ?: 'AIzaSyA2bgUM0-6D4D0fAL66NtcJ9G2YxVe_UoQ';

$config = [];

// Mock context with integrations
$context = [
    'integrations' => [
        [
            'provider' => 'gemini',
            'api_key' => $geminiApiKey,
        ]
    ]
];

echo "✅ Using Gemini API key: " . substr($geminiApiKey, 0, 15) . "...\n\n";

$productService = new ProductService();

echo "Testing with sample images:\n\n";

foreach ($testImages as $name => $imageUrl) {
    echo "📷 {$name}\n";
    echo "   URL: " . substr($imageUrl, 0, 60) . "...\n";
    
    $result = $productService->searchByImage($imageUrl, $config, $context);
    
    if ($result['ok'] && !empty($result['products'])) {
        echo "   ✅ Found " . count($result['products']) . " products:\n";
        foreach ($result['products'] as $product) {
            $code = $product['code'] ?? $product['product_code'] ?? 'N/A';
            $name = $product['name'] ?? $product['title'] ?? 'Unknown';
            echo "      → {$code}: {$name}\n";
        }
        if (!empty($result['detected_description'])) {
            echo "   📝 Detected: " . substr($result['detected_description'], 0, 100) . "...\n";
        }
    } else {
        echo "   ❌ No results\n";
        if (!empty($result['error'])) {
            echo "   Error: {$result['error']}\n";
        }
        if (!empty($result['message'])) {
            echo "   Message: {$result['message']}\n";
        }
        if (!empty($result['detected_description'])) {
            echo "   📝 Detected: {$result['detected_description']}\n";
        }
    }
    echo "\n";
}

echo "=========================================================\n";
echo "✅ Image Search Test Complete!\n";
