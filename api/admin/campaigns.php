<?php
/**
 * Admin API - Campaigns Management
 * 
 * Endpoints:
 *   GET    /api/admin/campaigns.php              - List all campaigns
 *   GET    /api/admin/campaigns.php?id=1         - Get campaign detail
 *   POST   /api/admin/campaigns.php              - Create campaign
 *   PUT    /api/admin/campaigns.php              - Update campaign
 *   DELETE /api/admin/campaigns.php?id=1         - Delete campaign
 */

header('Content-Type: application/json; charset=utf-8');

// Remove wildcard CORS - use specific origin or don't set it for same-origin
// This allows credentials to work properly
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/Auth.php';

// Require authentication
$userId = Auth::id();
if (!$userId) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please login.'
    ]);
    exit;
}

$db = Database::getInstance()->getPdo();

// ============================================================================
// GET - List Campaigns or Get Detail
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Get single campaign by ID
        if (isset($_GET['id'])) {
            $campaignId = (int)$_GET['id'];
            
            $stmt = $db->prepare("
                SELECT 
                    c.*,
                    (SELECT COUNT(*) FROM line_applications WHERE campaign_id = c.id) as application_count,
                    (SELECT COUNT(*) FROM line_applications WHERE campaign_id = c.id AND status = 'APPROVED') as approved_count,
                    (SELECT COUNT(*) FROM line_applications WHERE campaign_id = c.id AND status = 'REJECTED') as rejected_count
                FROM campaigns c
                WHERE c.id = ? AND c.created_by = ?
            ");
            
            $stmt->execute([$campaignId, $userId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$campaign) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Campaign not found'
                ]);
                exit;
            }
            
            // Decode JSON fields (with null safety)
            $campaign['form_config'] = !empty($campaign['form_config']) ? json_decode($campaign['form_config'], true) : [];
            $campaign['required_documents'] = !empty($campaign['required_documents']) ? json_decode($campaign['required_documents'], true) : [];
            $campaign['ocr_fields'] = !empty($campaign['ocr_fields']) ? json_decode($campaign['ocr_fields'], true) : [];
            $campaign['auto_approve_rules'] = !empty($campaign['auto_approve_rules']) ? json_decode($campaign['auto_approve_rules'], true) : null;
            
            echo json_encode([
                'success' => true,
                'data' => $campaign
            ]);
            exit;
        }
        
        // List all campaigns (only for the logged-in user)
        $sql = "
            SELECT 
                c.id,
                c.code,
                c.name,
                c.description,
                c.start_date,
                c.end_date,
                c.is_active,
                c.created_at,
                (SELECT COUNT(*) FROM line_applications WHERE campaign_id = c.id) as application_count,
                (SELECT COUNT(*) FROM line_applications WHERE campaign_id = c.id AND status = 'APPROVED') as approved_count
            FROM campaigns c
            WHERE c.created_by = ?
        ";
        
        $params = [$userId];
        
        // Filter by active status - default to showing only active campaigns
        if (isset($_GET['is_active'])) {
            $sql .= " AND c.is_active = " . ((int)$_GET['is_active']);
        } else {
            // By default, only show active campaigns (exclude soft-deleted ones)
            $sql .= " AND c.is_active = 1";
        }
        
        $sql .= " ORDER BY c.created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $campaigns,
            'count' => count($campaigns)
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_ADMIN_CAMPAIGNS] Get error', [
            'error' => $e->getMessage()
        ]);
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred'
        ]);
    }
    exit;
}

