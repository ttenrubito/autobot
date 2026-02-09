<?php
/**
 * Product Search Service
 * 
 * Wrapper service for calling Product Search API v1
 * Used by chatbot (RouterV1Handler) and internal PHP code
 * 
 * @date 2026-01-16
 */

class ProductSearchService
{
    /**
     * API base URL - uses internal path when called server-side
     */
    private static function getApiUrl(): string
    {
        // Use internal file include when running server-side
        return __DIR__ . '/../../api/v1/products/search.php';
    }

    /**
     * Search products by keyword
     * 
     * @param string $keyword Search keyword
     * @param int $limit Maximum results
     * @return array Products array
     */
    public static function searchByKeyword(string $keyword, int $limit = 10): array
    {
        return self::search(['keyword' => $keyword], $limit);
    }

    /**
     * Search products by product code
     * 
     * @param string $code Product code (e.g., "ROL-DAY-001")
     * @return array Products array
     */
    public static function searchByProductCode(string $code): array
    {
        return self::search(['product_code' => $code], 10);
    }

    /**
     * Search products by ref IDs (from vector search)
     * 
     * @param array $refIds Array of ref_id strings
     * @return array Products array
     */
    public static function searchByRefIds(array $refIds): array
    {
        return self::search(['ref_ids' => $refIds], count($refIds));
    }

    /**
     * Search products by category and/or material (Filter-first approach)
     * Used for broad queries like "มีแหวนไหม", "ทอง"
     * 
     * @param string|null $category Category filter (e.g., 'ring', 'watch', 'necklace')
     * @param string|null $material Material filter (e.g., 'gold', 'silver', 'diamond')
     * @param int $limit Maximum results
     * @return array Products array
     */
    public static function searchByCategory(?string $category, ?string $material = null, int $limit = 5): array
    {
        $products = self::getBasicMockProducts();
        $results = [];
        
        foreach ($products as $product) {
            $match = true;
            
            // Filter by category
            if ($category) {
                $productCategory = strtolower($product['category'] ?? '');
                if ($productCategory !== strtolower($category)) {
                    $match = false;
                }
            }
            
            // Filter by material (check in title/description)
            if ($material && $match) {
                $searchText = strtolower(
                    ($product['title'] ?? '') . ' ' .
                    ($product['description'] ?? '')
                );
                
                // Map material keywords to search terms
                $materialSearchTerms = [
                    'gold' => ['ทอง', 'gold', 'yellow gold', 'ทองคำ'],
                    'silver' => ['เงิน', 'silver'],
                    'diamond' => ['เพชร', 'diamond'],
                    'platinum' => ['แพลทินัม', 'platinum'],
                ];
                
                $terms = $materialSearchTerms[strtolower($material)] ?? [$material];
                $materialMatch = false;
                
                foreach ($terms as $term) {
                    if (mb_strpos($searchText, strtolower($term)) !== false) {
                        $materialMatch = true;
                        break;
                    }
                }
                
                if (!$materialMatch) {
                    $match = false;
                }
            }
            
            if ($match) {
                $results[] = $product;
            }
        }
        
        return array_slice($results, 0, $limit);
    }

    /**
     * Search ALL products without category filter
     * Used when user wants to see products from different categories
     * 
     * @param int $limit Maximum results
     * @return array Products array
     */
    public static function searchAll(int $limit = 20): array
    {
        $products = self::getBasicMockProducts();
        
        // Shuffle to get variety
        shuffle($products);
        
        return array_slice($products, 0, $limit);
    }

