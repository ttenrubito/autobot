<?php
/**
 * Authentication Tests
 * Tests for public and admin login
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

class AuthTest {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
        echo "🔐 Authentication Tests\n";
        echo str_repeat("=", 50) . "\n\n";
    }
    
    public function runAllTests() {
        $this->testPublicLogin();
        $this->testPublicLoginInvalidCredentials();
        $this->testAdminLogin();
        $this->testAdminLoginInvalidCredentials();
        
        echo "\n✅ All Authentication Tests Completed!\n";
    }
    
    private function testPublicLogin() {
        echo "🧪 Test 1: Public User Login (Valid Credentials)\n";
        
        // Ensure test user exists
        $this->ensureTestUserExists();
        
        $url = 'http://localhost/autobot/api/auth/login';
        $data = [
            'email' => 'test@example.com',
            'password' => 'password123'
        ];
        
        $response = $this->makeRequest($url, 'POST', $data);
        
        if ($response['success']) {
            echo "   ✓ Login successful\n";
            echo "   User: " . ($response['data']['user']['email'] ?? 'N/A') . "\n";
            echo "   Token: " . (isset($response['data']['token']) ? 'Generated ✓' : 'Missing ✗') . "\n";
        } else {
            echo "   ✗ Login failed\n";
            echo "   Error: " . ($response['message'] ?? 'Unknown error') . "\n";
        }
        echo "\n";
    }
    
    private function testPublicLoginInvalidCredentials() {
        echo "🧪 Test 2: Public User Login (Invalid Credentials)\n";
        
        $url = 'http://localhost/autobot/api/auth/login';
        $data = [
            'email' => 'test@example.com',
            'password' => 'wrongpassword'
        ];
        
        $response = $this->makeRequest($url, 'POST', $data);
        
        if (!$response['success']) {
            echo "   ✓ Correctly rejected invalid credentials\n";
            echo "   Error: " . ($response['message'] ?? 'Unknown error') . "\n";
        } else {
            echo "   ✗ Security issue: Accepted invalid credentials!\n";
        }
        echo "\n";
    }
    
    private function testAdminLogin() {
        echo "🧪 Test 3: Admin Login (Valid Credentials)\n";
        
        // Ensure test admin exists
        $this->ensureTestAdminExists();
        
        $url = 'http://localhost/autobot/api/admin/login';
        $data = [
            'username' => 'testadmin',
            'password' => 'admin123'
        ];
        
        $response = $this->makeRequest($url, 'POST', $data);
        
        if ($response['success']) {
            echo "   ✓ Admin login successful\n";
            echo "   Admin: " . ($response['data']['admin']['username'] ?? 'N/A') . "\n";
            echo "   Role: " . ($response['data']['admin']['role'] ?? 'N/A') . "\n";
            echo "   Token: " . (isset($response['data']['token']) ? 'Generated ✓' : 'Missing ✗') . "\n";
        } else {
            echo "   ✗ Admin login failed\n";
            echo "   Error: " . ($response['message'] ?? 'Unknown error') . "\n";
        }
        echo "\n";
    }
    
    private function testAdminLoginInvalidCredentials() {
        echo "🧪 Test 4: Admin Login (Invalid Credentials)\n";
        
        $url = 'http://localhost/autobot/api/admin/login';
        $data = [
            'username' => 'testadmin',
            'password' => 'wrongpassword'
        ];
        
        $response = $this->makeRequest($url, 'POST', $data);
        
        if (!$response['success']) {
            echo "   ✓ Correctly rejected invalid admin credentials\n";
            echo "   Error: " . ($response['message'] ?? 'Unknown error') . "\n";
        } else {
            echo "   ✗ Security issue: Accepted invalid admin credentials!\n";
        }
        echo "\n";
    }
    
    private function ensureTestUserExists() {
        $user = $this->db->queryOne(
            "SELECT id FROM users WHERE email = ? LIMIT 1",
            ['test@example.com']
        );
        
        if (!$user) {
            echo "   📝 Creating test user...\n";
            $this->db->execute(
                "INSERT INTO users (email, password_hash, full_name, status, created_at) 
                 VALUES (?, ?, ?, 'active', NOW())",
                ['test@example.com', password_hash('password123', PASSWORD_DEFAULT), 'Test User']
            );
            echo "   ✓ Test user created\n";
        }
    }
    
    private function ensureTestAdminExists() {
        $admin = $this->db->queryOne(
            "SELECT id FROM admin_users WHERE username = ? LIMIT 1",
            ['testadmin']
        );
        
        if (!$admin) {
            echo "   📝 Creating test admin...\n";
            $this->db->execute(
                "INSERT INTO admin_users (username, password_hash, full_name, email, role, is_active, created_at) 
                 VALUES (?, ?, ?, ?, 'admin', TRUE, NOW())",
                [
                    'testadmin',
                    password_hash('admin123', PASSWORD_DEFAULT),
                    'Test Admin',
                    'testadmin@example.com'
                ]
            );
            echo "   ✓ Test admin created\n";
        }
    }
    
    private function makeRequest($url, $method, $data = null) {
        $ch = curl_init($url);
        
        $headers = ['Content-Type: application/json'];
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $decoded = json_decode($response, true);
        
        if (!$decoded) {
            return [
                'success' => false,
                'message' => 'Invalid JSON response',
                'http_code' => $httpCode,
                'raw_response' => $response
            ];
        }
        
        return $decoded;
    }
}

// Run tests
if (php_sapi_name() === 'cli') {
    $test = new AuthTest();
    $test->runAllTests();
} else {
    echo "This script must be run from the command line.\n";
}
