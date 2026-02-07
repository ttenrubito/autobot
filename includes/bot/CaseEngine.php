<?php
/**
 * Case Engine - State Machine for Chatbot Commerce
 * 
 * Manages the 4 use cases:
 * 1. Product Inquiry
 * 2. Payment Full
 * 3. Payment Installment
 * 4. Payment Savings
 * 
 * @version 2.0
 * @date 2026-01-06
 */

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Logger.php';

class CaseEngine
{
    private $db;
    private $config;
    private $context;

    // Case type constants
    const CASE_PRODUCT_INQUIRY = 'product_inquiry';
    const CASE_PAYMENT_FULL = 'payment_full';
    const CASE_PAYMENT_INSTALLMENT = 'payment_installment';
    const CASE_PAYMENT_SAVINGS = 'payment_savings';
    const CASE_PAWN = 'pawn';           // ✅ รับฝาก/ฝากขาย (บริการเดียวกัน)
    const CASE_REPAIR = 'repair';       // ✅ Added for repair/ซ่อม flow

    // Case status constants
    const STATUS_OPEN = 'open';
    const STATUS_PENDING_CUSTOMER = 'pending_customer';
    const STATUS_PENDING_ADMIN = 'pending_admin';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_CANCELLED = 'cancelled';

    public function __construct(array $config, array $context)
    {
        $this->db = Database::getInstance();
        $this->config = $config;
        $this->context = $context;
    }

    /**
     * Detect case type from intent
     */
    public function detectCaseType(?string $intent, ?string $actionType = null): ?string
    {
        if (!$intent) {
            return null;
        }

        // ✅ EXCLUDE GREETING INTENTS - Don't create case for greetings
        $greetingIntents = [
            'greeting',
            'welcome',
            'hello',
            'hi',
            'thanks',
            'thank_you',
            'goodbye',
            'bye',
            'general_greeting',
            'chitchat',
            'small_talk',
            'unknown',
            'unclear',
            'fallback'
        ];
        if (in_array($intent, $greetingIntents)) {
            Logger::debug('[CaseEngine] Skipping case creation for greeting/non-actionable intent', [
                'intent' => $intent
            ]);
            return null;
        }

        $caseFlows = $this->config['case_flows'] ?? [];

        // Check each case flow for matching intent
        foreach ($caseFlows as $caseType => $flow) {
            $triggerIntents = $flow['trigger_intents'] ?? [];
            if (in_array($intent, $triggerIntents)) {
                return $caseType;
            }
        }

        // Fallback mapping
        $intentToCaseType = [
            'product_lookup_by_code' => self::CASE_PRODUCT_INQUIRY,
            'product_lookup_by_image' => self::CASE_PRODUCT_INQUIRY,
            'product_availability' => self::CASE_PRODUCT_INQUIRY,
            'price_inquiry' => self::CASE_PRODUCT_INQUIRY,
            'price_negotiation' => self::CASE_PRODUCT_INQUIRY, // ✅ NEW: ลดราคาได้ไหม
            'payment_slip_verify' => self::CASE_PAYMENT_FULL,
            'change_payment_method' => self::CASE_PAYMENT_FULL, // ✅ NEW: เปลี่ยนวิธีชำระ
            'installment_flow' => self::CASE_PAYMENT_INSTALLMENT,
            'installment_new' => self::CASE_PAYMENT_INSTALLMENT,
            'installment_pay' => self::CASE_PAYMENT_INSTALLMENT,
            'installment_extend' => self::CASE_PAYMENT_INSTALLMENT,
            'installment_inquiry' => self::CASE_PAYMENT_INSTALLMENT,
            'savings_new' => self::CASE_PAYMENT_SAVINGS,
            'savings_deposit' => self::CASE_PAYMENT_SAVINGS,
            'savings_inquiry' => self::CASE_PAYMENT_SAVINGS,
            'pawn_new' => self::CASE_PAWN, // ✅ จำนำ/รับฝาก/ฝากขาย
            'pawn_inquiry' => self::CASE_PAWN, // ✅ ถามเรื่องรับฝาก/ฝากขาย
            'pawn_pay_interest' => self::CASE_PAWN, // ✅ ต่อดอก
            'pawn_redeem' => self::CASE_PAWN, // ✅ ไถ่ถอน
            'repair_new' => self::CASE_REPAIR, // ✅ ซ่อม
            'repair_inquiry' => self::CASE_REPAIR, // ✅ NEW: เช็คสถานะซ่อม
        ];

        return $intentToCaseType[$intent] ?? null;
    }

