#!/bin/bash
# Simple test to verify regex pattern works

echo "🧪 Testing Admin Pattern Matching"
echo "=================================="
echo ""

/opt/lampp/bin/php << 'PHP'
<?php

$testCases = [
    ['text' => 'admin', 'should_match' => true],
    ['text' => 'Admin', 'should_match' => true],
    ['text' => 'ADMIN', 'should_match' => true],
    ['text' => 'admin มาตอบ', 'should_match' => true],
    ['text' => 'Admin มาตอบ', 'should_match' => true],
    ['text' => 'ดีจ้า', 'should_match' => false],
    ['text' => 'ยังไง', 'should_match' => false],
    ['text' => 'สนใจ', 'should_match' => false],
];

echo "Testing pattern: /^(?:\\/admin|#admin|admin)(?:\\s|\$)/u\n\n";

$passed = 0;
$failed = 0;

foreach ($testCases as $case) {
    $text = $case['text'];
    $shouldMatch = $case['should_match'];
    
    $t = mb_strtolower(trim($text), 'UTF-8');
    $matched = preg_match('/^(?:\/admin|#admin|admin)(?:\s|$)/u', $t);
    
    $result = $matched ? 'MATCH' : 'NO MATCH';
    $expected = $shouldMatch ? 'MATCH' : 'NO MATCH';
    $status = ($result === $expected) ? '✅' : '❌';
    
    if ($result === $expected) {
        $passed++;
    } else {
        $failed++;
    }
    
    printf("%s %-20s → %s (expected: %s)\n", $status, '"' . $text . '"', $result, $expected);
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
PHP

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ All pattern tests PASSED"
else
    echo ""
    echo "❌ Pattern tests FAILED"
fi
