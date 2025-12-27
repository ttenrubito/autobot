<?php
// Quick script to check KB entries
require_once __DIR__ . '/includes/Database.php';

$db = Database::getInstance();

echo "=== Checking Knowledge Base Entries ===\n\n";

// Find all active KB entries with 'one piece' keywords
$sql = "SELECT 
    id,
    user_id,
    question,
    answer,
    keywords,
    category,
    priority,
    is_active
FROM customer_knowledge_base 
WHERE is_active = 1 
  AND is_deleted = 0
  AND (
    keywords LIKE '%one piece%' OR 
    keywords LIKE '%onepiece%' OR
    keywords LIKE '%วันพีช%'
  )
ORDER BY priority DESC";

$entries = $db->query($sql);

echo "Found " . count($entries) . " matching entry/entries:\n\n";

foreach ($entries as $idx => $entry) {
    echo "Entry #" . ($idx + 1) . ":\n";
    echo "  ID: " . $entry['id'] . "\n";
    echo "  User ID: " . $entry['user_id'] . "\n";
    echo "  Question: " . mb_substr($entry['question'], 0, 100) . "\n";
    echo "  Answer: " . mb_substr($entry['answer'], 0, 100) . "\n";
    echo "  Category: " . ($entry['category'] ?? 'NULL') . "\n";
    echo "  Priority: " . $entry['priority'] . "\n";
    
    $keywords = json_decode($entry['keywords'], true);
    echo "  Keywords (raw JSON): " . $entry['keywords'] . "\n";
    
    if (is_array($keywords)) {
        echo "  Keywords structure:\n";
        if (isset($keywords['mode'])) {
            echo "    - Mode: " . $keywords['mode'] . "\n";
            if (isset($keywords['require_all'])) {
                echo "    - require_all: " . json_encode($keywords['require_all'], JSON_UNESCAPED_UNICODE) . "\n";
            }
            if (isset($keywords['require_any'])) {
                echo "    - require_any: " . json_encode($keywords['require_any'], JSON_UNESCAPED_UNICODE) . "\n";
            }
            if (isset($keywords['exclude_any'])) {
                echo "    - exclude_any: " . json_encode($keywords['exclude_any'], JSON_UNESCAPED_UNICODE) . "\n";
            }
        } else {
            echo "    - Legacy mode (array): " . json_encode($keywords, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    echo "\n";
}

// Test the matching logic
echo "\n=== Testing Match Logic ===\n\n";

function normalizeTextForKb(string $text): string {
    $t = mb_strtolower(trim($text), 'UTF-8');
    $t = preg_replace('/\s+/u', ' ', $t);
    $t = preg_replace('/[[:punct:]]+/u', '', $t);
    return trim($t);
}

function testMatch(string $query, array $rules): bool {
    $queryNorm = normalizeTextForKb($query);
    
    echo "Testing query: '$query'\n";
    echo "Normalized: '$queryNorm'\n";
    
    // 0. Require at least one positive matching rule
    $hasRequireAll = !empty($rules['require_all']) && is_array($rules['require_all']);
    $hasRequireAny = !empty($rules['require_any']) && is_array($rules['require_any']);
    
    echo "hasRequireAll: " . ($hasRequireAll ? 'true' : 'false') . "\n";
    echo "hasRequireAny: " . ($hasRequireAny ? 'true' : 'false') . "\n";
    
    if (!$hasRequireAll && !$hasRequireAny) {
        echo "Result: NO MATCH (no positive rules)\n\n";
        return false;
    }

    // 3. Check require_all
    if ($hasRequireAll) {
        echo "Checking require_all:\n";
        foreach ($rules['require_all'] as $required) {
            $requiredNorm = normalizeTextForKb((string)$required);
            $found = $requiredNorm !== '' && mb_strpos($queryNorm, $requiredNorm, 0, 'UTF-8') !== false;
            echo "  - '$required' → normalized: '$requiredNorm' → " . ($found ? '✓ FOUND' : '✗ NOT FOUND') . "\n";
            if ($requiredNorm !== '' && !$found) {
                echo "Result: NO MATCH (missing required keyword)\n\n";
                return false;
            }
        }
    }

    // 4. Check require_any
    if ($hasRequireAny) {
        echo "Checking require_any:\n";
        $foundAny = false;
        foreach ($rules['require_any'] as $anyKeyword) {
            $anyNorm = normalizeTextForKb((string)$anyKeyword);
            $found = $anyNorm !== '' && mb_strpos($queryNorm, $anyNorm, 0, 'UTF-8') !== false;
            echo "  - '$anyKeyword' → normalized: '$anyNorm' → " . ($found ? '✓ FOUND' : '✗ NOT FOUND') . "\n";
            if ($found) {
                $foundAny = true;
            }
        }
        if (!$foundAny) {
            echo "Result: NO MATCH (none of require_any matched)\n\n";
            return false;
        }
    }

    echo "Result: ✓ MATCH\n\n";
    return true;
}

// Test with the user's scenario
if (!empty($entries)) {
    $testEntry = $entries[0];
    $keywords = json_decode($testEntry['keywords'], true);
    
    if (isset($keywords['mode']) && $keywords['mode'] === 'advanced') {
        echo "Testing the first entry with different queries:\n\n";
        
        testMatch('one piece', $keywords);
        testMatch('ร้าน one piece', $keywords);
        testMatch('ร้าน', $keywords);
        testMatch('ร้าน วันพีช', $keywords);
    }
}
