<?php
/**
 * Address API v2 (ที่อยู่) - Uses AddressService
 * 
 * REST Endpoints:
 * GET  /api/v2/addresses                 - Get all addresses for customer
 * GET  /api/v2/addresses?id=X            - Get specific address
 * POST /api/v2/addresses                 - Add new address
 * POST /api/v2/addresses/parse           - Parse address from text
 * PUT  /api/v2/addresses?id=X            - Update address
 * DELETE /api/v2/addresses?id=X          - Delete address
 * 
 * @version 2.0
 * @date 2026-01-31
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/services/AddressService.php';

use App\Services\AddressService;

// Parse path info
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', $pathInfo));
$action = $pathParts[1] ?? null;

$method = $_SERVER['REQUEST_METHOD'];

// Parse endpoint is public (for chatbot)
if ($action !== 'parse') {
    Auth::require();
    $userId = Auth::id();
    $platformUserId = null; // Platform user ID from chatbot context only
}

try {
    $db = Database::getInstance()->getPdo();
    $addressService = new AddressService($db);
    
    // Route based on method and action
    if ($method === 'GET') {
        $addressId = $_GET['id'] ?? null;
        
        if ($addressId) {
            // Get specific address
            $address = getAddressById($db, (int)$addressId, $userId);
            if ($address) {
                Response::success($address);
            } else {
                Response::error('Address not found', 404);
            }
        } else {
            // List all addresses
            $addresses = $addressService->getAddresses($userId);
            Response::success([
                'items' => $addresses,
                'count' => count($addresses)
            ]);
        }
        
    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        
        switch ($action) {
            case 'parse':
                // Parse address from text (no auth needed, for chatbot)
                $text = $body['text'] ?? '';
                if (empty($text)) {
                    Response::error('text required', 400);
                }
                
                if (!$addressService->looksLikeAddress($text)) {
                    Response::success([
                        'is_address' => false,
                        'parsed' => null
                    ]);
                } else {
                    $parsed = $addressService->parseAddress($text);
                    Response::success([
                        'is_address' => true,
                        'parsed' => $parsed
                    ]);
                }
                break;
                
            default:
                // Create new address
                $result = $addressService->saveAddress($userId, $body);
                
                if ($result['success']) {
                    http_response_code(201);
                    Response::success($result);
                } else {
                    Response::error($result['error'] ?? 'Save failed', 400);
                }
        }
        
    } elseif ($method === 'PUT') {
        $addressId = $_GET['id'] ?? null;
        if (!$addressId) {
            Response::error('id required', 400);
        }
        
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        
        // Check ownership
        $address = getAddressById($db, (int)$addressId, $userId);
        if (!$address) {
            Response::error('Address not found', 404);
        }
        
        $result = updateAddress($db, (int)$addressId, $body);
        if ($result['success']) {
            Response::success($result);
        } else {
            Response::error($result['error'] ?? 'Update failed', 400);
        }
        
    } elseif ($method === 'DELETE') {
        $addressId = $_GET['id'] ?? null;
        if (!$addressId) {
            Response::error('id required', 400);
        }
        
        // Check ownership
        $address = getAddressById($db, (int)$addressId, $userId);
        if (!$address) {
            Response::error('Address not found', 404);
        }
        
        $result = deleteAddress($db, (int)$addressId);
        if ($result['success']) {
            Response::success(['deleted' => true]);
        } else {
            Response::error($result['error'] ?? 'Delete failed', 400);
        }
        
    } else {
        Response::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    error_log("Address API Error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}

/**
 * Get address by ID with ownership check
 */
function getAddressById(PDO $db, int $addressId, int $userId): ?array
{
    $stmt = $db->prepare("
        SELECT * FROM customer_addresses 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$addressId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Update address
 */
function updateAddress(PDO $db, int $addressId, array $data): array
{
    try {
        $allowedFields = ['label', 'full_name', 'phone', 'address_line', 'district', 
                          'subdistrict', 'province', 'postal_code', 'is_default'];
        
        $updates = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }
        
        $params[] = $addressId;
        $sql = "UPDATE customer_addresses SET " . implode(', ', $updates) . " WHERE id = ?";
        $db->prepare($sql)->execute($params);
        
        return ['success' => true];
        
    } catch (\Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Delete address
 */
function deleteAddress(PDO $db, int $addressId): array
{
    try {
        $stmt = $db->prepare("DELETE FROM customer_addresses WHERE id = ?");
        $stmt->execute([$addressId]);
        return ['success' => true];
    } catch (\Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
