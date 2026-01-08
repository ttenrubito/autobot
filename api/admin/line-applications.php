<?php
/**
 * Admin API - LINE Applications Management
 * 
 * Endpoints:
 *   GET  /api/admin/line-applications.php              - List with filters
 *   GET  /api/admin/line-applications.php?id=123       - Get detail
 *   PUT  /api/admin/line-applications.php              - Update status/approve/reject
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
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
// GET - List Applications with Filters
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['id'])) {
    try {
        // Build query with filters (only show applications from campaigns owned by this user)
        $sql = "
            SELECT 
                la.id,
                la.application_no,
                la.campaign_id,
                la.campaign_name,
                la.line_user_id,
                la.line_display_name,
                la.line_picture_url,
                la.phone,
                la.email,
                la.status,
                la.substatus,
                la.priority,
                la.needs_manual_review,
                la.is_duplicate,
                la.submitted_at,
                la.updated_at,
                (SELECT COUNT(*) FROM application_documents WHERE application_id = la.id) as document_count,
                (SELECT COUNT(*) FROM application_documents WHERE application_id = la.id AND ocr_processed = 1) as ocr_completed_count
            FROM line_applications la
            INNER JOIN campaigns c ON la.campaign_id = c.id
            WHERE c.created_by = ?
        ";
        
        $params = [$userId];
        
        // Filter by status (multiple)
        if (isset($_GET['status']) && !empty($_GET['status'])) {
            $statuses = is_array($_GET['status']) ? $_GET['status'] : explode(',', $_GET['status']);
            $placeholders = str_repeat('?,', count($statuses) - 1) . '?';
            $sql .= " AND la.status IN ($placeholders)";
            $params = array_merge($params, $statuses);
        }
        
        // Filter by campaign
        if (isset($_GET['campaign_id']) && !empty($_GET['campaign_id'])) {
            $sql .= " AND la.campaign_id = ?";
            $params[] = (int)$_GET['campaign_id'];
        }
        
        // Filter by date range
        if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
            $sql .= " AND DATE(la.submitted_at) >= ?";
            $params[] = $_GET['date_from'];
        }
        
        if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
            $sql .= " AND DATE(la.submitted_at) <= ?";
            $params[] = $_GET['date_to'];
        }
        
        // Filter by priority
        if (isset($_GET['priority']) && !empty($_GET['priority'])) {
            $priorities = is_array($_GET['priority']) ? $_GET['priority'] : explode(',', $_GET['priority']);
            $placeholders = str_repeat('?,', count($priorities) - 1) . '?';
            $sql .= " AND la.priority IN ($placeholders)";
            $params = array_merge($params, $priorities);
        }
        
        // Filter by needs_manual_review
        if (isset($_GET['needs_review']) && $_GET['needs_review'] == '1') {
            $sql .= " AND la.needs_manual_review = 1";
        }
        
        // Filter by is_duplicate
        if (isset($_GET['is_duplicate']) && $_GET['is_duplicate'] == '1') {
            $sql .= " AND la.is_duplicate = 1";
        }
        
        // Search by application_no, name, phone, email
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $search = '%' . $_GET['search'] . '%';
            $sql .= " AND (
                la.application_no LIKE ? OR
                la.line_display_name LIKE ? OR
                la.phone LIKE ? OR
                la.email LIKE ?
            )";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        // Count total (before pagination)
        $countSql = "SELECT COUNT(*) FROM (" . $sql . ") as count_table";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetchColumn();
        
        // Sorting
        $sortField = $_GET['sort_by'] ?? 'submitted_at';
        $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
        
        $allowedSortFields = ['application_no', 'campaign_name', 'status', 'submitted_at', 'updated_at', 'priority'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'submitted_at';
        }
        
        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }
        
        $sql .= " ORDER BY la.$sortField $sortOrder";
        
        // Pagination
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 20;
        $offset = ($page - 1) * $perPage;
        
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;
        
        // Execute query
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $applications,
            'pagination' => [
                'total' => $totalCount,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil($totalCount / $perPage),
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $totalCount)
            ]
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_ADMIN_LINEAPPS] List error', [
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
// GET - Get Application Detail
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    try {
        $applicationId = (int)$_GET['id'];
        
        $stmt = $db->prepare("
            SELECT 
                la.*,
                c.name as campaign_name,
                c.form_config,
                c.required_documents
            FROM line_applications la
            JOIN campaigns c ON la.campaign_id = c.id
            WHERE la.id = ? AND c.created_by = ?
        ");
        
        $stmt->execute([$applicationId, $userId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$application) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Application not found'
            ]);
            exit;
        }
        
        // Decode JSON fields (with null checks)
        $application['form_data'] = $application['form_data'] ? json_decode($application['form_data'], true) : null;
        $application['ocr_results'] = $application['ocr_results'] ? json_decode($application['ocr_results'], true) : null;
        $application['status_history'] = $application['status_history'] ? json_decode($application['status_history'], true) : null;
        $application['line_profile'] = $application['line_profile'] ? json_decode($application['line_profile'], true) : null;
        $application['form_config'] = $application['form_config'] ? json_decode($application['form_config'], true) : null;
        $application['required_documents'] = $application['required_documents'] ? json_decode($application['required_documents'], true) : null;
        
        // Get documents
        $stmt = $db->prepare("
            SELECT *
            FROM application_documents
            WHERE application_id = ?
            ORDER BY uploaded_at ASC
        ");
        $stmt->execute([$applicationId]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode OCR data in documents (with null checks)
        foreach ($documents as &$doc) {
            $doc['ocr_data'] = $doc['ocr_data'] ? json_decode($doc['ocr_data'], true) : null;
        }
        
        $application['documents'] = $documents;
        
        echo json_encode([
            'success' => true,
            'data' => $application
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_ADMIN_LINEAPPS] Get detail error', [
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
// PUT - Update Application Status / Approve / Reject
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['id']) || !isset($input['action'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing required fields: id, action'
            ]);
            exit;
        }
        
        $applicationId = (int)$input['id'];
        $action = $input['action'];
        $adminNotes = $input['admin_notes'] ?? '';
        
        // Get current application (check ownership via campaign)
        $stmt = $db->prepare("
            SELECT la.* 
            FROM line_applications la
            JOIN campaigns c ON la.campaign_id = c.id
            WHERE la.id = ? AND c.created_by = ?
        ");
        $stmt->execute([$applicationId, $userId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$application) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Application not found'
            ]);
            exit;
        }
        
        $currentStatus = $application['status'];
        $newStatus = null;
        $reason = '';
        $rejectionReason = null;
        $substatus = null;
        
        switch ($action) {
            case 'approve':
                $newStatus = 'APPROVED';
                $reason = 'Approved by admin';
                break;
                
            case 'reject':
                $newStatus = 'REJECTED';
                $rejectionReason = $input['rejection_reason'] ?? 'Application rejected';
                $reason = 'Rejected: ' . $rejectionReason;
                break;
                
            case 'request_docs':
                $newStatus = 'INCOMPLETE';
                $requiredDocs = $input['required_documents'] ?? [];
                $substatus = 'ขอเอกสารเพิ่ม: ' . implode(', ', $requiredDocs);
                $reason = 'Requested additional documents';
                break;
                
            case 'set_appointment':
                $appointmentDate = $input['appointment_datetime'] ?? null;
                $appointmentLocation = $input['appointment_location'] ?? null;
                $appointmentNote = $input['appointment_note'] ?? null;
                
                $stmt = $db->prepare("
                    UPDATE line_applications
                    SET appointment_datetime = ?,
                        appointment_location = ?,
                        appointment_note = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$appointmentDate, $appointmentLocation, $appointmentNote, $applicationId]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Appointment set successfully'
                ]);
                exit;
                
            default:
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid action'
                ]);
                exit;
        }
        
        // Update status history (with null check)
        $statusHistory = $application['status_history'] ? json_decode($application['status_history'], true) : [];
        $statusHistory = $statusHistory ?? [];
        $statusHistory[] = [
            'from' => $currentStatus,
            'to' => $newStatus,
            'changed_by' => 'admin',
            'changed_by_id' => $userId,
            'changed_at' => date('Y-m-d H:i:s'),
            'reason' => $reason
        ];
        
        // Update application
        $stmt = $db->prepare("
            UPDATE line_applications
            SET status = ?,
                substatus = ?,
                rejection_reason = ?,
                admin_notes = CONCAT(COALESCE(admin_notes, ''), '\n[', NOW(), '] ', ?),
                status_changed_by = ?,
                status_changed_at = NOW(),
                status_history = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $newStatus,
            $substatus,
            $rejectionReason,
            $adminNotes,
            $userId,
            json_encode($statusHistory),
            $applicationId
        ]);
        
        Logger::info('[API_ADMIN_LINEAPPS] Status updated', [
            'application_id' => $applicationId,
            'from_status' => $currentStatus,
            'to_status' => $newStatus,
            'action' => $action,
            'admin_id' => $userId
        ]);
        
        // TODO: Send LINE notification to applicant
        
        echo json_encode([
            'success' => true,
            'message' => 'Application updated successfully',
            'data' => [
                'application_id' => $applicationId,
                'new_status' => $newStatus
            ]
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_ADMIN_LINEAPPS] Update error', [
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