    /**
     * Internal search method
     * 
     * @param array $params Search parameters
     * @param int $limit Maximum results
     * @return array Products array
     */
    private static function search(array $params, int $limit): array
    {
        // Build request body
        $requestBody = array_merge($params, [
            'page' => ['limit' => $limit]
        ]);

        // Capture output from API
        ob_start();

        // Simulate POST request
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        // Store original input
        $originalInput = file_get_contents('php://input');

        // Create temp stream with our JSON
        $tempStream = fopen('php://temp', 'r+');
        fwrite($tempStream, json_encode($requestBody));
        rewind($tempStream);

        // Use output buffer trick to call the API
        try {
            // Include the API file directly for internal calls
            $mockProducts = self::getMockProducts();
            $results = self::filterProducts($mockProducts, $params);

            // Apply limit
            $results = array_slice($results, 0, $limit);

            return $results;
        } catch (Exception $e) {
            error_log("[ProductSearchService] Error: " . $e->getMessage());
            return [];
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Get mock products data - Load ALL products from API file
     * @return array All mock products from the API
     */
    private static function getMockProducts(): array
    {
        // Call the actual mock API to get all products
        // Store original server state
        $originalMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            // Simulate a search that returns all products (empty search)
            // We'll use keyword search with a very broad term or get all
            $_SERVER['REQUEST_METHOD'] = 'POST';

            // Create request body that will match all products
            $requestBody = json_encode([
                'keyword' => '',  // Empty to get structure
                'page' => ['limit' => 100]
            ]);

            // Read and parse the API file to extract $mockProducts array
            $apiFile = __DIR__ . '/../../api/v1/products/search.php';
            $content = file_get_contents($apiFile);

            // Extract the mockProducts array using regex
            if (preg_match('/\$mockProducts\s*=\s*\[(.*?)\];\s*\/\/ =+\s*\/\/ SEARCH LOGIC/s', $content, $matches)) {
                // Parse the PHP array - use eval carefully (this is internal code only)
                $arrayCode = '$mockProducts = [' . $matches[1] . '];';
                eval ($arrayCode);
                return $mockProducts ?? [];
            }

            // Fallback: Make HTTP request to the API
            $apiUrl = 'http://localhost/autobot/api/v1/products/search.php';
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['keyword' => 'ทอง', 'page' => ['limit' => 100]]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            if ($response) {
                $data = json_decode($response, true);
                if (!empty($data['data'])) {
                    return $data['data'];
                }
            }

            // Ultimate fallback - return basic products
            return self::getBasicMockProducts();

        } catch (Exception $e) {
            error_log("[ProductSearchService] getMockProducts error: " . $e->getMessage());
            return self::getBasicMockProducts();
        } finally {
            $_SERVER['REQUEST_METHOD'] = $originalMethod;
        }
    }

    /**
     * Basic fallback mock products (subset)
     * IMPORTANT: Must match ref_ids from api/v1/products/search.php
     * 
     * Updated 2026-02-02: Added amulet (พระเลี่ยมทอง) products
     */
    private static function getBasicMockProducts(): array
    {
        return [
            // Watches
            ['ref_id' => 'P-2026-000001', 'product_code' => 'ROL-DAY-001', 'title' => 'Rolex Day-Date 36mm Yellow Gold', 'brand' => 'Rolex', 'category' => 'watch', 'price' => 850000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1547996160-81dfa63595aa?w=400'],
            ['ref_id' => 'P-2026-000002', 'product_code' => 'ROL-SUB-002', 'title' => 'Rolex Submariner Date Black Dial', 'brand' => 'Rolex', 'category' => 'watch', 'price' => 420000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1523170335258-f5ed11844a49?w=400'],
            ['ref_id' => 'P-2026-000003', 'product_code' => 'TAG-CAR-001', 'title' => 'Tag Heuer Carrera Chronograph', 'brand' => 'Tag Heuer', 'category' => 'watch', 'price' => 89000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1524592094714-0f0654e20314?w=400'],
            ['ref_id' => 'P-2026-000004', 'product_code' => 'OMG-SEA-001', 'title' => 'Omega Seamaster Planet Ocean 600M', 'brand' => 'Omega', 'category' => 'watch', 'price' => 195000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1548171915-e79a380a2a4b?w=400'],
            // Rings
            ['ref_id' => 'P-2026-000010', 'product_code' => 'DIA-RNG-001', 'title' => 'แหวนเพชรแท้ 1 กะรัต ทองขาว', 'brand' => 'เพชรวิบวับ', 'category' => 'ring', 'price' => 289000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1605100804763-247f67b3557e?w=400'],
            ['ref_id' => 'P-2026-000012', 'product_code' => 'GLD-RNG-001', 'title' => 'แหวนทองคำ 96.5% ลายดอกไม้', 'brand' => 'Thai Gold', 'category' => 'ring', 'price' => 9500, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1515377905703-c4788e51af15?w=400'],
            // Necklaces
            ['ref_id' => 'P-2026-000021', 'product_code' => 'GLD-NCK-001', 'title' => 'สร้อยคอทองคำ 96.5% ลายสี่เสา', 'brand' => 'Thai Gold', 'category' => 'necklace', 'price' => 68000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=400'],
            ['ref_id' => 'P-2026-000022', 'product_code' => 'GLD-NCK-002', 'title' => 'สร้อยคอทองคำ ลายโซ่ 1 บาท', 'brand' => 'Thai Gold', 'category' => 'necklace', 'price' => 34000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1611085583191-a3b181a88401?w=400'],
            // Bracelets
            ['ref_id' => 'P-2026-000030', 'product_code' => 'GLD-BRC-001', 'title' => 'กำไลทองคำแท้ 96.5% ลายโซ่', 'brand' => 'Thai Gold', 'category' => 'bracelet', 'price' => 68000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?w=400'],
            // Earrings
            ['ref_id' => 'P-2026-000040', 'product_code' => 'DIA-EAR-001', 'title' => 'ต่างหูเพชรแท้ ทองคำขาว', 'brand' => 'เพชรวิบวับ', 'category' => 'earring', 'price' => 125000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1535632066927-ab7c9ab60908?w=400'],
            // Jewelry Sets (ชุดเครื่องประดับ)
            ['ref_id' => 'P-2026-000050', 'product_code' => 'SET-DIA-001', 'title' => 'ชุดเครื่องประดับเพชร สร้อย+ต่างหู+แหวน', 'brand' => 'เพชรวิบวับ', 'category' => 'jewelry', 'price' => 385000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?w=400'],
            ['ref_id' => 'P-2026-000051', 'product_code' => 'SET-GLD-001', 'title' => 'ชุดทองคำแท้ 96.5% สร้อย+กำไล+แหวน', 'brand' => 'Thai Gold', 'category' => 'jewelry', 'price' => 158000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1601121141461-9d6647bca1ed?w=400'],
            ['ref_id' => 'P-2026-000052', 'product_code' => 'SET-PRL-001', 'title' => 'ชุดเครื่องประดับไข่มุก สร้อย+ต่างหู', 'brand' => 'Pearl House', 'category' => 'jewelry', 'price' => 45000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1611085583191-a3b181a88401?w=400'],
            // Bags
            ['ref_id' => 'P-2026-000100', 'product_code' => 'GUC-MAR-001', 'title' => 'GUCCI Marmont Mini Bag Black', 'brand' => 'GUCCI', 'category' => 'bag', 'price' => 45900, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=400'],
            ['ref_id' => 'P-2026-000101', 'product_code' => 'LV-SPD-001', 'title' => 'Louis Vuitton Speedy 25 Monogram', 'brand' => 'Louis Vuitton', 'category' => 'bag', 'price' => 65000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=400'],
            ['ref_id' => 'P-2026-000102', 'product_code' => 'CHA-FLP-001', 'title' => 'Chanel Classic Flap Medium Black', 'brand' => 'Chanel', 'category' => 'bag', 'price' => 285000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1591561954557-26941169b49e?w=400'],
            // Amulets (พระเลี่ยมทอง)
            ['ref_id' => 'P-2026-000200', 'product_code' => 'AMU-LP-001', 'title' => 'พระหลวงปู่ทวด เลี่ยมทองคำแท้', 'brand' => 'วัดช้างให้', 'category' => 'amulet', 'price' => 35000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1617791160505-6f00504e3519?w=400'],
            ['ref_id' => 'P-2026-000201', 'product_code' => 'AMU-SG-001', 'title' => 'พระสมเด็จ วัดระฆัง เลี่ยมทอง', 'brand' => 'วัดระฆัง', 'category' => 'amulet', 'price' => 89000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1617791160505-6f00504e3519?w=400'],
            ['ref_id' => 'P-2026-000202', 'product_code' => 'AMU-NK-001', 'title' => 'พระนางกวัก เนื้อทองคำ เลี่ยมทอง', 'brand' => 'วัดหนองแขม', 'category' => 'amulet', 'price' => 125000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1617791160505-6f00504e3519?w=400'],
            ['ref_id' => 'P-2026-000203', 'product_code' => 'AMU-JT-001', 'title' => 'พระจตุคามรามเทพ เลี่ยมทองกรอบเพชร', 'brand' => 'วัดพระมหาธาตุ', 'category' => 'amulet', 'price' => 250000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1617791160505-6f00504e3519?w=400'],
            // Gold Bars (ทองคำแท่ง)
            ['ref_id' => 'P-2026-000300', 'product_code' => 'GLD-BAR-001', 'title' => 'ทองคำแท่ง 1 บาท 96.5%', 'brand' => 'ฮั่วเซ่งเฮง', 'category' => 'gold', 'price' => 42500, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1610375461246-83df859d849d?w=400'],
            ['ref_id' => 'P-2026-000301', 'product_code' => 'GLD-BAR-002', 'title' => 'ทองคำแท่ง 2 บาท 96.5%', 'brand' => 'ฮั่วเซ่งเฮง', 'category' => 'gold', 'price' => 85000, 'availability' => 'in_stock', 'thumbnail_url' => 'https://images.unsplash.com/photo-1610375461246-83df859d849d?w=400'],
        ];
    }

    /**
     * Filter products based on search params
     */
    private static function filterProducts(array $products, array $params): array
    {
        $results = [];

        foreach ($products as $product) {
            $match = false;

            // Match by ref_ids
            if (!empty($params['ref_ids'])) {
                if (in_array($product['ref_id'], $params['ref_ids'], true)) {
                    $match = true;
                }
            }

            // Match by product_code
            if (!empty($params['product_code'])) {
                if (
                    stripos($product['product_code'], $params['product_code']) !== false ||
                    stripos($product['ref_id'], $params['product_code']) !== false
                ) {
                    $match = true;
                }
            }

            // Match by keyword - using scoring (more words matched = higher score)
            if (!empty($params['keyword'])) {
                $searchText = strtolower(
                    ($product['title'] ?? '') . ' ' .
                    ($product['brand'] ?? '') . ' ' .
                    ($product['description'] ?? '') . ' ' .
                    ($product['product_code'] ?? '')
                );
                $keywordLower = strtolower($params['keyword']);

                // Filter out common short words (stopwords)
                $stopwords = ['the', 'a', 'an', 'and', 'or', 'in', 'on', 'at', 'to', 'for', 'of', 'with'];
                $words = array_filter(explode(' ', $keywordLower), function($word) use ($stopwords) {
                    return strlen($word) >= 2 && !in_array($word, $stopwords);
                });
                
                if (!empty($words)) {
                    $matchedWords = 0;
                    $totalWords = count($words);
                    
                    foreach ($words as $word) {
                        if (strpos($searchText, $word) !== false) {
                            $matchedWords++;
                        }
                    }
                    
                    // Calculate match ratio
                    $matchRatio = $matchedWords / $totalWords;
                    
                    // Require at least 50% of words to match, OR all words if <= 2 words
                    // This prevents "black" alone matching everything with "black"
                    $minMatchRatio = ($totalWords <= 2) ? 1.0 : 0.5;
                    
                    if ($matchRatio >= $minMatchRatio) {
                        $match = true;
                        // Store score for sorting later
                        $product['_match_score'] = $matchRatio;
                        $product['_matched_words'] = $matchedWords;
                    }
                }
            }

            if ($match) {
                $results[] = $product;
            }
        }

        // Sort by match score (highest first)
        usort($results, function($a, $b) {
            $scoreA = $a['_match_score'] ?? 0;
            $scoreB = $b['_match_score'] ?? 0;
            return $scoreB <=> $scoreA;
        });

        return $results;
    }

    /**
     * Format product for chatbot response
     * 
     * @param array $product Product data
     * @return string Formatted text for LINE/Facebook
     */
    public static function formatForChat(array $product): string
    {
        $name = $product['title'] ?? $product['name'] ?? 'สินค้า';
        $code = $product['product_code'] ?? $product['sku'] ?? '-';
        $price = number_format($product['price'] ?? 0);
        $brand = $product['brand'] ?? '';
        $availability = $product['availability'] ?? 'unknown';

        $statusEmoji = match ($availability) {
            'in_stock' => '✅ มีสินค้า',
            'reserved' => '🔒 จอง/มัดจำแล้ว',
            'sold' => '❌ ขายแล้ว',
            default => '❓'
        };

        return "📦 *{$name}*\n" .
            "🏷️ รหัส: {$code}\n" .
            ($brand ? "🏢 แบรนด์: {$brand}\n" : "") .
            "💰 ราคา: ฿{$price}\n" .
            "{$statusEmoji}\n\n" .
            "💡 พิมพ์ 'สนใจ' หรือ 'จอง' เพื่อทำรายการได้เลยค่ะ 😊";
    }

    /**
     * Format multiple products for chatbot
     * 
     * @param array $products Array of products
     * @param int $max Maximum products to show
     * @return string Formatted text
     */
    public static function formatMultipleForChat(array $products, int $max = 3): string
    {
        if (empty($products)) {
            return "❌ ไม่พบสินค้าที่ตรงกับคำค้นหาค่ะ";
        }

        $count = count($products);

        // ✅ ถ้าเจอแค่ 1 รายการ → ใช้ format เดี่ยว
        if ($count === 1) {
            return self::formatForChat($products[0]);
        }

        $showing = min($count, $max);

        $lines = ["🔍 พบสินค้า {$count} รายการค่ะ\n"];

        for ($i = 0; $i < $showing; $i++) {
            $num = $i + 1;
            $product = $products[$i];
            $name = $product['title'] ?? $product['name'] ?? 'สินค้า';
            $code = $product['product_code'] ?? $product['sku'] ?? '-';
            $price = number_format($product['price'] ?? 0);

            $lines[] = "{$num}️⃣ {$name}";
            $lines[] = "   🏷️ {$code} | 💰 ฿{$price}";
            if ($i < $showing - 1) {
                $lines[] = "";
            }
        }

        if ($count > $max) {
            $lines[] = "\n📝 และอีก " . ($count - $max) . " รายการ";
        }

        // Add checkout prompt - ถ้ามีหลายรายการให้เลือก
        $lines[] = "\n💡 สนใจตัวไหน พิมพ์เลข (1/2/3) หรือรหัสสินค้าได้เลยค่ะ 😊";

        return implode("\n", $lines);
    }
}
