<?php
/**
 * Payment API Tests
 * Tests for payment methods, add card, remove card, and set default
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/JWT.php';

class PaymentTest {
    private $db;
    private $testUserId;
    private $testToken;
    
    public function __construct() {
        $this->db = new Database();
        echo "🧪 Payment API Tests\n";
        echo str_repeat("=", 50) . "\n\n";
    }
    
    public function runAllTests() {
        $this->setupTestData();
        $this->testGetPaymentMethods();
        $this->testAddCard();
        $this->testSetDefaultCard();
        $this->testRemoveCard();
        $this->cleanup();
        
        echo "\n✅ All Payment Tests Completed!\n";
    }
    
    private function setupTestData() {
        echo "📋 Setting up test data...\n";
        
        // Create test user if not exists
        $testUser = $this->db->queryOne(
            "SELECT id FROM users WHERE email = ? LIMIT 1",
            ['test@example.com']
        );
        
        if (!$testUser) {
            $this->db->execute(
                "INSERT INTO users (email, password_hash, full_name, status, created_at) 
                 VALUES (?, ?, ?, 'active', NOW())",
                ['test@example.com', password_hash('password123', PASSWORD_DEFAULT), 'Test User']
            );
            $this->testUserId = $this->db->lastInsertId();
        } else {
            $this->testUserId = $testUser['id'];
        }
        
        // Generate JWT token for testing
        $this->testToken = JWT::generate([
            'user_id' => $this->testUserId,
            'email' => 'test@example.com'
        ]);
        
        echo "   User ID: {$this->testUserId}\n";
        echo "   Token generated ✓\n\n";
    }
    
    private function testGetPaymentMethods() {
        echo "🧪 Test 1: Get Payment Methods\n";
        
        $url = 'http://localhost/autobot/api/payment/methods';
        $response = $this->makeRequest($url, 'GET');
        
        if ($response['success']) {
            echo "   ✓ Successfully retrieved payment methods\n";
            echo "   Total methods: " . count($response['data']) . "\n";
        } else {
            echo "   ✗ Failed to get payment methods\n";
            echo "   Error: " . ($response['message'] ?? 'Unknown error') . "\n";
        }
        echo "\n";
    }
    
    private function testAddCard() {
        echo "🧪 Test 2: Add Payment Card\n";
        
        $url = 'http://localhost/autobot/api/payment/add-card';
        $data = [
            'omise_token' => 'tokn_test_' . uniqid(),
            'set_default' => true
        ];
        
        $response = $this->makeRequest($url, 'POST', $data);
        
        if ($response['success']) {
            echo "   ✓ Card added successfully\n";
            echo "   Card ID: " . $response['data']['id'] . "\n";
            echo "   Brand: " . $response['data']['card_brand'] . "\n";
            echo "   Last 4: " . $response['data']['card_last4'] . "\n";
        } else {
            echo "   ✗ Failed to add card\n";
            echo "   Error: " . ($response['message'] ?? 'Unknown error') . "\n";
        }
        echo "\n";
    }
    
    private function testSetDefaultCard() {
        echo "🧪 Test 3: Set Default Payment Card\n";
        
        // First, get a payment method ID
        $methods = $this->db->query(
            "SELECT id FROM payment_methods WHERE user_id = ? LIMIT 1",
            [$this->testUserId]
        );
        
        if (empty($methods)) {
            echo "   ⚠ Skipping: No payment methods found\n\n";
            return;
        }
        
        $cardId = $methods[0]['id'];
        $url = "http://localhost/autobot/api/payment/{$cardId}/set-default";
        
        $response = $this->makeRequest($url, 'POST');
        
        if ($response['success']) {
            echo "   ✓ Default card set successfully\n";
            echo "   Card ID: " . $cardId . "\n";
        } else {
            echo "   ✗ Failed to set default card\n";
            echo "   Error: " . ($response['message'] ?? 'Unknown error') . "\n";
        }
        echo "\n";
    }
    
    private function testRemoveCard() {
        echo "🧪 Test 4: Remove Payment Card\n";
        
        // Get a payment method to remove (not default)
        $methods = $this->db->query(
            "SELECT id FROM payment_methods WHERE user_id = ? AND is_default = FALSE LIMIT 1",
            [$this->testUserId]
        );
        
        if (empty($methods)) {
            echo "   ⚠ Skipping: No non-default payment methods to remove\n\n";
            return;
        }
        
        $cardId = $methods[0]['id'];
        $url = "http://localhost/autobot/api/payment/{$cardId}";
        
        $response = $this->makeRequest($url, 'DELETE');
        
        if ($response['success']) {
            echo "   ✓ Card removed successfully\n";
            echo "   Card ID: " . $cardId . "\n";
        } else {
            echo "   ✗ Failed to remove card\n";
            echo "   Error: " . ($response['message'] ?? 'Unknown error') . "\n";
        }
        echo "\n";
    }
    
    private function makeRequest($url, $method, $data = null) {
        $ch = curl_init($url);
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->testToken
        ];
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return json_decode($response, true) ?? ['success' => false, 'message' => 'Invalid response'];
    }
    
    private function cleanup() {
        echo "🧹 Cleaning up test data...\n";
        
        // Delete test payment methods
        $this->db->execute(
            "DELETE FROM payment_methods WHERE user_id = ?",
            [$this->testUserId]
        );
        
        echo "   ✓ Test data cleaned\n";
    }
}

// Run tests
if (php_sapi_name() === 'cli') {
    $test = new PaymentTest();
    $test->runAllTests();
} else {
    echo "This script must be run from the command line.\n";
}