// ============================================================================
// POST - Create Campaign
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['code', 'name', 'form_config'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Missing required field: $field"
                ]);
                exit;
            }
        }
        
        // Check if code already exists
        $stmt = $db->prepare("SELECT id FROM campaigns WHERE code = ?");
        $stmt->execute([$input['code']]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Campaign code already exists'
            ]);
            exit;
        }
        
        // Prepare data
        $data = [
            'code' => strtoupper(trim($input['code'])),
            'name' => trim($input['name']),
            'description' => $input['description'] ?? null,
            'form_config' => json_encode($input['form_config']),
            'required_documents' => json_encode($input['required_documents'] ?? []),
            'start_date' => $input['start_date'] ?? null,
            'end_date' => $input['end_date'] ?? null,
            'is_active' => isset($input['is_active']) ? (int)$input['is_active'] : 1,
            'max_applications' => $input['max_applications'] ?? null,
            
            // OCR settings
            'ocr_enabled' => isset($input['ocr_enabled']) ? (int)$input['ocr_enabled'] : 0,
            'ocr_fields' => json_encode($input['ocr_fields'] ?? []),
            'min_ocr_confidence' => $input['min_ocr_confidence'] ?? 0.75,
            
            // Workflow
            'auto_approve' => isset($input['auto_approve']) ? (int)$input['auto_approve'] : 0,
            'auto_approve_confidence' => $input['auto_approve_confidence'] ?? 0.9,
            'require_appointment' => isset($input['require_appointment']) ? (int)$input['require_appointment'] : 0,
            'allow_duplicate' => isset($input['allow_duplicate']) ? (int)$input['allow_duplicate'] : 0,
            
            // LINE settings
            'line_channel_id' => $input['line_channel_id'] ?? null,
            'line_rich_menu_id' => $input['line_rich_menu_id'] ?? null,
            'liff_id' => $input['liff_id'] ?? null,
            
            // Notification templates
            'notification_template_received' => $input['notification_template_received'] ?? null,
            'notification_template_approved' => $input['notification_template_approved'] ?? null,
            'notification_template_rejected' => $input['notification_template_rejected'] ?? null,
            'notification_template_need_docs' => $input['notification_template_need_docs'] ?? null,
            
            'created_by' => $userId,
            'tenant_id' => 'default'
        ];
        
        // Insert campaign
        $sql = "
            INSERT INTO campaigns (
                code, name, description, form_config, required_documents,
                start_date, end_date, is_active, max_applications,
                ocr_enabled, ocr_fields, min_ocr_confidence,
                auto_approve, auto_approve_confidence,
                require_appointment, allow_duplicate,
                line_channel_id, line_rich_menu_id, liff_id,
                notification_template_received, notification_template_approved,
                notification_template_rejected, notification_template_need_docs,
                created_by, tenant_id
            ) VALUES (
                :code, :name, :description, :form_config, :required_documents,
                :start_date, :end_date, :is_active, :max_applications,
                :ocr_enabled, :ocr_fields, :min_ocr_confidence,
                :auto_approve, :auto_approve_confidence,
                :require_appointment, :allow_duplicate,
                :line_channel_id, :line_rich_menu_id, :liff_id,
                :notification_template_received, :notification_template_approved,
                :notification_template_rejected, :notification_template_need_docs,
                :created_by, :tenant_id
            )
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($data);
        
        $campaignId = $db->lastInsertId();
        
        Logger::info('[API_ADMIN_CAMPAIGNS] Campaign created', [
            'campaign_id' => $campaignId,
            'code' => $data['code'],
            'name' => $data['name'],
            'created_by' => $userId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Campaign created successfully',
            'data' => [
                'id' => $campaignId,
                'code' => $data['code']
            ]
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_ADMIN_CAMPAIGNS] Create error', [
            'error' => $e->getMessage()
        ]);
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred'
        ]);
    }
    exit;
}

// ============================================================================
// PUT - Update Campaign
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing required field: id'
            ]);
            exit;
        }
        
        $campaignId = (int)$input['id'];
        
        // Check if campaign exists and belongs to user
        $stmt = $db->prepare("SELECT id FROM campaigns WHERE id = ? AND created_by = ?");
        $stmt->execute([$campaignId, $userId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Campaign not found'
            ]);
            exit;
        }
        
        // Build update query dynamically
        $updates = [];
        $params = [];
        
        $allowedFields = [
            'code', 'name', 'description', 'start_date', 'end_date', 'is_active', 'max_applications',
            'ocr_enabled', 'min_ocr_confidence', 'auto_approve', 'auto_approve_confidence',
            'require_appointment', 'allow_duplicate', 'line_channel_id', 'line_rich_menu_id', 'liff_id',
            'notification_template_received', 'notification_template_approved',
            'notification_template_rejected', 'notification_template_need_docs'
        ];
        
        $jsonFields = ['form_config', 'required_documents', 'ocr_fields'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $updates[] = "$field = ?";
                $params[] = $input[$field];
            }
        }
        
        foreach ($jsonFields as $field) {
            if (array_key_exists($field, $input)) {
                $updates[] = "$field = ?";
                $params[] = json_encode($input[$field]);
            }
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No fields to update'
            ]);
            exit;
        }
        
        $updates[] = "updated_at = NOW()";
        $params[] = $campaignId;
        
        $sql = "UPDATE campaigns SET " . implode(', ', $updates) . " WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        Logger::info('[API_ADMIN_CAMPAIGNS] Campaign updated', [
            'campaign_id' => $campaignId,
            'updated_by' => $userId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Campaign updated successfully'
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_ADMIN_CAMPAIGNS] Update error', [
            'error' => $e->getMessage()
        ]);
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred'
        ]);
    }
    exit;
}

// ============================================================================
// DELETE - Soft Delete Campaign
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameter: id'
            ]);
            exit;
        }
        
        $campaignId = (int)$_GET['id'];
        
        // Soft delete (set is_active = 0)
        $stmt = $db->prepare("
            UPDATE campaigns 
            SET is_active = 0, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$campaignId]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Campaign not found'
            ]);
            exit;
        }
        
        Logger::info('[API_ADMIN_CAMPAIGNS] Campaign deleted', [
            'campaign_id' => $campaignId,
            'deleted_by' => $userId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Campaign deleted successfully'
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_ADMIN_CAMPAIGNS] Delete error', [
            'error' => $e->getMessage()
        ]);
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred'
        ]);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed'
]);
