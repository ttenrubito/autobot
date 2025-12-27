<?php
/**
 * Standalone Test for matchAdvancedKeywords Logic
 * No database required - tests the matching logic directly
 */

class KBMatchTester {
    
    // Normalize text for KB matching (copied from RouterV1Handler)
    protected function normalizeTextForKb(string $text): string
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', $t);
        $t = preg_replace('/[[:punct:]]+/u', '', $t);
        return trim($t);
    }
    
    // ✅ UPDATED matchAdvancedKeywords with the fix
    protected function matchAdvancedKeywords(string $queryNorm, array $rules): bool
    {
        // 0. Require at least one positive matching rule (require_all OR require_any)
        $hasRequireAll = !empty($rules['require_all']) && is_array($rules['require_all']);
        $hasRequireAny = !empty($rules['require_any']) && is_array($rules['require_any']);
        
        if (!$hasRequireAll && !$hasRequireAny) {
            // No keywords to match = no match
            return false;
        }

        // 1. Check min_query_len
        if (isset($rules['min_query_len'])) {
            $minLen = (int)$rules['min_query_len'];
            if (mb_strlen($queryNorm, 'UTF-8') < $minLen) {
                return false;
            }
        }

        // 2. Check exclude_any (must NOT contain ANY)
        if (!empty($rules['exclude_any']) && is_array($rules['exclude_any'])) {
            foreach ($rules['exclude_any'] as $exclude) {
                $excludeNorm = $this->normalizeTextForKb((string)$exclude);
                if ($excludeNorm !== '' && mb_strpos($queryNorm, $excludeNorm, 0, 'UTF-8') !== false) {
                    return false;
                }
            }
        }

        // 3. Check require_all (must contain ALL)
        if ($hasRequireAll) {
            foreach ($rules['require_all'] as $required) {
                $requiredNorm = $this->normalizeTextForKb((string)$required);
                if ($requiredNorm !== '' && mb_strpos($queryNorm, $requiredNorm, 0, 'UTF-8') === false) {
                    return false;
                }
            }
        }

        // 4. Check require_any (must contain at least ONE)
        if ($hasRequireAny) {
            $foundAny = false;
            foreach ($rules['require_any'] as $anyKeyword) {
                $anyNorm = $this->normalizeTextForKb((string)$anyKeyword);
                if ($anyNorm !== '' && mb_strpos($queryNorm, $anyNorm, 0, 'UTF-8') !== false) {
                    $foundAny = true;
                    break;
                }
            }
            if (!$foundAny) return false;
        }

        return true;
    }
    
    // Public test method
    public function test(string $query, array $rules): bool {
        $normalized = $this->normalizeTextForKb($query);
        return $this->matchAdvancedKeywords($normalized, $rules);
    }
}

// ========================================
// Run Tests
// ========================================

echo "=== Testing Knowledge Base Advanced Matching ===\n\n";

$tester = new KBMatchTester();
$passedTests = 0;
$totalTests = 0;

function runTest($testName, $query, $rules, $expected, $tester) {
    global $passedTests, $totalTests;
    $totalTests++;
    
    $result = $tester->test($query, $rules);
    $status = $result === $expected ? '✅ PASS' : '❌ FAIL';
    
    echo "$status - $testName\n";
    echo "  Query: $query\n";
    echo "  Expected: " . ($expected ? 'MATCH' : 'NO MATCH') . ", Got: " . ($result ? 'MATCH' : 'NO MATCH') . "\n";
    
    if ($result !== $expected) {
        echo "  Rules: " . json_encode($rules, JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\n";
    
    if ($result === $expected) $passedTests++;
}

// ========================================
// Test 1: require_all only
// ========================================
echo "--- Test 1: require_all only ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => ['ร้าน', 'ที่อยู่'],
    'require_any' => [],
    'exclude_any' => []
];

runTest("1.1: Both keywords present", "ร้านที่อยู่ไหน", $rules, true, $tester);
runTest("1.2: Missing 'ร้าน'", "ที่อยู่ไหน", $rules, false, $tester);
runTest("1.3: Missing 'ที่อยู่'", "ร้านอยู่ไหน", $rules, false, $tester);

// ========================================
// Test 2: require_any only
// ========================================
echo "--- Test 2: require_any only ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => [],
    'require_any' => ['ที่อยู่', 'โลเคชั่น', 'พิกัด'],
    'exclude_any' => []
];

