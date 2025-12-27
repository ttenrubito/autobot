#!/usr/bin/env php
<?php
/**
 * Unit Tests for AI Automation Portal APIs
 * Run: php tests/unit/api_tests.php
 */

class APIUnitTests {
    private $baseUrl = 'http://localhost/autobot/api';
    private $passed = 0;
    private $failed = 0;
    private $testToken = null;
    private $testApiKey = 'ak_db070bf99d1762c5dc4cdabeb453554b';
    
    public function run() {
        echo "🧪 AI Automation Portal - Unit Tests\n";
        echo "======================================\n\n";
        
        // Test Authentication
        echo "📌 Testing Authentication...\n";
        $this->testLogin();
        $this->testLogout();
        $this->testInvalidLogin();
        
        // Test Dashboard
        echo "\n📌 Testing Dashboard APIs...\n";
        $this->testDashboardStats();
        
        // Test Services
        echo "\n📌 Testing Services APIs...\n";
        $this->testServicesList();
        
        // Test Payment
        echo "\n📌 Testing Payment APIs...\n";
        $this->testPaymentMethods();
        
        // Test Billing
        echo "\n📌 Testing Billing APIs...\n";
        $this->testInvoices();
        $this->testTransactions();
        
        // Test API Gateway
        echo "\n📌 Testing API Gateway...\n";
        $this->testGatewayAuth();
        
        // Test Health
        echo "\n📌 Testing System Health...\n";
        $this->testHealth();
        
        // Summary
        echo "\n======================================\n";
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        
        echo "Results: ";
        echo "\033[32m{$this->passed} passed\033[0m / ";
        echo "\033[31m{$this->failed} failed\033[0m\n";
        echo "Success Rate: {$percentage}%\n";
        
        return $this->failed === 0;
    }
    
    // Authentication Tests
    private function testLogin() {
        $result = $this->post('/auth/login.php', [
            'email' => 'demo@aiautomation.com',
            'password' => 'demo1234'
        ]);
        
        if ($result['success'] && isset($result['data']['token'])) {
            $this->testToken = $result['data']['token'];
            $this->pass('Login with valid credentials');
        } else {
            $this->fail('Login with valid credentials', $result);
        }
    }
    
    private function testInvalidLogin() {
        $result = $this->post('/auth/login.php', [
            'email' => 'invalid@test.com',
            'password' => 'wrong'
        ]);
        
        if (!$result['success']) {
            $this->pass('Login with invalid credentials (should fail)');
        } else {
            $this->fail('Login with invalid credentials should fail', $result);
        }
    }
    
    private function testLogout() {
        // Logout should accept token and return JSON {success:true}
        $result = $this->post('/auth/logout.php', []);

        if (isset($result['success']) && $result['success']) {
            $this->pass('Logout');
        } else {
            $this->fail('Logout', $result);
        }
    }
    
    // Dashboard Tests
    private function testDashboardStats() {
        $result = $this->get('/dashboard/stats.php');

        $totalServices = $result['data']['overview']['total_services'] ?? null;

        if (isset($result['success']) && $result['success'] && $totalServices !== null) {
            $this->pass('Dashboard stats retrieval');
        } else {
            $this->fail('Dashboard stats retrieval', $result);
        }
    }
    
    // Services Tests
    private function testServicesList() {
        $result = $this->get('/services/list.php');
        
        if ($result['success'] && isset($result['data'])) {
            $this->pass('Services list retrieval');
        } else {
            $this->fail('Services list retrieval', $result);
        }
    }
    
    // Payment Tests
    private function testPaymentMethods() {
        $result = $this->get('/payment/methods.php');
        
        if ($result['success']) {
            $this->pass('Payment methods retrieval');
        } else {
            $this->fail('Payment methods retrieval', $result);
        }
    }
    
    // Billing Tests
    private function testInvoices() {
        $result = $this->get('/billing/invoices.php');
        
        if ($result['success']) {
            $this->pass('Invoices list retrieval');
        } else {
            $this->fail('Invoices list retrieval', $result);
        }
    }
    
    private function testTransactions() {
        $result = $this->get('/billing/transactions.php');
        
        if ($result['success']) {
            $this->pass('Transactions list retrieval');
        } else {
            $this->fail('Transactions list retrieval', $result);
        }
    }
    
    // Gateway Tests
    private function testGatewayAuth() {
        // Test without API key
        $result = $this->post('/gateway/vision/labels', [
            'image' => ['content' => 'test']
        ], null, false);
        
        if (!isset($result['success']) || !$result['success']) {
            $this->pass('Gateway auth - reject no API key');
        } else {
            $this->fail('Gateway should reject requests without API key', $result);
        }
    }
    
    // Health Test
    private function testHealth() {
        // baseUrl already ends with /api, so this hits /api/health.php
        $result = $this->get('/health.php');

        $status = $result['status'] ?? ($result['data']['status'] ?? null);

        if ($status === 'healthy') {
            $this->pass('System health check');
        } else {
            $this->fail('System health check', $result);
        }
    }
    
    // Helper Methods
    private function get($endpoint) {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=test');

        $headers = [];
        if ($this->testToken) {
            $headers[] = 'Authorization: Bearer ' . $this->testToken;
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }
    
    private function post($endpoint, $data, $apiKey = null, $useToken = true) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $headers = ['Content-Type: application/json'];
        
        if ($apiKey) {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }
        
        if ($useToken && $this->testToken) {
            $headers[] = 'Authorization: Bearer ' . $this->testToken;
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=test');
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true) ?: [];
    }
    
    private function pass($test) {
        echo "  \033[32m✓\033[0m {$test}\n";
        $this->passed++;
    }
    
    private function fail($test, $result = null) {
        echo "  \033[31m✗\033[0m {$test}\n";
        if ($result) {
            echo "    Response: " . json_encode($result) . "\n";
        }
        $this->failed++;
    }
}

// Run tests
$tests = new APIUnitTests();
$success = $tests->run();

exit($success ? 0 : 1);
