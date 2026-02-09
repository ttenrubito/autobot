<?php
/**
 * StoreConfigService - Multi-tenant store configuration
 * 
 * Provides:
 * - Feature toggles per channel (pawn, repair, trade-in, savings)
 * - Business rules (rates, policies)
 * - Product category keywords for intent matching
 * 
 * @version 1.0
 * @date 2026-02-08
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../Logger.php';

class StoreConfigService
{
    protected $db;
    protected $cache = [];

    // Default features - can be overridden per channel
    const DEFAULT_FEATURES = [
        'pawn' => true,
        'repair' => true,
        'trade_in' => true,
        'savings' => true,
        'installment' => true,
        'deposit' => true,
    ];

    // Default business rules
    const DEFAULT_BUSINESS_RULES = [
        'trade_in' => [
            'exchange_rate' => 0.10,   // 10% deduction for exchange
            'return_rate' => 0.15,     // 15% deduction for return
            'special_brands' => [
                'Rolex' => 0.35,       // 35% deduction for Rolex
            ],
        ],
        'pawn' => [
            'interest_rate_monthly' => 0.02,  // 2% per month
            'loan_to_value' => 0.65,          // 65% of appraisal value
        ],
        'installment' => [
            'service_fee_rate' => 0.03,       // 3% service fee
            'default_periods' => 3,
        ],
    ];

    // Default product categories for intent matching
    const DEFAULT_CATEGORIES = [
        'luxury_resale' => 'นาฬิกา|แหวน|สร้อย|กำไล|กำไร|จี้|ต่างหู|เพชร|ทอง|กระเป๋า',
        'jewelry' => 'แหวน|สร้อย|กำไล|จี้|ต่างหู|เพชร|ทอง|พลอย|ไพลิน|ทับทิม',
        'watches' => 'นาฬิกา|watch|rolex|omega|patek|audemars',
        'amulets' => 'พระ|เลี่ยม|ตลับ|เหรียญ|พระเครื่อง',
        'general' => 'สินค้า|ของ|item|product',
    ];

    public function __construct()
    {
        $this->db = \Database::getInstance();
    }

    /**
     * Get store config for a channel
     */
    public function getConfig(int $channelId): array
    {
        // Check cache
        if (isset($this->cache[$channelId])) {
            return $this->cache[$channelId];
        }

        // Try to load from database
        $config = $this->loadFromDatabase($channelId);

        if (!$config) {
            // Use defaults
            $config = [
                'store_type' => 'luxury_resale',
                'features' => self::DEFAULT_FEATURES,
                'business_rules' => self::DEFAULT_BUSINESS_RULES,
                'category_keywords' => self::DEFAULT_CATEGORIES['luxury_resale'],
            ];
        }

        // Cache it
        $this->cache[$channelId] = $config;

        return $config;
    }

    /**
     * Check if a feature is enabled for a channel
     */
    public function isFeatureEnabled(int $channelId, string $feature): bool
    {
        $config = $this->getConfig($channelId);
        return !empty($config['features'][$feature]);
    }

    /**
     * Get business rule value
     */
    public function getBusinessRule(int $channelId, string $category, string $key, $default = null)
    {
        $config = $this->getConfig($channelId);
        return $config['business_rules'][$category][$key] ?? $default;
    }

    /**
     * Get trade-in rates for a channel
     */
    public function getTradeInRates(int $channelId): array
    {
        $config = $this->getConfig($channelId);
        return $config['business_rules']['trade_in'] ?? self::DEFAULT_BUSINESS_RULES['trade_in'];
    }

    /**
     * Get category keywords for product search
     */
    public function getCategoryKeywords(int $channelId): string
    {
        $config = $this->getConfig($channelId);
        return $config['category_keywords'] ?? self::DEFAULT_CATEGORIES['luxury_resale'];
    }

    /**
     * Load config from database (customer_channels.config JSON)
     * Store settings are stored at config.store_settings
     */
    protected function loadFromDatabase(int $channelId): ?array
    {
        try {
            $row = $this->db->queryOne(
                "SELECT config FROM customer_channels WHERE id = ? LIMIT 1",
                [$channelId]
            );

            if (!$row || empty($row['config'])) {
                return null;
            }

            $config = json_decode($row['config'], true);
            $storeSettings = $config['store_settings'] ?? null;

            if (!$storeSettings) {
                return null;
            }

            // Merge with defaults
            $features = $storeSettings['features'] ?? [];
            $businessRules = $storeSettings['business_rules'] ?? [];

            return [
                'store_type' => $storeSettings['store_type'] ?? 'luxury_resale',
                'features' => array_merge(self::DEFAULT_FEATURES, $features),
                'business_rules' => array_replace_recursive(self::DEFAULT_BUSINESS_RULES, $businessRules),
                'category_keywords' => $storeSettings['category_keywords'] ?? self::DEFAULT_CATEGORIES[$storeSettings['store_type'] ?? 'luxury_resale'] ?? self::DEFAULT_CATEGORIES['luxury_resale'],
            ];
        } catch (\Exception $e) {
            \Logger::warning('[StoreConfigService] Failed to load config', [
                'channel_id' => $channelId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Clear cache for a channel (after update)
     */
    public function clearCache(int $channelId): void
    {
        unset($this->cache[$channelId]);
    }

    /**
     * Get all enabled features as array
     */
    public function getEnabledFeatures(int $channelId): array
    {
        $config = $this->getConfig($channelId);
        $features = $config['features'] ?? [];

        return array_keys(array_filter($features)); // Return only enabled ones
    }
}
