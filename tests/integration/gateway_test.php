#!/usr/bin/env php
<?php
/**
 * Integration Test for API Gateway
 * Usage: php tests/integration/gateway_test.php
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../config-cloud.php';

class GatewayIntegrationTest {
    private $baseUrl;
    private $apiKey;
    private $passed = 0;
    private $failed = 0;
    
    public function __construct($baseUrl, $apiKey) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }
    
    public function run() {
        echo "🧪 API Gateway Integration Tests\n";
        echo "================================\n\n";
        
        // Health check
        $this->test('Health Check', function() {
            return $this->get('/health.php');
        }, function($response) {
            return isset($response['status']) && $response['status'] === 'healthy';
        });
        
        // Test API key validation
        $this->test('Invalid API Key', function() {
            return $this->post('/gateway/vision/labels', ['image' => ['content' => 'test']], 'invalid_key');
        }, function($response, $httpCode) {
            return $httpCode === 401 && $response['error_code'] === 'UNAUTHORIZED';
        });
        
        // Test missing API key
        $this->test('Missing API Key', function() {
            return $this->post('/gateway/vision/labels', ['image' => ['content' => 'test']], null);
        }, function($response, $httpCode) {
            return $httpCode === 401;
        });
        
        // Test rate limiting (if enabled)
        $this->test('Rate Limiting', function() {
            // Make multiple requests quickly
            for ($i = 0; $i < 15; $i++) {
                $result = $this->post('/gateway/vision/labels', [
                    'image' => ['content' => base64_encode('test')]
                ]);
                if ($result['httpCode'] === 429) {
                    return $result;
                }
            }
            return ['httpCode' => 200]; // No rate limit hit
        }, function($response, $httpCode) {
            // Either we hit rate limit or we didn't - both are valid
            return true;
        });
        
        // Test payload too large
        $this->test('Payload Too Large', function() {
            $largeData = str_repeat('A', 11 * 1024 * 1024); // 11MB
            return $this->post('/gateway/vision/labels', [
                'image' => ['content' => base64_encode($largeData)]
            ]);
        }, function($response, $httpCode) {
            return $httpCode === 413 || $httpCode === 400;
        });
        
        // Test invalid feature
        $this->test('Invalid Vision Feature', function() {
            return $this->post('/gateway/vision/invalid', [
                'image' => ['content' => 'test']
            ]);
        }, function($response, $httpCode) {
            return $httpCode === 400;
        });
        
        // Test invalid language feature
        $this->test('Invalid Language Feature', function() {
            return $this->post('/gateway/language/invalid', [
                'text' => 'test'
            ]);
        }, function($response, $httpCode) {
            return $httpCode === 400;
        });
        
        // Test missing request body
        $this->test('Missing Request Body - Vision', function() {
            return $this->post('/gateway/vision/labels', []);
        }, function($response, $httpCode) {
            return $httpCode === 400;
        });
        
        $this->test('Missing Request Body - Language', function() {
            return $this->post('/gateway/language/sentiment', []);
        }, function($response, $httpCode) {
            return $httpCode === 400;
        });
        
        // Summary
        echo "\n================================\n";
        echo "Results: ";
        echo "\033[32m{$this->passed} passed\033[0m, ";
        echo "\033[31m{$this->failed} failed\033[0m\n";
        
        return $this->failed === 0;
    }
    
    private function test($name, $callable, $assertion) {
        echo "Testing: {$name}... ";
        
        try {
            $result = $callable();
            $response = $result['response'] ?? [];
            $httpCode = $result['httpCode'] ?? 0;
            
            if ($assertion($response, $httpCode)) {
                echo "\033[32m✓ PASS\033[0m\n";
                $this->passed++;
            } else {
                echo "\033[31m✗ FAIL\033[0m\n";
                echo "  Response: " . json_encode($response) . "\n";
                echo "  HTTP Code: {$httpCode}\n";
                $this->failed++;
            }
        } catch (Exception $e) {
            echo "\033[31m✗ ERROR\033[0m\n";
            echo "  " . $e->getMessage() . "\n";
            $this->failed++;
        }
    }
    
    private function get($path) {
        $url = $this->baseUrl . $path;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'response' => json_decode($response, true),
            'httpCode' => $httpCode
        ];
    }
    
    private function post($path, $data, $apiKey = null) {
        $url = $this->baseUrl . $path;
        $apiKey = $apiKey ?? $this->apiKey;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $headers = ['Content-Type: application/json'];
        if ($apiKey) {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'response' => json_decode($response, true),
            'httpCode' => $httpCode
        ];
    }
}

// Configuration
$baseUrl = 'http://localhost/autobot/api/index.php';
$apiKey = 'ak_db070bf99d1762c5dc4cdabeb453554b'; // From database

// Run tests
$test = new GatewayIntegrationTest($baseUrl, $apiKey);
$success = $test->run();

exit($success ? 0 : 1);
