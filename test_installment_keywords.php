<?php
/**
 * Test Installment Keyword Detection
 * Tests the new installment_flow patterns and detectInstallmentActionTypeFromText logic
 */

echo "=== Testing Installment Keyword Detection ===\n\n";

class InstallmentKeywordTester
{

    /**
     * Test detectInstallmentActionTypeFromText logic
     * (Copied from RouterV1Handler.php)
     */
    public function detectInstallmentActionTypeFromText(string $text): ?string
    {
        $t = mb_strtolower($text, 'UTF-8');

        // Priority 1: SUMMARY patterns (check balance, remaining)
        if (
            preg_match(
                '/(' .
                '(เหลือ|ค้าง|ยอด|สรุป).{0,10}(งวด|ผ่อน)|' .
                '(งวด|ผ่อน).{0,10}(เหลือ|ค้าง|เท่าไหร่|กี่)|' .
                '(เช็คยอด|ดูยอด|ขอยอด|สรุปยอด)|' .
                '(เหลือ.*กี่.*งวด|จ่ายไปแล้ว.*กี่|ต้องจ่ายอีก)' .
                ')/u',
                $t
            )
        ) {
            return 'summary';
        }

        // Priority 2: CLOSE_CHECK (ปิดยอด)
        if (mb_strpos($t, 'ปิดยอด', 0, 'UTF-8') !== false) {
            return 'close_check';
        }

        // Priority 3: EXTEND_INTEREST (ต่อดอก)
        if (mb_strpos($t, 'ต่อดอก', 0, 'UTF-8') !== false) {
            return 'extend_interest';
        }

        // Priority 4: PAY (payment context)
        if (preg_match('/(ชำระ|โอน|จ่าย|ส่งงวด|แจ้งโอน|จ่ายงวด)/u', $t)) {
            return 'pay';
        }

        // Fallback: Check for generic summary words
        if (mb_strpos($t, 'เช็ค', 0, 'UTF-8') !== false || mb_strpos($t, 'สรุป', 0, 'UTF-8') !== false) {
            return 'summary';
        }

        return null;
    }

    /**
     * Test installment_flow intent detection (from keyword fallback)
     */
    public function detectInstallmentFlowIntent(string $text): array
    {
        $textLower = mb_strtolower($text, 'UTF-8');

        // Pattern for SUMMARY query (higher priority)
        $isSummaryQuery = preg_match(
            '/(' .
            '(งวด|ผ่อน).{0,10}(เหลือ|ค้าง|กี่บาท|กี่งวด|สรุป|เช็ค|ดู|ขอดู)|' .
            '(เหลือ|ค้าง|ยอด).{0,10}(งวด|ผ่อน)|' .
            '(เช็คยอด|ดูยอด|ขอยอด|สรุปยอด).{0,5}(ผ่อน|งวด)|' .
            '(เหลือ.*กี่.*งวด|ต้องจ่าย.*อีก.*เท่าไหร่|จ่ายไปแล้ว.*กี่.*งวด)' .
            ')/u',
            $textLower
        );

        if ($isSummaryQuery) {
            return ['intent' => 'installment_flow', 'action_type' => 'summary'];
        }

        // Explicit: ปิดยอด
        if (preg_match('/ปิดยอด/u', $textLower)) {
            return ['intent' => 'installment_flow', 'action_type' => 'close_check'];
        }

        // Explicit: ต่อดอก (context-aware)
        if (preg_match('/ต่อดอก/u', $textLower)) {
            $isPawnContext = preg_match('/จำนำ|ฝากจำนำ|ของจำนำ|ไถ่|ไถ่ถอน/u', $textLower);
            if ($isPawnContext) {
                return ['intent' => 'pawn_new', 'action_type' => 'extend'];
            } else {
                return ['intent' => 'installment_flow', 'action_type' => 'extend_interest'];
            }
        }

        // Check for generic installment keywords
        if (preg_match('/ผ่อน|งวด|ผ่อนชำระ/u', $textLower)) {
            // Payment context check
            $isPaymentContext = preg_match('/จ่าย.*(งวด|ผ่อน)|โอน.*(งวด|ผ่อน)|ชำระ.*(งวด|ผ่อน)|งวดที่/u', $textLower);

            if ($isPaymentContext) {
                return ['intent' => 'installment_flow', 'action_type' => 'pay'];
            } else {
                return ['intent' => 'interest_rate_inquiry', 'action_type' => null];
            }
        }

        return ['intent' => null, 'action_type' => null];
    }
}

$tester = new InstallmentKeywordTester();
$passedTests = 0;
$totalTests = 0;

