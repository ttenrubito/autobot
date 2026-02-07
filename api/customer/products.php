<?php
/**
 * Products API - Customer Portal
 * CRUD operations for product management (V5)
 * 
 * Endpoints:
 * GET    /api/customer/products         - List products (paginated)
 * GET    /api/customer/products?id=X    - Get single product
 * POST   /api/customer/products         - Create product
 * PUT    /api/customer/products?id=X    - Update product
 * DELETE /api/customer/products?id=X    - Delete product
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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
$shop_owner_id = $user_id; // Current user is the shop owner
$tenant_id = 'default';

try {
    $pdo = getDB();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                getProduct($pdo, intval($_GET['id']), $shop_owner_id);
            } else {
                listProducts($pdo, $shop_owner_id, $tenant_id);
            }
            break;


        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            createProduct($pdo, $input, $shop_owner_id, $tenant_id);
            break;

        case 'PUT':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing product id']);
                exit;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            updateProduct($pdo, intval($id), $input, $shop_owner_id);
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing product id']);
                exit;
            }
            deleteProduct($pdo, intval($id), $shop_owner_id);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("Products API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * List products with pagination and filters
 */
function listProducts($pdo, $shop_owner_id, $tenant_id)
{
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Filters
    $search = trim($_GET['search'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $brand = trim($_GET['brand'] ?? '');
    $status = trim($_GET['status'] ?? '');

    // Check which columns exist in products table
    $columns = getProductColumns($pdo);
    $hasShopOwner = in_array('shop_owner_id', $columns);
    $hasBrand = in_array('brand', $columns);
    $hasTags = in_array('tags', $columns);

    // Build WHERE clause
    $where = [];
    $params = [];

    // Use shop_owner_id if available, otherwise use tenant_id
    if ($hasShopOwner) {
        $where[] = '(shop_owner_id = ? OR shop_owner_id IS NULL)';
        $params[] = $shop_owner_id;
    } else {
        $where[] = 'tenant_id = ?';
        $params[] = $tenant_id;
    }

    if ($search) {
        $searchConditions = ['product_name LIKE ?', 'product_code LIKE ?', 'description LIKE ?'];
        $searchTerm = '%' . $search . '%';
        $where[] = '(' . implode(' OR ', $searchConditions) . ')';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($category) {
        $where[] = 'category = ?';
        $params[] = $category;
    }

    if ($brand && $hasBrand) {
        $where[] = 'brand = ?';
        $params[] = $brand;
    }

    if ($status) {
        $where[] = 'status = ?';
        $params[] = $status;
    }

    $whereClause = count($where) > 0 ? implode(' AND ', $where) : '1=1';

    // Get total count
    $countSql = "SELECT COUNT(*) FROM products WHERE $whereClause";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    // Build SELECT columns dynamically
    $selectCols = [
        'id',
        'product_code',
        'product_name',
        'description',
        'category',
        'price',
        'sale_price',
        'image_url',
        'stock',
        'status',
        'metadata',
        'created_at',
        'updated_at'
    ];
    if ($hasBrand)
        $selectCols[] = 'brand';
    if ($hasTags)
        $selectCols[] = 'tags';

    $selectClause = implode(', ', $selectCols);

    // Get products
    $sql = "SELECT $selectClause
            FROM products 
            WHERE $whereClause
            ORDER BY created_at DESC
            LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse JSON fields and normalize
    foreach ($products as &$p) {
        $p['metadata'] = isset($p['metadata']) && $p['metadata'] ? json_decode($p['metadata'], true) : null;
        $p['tags'] = isset($p['tags']) && $p['tags'] ? json_decode($p['tags'], true) : [];
        $p['brand'] = $p['brand'] ?? null;
        $p['price'] = floatval($p['price']);
        $p['sale_price'] = $p['sale_price'] ? floatval($p['sale_price']) : null;
        $p['stock'] = intval($p['stock']);
    }

    // Get categories for filters
    $categories = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $categoryList = $categories->fetchAll(PDO::FETCH_COLUMN);

    // Get brands for filters (if column exists)
    $brandList = [];
    if ($hasBrand) {
        $brands = $pdo->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' ORDER BY brand");
        $brandList = $brands->fetchAll(PDO::FETCH_COLUMN);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'products' => $products,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => intval($total),
                'total_pages' => max(1, ceil($total / $limit))
            ],
            'filters' => [
                'categories' => $categoryList,
                'brands' => $brandList
            ],
            'schema' => [
                'has_shop_owner_id' => $hasShopOwner,
                'has_brand' => $hasBrand,
                'has_tags' => $hasTags
            ]
        ]
    ]);
}

/**
 * Get list of columns in products table
 */
function getProductColumns($pdo)
{
    static $columns = null;
    if ($columns !== null)
        return $columns;

    try {
        $stmt = $pdo->query("DESCRIBE products");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $columns = [
            'id',
            'product_code',
            'product_name',
            'description',
            'category',
            'price',
            'sale_price',
            'image_url',
            'stock',
            'status',
            'metadata',
            'created_at',
            'updated_at'
        ];
    }
    return $columns;
}


