<?php
/**
 * Customer Addresses API
 * GET /api/customer/addresses - Get all addresses
 * GET /api/customer/addresses/{id} - Get specific address
 * POST /api/customer/addresses - Create new address
 * PUT /api/customer/addresses/{id} - Update address
 * PUT /api/customer/addresses/{id}/set-default - Set as default
 * DELETE /api/customer/addresses/{id} - Delete address
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

// Verify authentication
$auth = verifyToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $auth['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Parse URI
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
// Expected: api/customer/addresses[/{id}[/set-default]]

// ✅ FIX: Also check query string for id (for .php?id=X format)
$address_id_from_query = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;

try {
    $pdo = getDB();
    
    // Check if user wants to see all addresses (for admin/staff)
    // In customer portal, show all addresses since it's for store management
    $showAll = isset($_GET['all']) || true; // Default to show all for this portal
    
    if ($method === 'GET') {
        if (isset($uri_parts[3]) && is_numeric($uri_parts[3])) {
            // GET /api/customer/addresses/{id}
            $address_id = (int)$uri_parts[3];
            
            // For single address, allow viewing any address (admin access)
            $stmt = $pdo->prepare("
                SELECT ca.*, 
                       COALESCE(cp.display_name, cp.full_name) as customer_name,
                       cp.profile_pic_url as customer_avatar
                FROM customer_addresses ca
                LEFT JOIN customer_profiles cp ON ca.platform_user_id = cp.platform_user_id 
                    AND ca.platform = cp.platform
                WHERE ca.id = ?
            ");
            $stmt->execute([$address_id]);
            $address = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$address) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Address not found']);
                exit;
            }
            
            $address['additional_info'] = $address['additional_info'] ? 
                json_decode($address['additional_info'], true) : null;
            
            echo json_encode(['success' => true, 'data' => $address]);
            
        } else {
            // GET /api/customer/addresses - List all with pagination
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
            $offset = ($page - 1) * $limit;
            
            // Get total count - show all addresses for store management
            $countStmt = $pdo->query("SELECT COUNT(*) FROM customer_addresses");
            $total = (int)$countStmt->fetchColumn();
            
            // Get addresses with customer profile info
            $stmt = $pdo->prepare("
                SELECT ca.*, 
                       COALESCE(cp.display_name, cp.full_name) as customer_name,
                       cp.profile_pic_url as customer_avatar
                FROM customer_addresses ca
                LEFT JOIN customer_profiles cp ON ca.platform_user_id = cp.platform_user_id 
                    AND ca.platform = cp.platform
                ORDER BY ca.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($addresses as &$addr) {
                $addr['additional_info'] = $addr['additional_info'] ? 
                    json_decode($addr['additional_info'], true) : null;
                // Fallback to recipient_name if no customer profile
                if (empty($addr['customer_name']) && !empty($addr['recipient_name'])) {
                    $addr['customer_name'] = $addr['recipient_name'];
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'addresses' => $addresses,
                    'count' => count($addresses),
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'total_pages' => ceil($total / $limit)
                    ]
                ]
            ]);
        }
        
    } elseif ($method === 'POST') {
        // POST /api/customer/addresses - Create new
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['recipient_name', 'phone', 'address_line1', 'district', 'province', 'postal_code'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                exit;
            }
        }
        
        // If setting as default, unset other defaults
        if (!empty($input['is_default'])) {
            $stmt = $pdo->prepare("UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?");
            $stmt->execute([$user_id]);
        }
        
        // Prepare additional_info JSON
        $additional_info = json_encode([
            'landmark' => $input['landmark'] ?? '',
            'delivery_note' => $input['delivery_note'] ?? '',
            'gps_lat' => $input['gps_lat'] ?? '',
            'gps_lng' => $input['gps_lng'] ?? ''
        ]);
        
        $stmt = $pdo->prepare("
            INSERT INTO customer_addresses (
                customer_id, tenant_id, address_type, recipient_name, phone,
                address_line1, address_line2, subdistrict, district, province, postal_code,
                country, additional_info, is_default
            ) VALUES (?, 'default', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user_id,
            $input['address_type'] ?? 'shipping',
            $input['recipient_name'],
            $input['phone'],
            $input['address_line1'],
            $input['address_line2'] ?? '',
            $input['subdistrict'] ?? '',
            $input['district'],
            $input['province'],
            $input['postal_code'],
            $input['country'] ?? 'Thailand',
            $additional_info,
            !empty($input['is_default']) ? 1 : 0
        ]);
        
        $address_id = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Address created successfully',
            'data' => ['id' => $address_id]
        ]);
        
    } elseif ($method === 'PUT') {
        if (isset($uri_parts[3]) && is_numeric($uri_parts[3])) {
            $address_id = (int)$uri_parts[3];
            
            // Check if setting as default
            if (isset($uri_parts[4]) && $uri_parts[4] === 'set-default') {
                // PUT /api/customer/addresses/{id}/set-default
                
                // Verify address belongs to user
                $stmt = $pdo->prepare("SELECT id FROM customer_addresses WHERE id = ? AND customer_id = ?");
                $stmt->execute([$address_id, $user_id]);
                if (!$stmt->fetch()) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Address not found']);
                    exit;
                }
                
                // Unset all defaults
                $stmt = $pdo->prepare("UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?");
                $stmt->execute([$user_id]);
                
                // Set this as default
                $stmt = $pdo->prepare("UPDATE customer_addresses SET is_default = 1 WHERE id = ?");
                $stmt->execute([$address_id]);
                
                echo json_encode(['success' => true, 'message' => 'Default address updated']);
                
            } else {
                // PUT /api/customer/addresses/{id} - Update address
                $input = json_decode(file_get_contents('php://input'), true);
                
                // Verify address belongs to user
                $stmt = $pdo->prepare("SELECT id FROM customer_addresses WHERE id = ? AND customer_id = ?");
                $stmt->execute([$address_id, $user_id]);
                if (!$stmt->fetch()) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Address not found']);
                    exit;
                }
                
                // Build update query dynamically
                $updates = [];
                $params = [];
                
                $allowed_fields = [
                    'address_type', 'recipient_name', 'phone', 'address_line1', 'address_line2',
                    'subdistrict', 'district', 'province', 'postal_code', 'country'
                ];
                
                foreach ($allowed_fields as $field) {
                    if (isset($input[$field])) {
                        $updates[] = "$field = ?";
                        $params[] = $input[$field];
                    }
                }
                
                // Handle additional_info
                if (isset($input['landmark']) || isset($input['delivery_note'])) {
                    $additional_info = json_encode([
                        'landmark' => $input['landmark'] ?? '',
                        'delivery_note' => $input['delivery_note'] ?? '',
                        'gps_lat' => $input['gps_lat'] ?? '',
                        'gps_lng' => $input['gps_lng'] ?? ''
                    ]);
                    $updates[] = "additional_info = ?";
                    $params[] = $additional_info;
                }
                
                if (empty($updates)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'No fields to update']);
                    exit;
                }
                
                $params[] = $address_id;
                $sql = "UPDATE customer_addresses SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                echo json_encode(['success' => true, 'message' => 'Address updated successfully']);
            }
        }
        
    } elseif ($method === 'DELETE') {
        // ✅ FIX: Support both path-based and query string ID
        $address_id = null;
        if (isset($uri_parts[3]) && is_numeric($uri_parts[3])) {
            $address_id = (int)$uri_parts[3];
        } elseif ($address_id_from_query) {
            $address_id = $address_id_from_query;
        }
        
        if ($address_id) {
            
            // Verify address belongs to user
            $stmt = $pdo->prepare("SELECT is_default FROM customer_addresses WHERE id = ? AND customer_id = ?");
            $stmt->execute([$address_id, $user_id]);
            $address = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$address) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Address not found']);
                exit;
            }
            
            // Check if there are other addresses
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM customer_addresses WHERE customer_id = ?");
            $stmt->execute([$user_id]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($count == 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Cannot delete the only address']);
                exit;
            }
            
            // Delete address
            $stmt = $pdo->prepare("DELETE FROM customer_addresses WHERE id = ?");
            $stmt->execute([$address_id]);
            
            // If deleted address was default, set another as default
            if ($address['is_default']) {
                $stmt = $pdo->prepare("
                    UPDATE customer_addresses 
                    SET is_default = 1 
                    WHERE customer_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ");
                $stmt->execute([$user_id]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Address deleted successfully']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Address ID required']);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Addresses API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}
