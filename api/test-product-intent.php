<?php
// Test script for product search intent handling
// filepath: /opt/lampp/htdocs/autobot/api/test-product-intent.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bot/RouterV1Handler.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Logger.php';

// Mock bot configuration
$config = [
    'backend_api' => [
        'enabled' => true,
        'base_url' => 'https://autobot.boxdesign.in.th',
        'timeout_seconds' => 10,
        'endpoints' => [
            'product_search' => '/api/products/search',
        ]
    ],
    'tool_policy' => [
        'prefer_backend_over_llm' => true,
    ],
    'response_templates' => [
        'fallback' => 'ลูกค้าสนใจสินค้าไหนค่ะ 😊 ช่วยอธิบายรายละเอียดเพิ่มอีกนิดทีค่ะ เช่น รุ่น/รหัส',
        'product_found_one' => 'เช็คให้แล้วค่ะ ✅ พบสินค้า: {{name}} (รหัส {{code}})\nราคา {{price}} บาท\nสภาพ: {{condition}}',
        'product_found_many' => "พบหลายรายการที่ใกล้เคียงค่ะ 😊\n{{list}}\nพิมพ์เลือกเลข 1-{{n}} ได้เลยค่ะ",
        'product_not_found' => 'ตอนนี้ยังไม่เจอในระบบค่ะ 😅',
    ],
    'intents' => [
        'product_availability' => ['slots' => ['product_name']],
        'price_inquiry' => ['slots' => ['product_name']],
    ]
];

// Mock context
$context = [
    'channel' => ['id' => 1],
    'external_user_id' => 'test-user',
    'message' => ['text' => '']
];

// Test queries
$testQueries = [
    'Rolex',
    'นาฬิกา Rolex',
    'สนใจ นาฬิกา Rolex',
    'มี Rolex ไหม',
    'ราคา Rolex',
    'โรเล็กซ์',
];

$results = [];

// Create handler instance (use reflection to test protected methods)
$handler = new RouterV1Handler();
$reflection = new ReflectionClass($handler);

// Test rescueSlotsFromText method
$rescueMethod = $reflection->getMethod('rescueSlotsFromText');
$rescueMethod->setAccessible(true);

foreach ($testQueries as $query) {
    $intent = 'product_availability';
    $slots = [];
    
    // Test slot rescue
    $rescuedSlots = $rescueMethod->invoke($handler, $intent, $slots, $query);
    
    $results[] = [
        'query' => $query,
        'intent' => $intent,
        'rescued_slots' => $rescuedSlots,
        'product_name' => $rescuedSlots['product_name'] ?? null,
    ];
}

// Test tryHandleByIntentWithBackend method
$backendMethod = $reflection->getMethod('tryHandleByIntentWithBackend');
$backendMethod->setAccessible(true);

echo json_encode([
    'success' => true,
    'test_type' => 'slot_extraction',
    'results' => $results,
    'summary' => [
        'total_tests' => count($testQueries),
        'slot_extracted' => count(array_filter($results, fn($r) => !empty($r['product_name']))),
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