/**
 * Get single product by ID
 */
function getProduct($pdo, $id, $shop_owner_id)
{
    $columns = getProductColumns($pdo);
    $hasShopOwner = in_array('shop_owner_id', $columns);

    // Build query based on available columns
    if ($hasShopOwner) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND (shop_owner_id = ? OR shop_owner_id IS NULL)");
        $stmt->execute([$id, $shop_owner_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
    }
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        return;
    }

    // Normalize fields
    $product['metadata'] = isset($product['metadata']) && $product['metadata'] ? json_decode($product['metadata'], true) : null;
    $product['tags'] = isset($product['tags']) && $product['tags'] ? json_decode($product['tags'], true) : [];
    $product['brand'] = $product['brand'] ?? null;
    $product['price'] = floatval($product['price']);
    $product['sale_price'] = $product['sale_price'] ? floatval($product['sale_price']) : null;

    echo json_encode([
        'success' => true,
        'data' => $product
    ]);
}

/**
 * Create new product
 */
function createProduct($pdo, $input, $shop_owner_id, $tenant_id)
{
    $columns = getProductColumns($pdo);
    $hasShopOwner = in_array('shop_owner_id', $columns);
    $hasBrand = in_array('brand', $columns);
    $hasTags = in_array('tags', $columns);

    // Validate required fields
    $product_code = trim($input['product_code'] ?? '');
    $product_name = trim($input['product_name'] ?? '');
    $price = floatval($input['price'] ?? 0);

    if (!$product_code || !$product_name) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'กรุณากรอกรหัสสินค้าและชื่อสินค้า'
        ]);
        return;
    }

    // Check duplicate product_code
    if ($hasShopOwner) {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE shop_owner_id = ? AND product_code = ?");
        $stmt->execute([$shop_owner_id, $product_code]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE tenant_id = ? AND product_code = ?");
        $stmt->execute([$tenant_id, $product_code]);
    }
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'รหัสสินค้านี้มีอยู่แล้ว: ' . $product_code
        ]);
        return;
    }

    // Handle image upload if present
    $image_url = handleImageUpload($input);

    // Prepare data
    $description = trim($input['description'] ?? '') ?: null;
    $category = trim($input['category'] ?? '') ?: null;
    $sale_price = isset($input['sale_price']) && $input['sale_price'] !== '' ? floatval($input['sale_price']) : null;
    $stock = intval($input['stock'] ?? 0);
    $status = in_array($input['status'] ?? '', ['active', 'inactive', 'out_of_stock'])
        ? $input['status']
        : 'active';

    // Build dynamic INSERT based on available columns
    $insertCols = [
        'tenant_id',
        'product_code',
        'product_name',
        'description',
        'category',
        'price',
        'sale_price',
        'image_url',
        'stock',
        'status',
        'metadata'
    ];
    $insertVals = [
        $tenant_id,
        $product_code,
        $product_name,
        $description,
        $category,
        $price,
        $sale_price,
        $image_url,
        $stock,
        $status,
        isset($input['metadata']) ? json_encode($input['metadata']) : null
    ];

    if ($hasShopOwner) {
        array_unshift($insertCols, 'shop_owner_id');
        array_unshift($insertVals, $shop_owner_id);
    }
    if ($hasBrand) {
        $insertCols[] = 'brand';
        $insertVals[] = trim($input['brand'] ?? '') ?: null;
    }
    if ($hasTags) {
        $insertCols[] = 'tags';
        $insertVals[] = isset($input['tags']) ? json_encode($input['tags']) : null;
    }

    // Add timestamp columns
    $insertCols[] = 'created_at';
    $insertCols[] = 'updated_at';

    $placeholders = array_fill(0, count($insertVals), '?');
    $placeholders[] = 'NOW()';
    $placeholders[] = 'NOW()';

    $sql = "INSERT INTO products (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($insertVals);


    $product_id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'เพิ่มสินค้าเรียบร้อย',
        'data' => [
            'id' => $product_id,
            'product_code' => $product_code
        ]
    ]);
}

/**
 * Update existing product
 */
