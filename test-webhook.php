<?php
/**
 * Manual Webhook Test Script
 * Run this directly in browser or via command line to test webhook
 * 
 * URL: http://localhost/autobot/test-webhook.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Webhook Manual Test</h1>";
echo "<p>Testing webhook endpoint...</p>";
echo "<hr>";

$webhookUrl = 'http://localhost/autobot/api/webhooks/omise.php';

// Test scenarios
$tests = [
    [
        'name' => 'Successful Payment (charge.complete)',
        'payload' => [
            'object' => 'event',
            'id' => 'evnt_test_' . time(),
            'key' => 'charge.complete',
            'created_at' => date('c'),
            'data' => [
                'object' => 'charge',
                'id' => 'chrg_test_success_' . time(),
                'amount' => 500000,
                'currency' => 'THB',
                'description' => 'Test Invoice Payment',
                'status' => 'successful',
                'paid' => true,
                'paid_at' => date('c')
            ]
        ]
    ],
    [
        'name' => 'Failed Payment (charge.failed)',
        'payload' => [
            'object' => 'event',
            'id' => 'evnt_test_fail_' . time(),
            'key' => 'charge.failed',
            'created_at' => date('c'),
            'data' => [
                'object' => 'charge',
                'id' => 'chrg_test_fail_' . time(),
                'amount' => 300000,
                'currency' => 'THB',
                'status' => 'failed',
                'paid' => false,
                'failure_code' => 'payment_expired',
                'failure_message' => 'QR code expired'
            ]
        ]
    ],
    [
        'name' => 'Invalid Event Type',
        'payload' => [
            'key' => 'unknown.event',
            'data' => []
        ]
    ]
];

function sendWebhookRequest($url, $payload) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

// Run tests
$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    echo "<h3>Test: {$test['name']}</h3>";
    
    $result = sendWebhookRequest($webhookUrl, $test['payload']);
    
    echo "<strong>Payload:</strong><br>";
    echo "<pre>" . json_encode($test['payload'], JSON_PRETTY_PRINT) . "</pre>";
    
    echo "<strong>HTTP Code:</strong> {$result['http_code']}<br>";
    
    if ($result['http_code'] == 200) {
        echo "<span style='color: green;'>✓ PASS</span><br>";
        $passed++;
    } else {
        echo "<span style='color: red;'>✗ FAIL</span><br>";
        $failed++;
    }
    
    echo "<strong>Response:</strong><br>";
    echo "<pre>" . htmlspecialchars($result['response']) . "</pre>";
    
    if ($result['error']) {
        echo "<strong style='color: red;'>Error:</strong> {$result['error']}<br>";
    }
    
    echo "<hr>";
}

// Summary
echo "<h2>Test Summary</h2>";
echo "<p><strong style='color: green;'>Passed:</strong> $passed</p>";
echo "<p><strong style='color: red;'>Failed:</strong> $failed</p>";

if ($failed == 0) {
    echo "<h3 style='color: green;'>✓ All tests passed! Webhook is ready.</h3>";
} else {
    echo "<h3 style='color: red;'>✗ Some tests failed. Check errors above.</h3>";
}

// Check log file
$logFile = __DIR__ . '/logs/omise_webhooks.log';
if (file_exists($logFile)) {
    echo "<h2>Recent Log Entries</h2>";
    echo "<pre>" . htmlspecialchars(tail($logFile, 20)) . "</pre>";
}

function tail($filename, $lines = 10) {
    $file = file($filename);
    return implode("", array_slice($file, -$lines));
}