    /**
     * Get or create a case for the current conversation
     */
    public function getOrCreateCase(string $caseType, array $slots = []): ?array
    {
        $channelId = $this->context['channel']['id'] ?? null;
        $externalUserId = $this->context['external_user_id'] ??
            ($this->context['user']['external_user_id'] ?? null);
        $platform = $this->context['platform'] ??
            ($this->context['channel']['platform'] ?? 'unknown');
        $sessionId = $this->context['session_id'] ?? null;

        if (!$channelId || !$externalUserId) {
            Logger::error('[CaseEngine] Missing channel_id or external_user_id');
            return null;
        }

        // ✅ HYBRID DAILY CASE LOGIC:
        // Only find existing case if created TODAY (same date)
        // If no case today → create new case (even if old cases are still open)
        // This allows daily monitoring while keeping case history
        $existingCase = $this->db->queryOne(
            "SELECT * FROM cases 
             WHERE channel_id = ? AND external_user_id = ? AND case_type = ? 
             AND status NOT IN ('resolved', 'cancelled')
             AND DATE(created_at) = CURDATE()
             ORDER BY created_at DESC LIMIT 1",
            [$channelId, $externalUserId, $caseType]
        );

        // Log for debugging
        Logger::debug('[CaseEngine] Daily case lookup', [
            'channel_id' => $channelId,
            'external_user_id' => $externalUserId,
            'case_type' => $caseType,
            'found_today' => $existingCase ? true : false,
            'case_id' => $existingCase['id'] ?? null
        ]);

        if ($existingCase) {
            Logger::info('[CaseEngine] Found existing case', [
                'case_id' => $existingCase['id'],
                'case_no' => $existingCase['case_no'],
                'case_type' => $caseType
            ]);

            // Update slots if provided
            if (!empty($slots)) {
                $this->updateCaseSlots($existingCase['id'], $slots);
                $existingCase['slots'] = array_merge(
                    json_decode($existingCase['slots'] ?? '{}', true) ?: [],
                    $slots
                );
            }

            return $existingCase;
        }

        // Create new case
        return $this->createCase($caseType, $slots);
    }

