<?php
// filepath: /opt/lampp/htdocs/autobot/api/orders/status.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/Logger.php';

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: $_GET;
    
    Logger::info('Order Status API called', ['data' => $data]);
    
    $orderId = $data['order_id'] ?? null;
    $customerPhone = $data['customer_phone'] ?? null;
    
    // Mock order statuses
    $mockStatuses = [
        'กำลังเตรียมจัดส่ง',
        'อยู่ระหว่างขนส่ง',
        'จัดส่งสำเร็จ',
        'รอยืนยันออเดอร์'
    ];
    
    $mockCarriers = ['Kerry Express', 'Flash Express', 'Thailand Post EMS', 'J&T Express'];
    
    // Generate mock tracking number
    $tracking = 'TH' . rand(100000000000, 999999999999);
    
    // Mock response
    $response = [
        'success' => true,
        'data' => [
            'order_id' => $orderId ?: 'ORD-' . date('Ymd') . '-' . rand(1000, 9999),
            'status' => $mockStatuses[array_rand($mockStatuses)],
            'tracking_no' => $tracking,
            'tracking' => $tracking,
            'carrier' => $mockCarriers[array_rand($mockCarriers)],
            'estimated_delivery' => date('Y-m-d', strtotime('+2 days')),
            'last_updated' => date('Y-m-d H:i:s')
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    Logger::error('Order status error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
}
