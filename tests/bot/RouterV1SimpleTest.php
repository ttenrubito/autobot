<?php
/**
 * Simple Unit Tests for RouterV1Handler
 * Tests critical logic without full mocking (uses real Logger/Database stubs)
 * 
 * Run: /opt/lampp/bin/php tests/bot/RouterV1SimpleTest.php
 */

// Simulate test environment
date_default_timezone_set('Asia/Bangkok');
define('TESTING_MODE', true);

// Load dependencies
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/bot/RouterV1Handler.php';

// Test counter
$tests_passed = 0;
$tests_failed = 0;

function assert_equal($expected, $actual, $test_name) {
    global $tests_passed, $tests_failed;
    if ($expected === $actual) {
        echo "✅ PASS: {$test_name}\n";
        $tests_passed++;
        return true;
    } else {
        echo "❌ FAIL: {$test_name}\n";
        echo "   Expected: " . json_encode($expected) . "\n";
        echo "   Actual: " . json_encode($actual) . "\n";
        $tests_failed++;
        return false;
    }
}

function assert_null($actual, $test_name) {
    return assert_equal(null, $actual, $test_name);
}

function assert_not_null($actual, $test_name) {
    global $tests_passed, $tests_failed;
    if ($actual !== null) {
        echo "✅ PASS: {$test_name}\n";
        $tests_passed++;
        return true;
    } else {
        echo "❌ FAIL: {$test_name} - value is null\n";
        $tests_failed++;
        return false;
    }
}

echo "==========================================\n";
echo "RouterV1Handler Simple Unit Tests\n";
echo "==========================================\n\n";

// Test 1: Empty text → greeting
echo "Test 1: Empty text returns greeting\n";
echo "---\n";
try {
    $handler = new RouterV1Handler();
    
    $context = [
        'trace_id' => 'test_001',
        'channel' => ['id' => 999, 'platform' => 'facebook'],
        'external_user_id' => 'test_user',
        'user' => ['external_user_id' => 'test_user'],
        'message' => ['text' => '', 'message_type' => 'text'],
        'bot_profile' => [
            'config' => json_encode([
                'response_templates' => [
                    'greeting' => 'สวัสดีครับ ทดสอบ',
                    'fallback' => 'ขออภัย'
                ],
                'llm' => ['enabled' => false]
            ])
        ],
        'integrations' => []
    ];
    
    $result = $handler->handleMessage($context);
    
    assert_equal('สวัสดีครับ ทดสอบ', $result['reply_text'], "Reply should be greeting");
    assert_equal('empty_text_use_greeting', $result['meta']['reason'], "Reason should be empty_text_use_greeting");
    
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
    $tests_failed++;
}
echo "\n";

// Test 2: Echo message → ignored
echo "Test 2: Echo message is ignored\n";
echo "---\n";
try {
    $handler = new RouterV1Handler();
    
    $context = [
        'trace_id' => 'test_002',
        'channel' => ['id' => 999, 'platform' => 'facebook'],
        'external_user_id' => 'test_user',
        'user' => ['external_user_id' => 'test_user'],
        'message' => [
            'text' => 'สวัสดี',
            'message_type' => 'text',
            'is_echo' => true // Facebook echo
        ],
        'bot_profile' => [
            'config' => json_encode([
                'response_templates' => ['greeting' => 'สวัสดี'],
                'llm' => ['enabled' => false]
            ])
        ],
        'integrations' => []
    ];
    
    $result = $handler->handleMessage($context);
    
    assert_null($result['reply_text'], "Reply should be null for echo");
    assert_equal('ignore_echo', $result['meta']['reason'], "Reason should be ignore_echo");
    
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
    $tests_failed++;
}
echo "\n";

// Test 3: Admin command "admin" → no reply
echo "Test 3: Admin command triggers handoff\n";
echo "---\n";
try {
    $handler = new RouterV1Handler();
    
    $context = [
        'trace_id' => 'test_003',
        'channel' => ['id' => 999, 'platform' => 'facebook'],
        'external_user_id' => 'test_user',
        'user' => ['external_user_id' => 'test_user'],
        'message' => ['text' => 'admin', 'message_type' => 'text'],
        'bot_profile' => [
            'config' => json_encode([
                'response_templates' => ['fallback' => 'ขออภัย'],
                'llm' => ['enabled' => false]
            ])
        ],
        'integrations' => []
    ];
    
    $result = $handler->handleMessage($context);
    
    assert_null($result['reply_text'], "Reply should be null for admin command");
    assert_equal('admin_handoff_manual_command', $result['meta']['reason'], "Reason should be admin_handoff_manual_command");
    
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
    $tests_failed++;
}
echo "\n";

// Test 4: Admin command variations
echo "Test 4: Admin command variations (/admin, #admin)\n";
echo "---\n";
$admin_commands = ['admin', '/admin', '#admin', 'ADMIN', '  admin  '];
foreach ($admin_commands as $cmd) {
    try {
        $handler = new RouterV1Handler();
        
        $context = [
            'trace_id' => 'test_004_' . md5($cmd),
            'channel' => ['id' => 999, 'platform' => 'facebook'],
            'external_user_id' => 'test_user',
            'user' => ['external_user_id' => 'test_user'],
            'message' => ['text' => $cmd, 'message_type' => 'text'],
            'bot_profile' => [
                'config' => json_encode([
                    'response_templates' => ['fallback' => 'ขออภัย'],
                    'llm' => ['enabled' => false]
                ])
            ],
            'integrations' => []
        ];
        
        $result = $handler->handleMessage($context);
        
        assert_null($result['reply_text'], "Admin command '{$cmd}' should trigger handoff");
        
    } catch (Exception $e) {
        echo "❌ EXCEPTION for '{$cmd}': " . $e->getMessage() . "\n";
        $tests_failed++;
    }
}
echo "\n";

// Summary
echo "==========================================\n";
echo "Test Results\n";
echo "==========================================\n";
echo "✅ Passed: {$tests_passed}\n";
echo "❌ Failed: {$tests_failed}\n";
echo "\n";

if ($tests_failed > 0) {
    echo "⛔ Some tests failed!\n";
    exit(1);
} else {
    echo "✅ All tests passed!\n";
    exit(0);
}
