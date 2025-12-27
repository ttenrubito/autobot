<?php
// filepath: /opt/lampp/htdocs/autobot/api/getReceipt.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/Logger.php';

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    Logger::info('Receipt Verification API called', ['data' => $data]);
    
    $amount = $data['amount'] ?? null;
    $time = $data['time'] ?? null;
    $senderName = $data['sender_name'] ?? null;
    $paymentRef = $data['payment_ref'] ?? null;
    $slipImageUrl = $data['slip_image_url'] ?? null;
    
    // Generate mock receipt ID
    $receiptId = 'RCPT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Mock response - always pending review
    $response = [
        'success' => true,
        'status' => 'pending_review',
        'receipt_id' => $receiptId,
        'matched_amount' => $amount ? (float)str_replace([',', ' ', 'บาท'], '', $amount) : null,
        'note' => 'รอตรวจโดยเจ้าหน้าที่',
        'received_at' => date('Y-m-d H:i:s'),
        'data' => [
            'amount' => $amount,
            'time' => $time,
            'sender_name' => $senderName,
            'payment_ref' => $paymentRef,
            'has_slip_image' => !empty($slipImageUrl)
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    Logger::error('Receipt verification error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'status' => 'error'
    ], JSON_UNESCAPED_UNICODE);
}