function updateProduct($pdo, $id, $input, $shop_owner_id)
{
    require_once __DIR__ . '/../../includes/Logger.php';
    
    // Check product exists and belongs to shop
    $stmt = $pdo->prepare("SELECT id, product_code FROM products WHERE id = ? AND shop_owner_id = ?");
    $stmt->execute([$id, $shop_owner_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        return;
    }

    // Check duplicate product_code if changed
    $new_code = trim($input['product_code'] ?? $existing['product_code']);
    if ($new_code !== $existing['product_code']) {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE shop_owner_id = ? AND product_code = ? AND id != ?");
        $stmt->execute([$shop_owner_id, $new_code, $id]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'รหัสสินค้านี้มีอยู่แล้ว: ' . $new_code
            ]);
            return;
        }
    }

    // Handle image upload FIRST (before building update query)
    $uploadedImageUrl = handleImageUpload($input);
    if ($uploadedImageUrl) {
        Logger::info('[Products] Image uploaded for update', ['url' => $uploadedImageUrl, 'product_id' => $id]);
        $input['image_url'] = $uploadedImageUrl;
    }

    // Build update query dynamically
    $fields = [];
    $values = [];

    $allowedFields = [
        'product_code',
        'product_name',
        'brand',
        'description',
        'category',
        'price',
        'sale_price',
        'image_url',
        'stock',
        'status'
    ];

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            $value = $input[$field];

            // Handle empty strings for nullable fields
            if (in_array($field, ['brand', 'description', 'category', 'sale_price', 'image_url'])) {
                $value = $value === '' ? null : $value;
            }

            // Handle numeric fields
            if (in_array($field, ['price', 'sale_price'])) {
                $value = $value !== null ? floatval($value) : null;
            }
            if ($field === 'stock') {
                $value = intval($value);
            }

            $values[] = $value;
        }
    }

    // Handle JSON fields
    if (isset($input['tags'])) {
        $fields[] = 'tags = ?';
        $values[] = json_encode($input['tags']);
    }
    if (isset($input['metadata'])) {
        $fields[] = 'metadata = ?';
        $values[] = json_encode($input['metadata']);
    }

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        return;
    }

    $fields[] = 'updated_at = NOW()';
    $values[] = $id;

    $sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    echo json_encode([
        'success' => true,
        'message' => 'อัปเดตสินค้าเรียบร้อย'
    ]);
}

/**
 * Delete product
 */
function deleteProduct($pdo, $id, $shop_owner_id)
{
    // Check product exists and belongs to shop
    $stmt = $pdo->prepare("SELECT id, product_name FROM products WHERE id = ? AND shop_owner_id = ?");
    $stmt->execute([$id, $shop_owner_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode([
        'success' => true,
        'message' => 'ลบสินค้าเรียบร้อย: ' . $product['product_name']
    ]);
}

/**
 * Handle image upload (from base64 or file)
 */
function handleImageUpload($input)
{
    require_once __DIR__ . '/../../includes/Logger.php';
    
    // If image_url is provided directly (not base64), use it
    if (!empty($input['image_url'])) {
        Logger::info('[Products] Using provided image URL', ['url' => substr($input['image_url'], 0, 100)]);
        return $input['image_url'];
    }

    // Handle base64 image
    if (!empty($input['image_base64'])) {
        Logger::info('[Products] Processing base64 image', ['length' => strlen($input['image_base64'])]);
        
        $base64 = $input['image_base64'];
        
        // Remove data URL prefix if present
        if (strpos($base64, 'base64,') !== false) {
            $base64 = explode('base64,', $base64)[1];
            Logger::info('[Products] Stripped data URL prefix');
        }

        $imageData = base64_decode($base64);
        if ($imageData === false) {
            Logger::error('[Products] Base64 decode failed');
            return null;
        }
        
        // Try GCS upload first (production)
        try {
            require_once __DIR__ . '/../../includes/GoogleCloudStorage.php';

            $gcs = GoogleCloudStorage::getInstance();
            $fileName = 'product_' . time() . '_' . uniqid() . '.jpg';
            Logger::info('[Products] Uploading to GCS', ['fileName' => $fileName, 'dataSize' => strlen($imageData)]);

            $result = $gcs->uploadFile(
                $imageData,
                $fileName,
                'image/jpeg',
                'products',
                ['source' => 'product_management']
            );

            if ($result['success']) {
                Logger::info('[Products] GCS upload success', ['url' => $result['url']]);
                return $result['url'];
            } else {
                Logger::error('[Products] GCS upload failed', ['error' => $result['error'] ?? 'unknown']);
            }
        } catch (Exception $e) {
            Logger::warning("[Products] GCS not available, falling back to local storage", ['error' => $e->getMessage()]);
        }
        
        // Fallback: Save to local storage for development
        $localUploadDir = __DIR__ . '/../../public/uploads/products';
        if (!is_dir($localUploadDir)) {
            mkdir($localUploadDir, 0755, true);
        }
        
        $fileName = 'product_' . time() . '_' . uniqid() . '.jpg';
        $localPath = $localUploadDir . '/' . $fileName;
        
        if (file_put_contents($localPath, $imageData)) {
            // Return relative URL for local development
            $localUrl = '/autobot/public/uploads/products/' . $fileName;
            Logger::info('[Products] Local storage fallback success', ['url' => $localUrl]);
            return $localUrl;
        }
        
        Logger::error('[Products] All image storage methods failed');
    } else {
        Logger::info('[Products] No image data provided');
    }

    return null;
}
