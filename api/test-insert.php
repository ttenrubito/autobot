<?php
/**
 * Test PaymentService INSERT directly
 */
header('Content-Type: application/json');

$key = $_GET['key'] ?? '';
if ($key !== 'test-insert-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/services/PaymentService.php';

try {
    $paymentService = new \Autobot\Services\PaymentService();
    
    // Test data
    $slipData = [
        'amount' => 99.99,
        'bank' => 'Test Bank',
        'date' => '2026-01-14 04:00:00',
        'ref' => 'TEST-REF-' . time(),
        'sender_name' => 'Test Sender',
        'receiver_name' => 'Test Receiver'
    ];
    
    $context = [
        'external_user_id' => 'test_user_123',
        'platform' => 'test',
        'tenant_id' => 'default',
        'channel' => ['id' => 1]
    ];
    
    $imageUrl = 'https://example.com/test-slip.jpg';
    
    $result = $paymentService->processSlipFromChatbot($slipData, $context, $imageUrl);
    
    echo json_encode([
        'success' => true,
        'result' => $result
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
