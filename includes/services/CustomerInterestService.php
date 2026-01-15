<?php
/**
 * CustomerInterestService
 * 
 * Track and manage customer product interests for:
 * - Marketing push notifications
 * - Personalized recommendations
 * - Sales analytics
 * 
 * @version 1.0
 * @date 2026-01-15
 */

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Logger.php';

class CustomerInterestService
{
    private $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Track customer interest in a product
     * Called when customer asks about a product, sends image, or mentions product code
     * 
     * @param int $customerProfileId customer_profiles.id
     * @param array $productData Product information
     * @param array $options Additional options (channel_id, case_id, source, etc.)
     * @return int|null The interest record ID
     */
    public function trackProductInterest(int $customerProfileId, array $productData, array $options = []): ?int
    {
        if (empty($productData['product_ref_id']) && empty($productData['product_name'])) {
            return null;
        }
        
        try {
            $productRefId = $productData['product_ref_id'] ?? null;
            $productName = $productData['product_name'] ?? null;
            $productCategory = $productData['product_category'] ?? $this->detectCategory($productName, $productRefId);
            $productPrice = $productData['product_price'] ?? null;
            $productImageUrl = $productData['product_image_url'] ?? null;
            
            $channelId = $options['channel_id'] ?? null;
            $caseId = $options['case_id'] ?? null;
            $tenantId = $options['tenant_id'] ?? 'default';
            $interestType = $options['interest_type'] ?? 'inquired';
            $source = $options['source'] ?? 'chat';
            $messageText = $options['message_text'] ?? null;
            $metadata = $options['metadata'] ?? null;
            
            // Calculate interest score based on interest_type
            $interestScore = $this->calculateInterestScore($interestType, $metadata);
            
            // Use UPSERT to handle duplicate product interests
            $sql = "
                INSERT INTO customer_product_interests 
                (tenant_id, customer_profile_id, channel_id, case_id, product_ref_id, product_name, 
                 product_category, product_price, product_image_url, interest_type, interest_score,
                 source, message_text, metadata, first_seen_at, last_seen_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    product_name = COALESCE(VALUES(product_name), product_name),
                    product_category = COALESCE(VALUES(product_category), product_category),
                    product_price = COALESCE(VALUES(product_price), product_price),
                    product_image_url = COALESCE(VALUES(product_image_url), product_image_url),
                    interest_type = CASE 
                        WHEN VALUES(interest_type) IN ('added_to_cart', 'purchased') THEN VALUES(interest_type)
                        WHEN interest_type IN ('added_to_cart', 'purchased') THEN interest_type
                        ELSE VALUES(interest_type)
                    END,
                    interest_score = GREATEST(interest_score, VALUES(interest_score)),
                    case_id = COALESCE(VALUES(case_id), case_id),
                    last_seen_at = NOW(),
                    updated_at = NOW()
            ";
            
            $metadataJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
            
            $this->db->execute($sql, [
                $tenantId,
                $customerProfileId,
                $channelId,
                $caseId,
                $productRefId,
                $productName,
                $productCategory,
                $productPrice,
                $productImageUrl,
                $interestType,
                $interestScore,
                $source,
                $messageText ? mb_substr($messageText, 0, 500) : null,
                $metadataJson
            ]);
            
            // Get the ID
            $result = $this->db->queryOne(
                "SELECT id FROM customer_product_interests 
                 WHERE customer_profile_id = ? AND product_ref_id = ? 
                 ORDER BY updated_at DESC LIMIT 1",
                [$customerProfileId, $productRefId]
            );
            
            $interestId = $result ? (int)$result['id'] : null;
            
            Logger::info('[CUSTOMER_INTEREST] Product interest tracked', [
                'interest_id' => $interestId,
                'customer_profile_id' => $customerProfileId,
                'product_ref_id' => $productRefId,
                'product_name' => $productName,
                'interest_type' => $interestType,
                'interest_score' => $interestScore,
            ]);
            
            // Update customer profile preferred categories
            if ($productCategory) {
                $this->updatePreferredCategories($customerProfileId, $productCategory);
            }
            
            return $interestId;
            
        } catch (Throwable $e) {
            Logger::error('[CUSTOMER_INTEREST] Error tracking product interest: ' . $e->getMessage(), [
                'customer_profile_id' => $customerProfileId,
                'product_data' => $productData,
            ]);
            return null;
        }
    }
    
    /**
     * Track multiple products at once (e.g., from image search results)
     */
    public function trackMultipleInterests(int $customerProfileId, array $products, array $options = []): array
    {
        $results = [];
        foreach ($products as $product) {
            $id = $this->trackProductInterest($customerProfileId, $product, $options);
            if ($id) {
                $results[] = $id;
            }
        }
        return $results;
    }
    
    /**
     * Get all interests for a customer
     */
    public function getCustomerInterests(int $customerProfileId, array $filters = []): array
    {
        $sql = "SELECT * FROM customer_product_interests WHERE customer_profile_id = ?";
        $params = [$customerProfileId];
        
        if (!empty($filters['interest_type'])) {
            $sql .= " AND interest_type = ?";
            $params[] = $filters['interest_type'];
        }
        
        if (!empty($filters['category'])) {
            $sql .= " AND product_category = ?";
            $params[] = $filters['category'];
        }
        
        if (!empty($filters['min_score'])) {
            $sql .= " AND interest_score >= ?";
            $params[] = (int)$filters['min_score'];
        }
        
        $sql .= " ORDER BY last_seen_at DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        return $this->db->query($sql, $params);
    }
    
    /**
     * Get products that customers are interested in for push notification
     * Returns customers who haven't been notified recently
     */
    public function getInterestsForPushNotification(array $productRefIds, int $daysSinceLastNotify = 7): array
    {
        if (empty($productRefIds)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($productRefIds), '?'));
        
        $sql = "
            SELECT cpi.*, cp.platform, cp.platform_user_id, cp.display_name
            FROM customer_product_interests cpi
            JOIN customer_profiles cp ON cp.id = cpi.customer_profile_id
            WHERE cpi.product_ref_id IN ({$placeholders})
            AND cpi.interest_type NOT IN ('purchased')
            AND (cpi.notified_at IS NULL OR cpi.notified_at < DATE_SUB(NOW(), INTERVAL ? DAY))
            ORDER BY cpi.interest_score DESC, cpi.last_seen_at DESC
        ";
        
        $params = array_merge($productRefIds, [$daysSinceLastNotify]);
        
        return $this->db->query($sql, $params);
    }
    