runTest("2.1: Has 'ที่อยู่'", "ร้านที่อยู่ไหน", $rules, true, $tester);
runTest("2.2: Has 'โลเคชั่น'", "โลเคชั่นร้านอยู่ไหน", $rules, true, $tester);
runTest("2.3: Has 'พิกัด'", "พิกัดร้านหน่อย", $rules, true, $tester);
runTest("2.4: No matching keywords", "ราคาเท่าไหร่", $rules, false, $tester);

// ========================================
// Test 3: With exclude_any
// ========================================
echo "--- Test 3: With exclude_any ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => [],
    'require_any' => ['ร้าน', 'ที่อยู่'],
    'exclude_any' => ['ของฉัน', 'บ้านผม']
];

runTest("3.1: Match without excluded words", "ร้านอยู่ไหน", $rules, true, $tester);
runTest("3.2: Has exclude word 'บ้านผม'", "บ้านผมอยู่ไหน", $rules, false, $tester);
runTest("3.3: Has exclude word 'ของฉัน'", "ร้านของฉันอยู่ไหน", $rules, false, $tester);

// ========================================
// Test 4: ✅ Empty arrays - CRITICAL FIX TEST
// ========================================
echo "--- Test 4: Empty keyword arrays (THE FIX) ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => [],
    'require_any' => [],
    'exclude_any' => []
];

runTest("4.1: Empty arrays should NOT match anything", "ร้านอยู่ไหน", $rules, false, $tester);
runTest("4.2: Empty arrays should NOT match (2)", "สวัสดีครับ", $rules, false, $tester);

// ========================================
// Test 5: require_all + require_any combination
// ========================================
echo "--- Test 5: require_all + require_any ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => ['ร้าน'],
    'require_any' => ['ที่อยู่', 'โลเคชั่น'],
    'exclude_any' => []
];

runTest("5.1: Has require_all and one from require_any", "ร้านที่อยู่ไหน", $rules, true, $tester);
runTest("5.2: Has require_all but missing all require_any", "ร้านเปิดกี่โมง", $rules, false, $tester);
runTest("5.3: Has require_any but missing require_all", "ที่อยู่บ้านผม", $rules, false, $tester);

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

runTest("6.1: Query too short (< 10 chars)", "ร้าน", $rules, false, $tester);
runTest("6.2: Query long enough (>= 10 chars)", "ร้านอยู่ที่ไหนครับ", $rules, true, $tester);

// ========================================
// Test 7: Edge case - punctuation and spaces
// ========================================
echo "--- Test 7: Normalization edge cases ---\n";
$rules = [
    'mode' => 'advanced',
    'require_all' => [],
    'require_any' => ['ร้าน'],
    'exclude_any' => []
];

runTest("7.1: Extra spaces and punctuation", "ร้าน!!! อยู่ไหน???", $rules, true, $tester);
runTest("7.2: Mixed case (should still match)", "ร้าน MIXED CASE", $rules, true, $tester);

// ========================================
// Summary
// ========================================
echo "\n" . str_repeat("=", 60) . "\n";
echo "Test Results: $passedTests / $totalTests passed ";
if ($passedTests === $totalTests) {
    echo "🎉\n";
    echo "✅ All tests passed! The fix is working correctly.\n";
} else {
    $failed = $totalTests - $passedTests;
    echo "⚠️\n";
    echo "❌ $failed test(s) failed. Please review the implementation.\n";
}
echo str_repeat("=", 60) . "\n";

// Exit with appropriate code
exit($passedTests === $totalTests ? 0 : 1);
