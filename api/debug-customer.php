<?php
/**
 * DEBUG: Query customer_profiles
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
    
    $psid = $_GET['psid'] ?? null;
    
    if ($psid) {
        // Find by platform_user_id
        $stmt = $pdo->prepare("SELECT * FROM customer_profiles WHERE platform_user_id = ?");
        $stmt->execute([$psid]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'query' => "platform_user_id = $psid",
            'customer' => $customer
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        // Get recent customers
        $stmt = $pdo->query("SELECT id, platform, platform_user_id, display_name, full_name, created_at FROM customer_profiles ORDER BY id DESC LIMIT 10");
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'customers' => $customers
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