    /**
     * Mark interest as notified
     */
    public function markAsNotified(int $interestId): bool
    {
        try {
            $this->db->execute(
                "UPDATE customer_product_interests SET notified_at = NOW() WHERE id = ?",
                [$interestId]
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
    
    /**
     * Calculate interest score based on type and context
     */
    private function calculateInterestScore(string $interestType, ?array $metadata): int
    {
        $baseScores = [
            'viewed' => 1,
            'inquired' => 3,
            'price_check' => 4,
            'compared' => 5,
            'saved' => 6,
            'added_to_cart' => 8,
            'purchased' => 10,
        ];
        
        $score = $baseScores[$interestType] ?? 2;
        
        // Boost score based on context
        if ($metadata) {
            // Multiple inquiries about same product
            if (!empty($metadata['repeat_inquiry'])) {
                $score += 2;
            }
            // Sent image of product
            if (!empty($metadata['has_image'])) {
                $score += 1;
            }
            // Asked for price specifically
            if (!empty($metadata['price_inquiry'])) {
                $score += 1;
            }
        }
        
        return min($score, 10); // Cap at 10
    }
    
    /**
     * Detect product category from name or code
     */
    private function detectCategory(?string $productName, ?string $productRefId): ?string
    {
        $text = strtolower(($productName ?? '') . ' ' . ($productRefId ?? ''));
        
        $categoryPatterns = [
            'watch' => ['นาฬิกา', 'watch', 'wt-', 'นก-'],
            'ring' => ['แหวน', 'ring', 'rg-', 'แว-'],
            'necklace' => ['สร้อยคอ', 'necklace', 'nk-', 'สค-'],
            'bracelet' => ['กำไล', 'สร้อยข้อมือ', 'bracelet', 'br-', 'กล-'],
            'earring' => ['ต่างหู', 'earring', 'er-', 'ตห-'],
            'pendant' => ['จี้', 'pendant', 'pd-'],
            'brooch' => ['เข็มกลัด', 'brooch', 'bc-'],
            'amulet' => ['พระ', 'เครื่องราง', 'amulet'],
        ];
        
        foreach ($categoryPatterns as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($text, $pattern) !== false) {
                    return $category;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Update customer's preferred categories based on interests
     */
    private function updatePreferredCategories(int $customerProfileId, string $newCategory): void
    {
        try {
            // Get current preferred categories
            $customer = $this->db->queryOne(
                "SELECT preferred_categories FROM customer_profiles WHERE id = ?",
                [$customerProfileId]
            );
            
            $categories = [];
            if ($customer && !empty($customer['preferred_categories'])) {
                $categories = json_decode($customer['preferred_categories'], true) ?? [];
            }
            
            // Add new category with count
            if (!isset($categories[$newCategory])) {
                $categories[$newCategory] = 0;
            }
            $categories[$newCategory]++;
            
            // Sort by count descending and keep top 5
            arsort($categories);
            $categories = array_slice($categories, 0, 5, true);
            
            $this->db->execute(
                "UPDATE customer_profiles SET preferred_categories = ?, updated_at = NOW() WHERE id = ?",
                [json_encode($categories, JSON_UNESCAPED_UNICODE), $customerProfileId]
            );
            
        } catch (Throwable $e) {
            Logger::warning('[CUSTOMER_INTEREST] Failed to update preferred categories: ' . $e->getMessage());
        }
    }
    
    /**
     * Get customer profile by platform user ID
     */
    public function getCustomerProfileByPlatformUserId(string $platform, string $platformUserId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM customer_profiles WHERE platform = ? AND platform_user_id = ? LIMIT 1",
            [$platform, $platformUserId]
        );
    }
    
    /**
     * Get customer interests summary for display in case view
     */
    public function getInterestsSummaryForCase(int $caseId): array
    {
        return $this->db->query(
            "SELECT product_ref_id, product_name, product_category, product_price, 
                    interest_type, interest_score, first_seen_at, last_seen_at
             FROM customer_product_interests 
             WHERE case_id = ?
             ORDER BY interest_score DESC, last_seen_at DESC
             LIMIT 10",
            [$caseId]
        );
    }
    
    /**
     * Get customer interests summary by customer profile
     */
    public function getInterestsSummaryForCustomer(int $customerProfileId, int $limit = 10): array
    {
        return $this->db->query(
            "SELECT product_ref_id, product_name, product_category, product_price, 
                    interest_type, interest_score, first_seen_at, last_seen_at
             FROM customer_product_interests 
             WHERE customer_profile_id = ?
             ORDER BY interest_score DESC, last_seen_at DESC
             LIMIT ?",
            [$customerProfileId, $limit]
        );
    }
}
