<?php
/**
 * Test Admin Handoff Detection Logic
 * Tests detectAdminIntervention() function with various input scenarios
 */

require_once __DIR__ . '/../includes/Logger.php';

// Copy the function here for testing
function detectAdminIntervention(string $text): bool
{
    $textLower = mb_strtolower(trim($text), 'UTF-8');
    
    if ($textLower === '') {
        return false;
    }
    
    // Pattern 1: Contains "admin" anywhere in message
    if (mb_stripos($textLower, 'admin') !== false || mb_stripos($textLower, 'แอดมิน') !== false) {
        echo "[MATCH] Pattern 1: Contains 'admin' keyword\n";
        return true;
    }
    
    // Pattern 2: Thai phrases
    $thaiPhrases = [
        'คุยกับแอดมิน', 'ฉันจะดูแล', 'ให้ฉันดูแล', 'ผมจะดูแล',
        'ให้ผมดูแล', 'เดี๋ยวดูให้', 'เดี๋ยวช่วย', 'รอสักครู่นะ',
        'ตอนนี้ผมช่วย', 'ตอนนี้ฉันช่วย', 'ให้เราช่วย', 'เราจะดูแล',
        'ผมช่วย', 'ฉันช่วย'
    ];
    
    foreach ($thaiPhrases as $phrase) {
        if (mb_stripos($textLower, $phrase) !== false) {
            echo "[MATCH] Pattern 2: Thai phrase '{$phrase}'\n";
            return true;
        }
    }
    
    // Pattern 3: English phrases
    $englishPhrases = [
        "i'll handle", "i'll help", "let me help", "i'll take care",
        "i'm here to help", "i'll assist", "speaking to admin",
        "admin speaking", "this is admin", "i'll take over",
        "let me handle"
    ];
    
    foreach ($englishPhrases as $phrase) {
        if (stripos($textLower, $phrase) !== false) {
            echo "[MATCH] Pattern 3: English phrase '{$phrase}'\n";
            return true;
        }
    }
    
    return false;
}

// Test cases
$testCases = [
    // Bot's own messages (should NOT trigger pause)
    ['text' => 'สวัสดีค่ะ ยินดีต้อนรับ', 'expected' => false, 'description' => 'Bot greeting'],
    ['text' => 'ขอบคุณค่ะ มีอะไรให้ช่วยเพิ่มเติมไหมคะ', 'expected' => false, 'description' => 'Bot follow-up'],
    ['text' => 'สินค้านี้ราคา 1,500 บาทค่ะ', 'expected' => false, 'description' => 'Bot product info'],
    
    // Admin messages with "admin" keyword (SHOULD trigger pause)
    ['text' => 'admin สวัสดีครับ', 'expected' => true, 'description' => 'Admin at start'],
    ['text' => 'สวัสดีครับ admin', 'expected' => true, 'description' => 'Admin at end'],
    ['text' => 'ให้ admin ช่วยดูให้นะครับ', 'expected' => true, 'description' => 'Admin in middle'],
    ['text' => 'ตอนนี้แอดมินจะช่วยดูแลนะคะ', 'expected' => true, 'description' => 'Thai admin word'],
    
    // Admin intervention phrases (SHOULD trigger pause)
    ['text' => 'ฉันจะดูแลให้นะครับ', 'expected' => true, 'description' => 'Thai: I will handle'],
    ['text' => 'เดี๋ยวผมช่วยดูให้', 'expected' => true, 'description' => 'Thai: Let me help'],
    ['text' => "I'll help you with this", 'expected' => true, 'description' => 'English: I will help'],
    ['text' => "Let me handle this for you", 'expected' => true, 'description' => 'English: Let me handle'],
];

echo "==============================================\n";
echo "Testing Admin Handoff Detection\n";
echo "==============================================\n\n";

$passed = 0;
$failed = 0;

foreach ($testCases as $i => $test) {
    $num = $i + 1;
    echo "Test #{$num}: {$test['description']}\n";
    echo "Input: \"{$test['text']}\"\n";
    echo "Expected: " . ($test['expected'] ? 'ADMIN' : 'BOT') . "\n";
    
    $result = detectAdminIntervention($test['text']);
    $actualLabel = $result ? 'ADMIN' : 'BOT';
    $status = ($result === $test['expected']) ? '✅ PASS' : '❌ FAIL';
    
    echo "Actual: {$actualLabel} - {$status}\n";
    echo "---\n\n";
    
    if ($result === $test['expected']) {
        $passed++;
    } else {
        $failed++;
    }
}

echo "==============================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================================\n";

exit($failed > 0 ? 1 : 0);
