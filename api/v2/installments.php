<?php
/**
 * Installment API v2 (ผ่อนชำระ) - Uses InstallmentService
 * 
 * REST Endpoints:
 * GET  /api/v2/installments                    - Get all installments for customer
 * GET  /api/v2/installments?id=X               - Get specific installment detail
 * GET  /api/v2/installments/active             - Get active contracts only
 * GET  /api/v2/installments/preview            - Calculate payment plan preview
 * POST /api/v2/installments/pay                - Submit payment
 * 
 * @version 2.0
 * @date 2026-01-31
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/services/InstallmentService.php';

use App\Services\InstallmentService;

// Parse path info
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', $pathInfo));
$action = $pathParts[1] ?? null;

$method = $_SERVER['REQUEST_METHOD'];
$isPublicPreview = ($action === 'preview' && $method === 'GET');

// Auth required for most endpoints
if (!$isPublicPreview) {
    Auth::require();
    $userId = Auth::id();
    $platformUserId = null; // Platform user ID from chatbot context only
}

try {
    $db = Database::getInstance()->getPdo();
    $installmentService = new InstallmentService($db);
    
    // Route based on method and action
    if ($method === 'GET') {
        switch ($action) {
            case 'active':
                // Get active installments only
                $result = $installmentService->getActiveContracts($userId);
                Response::success([
                    'items' => $result,
                    'count' => count($result)
                ]);
                break;
                
            case 'preview':
                // Calculate payment plan preview (no auth needed)
                $totalAmount = floatval($_GET['amount'] ?? 0);
                $months = intval($_GET['months'] ?? 3);
                $interestRate = floatval($_GET['rate'] ?? 0);
                
                $result = $installmentService->calculatePlanPreview($totalAmount, $months, $interestRate);
                Response::success($result);
                break;
                
            default:
                // Get installments list or detail
                $contractId = $_GET['id'] ?? null;
                if ($contractId) {
                    // Get specific contract
                    $contract = getContractDetail($db, $contractId, $userId);
                    if ($contract) {
                        Response::success($contract);
                    } else {
                        Response::error('Installment contract not found', 404);
                    }
                } else {
                    // List all installments
                    $contracts = $installmentService->getActiveContracts($userId);
                    Response::success([
                        'items' => $contracts,
                        'count' => count($contracts)
                    ]);
                }
        }
        
    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        
        switch ($action) {
            case 'pay':
                // Submit payment
                $contractId = $body['contract_id'] ?? null;
                $paymentId = $body['payment_id'] ?? null;
                $installmentNumber = $body['installment_number'] ?? null;
                
                if (!$contractId) {
                    Response::error('contract_id required', 400);
                }
                
                $result = $installmentService->linkPaymentToInstallment(
                    $contractId, 
                    $paymentId, 
                    $installmentNumber
                );
                
                if ($result['success']) {
                    Response::success($result);
                } else {
                    Response::error($result['error'] ?? 'Payment failed', 400);
                }
                break;
                
            default:
                Response::error('Invalid action. Use: pay', 400);
        }
        
    } else {
        Response::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    error_log("Installment API Error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}

/**
 * Get contract detail with payments
 */
function getContractDetail(PDO $db, int $contractId, int $userId): ?array
{
    $stmt = $db->prepare("
        SELECT ic.*, o.order_no
        FROM installment_contracts ic
        JOIN orders o ON ic.order_id = o.id
        WHERE ic.id = ? AND ic.user_id = ?
    ");
    $stmt->execute([$contractId, $userId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) return null;
    
    // Get installment payments
    $stmt = $db->prepare("
        SELECT ip.*, p.amount as payment_amount, p.payment_date, p.status as payment_status
        FROM installment_payments ip
        LEFT JOIN payments p ON ip.payment_id = p.id
        WHERE ip.contract_id = ?
        ORDER BY ip.installment_number
    ");
    $stmt->execute([$contractId]);
    $contract['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate remaining
    $paidCount = count(array_filter($contract['payments'], fn($p) => $p['payment_status'] === 'verified'));
    $contract['remaining_installments'] = $contract['total_installments'] - $paidCount;
    
    return $contract;
}
