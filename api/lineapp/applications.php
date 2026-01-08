<?php
/**
 * LINE Application System - Applications API
 * 
 * Endpoints:
 *   POST /api/lineapp/applications.php                      - Create new application
 *   GET  /api/lineapp/applications.php?application_no=xxx   - Get application details
 *   GET  /api/lineapp/applications.php?line_user_id=xxx     - Get applications by LINE user
 *   PUT  /api/lineapp/applications.php                      - Update application (form_data)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';

$db = Database::getInstance()->getPdo();

// ============================================================================
// POST - Create New Application
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (!isset($input['campaign_id']) || !isset($input['line_user_id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing required fields: campaign_id, line_user_id'
            ]);
            exit;
        }
        
        $campaignId = (int)$input['campaign_id'];
        $lineUserId = trim($input['line_user_id']);
        $lineDisplayName = $input['line_display_name'] ?? null;
        $linePictureUrl = $input['line_picture_url'] ?? null;
        $lineProfile = $input['line_profile'] ?? null;
        $formData = $input['form_data'] ?? [];
        $phone = $input['phone'] ?? null;
        $email = $input['email'] ?? null;
        $source = $input['source'] ?? 'line_liff';
        
        // Get campaign info
        $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND is_active = 1");
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$campaign) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Campaign not found or inactive'
            ]);
            exit;
        }
        
        // Check if duplicate application exists
        if (!$campaign['allow_duplicate']) {
            $stmt = $db->prepare("
                SELECT id, application_no, status, form_data
                FROM line_applications
                WHERE line_user_id = ? 
                    AND campaign_id = ?
                    AND is_duplicate = 0
                LIMIT 1
            ");
            $stmt->execute([$lineUserId, $campaignId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Define active statuses that allow UPDATE
                $updateableStatuses = ['RECEIVED', 'FORM_INCOMPLETE', 'DOC_PENDING'];
                
                // Define statuses that are being processed (cannot update)
                $processingStatuses = ['OCR_PROCESSING', 'OCR_DONE', 'NEED_REVIEW', 'INCOMPLETE', 'APPROVED', 'REJECTED'];
                
                if (in_array($existing['status'], $updateableStatuses)) {
                    // ✅ อนุญาตให้ UPDATE ข้อมูล
                    Logger::info('[API_LINEAPP_APPLICATIONS] Updating existing application', [
                        'application_id' => $existing['id'],
                        'application_no' => $existing['application_no'],
                        'status' => $existing['status']
                    ]);
                    
                    // Update existing application
                    $stmt = $db->prepare("
                        UPDATE line_applications
                        SET line_display_name = :line_display_name,
                            line_picture_url = :line_picture_url,
                            line_profile = :line_profile,
                            phone = :phone,
                            email = :email,
                            form_data = :form_data,
                            status = :status,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    
                    // Determine status after update
                    $newStatus = (empty($formData) || count($formData) == 0) ? 'FORM_INCOMPLETE' : 'DOC_PENDING';
                    
                    $stmt->execute([
                        'id' => $existing['id'],
                        'line_display_name' => $lineDisplayName,
                        'line_picture_url' => $linePictureUrl,
                        'line_profile' => $lineProfile ? json_encode($lineProfile) : null,
                        'phone' => $phone,
                        'email' => $email,
                        'form_data' => json_encode($formData),
                        'status' => $newStatus
                    ]);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Application updated successfully',
                        'data' => [
                            'application_id' => $existing['id'],
                            'application_no' => $existing['application_no'],
                            'status' => $newStatus,
                            'campaign_name' => $campaign['name'],
                            'is_update' => true
                        ]
                    ]);
                    exit;
                    
                } elseif (in_array($existing['status'], $processingStatuses)) {
                    // ❌ ไม่อนุญาตให้แก้ไข (กำลังประมวลผล/เสร็จแล้ว)
                    http_response_code(409);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Cannot update application. Your application is currently being processed or has been completed.',
                        'existing_application' => [
                            'application_no' => $existing['application_no'],
                            'status' => $existing['status']
                        ]
                    ]);
                    exit;
                }
            }
        }
        
        // Generate application number
        $date = date('Ymd');
        $stmt = $db->prepare("
            SELECT MAX(CAST(SUBSTRING(application_no, 12) AS UNSIGNED)) as max_seq
            FROM line_applications
            WHERE application_no LIKE CONCAT('APP', ?, '%')
        ");
        $stmt->execute([$date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextSeq = ($result['max_seq'] ?? 0) + 1;
        $applicationNo = sprintf('APP%s%03d', $date, $nextSeq);
        
        // Insert application
        $stmt = $db->prepare("
            INSERT INTO line_applications (
                application_no,
                campaign_id,
                campaign_name,
                line_user_id,
                line_display_name,
                line_picture_url,
                line_profile,
                phone,
                email,
                form_data,
                status,
                source,
                ip_address,
                user_agent,
                submitted_at,
                status_history
            ) VALUES (
                :application_no,
                :campaign_id,
                :campaign_name,
                :line_user_id,
                :line_display_name,
                :line_picture_url,
                :line_profile,
                :phone,
                :email,
                :form_data,
                :status,
                :source,
                :ip_address,
                :user_agent,
                NOW(),
                :status_history
            )
        ");
        
        // Determine initial status
        $status = (empty($formData) || count($formData) == 0) ? 'FORM_INCOMPLETE' : 'DOC_PENDING';
        
        // Create status history
        $statusHistory = json_encode([[
            'from' => null,
            'to' => $status,
            'changed_by' => 'system',
            'changed_by_id' => null,
            'changed_at' => date('Y-m-d H:i:s'),
            'reason' => 'Application created via ' . $source
        ]]);
        
        $stmt->execute([
            'application_no' => $applicationNo,
            'campaign_id' => $campaignId,
            'campaign_name' => $campaign['name'],
            'line_user_id' => $lineUserId,
            'line_display_name' => $lineDisplayName,
            'line_picture_url' => $linePictureUrl,
            'line_profile' => $lineProfile ? json_encode($lineProfile) : null,
            'phone' => $phone,
            'email' => $email,
            'form_data' => json_encode($formData),
            'status' => $status,
            'source' => $source,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'status_history' => $statusHistory
        ]);
        
        $applicationId = $db->lastInsertId();
        
        Logger::info('[API_LINEAPP_APPLICATIONS] Application created', [
            'application_id' => $applicationId,
            'application_no' => $applicationNo,
            'campaign_id' => $campaignId,
            'line_user_id' => $lineUserId,
            'status' => $status
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Application created successfully',
            'data' => [
                'application_id' => $applicationId,
                'application_no' => $applicationNo,
                'status' => $status,
                'campaign_name' => $campaign['name']
            ]
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_LINEAPP_APPLICATIONS] Create error', [
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
// GET - Retrieve Application(s)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Get by application_no
        if (isset($_GET['application_no'])) {
            $applicationNo = trim($_GET['application_no']);
            
            $stmt = $db->prepare("
                SELECT 
                    la.*,
                    c.name as campaign_name,
                    c.form_config,
                    c.required_documents,
                    admin.full_name as assigned_to_name
                FROM line_applications la
                JOIN campaigns c ON la.campaign_id = c.id
                LEFT JOIN users admin ON la.assigned_to = admin.id
                WHERE la.application_no = ?
            ");
            
            $stmt->execute([$applicationNo]);
            $application = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$application) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Application not found'
                ]);
                exit;
            }
            
            // Decode JSON fields
            $application['form_data'] = json_decode($application['form_data'], true);
            $application['ocr_results'] = json_decode($application['ocr_results'], true);
            $application['extracted_data'] = json_decode($application['extracted_data'], true);
            $application['status_history'] = json_decode($application['status_history'], true);
            $application['line_profile'] = json_decode($application['line_profile'], true);
            $application['form_config'] = json_decode($application['form_config'], true);
            $application['required_documents'] = json_decode($application['required_documents'], true);
            
            // Get documents
            $stmt = $db->prepare("
                SELECT 
                    id,
                    document_type,
                    document_label,
                    original_filename,
                    file_size,
                    mime_type,
                    ocr_processed,
                    ocr_confidence,
                    is_verified,
                    uploaded_at
                FROM application_documents
                WHERE application_id = ?
                ORDER BY uploaded_at ASC
            ");
            $stmt->execute([$application['id']]);
            $application['documents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $application
            ]);
            exit;
        }
        
        // Get by line_user_id
        if (isset($_GET['line_user_id'])) {
            $lineUserId = trim($_GET['line_user_id']);
            $campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;
            
            $sql = "
                SELECT 
                    la.id,
                    la.application_no,
                    la.campaign_id,
                    la.campaign_name,
                    la.status,
                    la.submitted_at,
                    la.updated_at,
                    la.needs_manual_review
                FROM line_applications la
                WHERE la.line_user_id = ?
            ";
            
            $params = [$lineUserId];
            
            if ($campaignId) {
                $sql .= " AND la.campaign_id = ?";
                $params[] = $campaignId;
            }
            
            $sql .= " ORDER BY la.created_at DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $applications,
                'count' => count($applications)
            ]);
            exit;
        }
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required parameter: application_no or line_user_id'
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_LINEAPP_APPLICATIONS] Get error', [
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
// PUT - Update Application
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['application_id']) && !isset($input['application_no'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing required field: application_id or application_no'
            ]);
            exit;
        }
        
        // Get application
        if (isset($input['application_id'])) {
            $stmt = $db->prepare("SELECT * FROM line_applications WHERE id = ?");
            $stmt->execute([$input['application_id']]);
        } else {
            $stmt = $db->prepare("SELECT * FROM line_applications WHERE application_no = ?");
            $stmt->execute([$input['application_no']]);
        }
        
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$application) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Application not found'
            ]);
            exit;
        }
        
        // Update form_data
        if (isset($input['form_data'])) {
            $currentFormData = json_decode($application['form_data'], true) ?? [];
            $newFormData = array_merge($currentFormData, $input['form_data']);
            
            $stmt = $db->prepare("
                UPDATE line_applications 
                SET form_data = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([json_encode($newFormData), $application['id']]);
            
            Logger::info('[API_LINEAPP_APPLICATIONS] Form data updated', [
                'application_id' => $application['id'],
                'application_no' => $application['application_no']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Application updated successfully'
            ]);
            exit;
        }
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No valid update data provided'
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_LINEAPP_APPLICATIONS] Update error', [
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
