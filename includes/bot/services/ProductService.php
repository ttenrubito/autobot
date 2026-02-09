<?php
/**
 * ProductService - Product search and display
 * 
 * Handles:
 * - Product search by code/keyword via ProductSearchService
 * - Image search with Gemini Vision + Vector Search
 * - Hybrid search (Code → Exact Match, Keyword → Vector/Semantic)
 * - Product formatting for chat replies
 * 
 * NOTE: ไม่มี products table ในฝั่ง autobot
 * ใช้ ProductSearchService ซึ่งเป็น mock API (รอ data team ทำ service จริง)
 * 
 * @version 2.0 - AI Hybrid Search System
 * @date 2026-01-25
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../Logger.php';
require_once __DIR__ . '/../../services/ProductSearchService.php';
require_once __DIR__ . '/../../services/FirestoreVectorService.php';
require_once __DIR__ . '/BackendApiService.php';

use Autobot\Services\FirestoreVectorService;

class ProductService
{
    protected $db;
    protected $backendApi;
    protected $firestoreVector;
    protected $firestoreEnabled = false;
    protected $vectorConfig = [];
    protected $configLoaded = false;
    
    // Vector search similarity threshold (default, can be overridden by config)
    // Note: For TEXT search, we need higher threshold (0.55+) to avoid irrelevant results
    // e.g., "แหวน" should NOT match Rolex (which has similarity ~0.35)
    // For multimodal (image-to-image) search, use lower threshold (~0.40) in config
    const VECTOR_SIMILARITY_THRESHOLD = 0.55;
    
    // Category keywords mapping (Thai -> English category in DB)
    protected $categoryKeywords = [
        'แหวน' => 'ring',
        'ring' => 'ring',
        'สร้อย' => 'necklace',
        'สร้อยคอ' => 'necklace',
        'กำไล' => 'bracelet',
        'ข้อมือ' => 'bracelet',
        'นาฬิกา' => 'watch',
        'watch' => 'watch',
        'ต่างหู' => 'earring',
        'ตุ้มหู' => 'earring',
        'จี้' => 'pendant',
        'กระเป๋า' => 'bag',
        'bag' => 'bag',
        'พระ' => 'amulet',
        'พระเครื่อง' => 'amulet',
        'เลี่ยม' => 'amulet', // พระเลี่ยมทอง
    ];
    
    // Material keywords mapping
    protected $materialKeywords = [
        'ทอง' => 'gold',
        'ทองคำ' => 'gold',
        'gold' => 'gold',
        'เงิน' => 'silver',
        'silver' => 'silver',
        'เพชร' => 'diamond',
        'diamond' => 'diamond',
        'แพลทินัม' => 'platinum',
        'platinum' => 'platinum',
    ];
    
    // Brand aliases (Thai → English)
    protected $brandAliases = [
        'โรเล็กซ์' => 'Rolex',
        'โรเลกซ์' => 'Rolex',
        'โอเมก้า' => 'Omega',
        'โอเมกา' => 'Omega',
        'แท็กไฮเออร์' => 'Tag Heuer',
        'แทกไฮเออร์' => 'Tag Heuer',
        'แท็ก' => 'Tag',
        'คาร์เทียร์' => 'Cartier',
        'กุชชี่' => 'Gucci',
        'ชาแนล' => 'Chanel',
        'เฮอร์เมส' => 'Hermes',
        'หลุยส์วิตตอง' => 'Louis Vuitton',
    ];
    
    // Color keywords (Thai → English) for search matching
    protected $colorKeywords = [
        'ดำ' => 'black',
        'สีดำ' => 'black',
        'ขาว' => 'white',
        'สีขาว' => 'white',
        'แดง' => 'red',
        'สีแดง' => 'red',
        'น้ำเงิน' => 'blue',
        'สีน้ำเงิน' => 'blue',
        'ฟ้า' => 'blue',
        'สีฟ้า' => 'blue',
        'เขียว' => 'green',
        'สีเขียว' => 'green',
        'เหลือง' => 'yellow',
        'สีเหลือง' => 'yellow',
        'ชมพู' => 'pink',
        'สีชมพู' => 'pink',
        'ม่วง' => 'purple',
        'สีม่วง' => 'purple',
        'ส้ม' => 'orange',
        'สีส้ม' => 'orange',
        'น้ำตาล' => 'brown',
        'สีน้ำตาล' => 'brown',
        'เทา' => 'grey',
        'สีเทา' => 'grey',
        'ทอง' => 'gold',
        'สีทอง' => 'gold',
        'เงิน' => 'silver',
        'สีเงิน' => 'silver',
        'โรสโกลด์' => 'rose gold',
        'สีโรสโกลด์' => 'rose gold',
    ];
    
    // Gender keywords for filtering
    protected $genderKeywords = [
        'ผู้ชาย' => ['men', 'man', 'male', 'gent', 'gentleman'],
        'ชาย' => ['men', 'man', 'male', 'gent'],
        'ผู้หญิง' => ['women', 'woman', 'lady', 'ladies', 'female'],
        'หญิง' => ['women', 'woman', 'lady', 'ladies', 'female'],
        'เด็ก' => ['kids', 'children', 'child', 'junior'],
        'unisex' => ['unisex', 'ยูนิเซ็กซ์'],
    ];
    
    // Size keywords (Thai → English/patterns)
    protected $sizeKeywords = [
        'เล็ก' => ['small', 'mini', 'petite', '28mm', '30mm', '32mm', '34mm'],
        'กลาง' => ['medium', '36mm', '38mm'],
        'ใหญ่' => ['large', 'big', 'xl', '40mm', '42mm', '44mm', '46mm'],
        'มินิ' => ['mini', 'small'],
    ];
    
    // Style keywords (Thai → English)
    protected $styleKeywords = [
        'สปอร์ต' => ['sport', 'sporty', 'athletic', 'diver', 'chronograph'],
        'หรู' => ['luxury', 'luxurious', 'elegant', 'premium'],
        'หรูหรา' => ['luxury', 'elegant', 'premium'],
        'วินเทจ' => ['vintage', 'retro', 'classic', 'antique'],
        'คลาสสิก' => ['classic', 'traditional', 'timeless'],
        'โมเดิร์น' => ['modern', 'contemporary', 'minimalist'],
        'แฟชั่น' => ['fashion', 'trendy', 'stylish'],
        'ทางการ' => ['formal', 'dress', 'elegant'],
        'ลำลอง' => ['casual', 'everyday', 'daily'],
    ];
    
    // Watch strap/band keywords
    protected $strapKeywords = [
        'สายหนัง' => ['leather', 'strap', 'หนัง'],
        'หนัง' => ['leather', 'หนัง'],
        'สายเหล็ก' => ['steel', 'metal', 'bracelet', 'stainless'],
        'เหล็ก' => ['steel', 'metal', 'stainless'],
        'สายยาง' => ['rubber', 'silicon', 'silicone'],
        'ยาง' => ['rubber', 'silicon', 'silicone'],
        'สายนาโต้' => ['nato', 'nylon'],
        'ผ้า' => ['fabric', 'canvas', 'nylon'],
    ];
    
    // Condition keywords
    protected $conditionKeywords = [
        'มือสอง' => ['used', 'pre-owned', 'secondhand', 'second hand'],
        'ใหม่' => ['new', 'brand new', 'unused'],
        'สภาพดี' => ['excellent', 'good condition', 'mint'],
        'ของแท้' => ['authentic', 'genuine', 'original'],
        'แท้' => ['authentic', 'genuine', 'original', 'real'],
    ];

    public function __construct(array $config = [])
    {
        $this->db = \Database::getInstance();
        $this->backendApi = new BackendApiService();
        
        if (!empty($config)) {
            $this->setConfig($config);
        } else {
            // Lazy init - will be set later when config is available
            $this->initFirestoreIfNeeded();
        }
    }
    
    /**
     * Set config and reinitialize Firestore if needed
     * Called by RouterV4Handler when config is loaded
     */
    public function setConfig(array $config): void
    {
        if ($this->configLoaded) {
            return; // Already configured
        }
        
        $this->vectorConfig = $config['vector_search'] ?? [];
        $this->configLoaded = true;
        
        // Re-initialize Firestore with config
        $this->initFirestoreIfNeeded();
    }
    
    /**
     * Initialize Firestore Vector Service if enabled and not already initialized
     */
    protected function initFirestoreIfNeeded(): void
    {
        if ($this->firestoreEnabled) {
            return; // Already initialized
        }
        
        $vectorEnabled = $this->vectorConfig['enabled'] ?? true;
        
        // Check if Firebase service account is available (env or file)
        $hasFirebaseCredentials = false;
        $envJson = getenv('FIREBASE_SERVICE_ACCOUNT');
        if ($envJson && $envJson !== 'false') {
            $hasFirebaseCredentials = true;
        } else {
            $serviceAccountPath = __DIR__ . '/../../config/firebase-service-account.json';
            if (file_exists($serviceAccountPath)) {
                $hasFirebaseCredentials = true;
            }
        }
        
        if ($vectorEnabled && $hasFirebaseCredentials) {
            try {
                $threshold = $this->getSimilarityThreshold();
                $this->firestoreVector = new FirestoreVectorService($threshold);
                $this->firestoreEnabled = true;
                \Logger::info('[ProductService] Firestore Vector Search enabled', [
                    'similarity_threshold' => $threshold,
                    'source' => $envJson ? 'env' : 'file'
                ]);
            } catch (\Exception $e) {
                \Logger::warning('[ProductService] Firestore init failed', ['error' => $e->getMessage()]);
            }
        } else {
            \Logger::warning('[ProductService] Firestore not available', [
                'vector_enabled' => $vectorEnabled,
                'has_credentials' => $hasFirebaseCredentials
            ]);
        }
    }
    
    /**
     * Get similarity threshold from config or default
     */
    protected function getSimilarityThreshold(): float
    {
        return (float)($this->vectorConfig['similarity_threshold'] ?? self::VECTOR_SIMILARITY_THRESHOLD);
    }

    // ==================== PRODUCT SEARCH ====================

    /**
     * Search products by code, keyword, or category
     * Implements Hybrid Search Strategy:
     * - Exact Match for product codes
     * - Vector/Semantic search for keywords
     * 
     * @param string $query Search query
     * @param array $config Bot config
     * @param array $context Chat context
     * @return array ['ok' => bool, 'products' => array, 'total' => int]
     */
    public function search(string $query, array $config, array $context, array $options = []): array
    {
        $query = trim($query);
        
        if (empty($query)) {
            return ['ok' => false, 'products' => [], 'total' => 0, 'error' => 'empty_query'];
        }
        
        // Extract options
        $category = $options['category'] ?? null;
        $excludeCategory = $options['exclude_category'] ?? null;
        $searchAll = $options['search_all'] ?? false;

        // Detect search type
        $searchType = $this->detectSearchType($query);

        switch ($searchType) {
            case 'code':
                // Step A: Exact Match - Direct API call for product codes
                return $this->searchByCode($query, $config, $context);
            case 'keyword':
                // Step B: Semantic Match - Use Vector Search with filters
                return $this->searchByKeywordHybrid($query, $config, $context, $options);
            default:
                return $this->searchByKeywordHybrid($query, $config, $context, $options);
        }
    }

    /**
     * Search product by exact code
     * Uses ProductSearchService (mock API from data team)
     */
    public function searchByCode(string $code, array $config, array $context): array
    {
        // Normalize code - keep dashes for ProductSearchService
        $code = strtoupper(trim($code));

        try {
            // Use ProductSearchService (mock API)
            $products = \ProductSearchService::searchByProductCode($code);
            
            if (!empty($products)) {
                return [
                    'ok' => true,
                    'products' => array_map([$this, 'formatProduct'], $products),
                    'total' => count($products),
                    'source' => 'exact_match'
                ];
            }
            
            return [
                'ok' => true,
                'products' => [],
                'total' => 0,
                'source' => 'exact_match'
            ];
        } catch (\Exception $e) {
            \Logger::error("[ProductService] ProductSearchService error", ['error' => $e->getMessage()]);
            return ['ok' => false, 'products' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get single product by exact code
     * Wrapper for searchByCode that returns single product
     * Used by FunctionExecutor for LLM function calling
     */
    public function getByCode(string $code, array $config, array $context): array
    {
        $result = $this->searchByCode($code, $config, $context);
        
        if ($result['ok'] && !empty($result['products'])) {
            return [
                'ok' => true,
                'product' => $result['products'][0], // Return first match
            ];
        }
        
        return [
            'ok' => false,
            'product' => null,
            'error' => $result['error'] ?? 'Product not found',
        ];
    }

    /**
     * Normalize query for better matching
     * Handles: Thai numbers, spacing variants, brand name typos, Thai number words
     * 
     * @param string $query Raw user query
     * @return string Normalized query
     */
    protected function normalizeQuery(string $query): string
    {
        // 1. Convert Thai number digits to Arabic (๑๒๓ → 123)
        $thaiNumbers = ['๐', '๑', '๒', '๓', '๔', '๕', '๖', '๗', '๘', '๙'];
        $arabicNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $query = str_replace($thaiNumbers, $arabicNumbers, $query);
        
        // 2. Convert Thai number WORDS to digits (5แสน → 500000, 2หมื่น → 20000)
        $query = $this->convertThaiNumberWords($query);
        
        // 2. Normalize common brand name variants (Thai → English canonical)
        $brandVariants = [
            // Rolex
            'โรเล็กซ์' => 'rolex',
            'โรเลกซ์' => 'rolex',
            'โรเล็ก' => 'rolex',
            'โรเลก' => 'rolex',
            // Omega
            'โอเมก้า' => 'omega',
            'โอเมกา' => 'omega',
            // Cartier
            'คาร์เทียร์' => 'cartier',
            'คาเทียร์' => 'cartier',
            'คาร์ติเย่' => 'cartier',
            // Patek Philippe
            'ปาเต็ก' => 'patek',
            'พาเทค' => 'patek',
            // Hermes
            'แอร์เมส' => 'hermes',
            'เฮอร์เมส' => 'hermes',
            // Louis Vuitton
            'หลุยส์' => 'louis vuitton',
            'หลุยส์วิตตอง' => 'louis vuitton',
            'แอลวี' => 'lv',
            // Chanel
            'ชาเนล' => 'chanel',
            'ชาแนล' => 'chanel',
            // Gucci
            'กุชชี่' => 'gucci',
            'กุชชี' => 'gucci',
        ];
        
        $queryLower = mb_strtolower($query, 'UTF-8');
        foreach ($brandVariants as $thai => $english) {
            if (mb_strpos($queryLower, $thai) !== false) {
                $query = str_ireplace($thai, $english, $query);
            }
        }
        
        // 3. Remove spaces between attribute keywords for better matching
        // "สี ดำ" → "สีดำ", "ผู้ ชาย" → "ผู้ชาย"
        $spacedPatterns = [
            // Colors
            '/สี\s+ดำ/u' => 'สีดำ',
            '/สี\s+ขาว/u' => 'สีขาว',
            '/สี\s+แดง/u' => 'สีแดง',
            '/สี\s+น้ำเงิน/u' => 'สีน้ำเงิน',
            '/สี\s+เขียว/u' => 'สีเขียว',
            '/สี\s+เหลือง/u' => 'สีเหลือง',
            '/สี\s+ชมพู/u' => 'สีชมพู',
            '/สี\s+ม่วง/u' => 'สีม่วง',
            '/สี\s+ส้ม/u' => 'สีส้ม',
            '/สี\s+น้ำตาล/u' => 'สีน้ำตาล',
            '/สี\s+เทา/u' => 'สีเทา',
            '/สี\s+ฟ้า/u' => 'สีฟ้า',
            '/สี\s+ทอง/u' => 'สีทอง',
            '/สี\s+เงิน/u' => 'สีเงิน',
            // Gender
            '/ผู้\s*ชาย/u' => 'ผู้ชาย',
            '/ผู้\s*หญิง/u' => 'ผู้หญิง',
            // Size
            '/ไซส์\s+ใหญ่/u' => 'ไซส์ใหญ่',
            '/ไซส์\s+เล็ก/u' => 'ไซส์เล็ก',
            '/ไซ\s*ส์/u' => 'ไซส์',
            // Style
            '/สไต\s*ล์/u' => 'สไตล์',
            // Material
            '/สาย\s+หนัง/u' => 'สายหนัง',
            '/สาย\s+เหล็ก/u' => 'สายเหล็ก',
            '/สาย\s+ยาง/u' => 'สายยาง',
            // Condition
            '/มือ\s+สอง/u' => 'มือสอง',
            '/ของ\s+แท้/u' => 'ของแท้',
            // Price
            '/ราคา\s+ไม่\s*เกิน/u' => 'ราคาไม่เกิน',
            '/ไม่\s+เกิน/u' => 'ไม่เกิน',
        ];
        
        foreach ($spacedPatterns as $pattern => $replacement) {
            $query = preg_replace($pattern, $replacement, $query);
        }
        
        // 4. Normalize multiple spaces to single space
        $query = preg_replace('/\s+/', ' ', trim($query));
        
        return $query;
    }

    /**
     * Search products by keyword (legacy - simple search)
     */
    public function searchByKeyword(string $keyword, array $config, array $context, int $limit = 5): array
    {
        try {
            // Use ProductSearchService (mock API)
            $products = \ProductSearchService::searchByKeyword($keyword, $limit);
            
            return [
                'ok' => true,
                'products' => array_map([$this, 'formatProduct'], $products),
                'total' => count($products),
                'source' => 'keyword_search'
            ];
        } catch (\Exception $e) {
            \Logger::error("[ProductService] ProductSearchService keyword error", ['error' => $e->getMessage()]);
            return ['ok' => false, 'products' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build comprehensive search terms from Thai/English keyword mappings
     * Scans ALL attribute types: color, gender, size, style, strap, condition
     * 
     * @param string $queryLower Lowercased query string
     * @return array Search terms to match against products
     */
    protected function buildSearchTerms(string $queryLower): array
    {
        $searchTerms = [$queryLower];
        
        // 1. Color keywords (Thai → English single value)
        foreach ($this->colorKeywords as $thai => $english) {
            if (mb_strpos($queryLower, $thai) !== false) {
                $searchTerms[] = strtolower($english);
                // Also add Thai without "สี" prefix
                $withoutPrefix = str_replace('สี', '', $queryLower);
                if (!empty($withoutPrefix) && $withoutPrefix !== $queryLower) {
                    $searchTerms[] = $withoutPrefix;
                }
            }
        }
        
        // 2. Gender keywords (Thai → English array)
        foreach ($this->genderKeywords as $thai => $englishArray) {
            if (mb_strpos($queryLower, $thai) !== false) {
                foreach ($englishArray as $eng) {
                    $searchTerms[] = strtolower($eng);
                }
            }
        }
        
        // 3. Size keywords
        foreach ($this->sizeKeywords as $thai => $englishArray) {
            if (mb_strpos($queryLower, $thai) !== false) {
                foreach ($englishArray as $eng) {
                    $searchTerms[] = strtolower($eng);
                }
            }
        }
        
        // 4. Style keywords
        foreach ($this->styleKeywords as $thai => $englishArray) {
            if (mb_strpos($queryLower, $thai) !== false) {
                foreach ($englishArray as $eng) {
                    $searchTerms[] = strtolower($eng);
                }
            }
        }
        
        // 5. Strap/band keywords
        foreach ($this->strapKeywords as $thai => $englishArray) {
            if (mb_strpos($queryLower, $thai) !== false) {
                foreach ($englishArray as $eng) {
                    $searchTerms[] = strtolower($eng);
                }
            }
        }
        
        // 6. Condition keywords
        foreach ($this->conditionKeywords as $thai => $englishArray) {
            if (mb_strpos($queryLower, $thai) !== false) {
                foreach ($englishArray as $eng) {
                    $searchTerms[] = strtolower($eng);
                }
            }
        }
        
        // 7. Material keywords - expand "ทอง" → ["gold", "ทองคำ"], etc.
        $materialExpansions = [
            'ทอง' => ['gold', 'ทองคำ', 'ทองแท่ง'],
            'เงิน' => ['silver', 'sterling'],
            'เพชร' => ['diamond', 'เพชรแท้'],
            'แพลทินัม' => ['platinum', 'แพลตินัม'],
        ];
        foreach ($materialExpansions as $thai => $expansions) {
            if (mb_strpos($queryLower, $thai) !== false) {
                foreach ($expansions as $exp) {
                    $searchTerms[] = strtolower($exp);
                }
            }
        }
        
        // Remove duplicates
        $searchTerms = array_unique($searchTerms);
        
        \Logger::debug("[ProductService] Built search terms", [
            'query' => $queryLower,
            'terms_count' => count($searchTerms),
            'terms' => array_slice($searchTerms, 0, 10) // Log first 10 for debugging
        ]);
        
        return $searchTerms;
    }

    /**
     * Get keywords for a category (for exclusion matching)
     * 
     * @param string $category Category in English (e.g., 'watch', 'ring')
     * @return array Keywords that indicate this category
     */
    protected function getCategoryKeywords(string $category): array
    {
        $categoryMappings = [
            'watch' => ['watch', 'นาฬิกา', 'rolex', 'omega', 'tag', 'patek', 'seiko', 'citizen', 'casio', 'timepiece'],
            'ring' => ['ring', 'แหวน', 'wedding band', 'engagement'],
            'necklace' => ['necklace', 'สร้อยคอ', 'สร้อย', 'chain', 'pendant'],
            'bracelet' => ['bracelet', 'กำไล', 'ข้อมือ', 'bangle'],
            'earring' => ['earring', 'ต่างหู', 'ตุ้มหู', 'ear'],
            'pendant' => ['pendant', 'จี้', 'charm'],
            'bag' => ['bag', 'กระเป๋า', 'purse', 'handbag', 'wallet', 'กระเป๋าแบรนด์เนม'],
            'amulet' => ['amulet', 'พระ', 'พระเครื่อง', 'เลี่ยม', 'วัตถุมงคล'],
            'jewelry' => ['jewelry', 'jewellery', 'เครื่องประดับ', 'เพชร', 'diamond', 'ชุดเครื่องประดับ'],
            'gold' => ['gold', 'ทอง', 'ทองคำ', 'ทองแท่ง', 'gold bar', 'ทองคำแท่ง'],
        ];
        
        return $categoryMappings[$category] ?? [$category];
    }

    /**
     * Convert Thai number words to numeric values
     * Supports: "5แสน" → "500000", "2หมื่น" → "20000", "ล้านห้า" → "1500000"
     * 
     * @param string $query Query string
     * @return string Query with converted numbers
     */
    protected function convertThaiNumberWords(string $query): string
    {
        // Thai number word multipliers
        $multipliers = [
            'ล้าน' => 1000000,
            'แสน' => 100000,
            'หมื่น' => 10000,
            'พัน' => 1000,
            'ร้อย' => 100,
        ];
        
        // Thai digit words
        $thaiDigits = [
            'ศูนย์' => 0, 'หนึ่ง' => 1, 'สอง' => 2, 'สาม' => 3, 'สี่' => 4,
            'ห้า' => 5, 'หก' => 6, 'เจ็ด' => 7, 'แปด' => 8, 'เก้า' => 9,
            'สิบ' => 10, 'ยี่สิบ' => 20, 'สามสิบ' => 30, 'สี่สิบ' => 40, 'ห้าสิบ' => 50,
        ];
        
        // Pattern 1: Number + Thai multiplier (e.g., "5แสน", "2หมื่น", "1.5ล้าน")
        // Supports: 5แสน, 5 แสน, 50000, ห้าแสน
        foreach ($multipliers as $word => $value) {
            // Match: digit(s) + optional space + multiplier word
            // e.g., "5แสน", "5 แสน", "1.5ล้าน", "15หมื่น"
            $pattern = '/(\d+(?:\.\d+)?)\s*' . preg_quote($word, '/') . '/u';
            $query = preg_replace_callback($pattern, function($matches) use ($value) {
                $num = (float)$matches[1];
                return (string)(int)($num * $value);
            }, $query);
        }
        
        // Pattern 2: Thai word number + multiplier (e.g., "ห้าแสน", "สองหมื่น")
        foreach ($thaiDigits as $digitWord => $digitValue) {
            foreach ($multipliers as $multWord => $multValue) {
                $pattern = '/' . preg_quote($digitWord, '/') . '\s*' . preg_quote($multWord, '/') . '/u';
                if (preg_match($pattern, $query)) {
                    $query = preg_replace($pattern, (string)($digitValue * $multValue), $query);
                }
            }
        }
        
        // Pattern 3: Multiplier + digit (e.g., "ล้านห้า" = 1.5 million, "แสนห้า" = 150000)
        foreach ($multipliers as $multWord => $multValue) {
            foreach ($thaiDigits as $digitWord => $digitValue) {
                if ($digitValue > 0 && $digitValue < 10) {
                    $pattern = '/' . preg_quote($multWord, '/') . '\s*' . preg_quote($digitWord, '/') . '/u';
                    if (preg_match($pattern, $query)) {
                        // ล้านห้า = 1,500,000 (1 million + 0.5 million)
                        $result = $multValue + ($digitValue * $multValue / 10);
                        $query = preg_replace($pattern, (string)(int)$result, $query);
                    }
                }
            }
        }
        
        // Pattern 4: "งบ X", "budget X" - extract budget amount
        // Already handled by extractPriceFilter, no conversion needed
        
        return $query;
    }

    /**
     * Extract price filter from query
     * Supports: "ราคาไม่เกิน 50000", "ไม่เกิน 30000", "ถูกกว่า 100000", "ราคา 50000-100000"
     * Also: "งบ 500000", "budget 300000"
     * 
     * @param string $query Query string
     * @return array|null Price filter config or null
     */
    protected function extractPriceFilter(string $query): ?array
    {
        // Pattern 1: "ไม่เกิน X", "ถูกกว่า X", "ต่ำกว่า X", "งบ X", "budget X"
        if (preg_match('/(?:ไม่เกิน|ถูกกว่า|ต่ำกว่า|งบ|budget|under|below|less than)\s*(\d[\d,]*)/ui', $query, $matches)) {
            $value = (float) str_replace(',', '', $matches[1]);
            return ['type' => 'max', 'value' => $value];
        }
        
        // Pattern 2: "มากกว่า X", "แพงกว่า X", "เกิน X"
        if (preg_match('/(?:มากกว่า|แพงกว่า|เกิน|above|over|more than)\s*(\d[\d,]*)/ui', $query, $matches)) {
            $value = (float) str_replace(',', '', $matches[1]);
            return ['type' => 'min', 'value' => $value];
        }
        
        // Pattern 3: "X-Y", "X ถึง Y", "ระหว่าง X และ Y"
        if (preg_match('/(\d[\d,]*)\s*(?:-|ถึง|to)\s*(\d[\d,]*)/ui', $query, $matches)) {
            $min = (float) str_replace(',', '', $matches[1]);
            $max = (float) str_replace(',', '', $matches[2]);
            return ['type' => 'range', 'min' => min($min, $max), 'max' => max($min, $max)];
        }
        
        // Pattern 4: "ถูก", "ราคาถูก" → under 50000
        if (preg_match('/(?:ถูก|ราคาถูก|งบน้อย|ประหยัด)/ui', $query)) {
            return ['type' => 'max', 'value' => 50000];
        }
        
        // Pattern 5: "แพง", "ราคาแพง", "หรู" → above 100000
        if (preg_match('/(?:แพง|ราคาแพง|premium|luxury)/ui', $query)) {
            return ['type' => 'min', 'value' => 100000];
        }
        
        return null;
    }

    /**
     * Analyze query to extract filters (Category/Material)
     * and clean up the query for search
     * 
     * @param string $query User's search query
     * @return array Analysis result with filters and cleaned query
     */
    protected function analyzeQuery(string $query): array
    {
        // ==================== STEP 0: NORMALIZE QUERY ====================
        // จัดการ: เว้นวรรค/ไม่เว้นวรรค, ตัวเลขไทย, brand variants
        $query = $this->normalizeQuery($query);
        
        // 1. Clean stop words (ตัดคำฟุ่มเฟือย) - use regex for word boundaries
        // Supports both "มีแหวนไหม" (no space) and "มี แหวน ไหม" (with space)
        $stopWords = [
            // Question words
            'มี', 'ไหม', 'มั้ย', 'บ้าง', 'หรือเปล่า', 'รึเปล่า', 'ป่าว', 'ปะ',
            // Polite particles
            'ครับ', 'ค่ะ', 'คะ', 'คับ', 'จ้า', 'จ้ะ', 'นะ', 'นะคะ', 'นะครับ',
            // Request verbs (common in Thai queries)
            'ขอดู', 'อยากดู', 'อยากได้', 'อยากเห็น', 'ขอ', 'หา', 'ต้องการ', 'สนใจ', 
            'ดู', 'เอา', 'หน่อย', 'ช่วย', 'แนะนำ',
            // Filler words
            'ที่', 'ของ', 'ตัว', 'อัน', 'ชิ้น'
        ];
        
        $cleanQuery = $query;
        
        // Sort by length descending to remove longer phrases first
        usort($stopWords, function($a, $b) {
            return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
        });
        
        foreach ($stopWords as $word) {
            // Use regex with word boundary-like pattern for Thai
            // Match: start/space + word + end/space
            $pattern = '/(?:^|\s)' . preg_quote($word, '/') . '(?:\s|$)/u';
            $cleanQuery = preg_replace($pattern, ' ', $cleanQuery);
            
            // Also try direct replacement for concatenated words like "มีแหวนไหม"
            $cleanQuery = str_ireplace($word, '', $cleanQuery);
        }
        
        // Normalize whitespace
        $cleanQuery = trim(preg_replace('/\s+/', ' ', $cleanQuery));
        
        $filters = [];
        $remainingQuery = $cleanQuery;

        // 2. Check for Categories (Hard Filter) - longest match first
        $sortedCategories = $this->categoryKeywords;
        uksort($sortedCategories, function($a, $b) {
            return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
        });
        
        foreach ($sortedCategories as $key => $value) {
            if (mb_strpos($cleanQuery, $key) !== false) {
                $filters['category'] = $value;
                // Remove category keyword from query
                $tempQuery = str_replace($key, '', $remainingQuery);
                $tempQuery = trim(preg_replace('/\s+/', ' ', $tempQuery));
                if ($tempQuery !== '') {
                    $remainingQuery = $tempQuery;
                }
                break;
            }
        }

        // 3. Check for Materials - longest match first
        $sortedMaterials = $this->materialKeywords;
        uksort($sortedMaterials, function($a, $b) {
            return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
        });
        
        foreach ($sortedMaterials as $key => $value) {
            if (mb_strpos($cleanQuery, $key) !== false) {
                $filters['material'] = $value;
                break;
            }
        }
        
        // 4. Check for Brand Aliases (Thai → English) - longest match first
        $sortedBrands = $this->brandAliases;
        uksort($sortedBrands, function($a, $b) {
            return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
        });
        
        foreach ($sortedBrands as $thaiName => $englishName) {
            if (mb_strpos($cleanQuery, $thaiName) !== false) {
                $filters['brand'] = $englishName;
                // Replace Thai brand with English for better search
                $remainingQuery = str_replace($thaiName, $englishName, $remainingQuery);
                $cleanQuery = str_replace($thaiName, $englishName, $cleanQuery);
                break;
            }
        }

        // 5. Determine if this is a broad category search
        $isBroadSearch = !empty($filters) && (empty($remainingQuery) || $remainingQuery === $cleanQuery);

        \Logger::info('[ProductService] Query analyzed', [
            'original' => $query,
            'cleaned' => $cleanQuery,
            'remaining' => $remainingQuery,
            'filters' => $filters,
            'is_broad_search' => $isBroadSearch
        ]);

        return [
            'original_query' => $query,
            'clean_query' => $cleanQuery,
            'remaining_query' => $remainingQuery,
            'filters' => $filters,
            'is_broad_search' => $isBroadSearch
        ];
    }

    /**
     * Hybrid keyword search with Filter-First approach
     * 
     * Strategy (Filter-First, Always):
     * 1. ถ้า detect ได้ category/material → ใช้ Category Filter ก่อนเสมอ
     * 2. ถ้า Category Filter พบสินค้า → return ทันที
     * 3. ถ้าไม่พบ → fallback ไป traditional keyword search
     * 4. ถ้ายังไม่พบ → fallback ไป Vector Search
     * 
     * ปรับปรุง 2026-02-02: ใช้ Category Filter ก่อนเสมอ ไม่ใช่แค่ broad search
     * เพราะ "มีแหวนเพชรไหม" ควรหา category=ring + material=diamond ได้
     */
    protected function searchByKeywordHybrid(string $keyword, array $config, array $context, $optionsOrLimit = 5): array
    {
        // Support both old (int $limit) and new (array $options) signature
        if (is_array($optionsOrLimit)) {
            $options = $optionsOrLimit;
            $limit = 5;
        } else {
            $options = [];
            $limit = (int)$optionsOrLimit;
        }
        
        // Extract options for category filtering
        $requestedCategory = $options['category'] ?? null;
        $excludeCategory = $options['exclude_category'] ?? null;
        $searchAll = $options['search_all'] ?? false;
        
        // ✅ NEW: Extract price range from LLM options
        $llmPriceMin = isset($options['price_min']) ? (int)$options['price_min'] : null;
        $llmPriceMax = isset($options['price_max']) ? (int)$options['price_max'] : null;
        $llmGender = $options['gender'] ?? null;
        
        \Logger::info("[ProductService] Hybrid search started", [
            'keyword' => $keyword,
            'requested_category' => $requestedCategory,
            'exclude_category' => $excludeCategory,
            'search_all' => $searchAll,
            'price_range' => $llmPriceMin || $llmPriceMax ? [$llmPriceMin, $llmPriceMax] : null,
            'gender' => $llmGender
        ]);
        
        // Step 0: Analyze Query - extract category/material filters
        $analysis = $this->analyzeQuery($keyword);
        $filters = $analysis['filters'];
        
        // ✅ Override category from LLM if provided
        if ($requestedCategory) {
            $filters['category'] = $requestedCategory;
        }
        
        // ==================== STEP 1: Category Filter (Always Try First) ====================
        // ถ้า detect ได้ category หรือ material → ลอง filter ก่อนเสมอ
        // e.g., "มีแหวนเพชรแท้ไหม" → category=ring, material=diamond
        
        if (!empty($filters) || $excludeCategory || $searchAll) {
            \Logger::info("[ProductService] Using Category Filter (filter-first)", [
                'filters' => $filters,
                'exclude' => $excludeCategory,
                'search_all' => $searchAll
            ]);
            
            $category = $filters['category'] ?? null;
            $material = $filters['material'] ?? null;
            
            // ✅ If search_all, get all products (no category filter)
            if ($searchAll || $excludeCategory) {
                $products = \ProductSearchService::searchAll($limit * 4); // Get more for filtering
            } else {
                $products = \ProductSearchService::searchByCategory($category, $material, $limit * 2);
            }
            
            // ✅ Apply exclude_category filter
            if (!empty($products) && $excludeCategory) {
                $excludeCatLower = strtolower($excludeCategory);
                $productsFiltered = array_filter($products, function($p) use ($excludeCatLower) {
                    $productCategory = strtolower($p['category'] ?? '');
                    // Also check product_code prefix and title
                    $productCode = strtolower($p['product_code'] ?? '');
                    $title = mb_strtolower($p['title'] ?? '', 'UTF-8');
                    
                    // Map category to keywords for exclusion
                    $excludeKeywords = $this->getCategoryKeywords($excludeCatLower);
                    
                    // Check if product belongs to excluded category
                    foreach ($excludeKeywords as $exKw) {
                        if (mb_strpos($productCategory, $exKw) !== false) return false;
                        if (mb_strpos($title, $exKw) !== false) return false;
                        // Check brand too for watches
                        if ($excludeCatLower === 'watch') {
                            $watchBrands = ['rolex', 'omega', 'tag', 'patek', 'cartier', 'seiko', 'citizen'];
                            $brand = strtolower($p['brand'] ?? '');
                            if (in_array($brand, $watchBrands)) return false;
                        }
                    }
                    return true;
                });
                
                \Logger::info("[ProductService] Applied exclude filter", [
                    'exclude' => $excludeCategory,
                    'before' => count($products),
                    'after' => count($productsFiltered)
                ]);
                
                $products = array_values($productsFiltered);
            }
            
            if (!empty($products)) {
                // ถ้ามี remaining_query (e.g., "Rolex" จาก "นาฬิกา Rolex") → filter ต่อ
                $remainingQuery = trim($analysis['remaining_query'] ?? '');
                
                if (!empty($remainingQuery) && $remainingQuery !== $analysis['clean_query']) {
                    $searchLower = mb_strtolower($remainingQuery, 'UTF-8');
                    
                    // ✅ Build comprehensive search terms from ALL keyword mappings
                    $searchTerms = $this->buildSearchTerms($searchLower);
                    
                    // ✅ Extract price filter if present (e.g., "ราคาไม่เกิน 50000")
                    $priceFilter = $this->extractPriceFilter($remainingQuery);
                    
                    $productsFiltered = array_filter($products, function($p) use ($searchTerms, $priceFilter) {
                        // Search in ALL relevant fields (not just title + brand)
                        $searchText = mb_strtolower(
                            ($p['title'] ?? '') . ' ' . 
                            ($p['brand'] ?? '') . ' ' .
                            ($p['description'] ?? '') . ' ' .
                            ($p['product_code'] ?? ''), 
                            'UTF-8'
                        );
                        
                        // ✅ Check price filter first (if present)
                        if ($priceFilter !== null) {
                            $price = (float)($p['price'] ?? 0);
                            if ($priceFilter['type'] === 'max' && $price > $priceFilter['value']) {
                                return false;
                            }
                            if ($priceFilter['type'] === 'min' && $price < $priceFilter['value']) {
                                return false;
                            }
                            if ($priceFilter['type'] === 'range') {
                                if ($price < $priceFilter['min'] || $price > $priceFilter['max']) {
                                    return false;
                                }
                            }
                            // If only price filter (no text terms), pass the filter
                            if (empty($searchTerms)) {
                                return true;
                            }
                        }
                        
                        // If no search terms (only price filter handled above), return true
                        if (empty($searchTerms)) {
                            return true;
                        }
                        
                        // Match ANY of the search terms
                        foreach ($searchTerms as $term) {
                            if (mb_strpos($searchText, $term) !== false) {
                                return true;
                            }
                        }
                        return false;
                    });
                    
                    $countBefore = count($products);
                    $countAfter = count($productsFiltered);
                    
                    \Logger::info("[ProductService] Applied remaining query filter", [
                        'remaining' => $remainingQuery,
                        'search_terms' => $searchTerms,
                        'count_before' => $countBefore,
                        'count_after' => $countAfter
                    ]);
                    
                    // ✅ FIX: ถ้า filter แล้วไม่เหลือ → ถือว่าไม่พบสินค้าตามเงื่อนไข
                    // เช่น "นาฬิกาสีแดง" แต่ไม่มีนาฬิกาสีแดง → return empty
                    // แทนที่จะ return นาฬิกาทั้งหมด (ซึ่งไม่ตรงคำค้นหา)
                    $products = array_values($productsFiltered); // May be empty - that's correct!
                }
                
                // ✅ NEW: Apply LLM price filter (price_min/price_max from options)
                if (($llmPriceMin || $llmPriceMax) && !empty($products)) {
                    $products = array_filter($products, function($p) use ($llmPriceMin, $llmPriceMax) {
                        $price = (float)($p['price'] ?? 0);
                        if ($llmPriceMin && $price < $llmPriceMin) return false;
                        if ($llmPriceMax && $price > $llmPriceMax) return false;
                        return true;
                    });
                    $products = array_values($products);
                    
                    \Logger::info("[ProductService] Applied LLM price filter", [
                        'price_min' => $llmPriceMin,
                        'price_max' => $llmPriceMax,
                        'remaining' => count($products)
                    ]);
                }
                
                // ✅ NEW: Apply gender filter
                if ($llmGender && !empty($products)) {
                    $genderKeywords = $llmGender === 'male' 
                        ? ['ผู้ชาย', 'men', 'male', 'กว้าง', '40mm', '42mm', '44mm']
                        : ['ผู้หญิง', 'women', 'female', 'lady', 'เล็ก', '28mm', '32mm', '36mm'];
                    
                    $productsFiltered = array_filter($products, function($p) use ($genderKeywords) {
                        $searchText = mb_strtolower(
                            ($p['title'] ?? '') . ' ' . ($p['description'] ?? ''), 
                            'UTF-8'
                        );
                        foreach ($genderKeywords as $kw) {
                            if (mb_strpos($searchText, $kw) !== false) return true;
                        }
                        // If no gender info found, include product (don't exclude)
                        return true;
                    });
                    
                    // Only use filtered if we found matches
                    if (!empty($productsFiltered)) {
                        $products = array_values($productsFiltered);
                    }
                    
                    \Logger::info("[ProductService] Applied gender filter", [
                        'gender' => $llmGender,
                        'remaining' => count($products)
                    ]);
                }
                
                // Limit results
                $products = array_slice($products, 0, $limit);
                
                return [
                    'ok' => true,
                    'products' => array_map([$this, 'formatProduct'], $products),
                    'total' => count($products),
                    'source' => 'category_filter',
                    'detected_filters' => $filters
                ];
            }
            
            // Category filter ไม่พบ → log และไป step ถัดไป
            \Logger::info("[ProductService] Category filter returned empty, trying keyword search", $filters);
        }
        
        // ==================== STEP 2: Traditional Keyword Search ====================
        // e.g., "นาฬิกา Rolex สายหนัง" - ต้องหาชื่อ/brand เฉพาะ
        
        $traditionalResult = $this->searchByKeyword($keyword, $config, $context, $limit);
        
        if ($traditionalResult['ok'] && !empty($traditionalResult['products'])) {
            return $traditionalResult;
        }
        
        // Step B.2: Try Vector Search
        $vectorResult = $this->searchByVector($keyword, 10); // Get more to filter
        
        if ($vectorResult['ok'] && !empty($vectorResult['product_ids'])) {
            // Step B.3: Fetch product data
            $products = $this->getProductsByIds($vectorResult['product_ids']);
            
            // Step B.4: Apply post-filter if we detected category/material
            if (!empty($products) && !empty($filters)) {
                $products = array_filter($products, function($p) use ($filters) {
                    // Filter by category
                    if (!empty($filters['category'])) {
                        $productCategory = strtolower($p['category'] ?? '');
                        if ($productCategory !== strtolower($filters['category'])) {
                            return false;
                        }
                    }
                    return true;
                });
                $products = array_values($products);
            }
            
            // Limit results
            $products = array_slice($products, 0, $limit);
            
            if (!empty($products)) {
                return [
                    'ok' => true,
                    'products' => array_map([$this, 'formatProduct'], $products),
                    'total' => count($products),
                    'source' => 'vector_search_filtered',
                    'similarity_scores' => $vectorResult['scores'] ?? [],
                    'detected_filters' => $filters
                ];
            }
        }
        
        // Step B.5: Final fallback - no results
        \Logger::info('[ProductService] No products found for keyword', [
            'keyword' => $keyword,
            'traditional_count' => count($traditionalResult['products'] ?? []),
            'vector_count' => count($vectorResult['product_ids'] ?? []),
            'filters' => $filters
        ]);
        
        return [
            'ok' => true,
            'products' => [],
            'total' => 0,
            'source' => 'no_match',
            'message' => "ไม่พบสินค้าที่ตรงกับ \"{$keyword}\" ค่ะ\n\n💡 ลองค้นหาด้วย:\n• รหัสสินค้า เช่น P001, R023\n• ส่งรูปภาพสินค้าที่ต้องการ\n• ชื่อเฉพาะ เช่น แหวนเพชร, สร้อยทอง"
        ];
    }

    /**
     * Search by image using Gemini Vision
     * 
     * Logic:
     * 1. If image has annotations (circle/arrow), describe only the indicated item
     * 2. Use text description for Vector Search (Text-to-Vector)
     * 3. Fallback to Image-to-Vector if no annotations detected
     */
    public function searchByImage(string $imageUrl, array $config, array $context): array
    {
        \Logger::info("[ProductService] searchByImage called", ['image_url' => substr($imageUrl, 0, 100)]);
        
        // ========== Strategy 1: Direct Image Embedding (Most Accurate) ==========
        // Use multimodal embedding to create embedding directly from image pixels
        // This is the most accurate because it doesn't rely on LLM interpretation
        
        if ($this->firestoreEnabled && $this->firestoreVector) {
            \Logger::info("[ProductService] Trying direct image embedding search");
            
            try {
                $imageResult = $this->firestoreVector->searchByImage($imageUrl, 5);
                
                if ($imageResult['ok'] && !empty($imageResult['product_ids'])) {
                    $scores = $imageResult['scores'] ?? [];
                    $productIds = $imageResult['product_ids'];
                    
                    // ========== Smart Display Logic Based on Similarity ==========
                    // > 70% → Show 1 (very confident match)
                    // 50-70% → Show 3 (moderate confidence, let user choose)
                    // 30-50% → Show 5 (low confidence, show options)
                    // < 30% → No results (filtered by Firestore threshold)
                    
                    $topScore = !empty($scores) ? max($scores) : 0;
                    $displayLimit = 5; // default
                    $confidenceLevel = 'low';
                    
                    if ($topScore >= 0.70) {
                        $displayLimit = 1;
                        $confidenceLevel = 'high';
                    } elseif ($topScore >= 0.50) {
                        $displayLimit = 3;
                        $confidenceLevel = 'medium';
                    } else {
                        $displayLimit = 5;
                        $confidenceLevel = 'low';
                    }
                    
                    // Limit product IDs to display
                    $productIds = array_slice($productIds, 0, $displayLimit);
                    $products = $this->getProductsByIds($productIds);
                    
                    if (!empty($products)) {
                        \Logger::info("[ProductService] Image embedding search succeeded", [
                            'count' => count($products),
                            'top_score' => round($topScore * 100, 1) . '%',
                            'confidence' => $confidenceLevel,
                            'display_limit' => $displayLimit
                        ]);
                        return [
                            'ok' => true,
                            'products' => array_map([$this, 'formatProduct'], $products),
                            'total' => count($products),
                            'source' => 'image_embedding_direct',
                            'scores' => $scores,
                            'top_score' => $topScore,
                            'confidence' => $confidenceLevel
                        ];
                    }
                }
                
                \Logger::info("[ProductService] Image embedding search returned no results, falling back to text");
                
            } catch (\Exception $e) {
                \Logger::warning("[ProductService] Image embedding failed, falling back to text", [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // ========== Strategy 2: LLM Description + Text Embedding (Fallback) ==========
        // Analyze image with Gemini Vision to get text description, then embed the text
        
        \Logger::info("[ProductService] Using LLM text description fallback");
        
        $imageDescription = $this->analyzeImageForProductSearch($imageUrl, $config, $context);
        
        if (!empty($imageDescription['error'])) {
            return [
                'ok' => false, 
                'error' => $imageDescription['error'], 
                'products' => [],
                'message' => 'ไม่สามารถวิเคราะห์รูปภาพได้ กรุณาลองใหม่หรือพิมพ์ชื่อสินค้าค่ะ'
            ];
        }
        
        $productDescription = $imageDescription['description'] ?? '';
        $hasAnnotation = $imageDescription['has_annotation'] ?? false;
        $productType = $imageDescription['product_type'] ?? 'unknown';
        
        \Logger::info("[ProductService] Image analysis result (LLM)", [
            'description' => substr($productDescription, 0, 200),
            'has_annotation' => $hasAnnotation,
            'product_type' => $productType
        ]);
        
        // Use text description for Vector Search
        if (!empty($productDescription)) {
            $vectorResult = $this->searchByVector($productDescription, 5);
            
            if ($vectorResult['ok'] && !empty($vectorResult['product_ids'])) {
                $products = $this->getProductsByIds($vectorResult['product_ids']);
                
                if (!empty($products)) {
                    return [
                        'ok' => true,
                        'products' => array_map([$this, 'formatProduct'], $products),
                        'total' => count($products),
                        'source' => 'image_text_embedding',
                        'detected_description' => $productDescription
                    ];
                }
            }
        }
        
        // ========== Strategy 3: Keyword Search (Last Resort) ==========
        if (!empty($productType) && $productType !== 'unknown') {
            $keywordResult = $this->searchByKeyword($productType, $config, $context, 5);
            
            if ($keywordResult['ok'] && !empty($keywordResult['products'])) {
                return array_merge($keywordResult, ['source' => 'image_keyword_fallback']);
            }
        }
        
        return [
            'ok' => false, 
            'error' => 'no_matching_products', 
            'products' => [],
            'message' => 'ไม่พบสินค้าที่ตรงกับรูปภาพ กรุณาพิมพ์ชื่อหรือรหัสสินค้าค่ะ',
            'detected_description' => $productDescription
        ];
    }

    // ==================== VECTOR SEARCH (Firebase/Firestore) ====================

    /**
     * Generate text embedding for vector search
     * 
     * TODO: Integrate with Google Cloud Vertex AI Embeddings or OpenAI Embeddings
     * Currently returns mock vector for development
     * 
     * @param string $text Text to embed
     * @return array Vector embedding (768 dimensions for text-embedding-004)
     */
    protected function generateEmbedding(string $text): array
    {
        // TODO: Call Vertex AI Text Embeddings API
        // Endpoint: https://us-central1-aiplatform.googleapis.com/v1/projects/{PROJECT}/locations/us-central1/publishers/google/models/text-embedding-004:predict
        // 
        // Example request:
        // {
        //   "instances": [{"content": "Rolex gold watch with diamonds"}],
        //   "parameters": {"outputDimensionality": 768}
        // }
        
        \Logger::info("[ProductService] generateEmbedding called (mock)", ['text' => substr($text, 0, 100)]);
        
        // Mock: Return a dummy 768-dimensional vector
        // In production, this should call the actual embedding API
        $mockVector = [];
        $seed = crc32($text); // Consistent seed based on text
        mt_srand($seed);
        for ($i = 0; $i < 768; $i++) {
            $mockVector[] = (mt_rand() / mt_getrandmax()) * 2 - 1; // Range: -1 to 1
        }
        
        return $mockVector;
    }

    /**
     * Search vector database for similar products
     * 
     * Uses Firebase Firestore Vector Search when available
     * Falls back to mock implementation if not configured
     * 
     * @param string $query Search query text
     * @param int $limit Max results
     * @return array ['ok' => bool, 'product_ids' => array, 'scores' => array]
     */
    protected function searchByVector(string $query, int $limit = 5): array
    {
        \Logger::info("[ProductService] searchByVector called", [
            'query' => substr($query, 0, 100),
            'firestore_enabled' => $this->firestoreEnabled
        ]);
        
        // ✅ Use Firebase Firestore Vector Search if enabled
        if ($this->firestoreEnabled && $this->firestoreVector) {
            try {
                $result = $this->firestoreVector->searchSimilar($query, $limit);
                
                if ($result['ok'] && !empty($result['product_ids'])) {
                    \Logger::info("[ProductService] Vector search found results", [
                        'count' => count($result['product_ids'])
                    ]);
                    return $result;
                }
                
                // Vector search returned no results - continue to fallback
                \Logger::info("[ProductService] Vector search returned no results");
                
            } catch (\Exception $e) {
                \Logger::warning("[ProductService] Vector search error", ['error' => $e->getMessage()]);
            }
        }
        
        // Fallback: Return empty (will fall back to keyword search)
        return [
            'ok' => true,
            'product_ids' => [],
            'scores' => [],
            'source' => $this->firestoreEnabled ? 'firestore_no_results' : 'firestore_not_configured'
        ];
    }

    /**
     * Fetch products by IDs from Data Team API
     * 
     * TODO: This will call the actual Product API when available
     * For now, uses ProductSearchService mock
     * 
     * @param array $refIds Product reference IDs
     * @return array Products data
     */
    protected function getProductsByIds(array $refIds): array
    {
        if (empty($refIds)) {
            return [];
        }
        
        \Logger::info("[ProductService] getProductsByIds called", ['ids' => $refIds]);
        
        // Use searchByRefIds to fetch products by their ref_id
        try {
            $products = \ProductSearchService::searchByRefIds($refIds);
            \Logger::info("[ProductService] getProductsByIds result", [
                'requested_ids' => count($refIds),
                'found_products' => count($products)
            ]);
            return $products;
        } catch (\Exception $e) {
            \Logger::warning("[ProductService] getProductsByIds failed", [
                'ref_ids' => $refIds,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Analyze image for product search using Gemini Vision
     * Handles annotated images (circles, arrows) by focusing on indicated items
     * 
     * @param string $imageUrl Image URL
     * @param array $config Bot config
     * @param array $context Chat context
     * @return array ['description' => string, 'has_annotation' => bool, 'product_type' => string]
     */
    protected function analyzeImageForProductSearch(string $imageUrl, array $config, array $context): array
    {
        // Get Gemini API key - try multiple sources
        $geminiKey = null;
        
        // 1. Try from environment variable first (Cloud Run secrets)
        $geminiKey = getenv('GEMINI_API_KEY');
        
        // 2. Try from integrations in context
        if (!$geminiKey) {
            $integrations = $context['integrations'] ?? [];
            foreach ($integrations as $integration) {
                if (($integration['provider'] ?? '') === 'gemini' && !empty($integration['api_key'])) {
                    $geminiKey = $integration['api_key'];
                    break;
                }
            }
        }
        
        // 3. Try from config
        if (!$geminiKey) {
            $geminiKey = $config['llm']['gemini_api_key'] ?? null;
        }
        
        if (!$geminiKey) {
            \Logger::warning("[ProductService] No Gemini API key found for image analysis");
            return ['error' => 'no_gemini_key', 'description' => '', 'has_annotation' => false];
        }
        
        \Logger::info("[ProductService] Gemini API key found, analyzing image");
        
        // Build the analysis prompt
        $systemPrompt = <<<PROMPT
You are a luxury watch and jewelry expert with deep knowledge of brand identification.
Analyze this image CAREFULLY and identify the product accurately.

**CRITICAL IDENTIFICATION RULES:**
1. READ the brand name/logo on the product dial, case, or clasp CAREFULLY
2. Pay attention to distinctive design elements:
   - Rolex: Crown logo, cyclops date magnifier, specific bezel styles
   - Omega: Ω symbol, Seamaster/Speedmaster distinctive designs
   - Cartier: Roman numerals, blue hands, distinctive case shapes
3. DO NOT guess the brand - if you cannot clearly read or identify it, say "unknown"
4. If there's an ANNOTATION (circle/arrow) in the image, focus ONLY on that item

**FOR WATCHES - CHECK CAREFULLY:**
- Brand name on dial (ROLEX, OMEGA, etc.)
- Sub-dial layout (chronograph vs. simple)
- Bezel type (rotating, fixed, ceramic)
- Case shape and crown style

**RESPONSE FORMAT (JSON only):**
{
  "has_annotation": true/false,
  "product_type": "watch/jewelry/bag/accessory/other",
  "brand": "EXACT brand name read from product OR null if unclear",
  "model": "Model/collection name if visible",
  "description": "Detailed product description for search in Thai language",
  "search_keywords": ["keyword1", "keyword2", "keyword3"],
  "confidence": "high/medium/low"
}

Respond ONLY with valid JSON.
PROMPT;

        try {
            // Call Gemini Vision API
            $response = $this->callGeminiVision($geminiKey, $imageUrl, $systemPrompt);
            
            if (empty($response) || !empty($response['error'])) {
                return [
                    'error' => $response['error'] ?? 'gemini_call_failed',
                    'description' => '',
                    'has_annotation' => false
                ];
            }
            
            // Parse JSON response
            $text = $response['text'] ?? '';
            
            // Log raw Gemini response for debugging
            \Logger::info("[ProductService] Gemini Vision raw response", [
                'text_length' => strlen($text),
                'text_preview' => mb_substr($text, 0, 500)
            ]);
            
            // Extract JSON from response (handle markdown code blocks)
            if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
                $text = $matches[1];
            }
            
            $parsed = json_decode($text, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                \Logger::warning("[ProductService] Failed to parse Gemini response as JSON", ['text' => $text]);
                // Try to extract description from plain text
                return [
                    'description' => $text,
                    'has_annotation' => false,
                    'product_type' => 'unknown'
                ];
            }
            
            // Log parsed result with brand and confidence
            \Logger::info("[ProductService] Gemini Vision parsed result", [
                'brand' => $parsed['brand'] ?? 'null',
                'model' => $parsed['model'] ?? 'null', 
                'product_type' => $parsed['product_type'] ?? 'null',
                'confidence' => $parsed['confidence'] ?? 'null',
                'keywords' => $parsed['search_keywords'] ?? []
            ]);
            
            return [
                'description' => $parsed['description'] ?? '',
                'has_annotation' => $parsed['has_annotation'] ?? false,
                'product_type' => $parsed['product_type'] ?? 'unknown',
                'brand' => $parsed['brand'] ?? null,
                'model' => $parsed['model'] ?? null,
                'confidence' => $parsed['confidence'] ?? null,
                'keywords' => $parsed['search_keywords'] ?? []
            ];
            
        } catch (\Exception $e) {
            \Logger::error("[ProductService] Image analysis failed", ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage(), 'description' => '', 'has_annotation' => false];
        }
    }

    /**
     * Call Gemini Vision API
     */
    protected function callGeminiVision(string $apiKey, string $imageUrl, string $prompt): array
    {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
        
        // Build request with image
        $requestBody = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg',
                                'data' => $this->getImageBase64($imageUrl)
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 1024
            ]
        ];
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestBody),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Retry once on 5xx or timeout
        if ($curlError || $httpCode >= 500 || $httpCode == 429) {
            \Logger::warning("[ProductService] Gemini API transient error, retrying", [
                'http_code' => $httpCode,
                'error' => $curlError
            ]);
            usleep(500000); // 0.5s delay
            
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($requestBody),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        
        if ($httpCode !== 200) {
            \Logger::error("[ProductService] Gemini API error", [
                'http_code' => $httpCode,
                'response' => substr($response, 0, 500)
            ]);
            return ['error' => "Gemini API error: HTTP {$httpCode}"];
        }
        
        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        return ['text' => $text];
    }

    /**
     * Get image as base64
     */
    protected function getImageBase64(string $imageUrl): string
    {
        // Use cURL with proper headers to fetch images
        $ch = curl_init($imageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept: image/*,*/*',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($imageData === false || $httpCode !== 200) {
            \Logger::warning("[ProductService] Failed to fetch image", [
                'url' => substr($imageUrl, 0, 100),
                'http_code' => $httpCode,
                'error' => $error
            ]);
            throw new \Exception("Failed to fetch image from URL (HTTP {$httpCode})");
        }
        
        return base64_encode($imageData);
    }

    // ==================== PRODUCT FORMATTING ====================

    /**
     * Format raw product data to standard format
     */
    public function formatProduct(array $raw): array
    {
        return [
            'ref_id' => $raw['ref_id'] ?? $raw['id'] ?? null,
            'code' => $raw['product_code'] ?? $raw['code'] ?? '',
            'name' => $raw['name'] ?? $raw['product_name'] ?? $raw['title'] ?? '',
            'title' => $raw['title'] ?? $raw['name'] ?? $raw['product_name'] ?? '',
            'brand' => $raw['brand'] ?? '',
            'category' => $raw['category'] ?? '',
            'price' => (float)($raw['price'] ?? 0),
            'sale_price' => (float)($raw['sale_price'] ?? $raw['price'] ?? 0),
            'image' => $raw['thumbnail_url'] ?? $raw['image_url'] ?? $raw['image'] ?? '',
            'availability' => $raw['availability'] ?? 'in_stock',
            'description' => $raw['description'] ?? '',
            'stock' => (int)($raw['stock'] ?? 0),
            'status' => $raw['status'] ?? 'active'
        ];
    }

    /**
     * Format product for LINE Flex Message
     */
    public function formatForLineFlex(array $product): array
    {
        $price = $product['sale_price'] ?? $product['price'] ?? 0;
        $originalPrice = $product['price'] ?? $price;
        $hasDiscount = $originalPrice > $price;

        $bubble = [
            'type' => 'bubble',
            'hero' => [
                'type' => 'image',
                'url' => $product['image'] ?: 'https://via.placeholder.com/400x300?text=No+Image',
                'size' => 'full',
                'aspectRatio' => '4:3',
                'aspectMode' => 'cover'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $product['code'] ?? '',
                        'weight' => 'bold',
                        'size' => 'sm',
                        'color' => '#666666'
                    ],
                    [
                        'type' => 'text',
                        'text' => $product['name'] ?? 'ไม่ระบุชื่อ',
                        'weight' => 'bold',
                        'size' => 'md',
                        'margin' => 'sm',
                        'wrap' => true
                    ],
                    [
                        'type' => 'text',
                        'text' => '฿' . number_format($price, 0),
                        'weight' => 'bold',
                        'size' => 'lg',
                        'color' => '#E53E3E',
                        'margin' => 'md'
                    ]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'button',
                        'action' => [
                            'type' => 'message',
                            'label' => 'สนใจ',
                            'text' => 'สนใจ ' . ($product['code'] ?? '')
                        ],
                        'style' => 'primary',
                        'color' => '#38A169'
                    ]
                ]
            ]
        ];

        // Add original price if discounted
        if ($hasDiscount) {
            $bubble['body']['contents'][] = [
                'type' => 'text',
                'text' => '฿' . number_format($originalPrice, 0),
                'size' => 'sm',
                'color' => '#999999',
                'decoration' => 'line-through'
            ];
        }

        return $bubble;
    }

    /**
     * Format products as LINE Carousel
     */
    public function formatAsCarousel(array $products): array
    {
        $bubbles = [];
        
        foreach (array_slice($products, 0, 10) as $product) { // LINE limit: 10 bubbles
            $bubbles[] = $this->formatForLineFlex($product);
        }

        return [
            'type' => 'flex',
            'altText' => 'พบสินค้า ' . count($products) . ' รายการ',
            'contents' => [
                'type' => 'carousel',
                'contents' => $bubbles
            ]
        ];
    }

    /**
     * Format as text reply (fallback)
     */
    public function formatAsText(array $products): string
    {
        if (empty($products)) {
            return 'ไม่พบสินค้าที่ค้นหา';
        }

        $lines = ['🔍 พบสินค้า ' . count($products) . ' รายการ:', ''];
        
        foreach ($products as $i => $product) {
            $num = $i + 1;
            $code = $product['code'] ?? '';
            $name = $product['name'] ?? '';
            $price = number_format($product['sale_price'] ?? $product['price'] ?? 0, 0);
            
            $lines[] = "{$num}. {$code} - {$name}";
            $lines[] = "   💰 ฿{$price}";
        }

        $lines[] = '';
        $lines[] = 'พิมพ์ "สนใจ [รหัส]" เพื่อสั่งซื้อ';

        return implode("\n", $lines);
    }

    // ==================== RECENTLY VIEWED ====================

    /**
     * Get recently viewed product for a user
     */
    public function getRecentlyViewed(array $context): ?array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        if (!$platformUserId || !$channelId) {
            return null;
        }

        try {
            $sql = "SELECT product_code, product_data, viewed_at 
                    FROM product_views 
                    WHERE platform_user_id = ? 
                    AND channel_id = ?
                    AND viewed_at > NOW() - INTERVAL 1 HOUR
                    ORDER BY viewed_at DESC 
                    LIMIT 1";
            
            $row = $this->db->queryOne($sql, [$platformUserId, $channelId]);
            
            if (!$row) {
                return null;
            }

            $productData = json_decode($row['product_data'] ?? '{}', true);
            $productData['code'] = $row['product_code'];
            
            return $productData;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Track product view
     */
    public function trackView(array $product, array $context): void
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;
        $code = $product['code'] ?? null;

        if (!$platformUserId || !$channelId || !$code) {
            return;
        }

        try {
            $sql = "INSERT INTO product_views 
                    (platform_user_id, channel_id, product_code, product_data, viewed_at) 
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        product_data = VALUES(product_data),
                        viewed_at = NOW()";
            
            $this->db->execute($sql, [
                $platformUserId,
                $channelId,
                $code,
                json_encode($product, JSON_UNESCAPED_UNICODE)
            ]);
        } catch (\Exception $e) {
            // Silently fail - view tracking is not critical
        }
    }

    // ==================== HELPERS ====================

    /**
     * Detect search type from query
     */
    protected function detectSearchType(string $query): string
    {
        // Product code patterns:
        // Format 1: XXX-XXX-NNN (e.g., ROL-DAY-001, GLD-NCK-001)
        // Format 2: XXX-NNN (e.g., A-123, ABC-123)
        // Format 3: XXXNNN (e.g., ABC123)
        if (preg_match('/^[A-Z]{2,5}[-][A-Z]{2,5}[-]\d{3,5}$/i', $query)) {
            return 'code';
        }
        if (preg_match('/^[A-Z]{1,5}[-]?\d{3,10}$/i', $query)) {
            return 'code';
        }

        return 'keyword';
    }

    /**
     * Check if backend is enabled for a specific endpoint
     */
    protected function isBackendEnabled(array $config, string $endpoint): bool
    {
        return !empty($config['backend_api']['enabled']) &&
               !empty($config['backend_api']['endpoints'][$endpoint]);
    }
}
