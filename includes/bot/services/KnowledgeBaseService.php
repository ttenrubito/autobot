<?php
/**
 * KnowledgeBaseService - Knowledge base search for FAQ/Policy answers
 * 
 * Searches customer_knowledge_base table for answers to common questions
 * about policies, store info, FAQs etc.
 * 
 * @version 1.0
 * @date 2026-01-23
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../Logger.php';

class KnowledgeBaseService
{
    protected $db;

    public function __construct()
    {
        $this->db = \Database::getInstance();
    }

    /**
     * Search knowledge base for answer
     * 
     * @param array $context Chat context (must include channel info for tenant)
     * @param string $query User's question
     * @return array Search results with best match
     */
    public function search(array $context, string $query): array
    {
        $tenantUserId = $this->resolveTenantUserId($context);
        
        if (!$tenantUserId) {
            return [];
        }

        $normalizedQuery = $this->normalizeText($query);
        
        if (empty($normalizedQuery)) {
            return [];
        }

        return $this->searchInternal($tenantUserId, $normalizedQuery, $query);
    }

    /**
     * Check if query looks like a policy question
     */
    public function isPolicyQuestion(string $text): bool
    {
        $patterns = [
            '/(\bเปลี่ยน.*คืน|\bคืน.*สินค้า)/iu',
            '/(\bประกัน|\bรับประกัน|\bwarranty)/iu',
            '/(\breturn|\brefund|\bexchange)/iu',
            '/(\bนโยบาย|\bpolicy|\bเงื่อนไข)/iu',
            '/(\bเปลี่ยนสินค้า|\bคืนเงิน)/iu',
            '/(\bรับซื้อคืน|\bรับซื้อ)/iu',
            '/(\bจำนำ.*นโยบาย|\bผ่อน.*เงื่อนไข)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if query looks like store info question
     */
    public function isStoreInfoQuestion(string $text): bool
    {
        $textLower = mb_strtolower($text, 'UTF-8');
        
        $keywords = [
            'เปิดกี่โมง', 'ปิดกี่โมง', 'เวลาเปิด', 'เวลาปิด',
            'อยู่ที่ไหน', 'อยู่ไหน', 'ที่อยู่', 'แผนที่',
            'เบอร์โทร', 'โทรศัพท์', 'ติดต่อ', 'line id',
            'ไลน์', 'facebook', 'เฟส', 'ig', 'instagram',
            'สาขา', 'ร้านอยู่', 'ร้านตั้งอยู่',
            'วันหยุด', 'เปิดวันไหน',
        ];

        foreach ($keywords as $kw) {
            if (mb_strpos($textLower, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Internal search method
     */
    protected function searchInternal(int $userId, string $query, ?string $originalQuery = null): array
    {
        $results = [];

        try {
            // First try: Exact keyword match
            // customer_knowledge_base uses: keywords (JSON column, not keywords_json)
            $sql = "SELECT * FROM customer_knowledge_base
                    WHERE user_id = ?
                    AND is_deleted = 0
                    AND is_active = 1
                    AND (
                        question LIKE ?
                        OR keywords LIKE ?
                    )
                    ORDER BY priority DESC, id ASC
                    LIMIT 5";

            $searchTerm = "%{$query}%";
            $rows = $this->db->query($sql, [$userId, $searchTerm, $searchTerm]);

            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $results[] = [
                        'id' => $row['id'],
                        'question' => $row['question'],
                        'answer' => $row['answer'],
                        'category' => $row['category'] ?? 'general',
                        'match_type' => 'keyword',
                        'match_score' => $this->calculateMatchScore($query, $row),
                        'matched_keyword' => $query,
                    ];
                }
            }

            // Second try: Fulltext search on question
            if (empty($results)) {
                $sql = "SELECT * FROM customer_knowledge_base
                        WHERE user_id = ?
                        AND is_deleted = 0
                        AND is_active = 1
                        AND MATCH(question) AGAINST(? IN NATURAL LANGUAGE MODE)
                        ORDER BY priority DESC
                        LIMIT 5";

                $rows = $this->db->query($sql, [$userId, $query]);

                foreach ($rows as $row) {
                    $results[] = [
                        'id' => $row['id'],
                        'question' => $row['question'],
                        'answer' => $row['answer'],
                        'category' => $row['category'] ?? 'general',
                        'match_type' => 'fulltext',
                        'match_score' => 0.7,
                        'matched_keyword' => null,
                    ];
                }
            }

            // Sort by score
            usort($results, function ($a, $b) {
                return $b['match_score'] <=> $a['match_score'];
            });

            return $results;

        } catch (\Exception $e) {
            \Logger::error("[KnowledgeBaseService] Search error", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Calculate match score
     */
    protected function calculateMatchScore(string $query, array $row): float
    {
        $score = 0.5;

        $question = mb_strtolower($row['question'] ?? '', 'UTF-8');
        $queryLower = mb_strtolower($query, 'UTF-8');

        // Exact match in question
        if (mb_strpos($question, $queryLower) !== false) {
            $score += 0.3;
        }

        // Keywords match
        $keywords = $row['keywords'] ?? '';
        if (mb_strpos(mb_strtolower($keywords, 'UTF-8'), $queryLower) !== false) {
            $score += 0.2;
        }

        // Priority bonus
        $priority = (int)($row['priority'] ?? 0);
        $score += min($priority * 0.05, 0.2);

        return min($score, 1.0);
    }

    /**
     * Match advanced rules (AND/OR/NOT logic)
     */
    protected function matchAdvancedRules(string $query, array $rules): bool
    {
        $queryLower = mb_strtolower($query, 'UTF-8');

        // Required keywords (AND)
        $required = $rules['required'] ?? $rules['and'] ?? [];
        if (!empty($required)) {
            foreach ((array)$required as $kw) {
                if (mb_strpos($queryLower, mb_strtolower($kw, 'UTF-8')) === false) {
                    return false;
                }
            }
        }

        // Any of keywords (OR)
        $anyOf = $rules['any_of'] ?? $rules['or'] ?? [];
        if (!empty($anyOf)) {
            $found = false;
            foreach ((array)$anyOf as $kw) {
                if (mb_strpos($queryLower, mb_strtolower($kw, 'UTF-8')) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        // Excluded keywords (NOT)
        $excluded = $rules['excluded'] ?? $rules['not'] ?? [];
        if (!empty($excluded)) {
            foreach ((array)$excluded as $kw) {
                if (mb_strpos($queryLower, mb_strtolower($kw, 'UTF-8')) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Normalize text for matching
     */
    protected function normalizeText(string $text): string
    {
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        // Remove common filler words (Thai)
        $text = preg_replace('/\b(ครับ|ค่ะ|คะ|นะ|จ้า|จ๊า|หน่อย|ได้ไหม|ได้มั้ย)\b/u', '', $text);
        
        // Remove punctuation
        $text = preg_replace('/[?!.,;:"\'"()]/u', '', $text);
        
        return trim($text);
    }

    /**
     * Resolve tenant user ID from context
     */
    protected function resolveTenantUserId(array $context): ?int
    {
        // Try direct user_id
        if (!empty($context['user_id'])) {
            return (int)$context['user_id'];
        }

        // Try from channel
        if (!empty($context['channel']['user_id'])) {
            return (int)$context['channel']['user_id'];
        }

        // Try from tenant_id lookup
        $tenantId = $context['channel']['tenant_id'] ?? $context['tenant_id'] ?? null;
        if ($tenantId) {
            try {
                $row = $this->db->queryOne(
                    "SELECT id FROM users WHERE tenant_id = ? LIMIT 1",
                    [$tenantId]
                );
                if ($row) {
                    return (int)$row['id'];
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        return null;
    }
}
