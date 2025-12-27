<?php
// filepath: /opt/lampp/htdocs/autobot/api/installment/payment.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/Logger.php';

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    Logger::info('Installment Payment API called', ['data' => $data]);
    
    $installmentId = $data['installment_id'] ?? null;
    $customerPhone = $data['customer_phone'] ?? null;
    $actionType = $data['action_type'] ?? 'pay';
    $amount = $data['amount'] ?? null;
    $time = $data['time'] ?? null;
    $senderName = $data['sender_name'] ?? null;
    
    // Generate mock payment ID
    $paymentId = 'INSTPAY-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    
    // Mock response
    $response = [
        'success' => true,
        'status' => 'pending_review',
        'installment_payment_id' => $paymentId,
        'message' => 'บันทึกข้อมูลแล้ว รอตรวจโดยเจ้าหน้าที่',
        'data' => [
            'installment_id' => $installmentId,
            'customer_phone' => $customerPhone,
            'action_type' => $actionType,
            'amount' => $amount,
            'time' => $time,
            'sender_name' => $senderName,
            'recorded_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    Logger::error('Installment payment error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'status' => 'error'
    ], JSON_UNESCAPED_UNICODE);
}
