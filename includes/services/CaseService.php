<?php
/**
 * CaseService - Business logic for case management
 * 
 * @version 1.0
 * @date 2026-01-31
 */

namespace App\Services;

use PDO;
use Exception;

class CaseService
{
    private PDO $db;
    
    // Case types
    const CASE_PRODUCT_INQUIRY = 'product_inquiry';
    const CASE_PAYMENT_FULL = 'payment_full';
    const CASE_PAYMENT_INSTALLMENT = 'payment_installment';
    const CASE_PAYMENT_SAVINGS = 'payment_savings';
    const CASE_PAWN = 'pawn';
    const CASE_PAWN_NEW = 'pawn_new';
    const CASE_PAWN_REDEMPTION = 'pawn_redemption';
    const CASE_REPAIR = 'repair';
    const CASE_GENERAL = 'general';
    
    // Case statuses
    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_WAITING = 'waiting_customer';
    const STATUS_CLOSED = 'closed';
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \getDB();
    }
    
    /**
     * Create or update a case
     * @param string $caseType Case type
     * @param array $data Case data (subject, description, priority, etc.)
     * @param array $context Chat context (for customer/channel info)
     * @return array ['success' => bool, 'case_id' => int, 'case_no' => string]
     */
    public function createOrUpdate(string $caseType, array $data, array $context = []): array
    {
        $channelId = $context['channel']['id'] ?? $data['channel_id'] ?? null;
        $platformUserId = $context['platform_user_id'] ?? $context['external_user_id'] ?? $data['platform_user_id'] ?? null;
        $customerId = $context['customer_profile_id'] ?? $data['customer_profile_id'] ?? null;
        $userId = $context['user_id'] ?? $data['user_id'] ?? null;
        
        // Check for existing open case of same type
        if ($platformUserId) {
            $existingCase = $this->findOpenCase($caseType, $platformUserId);
            if ($existingCase) {
                // Update existing case
                return $this->updateCase($existingCase['id'], $data);
            }
        }
        
        // Create new case
        return $this->create($caseType, $data, $channelId, $platformUserId, $customerId, $userId);
    }
    
    /**
     * Create a new case
     */
    private function create(
        string $caseType, 
        array $data, 
        ?int $channelId, 
        ?string $platformUserId,
        ?int $customerId,
        ?int $userId
    ): array {
        try {
            $caseNo = 'CASE-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            
            $stmt = $this->db->prepare("
                INSERT INTO cases (
                    case_no, channel_id, external_user_id, user_id, customer_profile_id,
                    case_type, status, subject, description, priority, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $caseNo,
                $channelId,
                $platformUserId,
                $userId,
                $customerId,
                $caseType,
                $data['subject'] ?? "Case: {$caseType}",
                $data['description'] ?? '',
                $data['priority'] ?? 'normal'
            ]);
            
            $caseId = (int)$this->db->lastInsertId();
            
            return [
                'success' => true,
                'case_id' => $caseId,
                'case_no' => $caseNo,
                'is_new' => true
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Update existing case
     */
    private function updateCase(int $caseId, array $data): array
    {
        try {
            $updates = [];
            $params = [];
            
            if (isset($data['description'])) {
                $updates[] = "description = CONCAT(description, '\n---\n', ?)";
                $params[] = $data['description'];
            }
            if (isset($data['priority'])) {
                $updates[] = "priority = ?";
                $params[] = $data['priority'];
            }
            if (isset($data['status'])) {
                $updates[] = "status = ?";
                $params[] = $data['status'];
            }
            
            $updates[] = "updated_at = NOW()";
            $params[] = $caseId;
            
            $sql = "UPDATE cases SET " . implode(', ', $updates) . " WHERE id = ?";
            $this->db->prepare($sql)->execute($params);
            
            // Get case_no
            $case = $this->getCaseById($caseId);
            
            return [
                'success' => true,
                'case_id' => $caseId,
                'case_no' => $case['case_no'] ?? '',
                'is_new' => false
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Find existing open case of same type
     */
    public function findOpenCase(string $caseType, string $platformUserId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM cases 
            WHERE case_type = ? 
            AND external_user_id = ?
            AND status IN ('open', 'in_progress', 'waiting_customer')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$caseType, $platformUserId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Close a case
     * @param int $caseId Case ID
     * @param string $resolution Resolution notes
     * @return array ['success' => bool]
     */
    public function closeCase(int $caseId, string $resolution = ''): array
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE cases SET 
                    status = 'closed', 
                    resolved_at = NOW(),
                    resolution = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$resolution, $caseId]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Close cases by subject pattern (for auto-close)
     * @param string $caseType Case type
     * @param string $subjectPattern LIKE pattern for subject
     * @param string $resolution Resolution notes
     * @return array ['success' => bool, 'closed_count' => int]
     */
    public function closeCasesBySubject(string $caseType, string $subjectPattern, string $resolution = ''): array
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE cases SET 
                    status = 'closed', 
                    resolved_at = NOW(),
                    resolution = ?,
                    updated_at = NOW()
                WHERE case_type = ?
                AND status = 'open'
                AND subject LIKE ?
            ");
            $stmt->execute([$resolution, $caseType, $subjectPattern]);
            
            return ['success' => true, 'closed_count' => $stmt->rowCount()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get case by ID
     */
    public function getCaseById(int $caseId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM cases WHERE id = ?");
        $stmt->execute([$caseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get cases for a customer
     * @param string $platformUserId Platform user ID or "user:X" format
     * @param array $statuses Filter by statuses
     * @param int $limit Max results
     * @return array List of cases
     */
    public function getCasesByCustomer(string $platformUserId, array $statuses = [], int $limit = 20): array
    {
        // Handle "user:X" format from API v2
        if (preg_match('/^user:(\d+)$/', $platformUserId, $matches)) {
            $userId = (int)$matches[1];
            $sql = "SELECT * FROM cases WHERE user_id = ?";
            $params = [$userId];
        } else {
            $sql = "SELECT * FROM cases WHERE external_user_id = ?";
            $params = [$platformUserId];
        }
        
        if (!empty($statuses)) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $sql .= " AND status IN ({$placeholders})";
            $params = array_merge($params, $statuses);
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Detect case type from intent
     * @param string $intent Chat intent
     * @return string|null Case type or null
     */
    public function detectCaseTypeFromIntent(string $intent): ?string
    {
        $map = [
            'product_lookup_by_code' => self::CASE_PRODUCT_INQUIRY,
            'product_search' => self::CASE_PRODUCT_INQUIRY,
            'product_availability' => self::CASE_PRODUCT_INQUIRY,
            'price_inquiry' => self::CASE_PRODUCT_INQUIRY,
            'checkout_confirm' => self::CASE_PAYMENT_FULL,
            'payment_slip_verify' => self::CASE_PAYMENT_FULL,
            'installment_check' => self::CASE_PAYMENT_INSTALLMENT,
            'installment_flow' => self::CASE_PAYMENT_INSTALLMENT,
            'pawn_check' => self::CASE_PAWN,
            'pawn_new' => self::CASE_PAWN_NEW,
            'pawn_inquiry' => self::CASE_PAWN,
            'repair_check' => self::CASE_REPAIR,
            'savings_check' => self::CASE_PAYMENT_SAVINGS,
        ];
        
        return $map[$intent] ?? null;
    }
    
    /**
     * Add note to case
     * @param int $caseId Case ID
     * @param string $note Note content
     * @param int|null $userId User ID who added the note
     * @return array ['success' => bool]
     */
    public function addNote(int $caseId, string $note, ?int $userId = null): array
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO case_notes (case_id, user_id, note, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$caseId, $userId, $note]);
            
            return ['success' => true, 'note_id' => (int)$this->db->lastInsertId()];
            
        } catch (Exception $e) {
            // Table might not exist
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
