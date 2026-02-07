<?php
/**
 * Pawn API v2 (ฝากจำนำ) - Uses PawnService
 * 
 * REST Endpoints:
 * GET  /api/v2/pawns                    - Get all pawns for customer
 * GET  /api/v2/pawns?id=X               - Get specific pawn detail  
 * GET  /api/v2/pawns/eligible           - Get items eligible for pawning
 * GET  /api/v2/pawns/preview            - Calculate interest preview
 * POST /api/v2/pawns                    - Create new pawn
 * POST /api/v2/pawns/interest           - Submit interest payment
 * POST /api/v2/pawns/redeem             - Submit redemption payment
 * 
 * @version 2.0
 * @date 2026-01-31
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/services/PawnService.php';

use App\Services\PawnService;

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
    $pawnService = new PawnService($db);
    
    // Route based on method and action
    if ($method === 'GET') {
        switch ($action) {
            case 'eligible':
                // Get eligible items for pawning (always use userId)
                $result = $pawnService->getEligibleItems($userId);
                Response::success($result);
                break;
                
            case 'preview':
                // Calculate interest preview (no auth needed)
                // Params: appraised_value (required), loan_percentage (default 65), interest_rate (default 2)
                $appraisedValue = floatval($_GET['appraised_value'] ?? $_GET['principal'] ?? 0);
                $loanPercentage = floatval($_GET['loan_percentage'] ?? PawnService::DEFAULT_LOAN_PERCENTAGE);
                $interestRate = floatval($_GET['interest_rate'] ?? PawnService::DEFAULT_INTEREST_RATE);
                $result = $pawnService->calculateInterestPreview($appraisedValue, $loanPercentage, $interestRate);
                Response::success($result);
                break;
                
            default:
                // Get pawns list or detail
                $pawnId = $_GET['id'] ?? null;
                if ($pawnId) {
                    // Get specific pawn
                    $pawn = getPawnDetail($db, $pawnId, $userId);
                    if ($pawn) {
                        Response::success($pawn);
                    } else {
                        Response::error('Pawn not found', 404);
                    }
                } else {
                    // List all pawns
                    $pawns = $pawnService->findActivePawns("user:{$userId}");
                    Response::success([
                        'items' => $pawns,
                        'count' => count($pawns)
                    ]);
                }
        }
        
    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        
        switch ($action) {
            case 'interest':
                // Submit interest payment
                $result = submitInterestPayment($db, $userId, $body);
                if ($result['success']) {
                    Response::success($result);
                } else {
                    Response::error($result['error'] ?? 'Payment failed', 400);
                }
                break;
                
            case 'redeem':
                // Submit redemption payment
                $result = submitRedemption($db, $userId, $body);
                if ($result['success']) {
                    Response::success($result);
                } else {
                    Response::error($result['error'] ?? 'Redemption failed', 400);
                }
                break;
                
            default:
                // Create new pawn
                $orderId = $body['order_id'] ?? null;
                $orderItemId = $body['order_item_id'] ?? null;
                $loanPercentage = $body['loan_percentage'] ?? PawnService::DEFAULT_LOAN_PERCENTAGE;
                $bankAccountId = $body['bank_account_id'] ?? null;
                
                if (!$orderId || !$orderItemId) {
                    Response::error('order_id and order_item_id required', 400);
                }
                
                $result = $pawnService->createPawn(
                    $orderId,
                    $orderItemId,
                    $userId,
                    $loanPercentage,
                    $bankAccountId
                );
                
                if ($result['success']) {
                    http_response_code(201);
                    Response::success($result);
                } else {
                    Response::error($result['error'], 400);
                }
        }
        
    } else {
        Response::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    error_log("Pawn API Error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}

/**
 * Get pawn detail with full info
 */
function getPawnDetail(PDO $db, int $pawnId, int $userId): ?array
{
    $stmt = $db->prepare("
        SELECT p.*, 
               oi.product_name, oi.variant_info,
               o.order_no
        FROM pawns p
        JOIN order_items oi ON p.order_item_id = oi.id
        JOIN orders o ON p.order_id = o.id
        WHERE p.id = ? AND p.user_id = ?
    ");
    $stmt->execute([$pawnId, $userId]);
    $pawn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pawn) return null;
    
    // Get payment history
    $stmt = $db->prepare("
        SELECT pp.*, p.amount as payment_amount, p.payment_date
        FROM pawn_payments pp
        LEFT JOIN payments p ON pp.payment_id = p.id
        WHERE pp.pawn_id = ?
        ORDER BY pp.created_at DESC
    ");
    $stmt->execute([$pawnId]);
    $pawn['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $pawn;
}

/**
 * Submit interest payment
 */
function submitInterestPayment(PDO $db, int $userId, array $body): array
{
    $pawnId = $body['pawn_id'] ?? null;
    $paymentId = $body['payment_id'] ?? null;
    
    if (!$pawnId) {
        return ['success' => false, 'error' => 'pawn_id required'];
    }
    
    $pawnService = new PawnService($db);
    return $pawnService->linkPaymentToPawn($pawnId, $paymentId, 'interest');
}

/**
 * Submit redemption payment
 */
function submitRedemption(PDO $db, int $userId, array $body): array
{
    $pawnId = $body['pawn_id'] ?? null;
    $paymentId = $body['payment_id'] ?? null;
    
    if (!$pawnId) {
        return ['success' => false, 'error' => 'pawn_id required'];
    }
    
    $pawnService = new PawnService($db);
    return $pawnService->linkPaymentToPawn($pawnId, $paymentId, 'redemption');
}
