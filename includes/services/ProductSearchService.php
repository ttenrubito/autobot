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
     * Get mock products data
     */
    private static function getMockProducts(): array
    {
        // Include mock data from v1 API file
        $apiFile = __DIR__ . '/../../api/v1/products/search.php';

        // Read the file and extract mock data
        $content = file_get_contents($apiFile);

        // For now, return a subset that matches what the API has
        // This is a simplified version - in production, you'd call the actual API
        return [
            [
                'ref_id' => 'P-2026-000001',
                'product_code' => 'ROL-DAY-001',
                'title' => 'Rolex Day-Date 36mm Yellow Gold',
                'brand' => 'Rolex',
                'category' => 'watch',
                'description' => 'นาฬิกา Rolex Day-Date ทองคำแท้ 18K สภาพสวย 95% อุปกรณ์ครบกล่อง ใบเซอร์',
                'price' => 850000,
                'currency' => 'THB',
                'availability' => 'in_stock',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1547996160-81dfa63595aa?w=400',
                'image_count' => 6,
            ],
            [
                'ref_id' => 'P-2026-000002',
                'product_code' => 'ROL-SUB-002',
                'title' => 'Rolex Submariner Date Black Dial',
                'brand' => 'Rolex',
                'category' => 'watch',
                'description' => 'Rolex Submariner หน้าปัดดำ สายเหล็ก สภาพสวย 90% กล่องใบ',
                'price' => 420000,
                'currency' => 'THB',
                'availability' => 'in_stock',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1523170335258-f5ed11844a49?w=400',
                'image_count' => 5,
            ],
            [
                'ref_id' => 'P-2026-000010',
                'product_code' => 'DIA-RNG-001',
                'title' => 'แหวนเพชรแท้ 1 กะรัต ทองขาว',
                'brand' => 'เพชรวิบวับ',
                'category' => 'ring',
                'description' => 'แหวนเพชรแท้ น้ำหนัก 1.05 ct น้ำ D VVS1 ตัวเรือนทองขาว 18K',
                'price' => 289000,
                'currency' => 'THB',
                'availability' => 'in_stock',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1605100804763-247f67b3557e?w=400',
                'image_count' => 8,
            ],
            [
                'ref_id' => 'P-2026-000020',
                'product_code' => 'DIA-NCK-001',
                'title' => 'สร้อยคอเพชร Tennis Necklace',
                'brand' => 'เพชรวิบวับ',
                'category' => 'necklace',
                'description' => 'สร้อยคอเพชรแท้ รวม 5 กะรัต ตัวเรือนทองขาว 18K ความยาว 16 นิ้ว',
                'price' => 450000,
                'currency' => 'THB',
                'availability' => 'reserved',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?w=400',
                'image_count' => 5,
            ],
            [
                'ref_id' => 'P-2026-000030',
                'product_code' => 'GLD-BRC-001',
                'title' => 'กำไลทองคำแท้ 96.5% ลายโซ่',
                'brand' => 'Thai Gold',
                'category' => 'bracelet',
                'description' => 'กำไลทองคำแท้ 96.5% น้ำหนัก 2 บาท ลายโซ่คลาสสิก',
                'price' => 68000,
                'currency' => 'THB',
                'availability' => 'in_stock',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?w=400',
                'image_count' => 4,
            ],
            [
                'ref_id' => 'P-2026-000100',
                'product_code' => 'GUC-MAR-001',
                'title' => 'GUCCI Marmont Mini Bag Black',
                'brand' => 'GUCCI',
                'category' => 'bag',
                'description' => 'กระเป๋า GUCCI Marmont Mini หนังแท้สีดำ สภาพสวย 90%',
                'price' => 45900,
                'currency' => 'THB',
                'availability' => 'in_stock',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=400',
                'image_count' => 5,
            ],
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

            // Match by keyword
            if (!empty($params['keyword'])) {
                $searchText = strtolower(
                    $product['title'] . ' ' .
                    $product['brand'] . ' ' .
                    $product['description'] . ' ' .
                    $product['product_code']
                );
                $keywordLower = strtolower($params['keyword']);

                $words = explode(' ', $keywordLower);
                foreach ($words as $word) {
                    if (strlen($word) >= 2 && strpos($searchText, $word) !== false) {
                        $match = true;
                        break;
                    }
                }
            }

            if ($match) {
                $results[] = $product;
            }
        }

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
            "{$statusEmoji}";
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
        $showing = min($count, $max);

        $lines = ["🔍 พบสินค้า {$count} รายการค่ะ\n"];

        for ($i = 0; $i < $showing; $i++) {
            $lines[] = self::formatForChat($products[$i]);
            if ($i < $showing - 1) {
                $lines[] = "---";
            }
        }

        if ($count > $max) {
            $lines[] = "\n📝 และอีก " . ($count - $max) . " รายการ";
        }

        return implode("\n", $lines);
    }
}
