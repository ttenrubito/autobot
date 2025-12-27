<?php
/**
 * Script to convert legacy KB entries to advanced format
 * 
 * Usage:
 * 1. To list all legacy entries:
 *    php scripts/convert_kb_to_advanced.php --list
 * 
 * 2. To convert specific entry:
 *    php scripts/convert_kb_to_advanced.php --convert --id=123
 * 
 * 3. To convert all legacy entries for a user:
 *    php scripts/convert_kb_to_advanced.php --convert-all --user-id=1
 * 
 * 4. To do a dry run (preview without saving):
 *    php scripts/convert_kb_to_advanced.php --convert --id=123 --dry-run
 */

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Logger.php';

class KBConverter
{
    private $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * List all legacy KB entries
     */
    public function listLegacyEntries(?int $userId = null, ?string $searchTerm = null): array
    {
        $sql = "SELECT id, user_id, category, priority, keywords, question, answer
                FROM customer_knowledge_base
                WHERE is_active = 1 AND is_deleted = 0";
        
        $params = [];
        
        if ($userId) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }
        
        if ($searchTerm) {
            $search = "%{$searchTerm}%";
            $sql .= " AND (keywords LIKE ? OR question LIKE ? OR answer LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY user_id, priority DESC";
        
        $entries = $this->db->query($sql, $params);
        $legacyEntries = [];
        
        foreach ($entries as $entry) {
            $keywords = json_decode($entry['keywords'] ?? '[]', true);
            if (!is_array($keywords)) continue;
            
            // Check if it's legacy (array) or advanced (object with 'mode')
            $isLegacy = !isset($keywords['mode']) || $keywords['mode'] !== 'advanced';
            
            if ($isLegacy) {
                $entry['keywords_parsed'] = $keywords;
                $entry['is_legacy'] = true;
                $legacyEntries[] = $entry;
            }
        }
        
        return $legacyEntries;
    }
    
    /**
     * Convert a single KB entry to advanced format
     * 
     * @param int $entryId
     * @param array $advancedRules - Custom advanced rules, or null for auto-conversion
     * @param bool $dryRun - If true, don't save changes
     * @return array Result with success status and message
     */
    public function convertEntry(int $entryId, ?array $advancedRules = null, bool $dryRun = false): array
    {
        // Get the entry
        $sql = "SELECT * FROM customer_knowledge_base WHERE id = ?";
        $entries = $this->db->query($sql, [$entryId]);
        
        if (empty($entries)) {
            return ['success' => false, 'message' => "Entry ID {$entryId} not found"];
        }
        
        $entry = $entries[0];
        $keywords = json_decode($entry['keywords'] ?? '[]', true);
        
        if (!is_array($keywords)) {
            return ['success' => false, 'message' => "Invalid keywords format"];
        }
        
        // Check if already advanced
        if (isset($keywords['mode']) && $keywords['mode'] === 'advanced') {
            return ['success' => false, 'message' => "Entry is already in advanced format"];
        }
        
        // If custom rules provided, use them
        if ($advancedRules) {
            $newKeywords = $advancedRules;
        } else {
            // Auto-conversion: convert legacy array to basic advanced format
            $newKeywords = $this->autoConvertToAdvanced($keywords, $entry);
        }
        
        // Ensure mode is set
        if (!isset($newKeywords['mode'])) {
            $newKeywords['mode'] = 'advanced';
        }
        
        $newKeywordsJson = json_encode($newKeywords, JSON_UNESCAPED_UNICODE);
        
        $result = [
            'success' => true,
            'entry_id' => $entryId,
            'category' => $entry['category'],
            'question' => $entry['question'],
            'old_keywords' => $keywords,
            'new_keywords' => $newKeywords,
            'new_keywords_json' => $newKeywordsJson,
            'dry_run' => $dryRun
        ];
        
        if (!$dryRun) {
            $updateSql = "UPDATE customer_knowledge_base SET keywords = ? WHERE id = ?";
            $this->db->execute($updateSql, [$newKeywordsJson, $entryId]);
            $result['message'] = "Entry updated successfully";
        } else {
            $result['message'] = "Dry run - no changes made";
        }
        
        return $result;
    }
    
    /**
     * Auto-convert legacy keywords array to advanced format
     * This creates a basic advanced format using require_any
     */
    private function autoConvertToAdvanced(array $legacyKeywords, array $entry): array
    {
        $advanced = [
            'mode' => 'advanced',
            'require_all' => [],
            'require_any' => [],
            'exclude_any' => [],
            'min_query_len' => 3
        ];
        
        // Put all legacy keywords into require_any
        foreach ($legacyKeywords as $keyword) {
            if (is_string($keyword) && trim($keyword) !== '') {
                $advanced['require_any'][] = trim($keyword);
            }
        }
        
        return $advanced;
    }
    
