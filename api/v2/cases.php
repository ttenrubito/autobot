<?php
/**
 * Case API v2 (เคส) - Uses CaseService
 * 
 * REST Endpoints:
 * GET  /api/v2/cases                     - Get all cases for customer
 * GET  /api/v2/cases?id=X                - Get specific case detail
 * GET  /api/v2/cases/open                - Get open cases only
 * POST /api/v2/cases                     - Create new case
 * POST /api/v2/cases/note                - Add note to case
 * PUT  /api/v2/cases/close               - Close a case
 * 
 * @version 2.0
 * @date 2026-01-31
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/services/CaseService.php';

use App\Services\CaseService;

// Parse path info
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', $pathInfo));
$action = $pathParts[1] ?? null;

$method = $_SERVER['REQUEST_METHOD'];

Auth::require();
$userId = Auth::id();
$platformUserId = null; // Platform user ID from chatbot context only

try {
    $db = Database::getInstance()->getPdo();
    $caseService = new CaseService($db);
    
    // Route based on method and action
    if ($method === 'GET') {
        switch ($action) {
            case 'open':
                // Get open cases only
                $result = $caseService->getCasesByCustomer(
                    "user:{$userId}", 
                    [CaseService::STATUS_OPEN, CaseService::STATUS_IN_PROGRESS, CaseService::STATUS_WAITING]
                );
                Response::success([
                    'items' => $result,
                    'count' => count($result)
                ]);
                break;
                
            default:
                // Get cases list or detail
                $caseId = $_GET['id'] ?? null;
                
                if ($caseId) {
                    $case = $caseService->getCaseById((int)$caseId);
                    if ($case) {
                        // Add notes if exists
                        $case['notes'] = getCaseNotes($db, (int)$caseId);
                        Response::success($case);
                    } else {
                        Response::error('Case not found', 404);
                    }
                } else {
                    // List all cases
                    $cases = $caseService->getCasesByCustomer("user:{$userId}");
                    Response::success([
                        'items' => $cases,
                        'count' => count($cases)
                    ]);
                }
        }
        
    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        
        switch ($action) {
            case 'note':
                // Add note to case
                $caseId = $body['case_id'] ?? null;
                $note = $body['note'] ?? '';
                
                if (!$caseId || empty($note)) {
                    Response::error('case_id and note required', 400);
                }
                
                $result = $caseService->addNote((int)$caseId, $note, $userId);
                
                if ($result['success']) {
                    Response::success($result);
                } else {
                    Response::error($result['error'] ?? 'Note add failed', 400);
                }
                break;
                
            default:
                // Create new case
                $caseType = $body['case_type'] ?? CaseService::CASE_GENERAL;
                $context = [
                    'user_id' => $userId,
                    'platform_user_id' => null
                ];
                
                $result = $caseService->createOrUpdate($caseType, $body, $context);
                
                if ($result['success']) {
                    http_response_code(201);
                    Response::success($result);
                } else {
                    Response::error($result['error'] ?? 'Case creation failed', 400);
                }
        }
        
    } elseif ($method === 'PUT') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        
        switch ($action) {
            case 'close':
                // Close a case
                $caseId = $body['case_id'] ?? null;
                $resolution = $body['resolution'] ?? '';
                
                if (!$caseId) {
                    Response::error('case_id required', 400);
                }
                
                $result = $caseService->closeCase((int)$caseId, $resolution);
                
                if ($result['success']) {
                    Response::success(['closed' => true]);
                } else {
                    Response::error($result['error'] ?? 'Close failed', 400);
                }
                break;
                
            default:
                Response::error('Invalid action. Use: close', 400);
        }
        
    } else {
        Response::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    error_log("Case API Error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}

/**
 * Get case notes
 */
function getCaseNotes(PDO $db, int $caseId): array
{
    try {
        $stmt = $db->prepare("
            SELECT cn.*, u.name as user_name
            FROM case_notes cn
            LEFT JOIN users u ON cn.user_id = u.id
            WHERE cn.case_id = ?
            ORDER BY cn.created_at ASC
        ");
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        // Table might not exist
        return [];
    }
}
