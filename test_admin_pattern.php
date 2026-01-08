<?php
echo "Testing admin handoff detection...\n\n";

// Test regex pattern for admin command
$testCases = [
    'admin' => true,
    '/admin' => true,
    '#admin' => true,
    'ADMIN' => true,
    '  admin  ' => true,
    'admin please help' => false,
    'hello admin' => false,
    'สวัสดี' => false,
];

echo "Testing admin command pattern:\n";
echo "-------------------------------\n";

$passed = 0;
$failed = 0;

foreach ($testCases as $text => $shouldMatch) {
    $t = mb_strtolower(trim($text), 'UTF-8');
    $matched = preg_match('/^(?:\/admin|#admin|admin)$/u', $t);
    
    $result = $matched ? 'MATCH' : 'NO MATCH';
    $expected = $shouldMatch ? 'MATCH' : 'NO MATCH';
    $status = ($result === $expected) ? '✅' : '❌';
    
    echo "{$status} '{$text}' -> {$result} (expected: {$expected})\n";
    
    if ($result === $expected) {
        $passed++;
    } else {
        $failed++;
    }
}

echo "\n";
echo "Passed: {$passed}/" . count($testCases) . "\n";

if ($failed === 0) {
    echo "\n✅ All pattern tests passed!\n";
    echo "\nNext step: Check if RouterV1Handler properly uses this pattern.\n";
    exit(0);
} else {
    echo "\n❌ Some tests failed!\n";
    exit(1);
}