    /**
     * Create a recommended advanced format for specific use cases
     */
    public function createAdvancedTemplate(string $type, array $params = []): array
    {
        $templates = [
            'shop_address' => [
                'mode' => 'advanced',
                'require_all' => ['ร้าน'],
                'require_any' => $params['shop_names'] ?? [],
                'exclude_any' => [
                    'ที่อยู่ของฉัน', 'ที่อยู่ผม', 'ที่อยู่ฉัน',
                    'บ้านฉัน', 'บ้านผม', 'ของฉัน', 'ของผม'
                ],
                'min_query_len' => 6
            ],
            'product_info' => [
                'mode' => 'advanced',
                'require_all' => [],
                'require_any' => $params['product_names'] ?? [],
                'exclude_any' => $params['exclude'] ?? [],
                'min_query_len' => 4
            ],
            'contact_info' => [
                'mode' => 'advanced',
                'require_all' => $params['require_all'] ?? [],
                'require_any' => $params['contact_keywords'] ?? ['ติดต่อ', 'โทร', 'เบอร์', 'ไลน์'],
                'exclude_any' => [],
                'min_query_len' => 3
            ]
        ];
        
        return $templates[$type] ?? $templates['product_info'];
    }
}

// CLI Handler
if (php_sapi_name() === 'cli') {
    $options = getopt('', [
        'list',
        'convert',
        'convert-all',
        'user-id:',
        'id:',
        'search:',
        'dry-run',
        'help'
    ]);
    
    if (isset($options['help']) || empty($options)) {
        echo "KB Converter - Convert legacy KB entries to advanced format\n\n";
        echo "Usage:\n";
        echo "  --list                      List all legacy entries\n";
        echo "  --list --user-id=N          List legacy entries for specific user\n";
        echo "  --list --search=TERM        Search for entries containing TERM\n";
        echo "  --convert --id=N            Convert specific entry\n";
        echo "  --convert --id=N --dry-run  Preview conversion without saving\n";
        echo "  --convert-all --user-id=N   Convert all legacy entries for user\n";
        echo "\nExamples:\n";
        echo "  php scripts/convert_kb_to_advanced.php --list --search=\"one piece\"\n";
        echo "  php scripts/convert_kb_to_advanced.php --convert --id=123 --dry-run\n";
        echo "  php scripts/convert_kb_to_advanced.php --convert --id=123\n";
        exit(0);
    }
    
    $converter = new KBConverter();
    
    if (isset($options['list'])) {
        $userId = isset($options['user-id']) ? (int)$options['user-id'] : null;
        $search = $options['search'] ?? null;
        
        $entries = $converter->listLegacyEntries($userId, $search);
        
        echo "Found " . count($entries) . " legacy KB entries:\n\n";
        echo str_repeat("=", 100) . "\n";
        
        foreach ($entries as $entry) {
            echo "ID: {$entry['id']} | User: {$entry['user_id']} | Priority: {$entry['priority']} | Category: {$entry['category']}\n";
            echo "Question: {$entry['question']}\n";
            echo "Keywords: " . json_encode($entry['keywords_parsed'], JSON_UNESCAPED_UNICODE) . "\n";
            echo "Answer: " . substr($entry['answer'], 0, 100) . "...\n";
            echo str_repeat("-", 100) . "\n";
        }
        
    } elseif (isset($options['convert'])) {
        if (!isset($options['id'])) {
            echo "Error: --id is required for conversion\n";
            exit(1);
        }
        
        $entryId = (int)$options['id'];
        $dryRun = isset($options['dry-run']);
        
        $result = $converter->convertEntry($entryId, null, $dryRun);
        
        if ($result['success']) {
            echo "Entry ID: {$result['entry_id']}\n";
            echo "Category: {$result['category']}\n";
            echo "Question: {$result['question']}\n\n";
            echo "Old Keywords (Legacy):\n";
            echo json_encode($result['old_keywords'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
            echo "New Keywords (Advanced):\n";
            echo json_encode($result['new_keywords'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
            echo $result['message'] . "\n";
        } else {
            echo "Error: {$result['message']}\n";
            exit(1);
        }
        
    } elseif (isset($options['convert-all'])) {
        if (!isset($options['user-id'])) {
            echo "Error: --user-id is required for batch conversion\n";
            exit(1);
        }
        
        $userId = (int)$options['user-id'];
        $dryRun = isset($options['dry-run']);
        
        $entries = $converter->listLegacyEntries($userId);
        echo "Found " . count($entries) . " legacy entries for user {$userId}\n\n";
        
        $converted = 0;
        $failed = 0;
        
        foreach ($entries as $entry) {
            echo "Converting entry ID {$entry['id']}... ";
            $result = $converter->convertEntry($entry['id'], null, $dryRun);
            
            if ($result['success']) {
                echo "OK\n";
                $converted++;
            } else {
                echo "FAILED: {$result['message']}\n";
                $failed++;
            }
        }
        
        echo "\nSummary:\n";
        echo "  Converted: {$converted}\n";
        echo "  Failed: {$failed}\n";
        if ($dryRun) {
            echo "  (Dry run - no changes saved)\n";
        }
    }
}
