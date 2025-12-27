<?php
/**
 * Update Credit Card API
 * POST /api/payment/update-card.php
 * 
 * Purpose: อัพเดทบัตรเครดิตใหม่
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/Database.php';
    require_once __DIR__ . '/../../includes/OmiseClient.php';
    
    // Verify user authentication
    $token = null;
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }
    
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $tokenData = json_decode(base64_decode($token), true);
    if (!$tokenData || !isset($tokenData['user_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }
    
    $user_id = $tokenData['user_id'];
    
    $data = json_decode(file_get_contents('php://input'), true);
    $omise_token = $data['omise_token'] ?? '';
    
    if (!$omise_token) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Omise token is required']);
        exit;
    }
    
    $db = Database::getInstance();
    $omise = new OmiseClient();
    
    // Get existing payment method
    $existingMethod = $db->queryOne(
        "SELECT * FROM payment_methods WHERE user_id = ? AND is_default = TRUE LIMIT 1",
        [$user_id]
    );
    
    if (!$existingMethod) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No existing payment method found']);
        exit;
    }
    
    try {
        // Add new card to existing Omise customer
        $result = $omise->addCard($existingMethod['omise_customer_id'], $omise_token);
        $newCard = $result['cards']['data'][0];
        
        // Delete old card from Omise
        $omise->deleteCard($existingMethod['omise_customer_id'], $existingMethod['omise_card_id']);
        
        // Update payment method in database
        $db->execute(
            "UPDATE payment_methods 
             SET omise_card_id = ?,
                 card_brand = ?,
                 card_last4 = ?,
                 card_expiry_month = ?,
                 card_expiry_year = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [
                $newCard['id'],
                $newCard['brand'],
                $newCard['last_digits'],
                $newCard['expiration_month'],
                $newCard['expiration_year'],
                $existingMethod['id']
            ]
        );
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'บัตรเครดิตถูกอัพเดทเรียบร้อยแล้ว',
            'data' => [
                'card_brand' => $newCard['brand'],
                'card_last4' => $newCard['last_digits'],
                'card_expiry' => sprintf('%02d/%04d', $newCard['expiration_month'], $newCard['expiration_year'])
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update card: ' . $e->getMessage()
        ]);
    }
    
} catch (Exception $e) {
    error_log("Update Card Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
