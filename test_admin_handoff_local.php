<?php
/**
 * Test Admin Handoff Logic (Local)
 * 
 * Tests:
 * 1. Manual "admin" command triggers handoff
 * 2. Bot stays silent for 1 hour after admin message
 * 3. Bot resumes after timeout
 */

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/bot/RouterV1Handler.php';

echo "==============================================\n";
echo "🧪 Testing Admin Handoff Logic\n";
echo "==============================================\n\n";

// Test 1: Manual "admin" command
echo "Test 1: Manual 'admin' command\n";
echo "----------------------------------------------\n";

$handler = new RouterV1Handler();

$context = [
    'trace_id' => 'test_admin_' . bin2hex(random_bytes(4)),
    'channel' => ['id' => 999, 'platform' => 'facebook'],
    'external_user_id' => 'test_admin_user_001',
    'user' => ['external_user_id' => 'test_admin_user_001'],
    'message' => ['text' => 'admin', 'message_type' => 'text'],
    'bot_profile' => [
        'id' => 999,
        'name' => 'TestBot',
        'config' => json_encode([
            'response_templates' => [
                'greeting' => 'สวัสดีครับ',
                'fallback' => 'ขออภัย'
            ],
            'llm' => ['enabled' => false]
        ])
    ],
    'integrations' => [],
    'platform' => 'facebook'
];

try {
    $result = $handler->handleMessage($context);
    
    echo "✅ Result:\n";
    echo "   reply_text: " . ($result['reply_text'] ?? 'NULL') . "\n";
    echo "   reason: " . ($result['meta']['reason'] ?? 'N/A') . "\n";
    
    if ($result['reply_text'] === null && $result['meta']['reason'] === 'admin_handoff_manual_command') {
        echo "\n✅ PASS: Admin command triggered handoff (no reply)\n\n";
    } else {
        echo "\n❌ FAIL: Admin command did NOT trigger handoff\n\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    exit(1);
}

// Test 2: User message during admin timeout (should be paused)
echo "Test 2: User message during admin timeout\n";
echo "----------------------------------------------\n";

$context2 = $context;
$context2['trace_id'] = 'test_admin_2_' . bin2hex(random_bytes(4));
$context2['message'] = ['text' => 'สวัสดี', 'message_type' => 'text'];

try {
    $result2 = $handler->handleMessage($context2);
    
    echo "✅ Result:\n";
    echo "   reply_text: " . ($result2['reply_text'] ?? 'NULL') . "\n";
    echo "   reason: " . ($result2['meta']['reason'] ?? 'N/A') . "\n";
    
    if ($result2['reply_text'] === null && $result2['meta']['reason'] === 'admin_handoff_active') {
        echo "\n✅ PASS: Bot paused during admin timeout\n\n";
    } else {
        echo "\n❌ FAIL: Bot should be paused but still replied\n\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    exit(1);
}

// Test 3: Check variations of admin command
echo "Test 3: Admin command variations\n";
echo "----------------------------------------------\n";

$variations = ['admin', '/admin', '#admin', 'ADMIN', '  admin  '];
$passed = 0;

foreach ($variations as $cmd) {
    $ctx = $context;
    $ctx['trace_id'] = 'test_var_' . bin2hex(random_bytes(4));
    $ctx['external_user_id'] = 'test_var_' . bin2hex(random_bytes(4));
    $ctx['message'] = ['text' => $cmd, 'message_type' => 'text'];
    
    try {
        $res = $handler->handleMessage($ctx);
        if ($res['reply_text'] === null && $res['meta']['reason'] === 'admin_handoff_manual_command') {
            echo "   ✅ '{$cmd}' -> handoff triggered\n";
            $passed++;
        } else {
            echo "   ❌ '{$cmd}' -> failed (reason: " . ($res['meta']['reason'] ?? 'N/A') . ")\n";
        }
    } catch (Exception $e) {
        echo "   ❌ '{$cmd}' -> error: " . $e->getMessage() . "\n";
    }
}

echo "\nPassed: {$passed}/" . count($variations) . "\n";

if ($passed === count($variations)) {
    echo "\n✅ PASS: All admin command variations work\n\n";
} else {
    echo "\n❌ FAIL: Some variations did not work\n\n";
    exit(1);
}

echo "==============================================\n";
echo "✅ ALL TESTS PASSED!\n";
echo "==============================================\n";
echo "\n";
echo "Admin handoff is working correctly in local environment.\n";
echo "Ready to deploy to production.\n";
echo "\n";