    /**
     * Create a new case
     */
    public function createCase(string $caseType, array $slots = []): ?array
    {
        $channelId = $this->context['channel']['id'] ?? null;
        $externalUserId = $this->context['external_user_id'] ??
            ($this->context['user']['external_user_id'] ?? null);
        $platform = $this->context['platform'] ??
            ($this->context['channel']['platform'] ?? 'unknown');
        $sessionId = $this->context['session_id'] ?? null;

        // Generate case number
        $caseNo = 'CASE-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        // Determine subject based on case type
        $subjectMap = [
            self::CASE_PRODUCT_INQUIRY => 'สอบถามสินค้า',
            self::CASE_PAYMENT_FULL => 'ชำระเงินเต็ม',
            self::CASE_PAYMENT_INSTALLMENT => 'ชำระเงินผ่อน',
            self::CASE_PAYMENT_SAVINGS => 'ออมสินค้า',
            self::CASE_PAWN => 'ขอประเมินรับฝาก',    // ✅ รับฝาก/ฝากขาย (บริการเดียวกัน)
            self::CASE_REPAIR => 'ขอประเมินซ่อม',   // ✅ Added
        ];
        $subject = $subjectMap[$caseType] ?? 'ติดต่อทั่วไป';

        try {
            // ✅ Build products_interested from slots - include all product data
            $productsInterested = null;
            if (!empty($slots['product_ref_id']) || !empty($slots['product_name']) || !empty($slots['product_code'])) {
                $productsInterested = json_encode([
                    [
                        'product_code' => $slots['product_code'] ?? null,           // Customer-facing code (GLD-BRC-001)
                        'product_ref_id' => $slots['product_ref_id'] ?? null,       // Internal ref ID (P-2026-000030)
                        'product_name' => $slots['product_name'] ?? null,           // Product title
                        'product_price' => $slots['product_price'] ?? null,         // Price
                        'product_image_url' => $slots['product_image_url'] ?? ($slots['thumbnail_url'] ?? null), // Image
                        'product_brand' => $slots['product_brand'] ?? null,         // Brand
                        'interest_type' => 'inquired',
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]
                ], JSON_UNESCAPED_UNICODE);
            }

            // ✅ Get customer_id from customer_profiles based on external_user_id + platform
            $customerId = null;
            if ($externalUserId && $platform) {
                $customerProfile = $this->db->queryOne(
                    "SELECT id FROM customer_profiles WHERE platform_user_id = ? AND platform = ? LIMIT 1",
                    [$externalUserId, $platform]
                );
                $customerId = $customerProfile['id'] ?? null;
            }

            // ✅ Get user_id (shop owner) from channel
            $userId = null;
            if ($channelId) {
                $channel = $this->db->queryOne(
                    "SELECT user_id FROM customer_channels WHERE id = ? LIMIT 1",
                    [$channelId]
                );
                $userId = $channel['user_id'] ?? null;
            }

            $this->db->execute(
                "INSERT INTO cases (case_no, tenant_id, case_type, channel_id, external_user_id, 
                 platform, session_id, subject, slots, products_interested, product_ref_id, customer_id, user_id, status, priority, created_at, updated_at)
                 VALUES (?, 'default', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 'normal', NOW(), NOW())",
                [
                    $caseNo,
                    $caseType,
                    $channelId,
                    $externalUserId,
                    $platform,
                    $sessionId,
                    $subject,
                    json_encode($slots),
                    $productsInterested,
                    $slots['product_ref_id'] ?? ($slots['product_code'] ?? null),
                    $customerId,
                    $userId
                ]
            );

            $newId = $this->db->lastInsertId();

            // Log activity
            $this->logCaseActivity($newId, 'created', null, [
                'case_type' => $caseType,
                'status' => 'open'
            ], 'bot');

            Logger::info('[CaseEngine] Case created', [
                'case_id' => $newId,
                'case_no' => $caseNo,
                'case_type' => $caseType
            ]);

            // Update chat_session with active case
            if ($sessionId) {
                $this->db->execute(
                    "UPDATE chat_sessions SET active_case_id = ?, active_case_type = ?, updated_at = NOW() 
                     WHERE id = ?",
                    [$newId, $caseType, $sessionId]
                );
            }

            return [
                'id' => $newId,
                'case_no' => $caseNo,
                'case_type' => $caseType,
                'status' => 'open',
                'slots' => $slots
            ];

        } catch (Exception $e) {
            Logger::error('[CaseEngine] Failed to create case: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update case slots - Appends products to history instead of overwriting
     */
    public function updateCaseSlots(int $caseId, array $newSlots): bool
    {
        try {
            $case = $this->db->queryOne("SELECT slots, products_interested FROM cases WHERE id = ?", [$caseId]);
            if (!$case) {
                return false;
            }

            $existingSlots = json_decode($case['slots'] ?? '{}', true) ?: [];
            
            // ✅ Handle products_history - append new product instead of overwrite
            $productsHistory = $existingSlots['products_history'] ?? [];
            
            if (!empty($newSlots['product_ref_id']) || !empty($newSlots['product_name']) || !empty($newSlots['product_code'])) {
                // Check if this product already exists in history (avoid duplicates)
                $productRefId = $newSlots['product_ref_id'] ?? null;
                $productCode = $newSlots['product_code'] ?? null;
                $isDuplicate = false;
                
                foreach ($productsHistory as $existing) {
                    if (($productRefId && $existing['product_ref_id'] === $productRefId) ||
                        ($productCode && $existing['product_code'] === $productCode)) {
                        $isDuplicate = true;
                        break;
                    }
                }
                
                if (!$isDuplicate) {
                    $productsHistory[] = [
                        'idx' => count($productsHistory) + 1,
                        'product_code' => $productCode,
                        'product_ref_id' => $productRefId,
                        'product_name' => $newSlots['product_name'] ?? null,
                        'product_price' => $newSlots['product_price'] ?? null,
                        'product_image_url' => $newSlots['product_image_url'] ?? ($newSlots['thumbnail_url'] ?? null),
                        'product_brand' => $newSlots['product_brand'] ?? null,
                        'searched_at' => date('Y-m-d H:i:s'),
                    ];
                }
            }
            
            // Store products_history in slots
            $newSlots['products_history'] = $productsHistory;
            
            // Remove individual product fields from top-level slots (use history instead)
            // Keep them for backward compatibility but products_history is the source of truth
            $mergedSlots = array_merge($existingSlots, $newSlots);

            // ✅ Update products_interested (for admin view) - include ALL products from history
            $productsInterested = null;
            if (!empty($productsHistory)) {
                $productsInterested = json_encode(array_map(function($p) {
                    return [
                        'product_code' => $p['product_code'] ?? null,
                        'product_ref_id' => $p['product_ref_id'] ?? null,
                        'product_name' => $p['product_name'] ?? null,
                        'product_price' => $p['product_price'] ?? null,
                        'product_image_url' => $p['product_image_url'] ?? null,
                        'product_brand' => $p['product_brand'] ?? null,
                        'interest_type' => 'inquired',
                        'timestamp' => $p['searched_at'] ?? date('Y-m-d H:i:s'),
                    ];
                }, $productsHistory), JSON_UNESCAPED_UNICODE);
            }

            $this->db->execute(
                "UPDATE cases SET slots = ?, products_interested = COALESCE(?, products_interested), product_ref_id = COALESCE(?, product_ref_id), updated_at = NOW() WHERE id = ?",
                [json_encode($mergedSlots, JSON_UNESCAPED_UNICODE), $productsInterested, $mergedSlots['product_ref_id'] ?? ($mergedSlots['product_code'] ?? null), $caseId]
            );

            // Log activity
            $this->logCaseActivity($caseId, 'slot_updated', $existingSlots, $mergedSlots, 'bot');

            Logger::info('[CaseEngine] Case slots updated', [
                'case_id' => $caseId,
                'products_count' => count($productsHistory),
                'new_slots' => array_keys($newSlots)
            ]);

            return true;

        } catch (Exception $e) {
            Logger::error('[CaseEngine] Failed to update slots: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Select a product from history for checkout
     */
    public function selectProductFromHistory(int $caseId, $productIdentifier): ?array
    {
        try {
            $case = $this->db->queryOne("SELECT slots FROM cases WHERE id = ?", [$caseId]);
            if (!$case) {
                return null;
            }

            $slots = json_decode($case['slots'] ?? '{}', true) ?: [];
            $productsHistory = $slots['products_history'] ?? [];
            
            if (empty($productsHistory)) {
                return null;
            }

            $selectedProduct = null;

            // Try to match by index (e.g., "ตัวที่ 2", "รายการที่ 1")
            if (is_numeric($productIdentifier)) {
                $idx = (int)$productIdentifier;
                foreach ($productsHistory as $p) {
                    if (($p['idx'] ?? 0) === $idx) {
                        $selectedProduct = $p;
                        break;
                    }
                }
            }
            
            // Try to match by product_ref_id
            if (!$selectedProduct && is_string($productIdentifier)) {
                foreach ($productsHistory as $p) {
                    if ($p['product_ref_id'] === $productIdentifier || $p['product_code'] === $productIdentifier) {
                        $selectedProduct = $p;
                        break;
                    }
                }
            }
            
            // Try to match by name (partial match)
            if (!$selectedProduct && is_string($productIdentifier)) {
                foreach ($productsHistory as $p) {
                    if (stripos($p['product_name'] ?? '', $productIdentifier) !== false) {
                        $selectedProduct = $p;
                        break;
                    }
                }
            }

            if ($selectedProduct) {
                // Update slots with selected product
                $slots['selected_product'] = $selectedProduct;
                $slots['product_ref_id'] = $selectedProduct['product_ref_id'];
                $slots['product_code'] = $selectedProduct['product_code'];
                $slots['product_name'] = $selectedProduct['product_name'];
                $slots['product_price'] = $selectedProduct['product_price'];
                $slots['product_image_url'] = $selectedProduct['product_image_url'];
                
                $this->db->execute(
                    "UPDATE cases SET slots = ?, updated_at = NOW() WHERE id = ?",
                    [json_encode($slots, JSON_UNESCAPED_UNICODE), $caseId]
                );
                
                Logger::info('[CaseEngine] Product selected from history', [
                    'case_id' => $caseId,
                    'product_ref_id' => $selectedProduct['product_ref_id']
                ]);
            }

            return $selectedProduct;
        } catch (Exception $e) {
            Logger::error('[CaseEngine] Failed to select product: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get products history for a case
     */
    public function getProductsHistory(int $caseId): array
    {
        try {
            $case = $this->db->queryOne("SELECT slots FROM cases WHERE id = ?", [$caseId]);
            if (!$case) {
                return [];
            }
            $slots = json_decode($case['slots'] ?? '{}', true) ?: [];
            return $slots['products_history'] ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Update case status
     */
    public function updateCaseStatus(int $caseId, string $newStatus): bool
    {
        try {
            $case = $this->db->queryOne("SELECT status FROM cases WHERE id = ?", [$caseId]);
            if (!$case) {
                return false;
            }

            $oldStatus = $case['status'];

            $this->db->execute(
                "UPDATE cases SET status = ?, updated_at = NOW() WHERE id = ?",
                [$newStatus, $caseId]
            );

            // Log activity
            $this->logCaseActivity(
                $caseId,
                'status_changed',
                ['status' => $oldStatus],
                ['status' => $newStatus],
                'bot'
            );

            return true;

        } catch (Exception $e) {
            Logger::error('[CaseEngine] Failed to update status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve/Close a case when order is completed
     */
    public function resolveCase(int $caseId, string $resolutionType = 'completed', ?string $notes = null): bool
    {
        try {
            $case = $this->db->queryOne("SELECT status FROM cases WHERE id = ?", [$caseId]);
            if (!$case) {
                return false;
            }

            $oldStatus = $case['status'];

            $this->db->execute(
                "UPDATE cases SET 
                    status = 'resolved', 
                    resolution_type = ?, 
                    resolution_notes = ?,
                    resolved_at = NOW(),
                    updated_at = NOW() 
                 WHERE id = ?",
                [$resolutionType, $notes, $caseId]
            );

            $this->logCaseActivity(
                $caseId,
                'case_resolved',
                ['status' => $oldStatus],
                ['status' => 'resolved', 'resolution_type' => $resolutionType],
                'bot'
            );

            Logger::info('[CaseEngine] Case resolved', [
                'case_id' => $caseId,
                'resolution_type' => $resolutionType
            ]);

            return true;

        } catch (Exception $e) {
            Logger::error('[CaseEngine] Failed to resolve case: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Link an order to a case
     */
    public function linkOrderToCase(int $caseId, int $orderId): bool
    {
        try {
            $this->db->execute(
                "UPDATE cases SET order_id = ?, updated_at = NOW() WHERE id = ?",
                [$orderId, $caseId]
            );

            $this->logCaseActivity(
                $caseId,
                'order_linked',
                null,
                ['order_id' => $orderId],
                'bot'
            );

            Logger::info('[CaseEngine] Order linked to case', [
                'case_id' => $caseId,
                'order_id' => $orderId
            ]);

            return true;

        } catch (Exception $e) {
            Logger::error('[CaseEngine] Failed to link order: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Link a payment to a case
     */
    public function linkPaymentToCase(int $caseId, int $paymentId): bool
    {
        try {
            $this->db->execute(
                "UPDATE cases SET payment_id = ?, updated_at = NOW() WHERE id = ?",
                [$paymentId, $caseId]
            );

            $this->logCaseActivity(
                $caseId,
                'payment_linked',
                null,
                ['payment_id' => $paymentId],
                'bot'
            );

            return true;

        } catch (Exception $e) {
            Logger::error('[CaseEngine] Failed to link payment: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get active case for user (any type)
     */
    public function getActiveCase(int $channelId, string $externalUserId): ?array
    {
        try {
            return $this->db->queryOne(
                "SELECT * FROM cases 
                 WHERE channel_id = ? AND external_user_id = ? 
                 AND status NOT IN ('resolved', 'cancelled')
                 ORDER BY updated_at DESC LIMIT 1",
                [$channelId, $externalUserId]
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get active case ID from context
     */
    public function getActiveCaseId(): ?int
    {
        $channelId = $this->context['channel']['id'] ?? null;
        $externalUserId = $this->context['external_user_id'] ?? 
            ($this->context['user']['external_user_id'] ?? null);
        
        if (!$channelId || !$externalUserId) {
            return null;
        }

        $case = $this->getActiveCase($channelId, $externalUserId);
        return $case['id'] ?? null;
    }

    /**
     * Check if handoff to admin is needed
     */
    public function shouldHandoffToAdmin(string $text, array $slots = []): bool
    {
        $caseManagement = $this->config['case_management'] ?? [];
        $handoffTriggers = $caseManagement['admin_handoff_triggers'] ?? [];

        // Check text for handoff triggers
        foreach ($handoffTriggers as $trigger) {
            if (mb_stripos($text, $trigger, 0, 'UTF-8') !== false) {
                Logger::info('[CaseEngine] Handoff trigger detected', [
                    'trigger' => $trigger,
                    'text' => $text
                ]);
                return true;
            }
        }

        // Check slots for handoff indicators
        if (!empty($slots['handoff_to_admin'])) {
            return true;
        }

        return false;
    }

    /**
     * Trigger handoff to admin
     */
    public function triggerHandoff(int $caseId, string $reason = 'user_request'): bool
    {
        try {
            $this->db->execute(
                "UPDATE cases SET status = 'pending_admin', priority = 'high', updated_at = NOW() 
                 WHERE id = ?",
                [$caseId]
            );

            // Log activity
            $this->logCaseActivity($caseId, 'handoff_triggered', null, [
                'reason' => $reason,
                'status' => 'pending_admin'
            ], 'bot');

            Logger::info('[CaseEngine] Handoff triggered', [
                'case_id' => $caseId,
                'reason' => $reason
            ]);

            return true;

        } catch (Exception $e) {
            Logger::error('[CaseEngine] Failed to trigger handoff: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get required slots for a case type
     */
    public function getRequiredSlots(string $caseType, ?string $actionType = null): array
    {
        $caseFlows = $this->config['case_flows'] ?? [];
        $flow = $caseFlows[$caseType] ?? [];

        $required = $flow['required_slots'] ?? [];

        // Add conditional slots based on action_type
        if ($actionType && isset($flow['conditional_slots'][$actionType])) {
            $required = array_merge($required, $flow['conditional_slots'][$actionType]);
        }

        return array_unique($required);
    }

    /**
     * Check which slots are missing
     */
    public function getMissingSlots(string $caseType, array $currentSlots, ?string $actionType = null): array
    {
        $required = $this->getRequiredSlots($caseType, $actionType);
        $missing = [];

        foreach ($required as $slot) {
            if (empty($currentSlots[$slot])) {
                $missing[] = $slot;
            }
        }

        return $missing;
    }

    /**
     * Get question for a missing slot
     */
    public function getSlotQuestion(string $slotName): ?string
    {
        $slotQuestions = $this->config['slot_questions'] ?? [];
        return $slotQuestions[$slotName] ?? null;
    }

    /**
     * Log case activity
     */
    private function logCaseActivity(int $caseId, string $activityType, $oldValue, $newValue, string $actorType, $actorId = null): void
    {
        try {
            $this->db->execute(
                "INSERT INTO case_activities (case_id, activity_type, old_value, new_value, actor_type, actor_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [
                    $caseId,
                    $activityType,
                    $oldValue ? json_encode($oldValue) : null,
                    $newValue ? json_encode($newValue) : null,
                    $actorType,
                    $actorId
                ]
            );
        } catch (Exception $e) {
            Logger::error('[CaseEngine] Failed to log activity: ' . $e->getMessage());
        }
    }

    /**
     * Process savings flow
     */
    public function processSavingsFlow(string $actionType, array $slots): array
    {
        $channelId = $this->context['channel']['id'] ?? null;
        $externalUserId = $this->context['external_user_id'] ??
            ($this->context['user']['external_user_id'] ?? null);
        $platform = $this->context['platform'] ??
            ($this->context['channel']['platform'] ?? 'unknown');

        switch ($actionType) {
            case 'new':
                return $this->createSavingsAccount($slots);

            case 'deposit':
                return $this->processSavingsDeposit($slots);

            case 'inquiry':
                return $this->getSavingsStatus($slots);

            default:
                return [
                    'success' => false,
                    'message' => 'Unknown savings action type'
                ];
        }
    }

    /**
     * Create savings account via API
     */
    private function createSavingsAccount(array $slots): array
    {
        $channelId = $this->context['channel']['id'] ?? null;
        $externalUserId = $this->context['external_user_id'] ??
            ($this->context['user']['external_user_id'] ?? null);
        $platform = $this->context['platform'] ?? 'unknown';

        // Validate required fields
        if (empty($slots['product_ref_id']) && empty($slots['product_name'])) {
            return [
                'success' => false,
                'need_slot' => 'product_ref_id',
                'message' => $this->getSlotQuestion('product_ref_id')
            ];
        }

        // Call API
        $apiUrl = $this->getApiUrl('savings_create');
        $response = $this->callInternalApi($apiUrl, 'POST', [
            'channel_id' => $channelId,
            'external_user_id' => $externalUserId,
            'platform' => $platform,
            'product_ref_id' => $slots['product_ref_id'] ?? null,
            'product_name' => $slots['product_name'] ?? 'Unknown Product',
            'product_price' => $slots['product_price'] ?? $slots['target_amount'] ?? 0
        ]);

        return $response;
    }

    /**
     * Process savings deposit
     */
    private function processSavingsDeposit(array $slots): array
    {
        if (empty($slots['savings_id']) && empty($slots['savings_account_id'])) {
            // Try to find by user
            $channelId = $this->context['channel']['id'] ?? null;
            $externalUserId = $this->context['external_user_id'] ??
                ($this->context['user']['external_user_id'] ?? null);

            $savings = $this->db->queryOne(
                "SELECT id FROM savings_accounts 
                 WHERE channel_id = ? AND external_user_id = ? AND status = 'active'
                 ORDER BY created_at DESC LIMIT 1",
                [$channelId, $externalUserId]
            );

            if ($savings) {
                $slots['savings_id'] = $savings['id'];
            } else {
                return [
                    'success' => false,
                    'need_slot' => 'savings_id',
                    'message' => 'ไม่พบบัญชีออมที่เปิดไว้ค่ะ ต้องการเปิดบัญชีออมใหม่ไหมคะ? 🏦'
                ];
            }
        }

        if (empty($slots['slip_image_url'])) {
            return [
                'success' => false,
                'need_slot' => 'slip_image_url',
                'message' => $this->getSlotQuestion('slip_image_url')
            ];
        }

        $savingsId = $slots['savings_id'] ?? $slots['savings_account_id'];
        $apiUrl = str_replace('{id}', $savingsId, $this->getApiUrl('savings_deposit'));

        return $this->callInternalApi($apiUrl, 'POST', [
            'amount' => $slots['amount'] ?? 0,
            'slip_image_url' => $slots['slip_image_url'],
            'payment_time' => $slots['payment_time'] ?? null,
            'sender_name' => $slots['sender_name'] ?? null
        ]);
    }

    /**
     * Get savings status
     */
    private function getSavingsStatus(array $slots): array
    {
        $channelId = $this->context['channel']['id'] ?? null;
        $externalUserId = $this->context['external_user_id'] ??
            ($this->context['user']['external_user_id'] ?? null);

        if (!empty($slots['savings_id'])) {
            $apiUrl = str_replace('{id}', $slots['savings_id'], $this->getApiUrl('savings_status'));
            return $this->callInternalApi($apiUrl, 'GET');
        }

        // Get all active savings for user
        $savings = $this->db->queryAll(
            "SELECT * FROM savings_accounts 
             WHERE channel_id = ? AND external_user_id = ? AND status = 'active'
             ORDER BY created_at DESC",
            [$channelId, $externalUserId]
        );

        if (empty($savings)) {
            return [
                'success' => true,
                'message' => 'ไม่มีบัญชีออมที่กำลังดำเนินการอยู่ค่ะ 📭'
            ];
        }

        return [
            'success' => true,
            'data' => $savings
        ];
    }

    /**
     * Get API URL from config
     */
    private function getApiUrl(string $endpoint): string
    {
        $baseUrl = $this->config['backend_api']['base_url'] ?? 'http://localhost';
        $endpoints = $this->config['backend_api']['endpoints'] ?? [];
        $path = $endpoints[$endpoint] ?? '';

        return $baseUrl . $path;
    }

    /**
     * Call internal API
     */
    private function callInternalApi(string $url, string $method = 'GET', array $data = []): array
    {
        try {
            $ch = curl_init();

            if ($method === 'GET' && !empty($data)) {
                $url .= '?' . http_build_query($data);
            }

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $result = json_decode($response, true);
                return $result ?: ['success' => true];
            }

            return [
                'success' => false,
                'message' => 'API request failed',
                'http_code' => $httpCode
            ];

        } catch (Exception $e) {
            Logger::error('[CaseEngine] API call failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
