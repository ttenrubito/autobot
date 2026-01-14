<?php
/**
 * DEBUG: Check specific payment details
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$key = $_GET['key'] ?? '';
if ($key !== 'debug-payments-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $pdo = getDB();
    
    $id = $_GET['id'] ?? null;
    
    if ($id) {
        // Get specific payment
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'payment' => $payment
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        // Get recent payments
        $stmt = $pdo->query("SELECT id, payment_no, customer_id, tenant_id, order_id, amount, status, source, payment_details, created_at FROM payments ORDER BY id DESC LIMIT 10");
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'payments' => $payments
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