function runTest($testName, $input, $expectedIntent, $expectedAction, $tester)
{
    global $passedTests, $totalTests;
    $totalTests++;

    $result = $tester->detectInstallmentFlowIntent($input);
    $actionResult = $tester->detectInstallmentActionTypeFromText($input);

    // Check intent
    $intentMatch = $result['intent'] === $expectedIntent;
    // Check action_type (either from intent detection or action function)
    $actionMatch = ($result['action_type'] === $expectedAction) || ($actionResult === $expectedAction);

    $passed = $intentMatch && ($expectedAction === null || $actionMatch);

    if ($passed) {
        $passedTests++;
        echo "✅ PASS - $testName\n";
    } else {
        echo "❌ FAIL - $testName\n";
        echo "   Input: \"$input\"\n";
        echo "   Expected: intent=$expectedIntent, action=$expectedAction\n";
        echo "   Got: intent={$result['intent']}, action={$result['action_type']} (detectAction: $actionResult)\n";
    }
    echo "\n";
}

// ================================================
// Test Group 1: Summary Query Detection
// ================================================
echo "--- Test Group 1: Summary Query Detection ---\n\n";

runTest(
    "1.1: เหลืออีกกี่งวด",
    "เหลืออีกกี่งวด",
    "installment_flow",
    "summary",
    $tester
);

runTest(
    "1.2: ยอดค้างผ่อนเท่าไหร่",
    "ยอดค้างผ่อนเท่าไหร่",
    "installment_flow",
    "summary",
    $tester
);

runTest(
    "1.3: เช็คยอดผ่อนหน่อย",
    "เช็คยอดผ่อนหน่อย",
    "installment_flow",
    "summary",
    $tester
);

runTest(
    "1.4: งวดผ่อนเหลืออีกกี่บาท",
    "งวดผ่อนเหลืออีกกี่บาท",
    "installment_flow",
    "summary",
    $tester
);

runTest(
    "1.5: ต้องจ่ายอีกเท่าไหร่ครับ",
    "ต้องจ่ายอีกเท่าไหร่ครับ",
    "installment_flow",
    "summary",
    $tester
);

runTest(
    "1.6: จ่ายไปแล้วกี่งวดแล้ว",
    "จ่ายไปแล้วกี่งวดแล้ว",
    "installment_flow",
    "summary",
    $tester
);

runTest(
    "1.7: สรุปยอดผ่อน",
    "สรุปยอดผ่อน",
    "installment_flow",
    "summary",
    $tester
);

// ================================================
// Test Group 2: Payment Detection
// ================================================
echo "--- Test Group 2: Payment Detection ---\n\n";

runTest(
    "2.1: จ่ายงวดที่ 2",
    "จ่ายงวดที่ 2",
    "installment_flow",
    "pay",
    $tester
);

runTest(
    "2.2: โอนงวดแล้ว",
    "โอนงวดแล้ว",
    "installment_flow",
    "pay",
    $tester
);

runTest(
    "2.3: แจ้งโอนค่างวด",
    "แจ้งโอนค่างวด",
    "installment_flow",
    "pay",
    $tester
);

runTest(
    "2.4: ชำระงวดที่ 3",
    "ชำระงวดที่ 3",
    "installment_flow",
    "pay",
    $tester
);

// ================================================
// Test Group 3: Promotion Inquiry (NOT payment)
// ================================================
echo "--- Test Group 3: Promotion Inquiry (NOT installment_flow) ---\n\n";

runTest(
    "3.1: ผ่อนกี่เดือน (asking about terms)",
    "ผ่อนกี่เดือน",
    "interest_rate_inquiry",
    null,
    $tester
);

runTest(
    "3.2: ผ่อนได้ไหม (asking if can pay installment)",
    "ผ่อนได้ไหม",
    "interest_rate_inquiry",
    null,
    $tester
);

runTest(
    "3.3: อยากผ่อนสินค้า (new purchase)",
    "อยากผ่อนสินค้า",
    "interest_rate_inquiry",
    null,
    $tester
);

// ================================================
// Test Group 4: Other Actions
// ================================================
echo "--- Test Group 4: Other Actions ---\n\n";

runTest(
    "4.1: ปิดยอด (close check)",
    "ปิดยอด",
    "installment_flow",
    "close_check",
    $tester
);

runTest(
    "4.2: ต่อดอก (extend interest)",
    "ต่อดอก",
    "installment_flow",
    "extend_interest",
    $tester
);

// ================================================
// Test Group 5: Edge Cases
// ================================================
echo "--- Test Group 5: Edge Cases ---\n\n";

runTest(
    "5.1: ต่อ alone (should NOT match extend_interest)",
    "ต่อ",
    null,
    null,
    $tester
);

runTest(
    "5.2: งวด alone (generic - should go to interest_rate_inquiry)",
    "งวด",
    "interest_rate_inquiry",
    null,
    $tester
);

// ================================================
// Summary
// ================================================
echo str_repeat("=", 60) . "\n";
echo "Test Results: $passedTests / $totalTests passed ";
if ($passedTests === $totalTests) {
    echo "🎉\n";
    echo "✅ All tests passed!\n";
} else {
    $failed = $totalTests - $passedTests;
    echo "⚠️\n";
    echo "❌ $failed test(s) failed.\n";
}
echo str_repeat("=", 60) . "\n";

exit($passedTests === $totalTests ? 0 : 1);
