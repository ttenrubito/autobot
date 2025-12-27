<?php
/**
 * Test script for Knowledge Base Advanced Matching
 * Tests the matchAdvancedKeywords function with various scenarios
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/bot/RouterV1Handler.php';

echo "=== Testing Knowledge Base Advanced Matching ===\n\n";

// Create a test instance
$db = Database::getInstance();
$handler = new RouterV1Handler($db);

// Use Reflection to access protected methods
$reflection = new ReflectionClass($handler);
$normalizeMethod = $reflection->getMethod('normalizeTextForKb');
$normalizeMethod->setAccessible(true);
$matchMethod = $reflection->getMethod('matchAdvancedKeywords');
$matchMethod->setAccessible(true);

// Test helper function
function runTest($testName, $query, $rules, $expected, $normalizeMethod, $matchMethod, $handler) {
    $normalizedQuery = $normalizeMethod->invoke($handler, $query);
    $result = $matchMethod->invoke($handler, $normalizedQuery, $rules);
    $status = $result === $expected ? '✅ PASS' : '❌ FAIL';
    
    echo "$status - $testName\n";
    echo "  Query: $query\n";
    echo "  Normalized: $normalizedQuery\n";
    echo "  Rules: " . json_encode($rules, JSON_UNESCAPED_UNICODE) . "\n";
    echo "  Expected: " . ($expected ? 'true' : 'false') . ", Got: " . ($result ? 'true' : 'false') . "\n\n";
    
    return $result === $expected;
}

$passedTests = 0;
$totalTests = 0;

// ========================================
// Test 1: require_all only - should match when ALL keywords present
// ========================================
echo "--- Test 1: require_all only ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => ['ร้าน', 'ที่อยู่'],
    'require_any' => [],
    'exclude_any' => []
];

$totalTests++;
if (runTest("Test 1.1: Both keywords present", "ร้านที่อยู่ไหน", $rules, true, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 1.2: Missing 'ร้าน'", "ที่อยู่ไหน", $rules, false, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 1.3: Missing 'ที่อยู่'", "ร้านอยู่ไหน", $rules, false, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

// ========================================
// Test 2: require_any only - should match when ANY keyword present
// ========================================
echo "--- Test 2: require_any only ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => [],
    'require_any' => ['ที่อยู่', 'โลเคชั่น', 'พิกัด'],
    'exclude_any' => []
];

$totalTests++;
if (runTest("Test 2.1: Has 'ที่อยู่'", "ร้านที่อยู่ไหน", $rules, true, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 2.2: Has 'โลเคชั่น'", "โลเคชั่นร้านอยู่ไหน", $rules, true, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 2.3: Has 'พิกัด'", "พิกัดร้านหน่อย", $rules, true, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 2.4: No matching keywords", "ราคาเท่าไหร่", $rules, false, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

// ========================================
// Test 3: Combination with exclude_any
// ========================================
echo "--- Test 3: With exclude_any ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => [],
    'require_any' => ['ร้าน', 'ที่อยู่'],
    'exclude_any' => ['ของฉัน', 'บ้านผม']
];

$totalTests++;
if (runTest("Test 3.1: Match without excluded words", "ร้านอยู่ไหน", $rules, true, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 3.2: Has exclude word 'บ้านผม'", "บ้านผมอยู่ไหน", $rules, false, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 3.3: Has exclude word 'ของฉัน'", "ร้านของฉันอยู่ไหน", $rules, false, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

// ========================================
// Test 4: Empty arrays - should NOT match
// ========================================
echo "--- Test 4: Empty keyword arrays ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => [],
    'require_any' => [],
    'exclude_any' => []
];

$totalTests++;
if (runTest("Test 4.1: Empty arrays should not match", "ร้านอยู่ไหน", $rules, false, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 4.2: Empty arrays should not match (2)", "สวัสดีครับ", $rules, false, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

// ========================================
// Test 5: Complex combination require_all + require_any
// ========================================  
echo "--- Test 5: require_all + require_any ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => ['ร้าน'],
    'require_any' => ['ที่อยู่', 'โลเคชั่น'],
    'exclude_any' => []
];

$totalTests++;
if (runTest("Test 5.1: Has require_all and one from require_any", "ร้านที่อยู่ไหน", $rules, true, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 5.2: Has require_all but missing all require_any", "ร้านเปิดกี่โมง", $rules, false, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 5.3: Has require_any but missing require_all", "ที่อยู่บ้านผม", $rules, false, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

// ========================================
// Test 6: min_query_len
// ========================================
echo "--- Test 6: min_query_len ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => [],
    'require_any' => ['ร้าน'],
    'exclude_any' => [],
    'min_query_len' => 10
];

$totalTests++;
if (runTest("Test 6.1: Query length < min_query_len", "ร้าน", $rules, false, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

$totalTests++;
if (runTest("Test 6.2: Query length >= min_query_len", "ร้านอยู่ที่ไหนครับ", $rules, true, $normalizeMethod, $matchMethod, $handler)) $passedTests++;

// ========================================
// Summary
// ========================================
echo "\n" . str_repeat("=", 50) . "\n";
echo "Test Results: $passedTests / $totalTests passed\n";
if ($passedTests === $totalTests) {
    echo "🎉 All tests passed!\n";
} else {
    echo "⚠️  Some tests failed. Please review the implementation.\n";
}
echo str_repeat("=", 50) . "\n";
