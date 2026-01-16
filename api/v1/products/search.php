<?php
/**
 * Mock Product Search API
 * 
 * POST /api/v1/products/search
 * 
 * This is a MOCK implementation for testing purposes.
 * Will be replaced when Data team provides the real API.
 * 
 * @date 2026-01-16
 * @version 1.0 (mock)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Only POST allowed']]);
    exit;
}

// Parse request body
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$keyword = trim($input['keyword'] ?? '');
$productCode = trim($input['product_code'] ?? '');
$refIds = $input['ref_ids'] ?? [];
$filters = $input['filters'] ?? [];
$sort = $input['sort'] ?? ['by' => 'relevance', 'order' => 'desc'];
$page = $input['page'] ?? ['limit' => 20];
$limit = (int) ($page['limit'] ?? 20);

// Validate: at least one search criteria required
if (empty($keyword) && empty($productCode) && empty($refIds)) {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'code' => 'INVALID_REQUEST',
            'message' => 'keyword, product_code, or ref_ids is required',
            'details' => []
        ]
    ]);
    exit;
}

// =====================================================
// MOCK PRODUCT DATA - ร้าน ฮ.เฮง เฮง
// Categories: watch, ring, necklace, bracelet, earring, pendant, brooch, amulet, set, keychain
// =====================================================
$mockProducts = [
    // =====================================================
    // WATCHES (นาฬิกา)
    // =====================================================
    [
        'ref_id' => 'P-2026-000001',
        'product_code' => 'ROL-DAY-001',
        'title' => 'Rolex Day-Date 36mm Yellow Gold',
        'brand' => 'Rolex',
        'category' => 'watch',
        'description' => 'นาฬิกา Rolex Day-Date ทองคำแท้ 18K สภาพสวย 95% อุปกรณ์ครบกล่อง ใบเซอร์',
        'price' => 850000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1547996160-81dfa63595aa?w=400',
        'image_count' => 6,
        'updated_at' => '2026-01-15T10:30:00Z',
        'attributes' => ['color' => 'gold', 'material' => '18k gold', 'size' => '36mm', 'condition' => '95%']
    ],
    [
        'ref_id' => 'P-2026-000002',
        'product_code' => 'ROL-SUB-002',
        'title' => 'Rolex Submariner Date Black Dial',
        'brand' => 'Rolex',
        'category' => 'watch',
        'description' => 'Rolex Submariner หน้าปัดดำ สายเหล็ก สภาพสวย 90% กล่องใบ',
        'price' => 420000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1523170335258-f5ed11844a49?w=400',
        'image_count' => 5,
        'updated_at' => '2026-01-14T14:20:00Z',
        'attributes' => ['color' => 'black', 'material' => 'stainless steel', 'size' => '40mm', 'condition' => '90%']
    ],
    [
        'ref_id' => 'P-2026-000003',
        'product_code' => 'TAG-CAR-001',
        'title' => 'Tag Heuer Carrera Chronograph',
        'brand' => 'Tag Heuer',
        'category' => 'watch',
        'description' => 'Tag Heuer Carrera Chronograph สายหนังน้ำตาล สภาพ 85%',
        'price' => 89000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1524592094714-0f0654e20314?w=400',
        'image_count' => 4,
        'updated_at' => '2026-01-13T09:15:00Z',
        'attributes' => ['color' => 'brown', 'material' => 'leather strap', 'size' => '42mm', 'condition' => '85%']
    ],
    [
        'ref_id' => 'P-2026-000004',
        'product_code' => 'OMG-SEA-001',
        'title' => 'Omega Seamaster Planet Ocean 600M',
        'brand' => 'Omega',
        'category' => 'watch',
        'description' => 'Omega Seamaster Planet Ocean สายยาง สภาพใหม่ 98%',
        'price' => 195000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1548171915-e79a380a2a4b?w=400',
        'image_count' => 6,
        'updated_at' => '2026-01-12T16:45:00Z',
        'attributes' => ['color' => 'blue', 'material' => 'rubber strap', 'size' => '43.5mm', 'condition' => '98%']
    ],
    [
        'ref_id' => 'P-2026-000005',
        'product_code' => 'OMG-SPM-001',
        'title' => 'Omega Speedmaster Moonwatch',
        'brand' => 'Omega',
        'category' => 'watch',
        'description' => 'Omega Speedmaster รุ่น Moonwatch สายเหล็ก สภาพ 92%',
        'price' => 185000,
        'currency' => 'THB',
        'availability' => 'reserved',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1522312346375-d1a52e2b99b3?w=400',
        'image_count' => 5,
        'updated_at' => '2026-01-11T11:00:00Z',
        'attributes' => ['color' => 'black', 'material' => 'stainless steel', 'size' => '42mm', 'condition' => '92%']
    ],

    // =====================================================
    // RINGS (แหวน)
    // =====================================================
    [
        'ref_id' => 'P-2026-000010',
        'product_code' => 'DIA-RNG-001',
        'title' => 'แหวนเพชรแท้ 1 กะรัต ทองขาว',
        'brand' => 'เพชรวิบวับ',
        'category' => 'ring',
        'description' => 'แหวนเพชรแท้ น้ำหนัก 1.05 ct น้ำ D VVS1 ตัวเรือนทองขาว 18K',
        'price' => 289000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1605100804763-247f67b3557e?w=400',
        'image_count' => 8,
        'updated_at' => '2026-01-09T10:00:00Z',
        'attributes' => ['color' => 'white gold', 'material' => '18k white gold', 'carat' => '1.05ct', 'clarity' => 'VVS1']
    ],
    [
        'ref_id' => 'P-2026-000011',
        'product_code' => 'DIA-RNG-002',
        'title' => 'แหวนเพชรแถว 0.5 กะรัต ทองคำ',
        'brand' => 'เพชรวิบวับ',
        'category' => 'ring',
        'description' => 'แหวนเพชรแถว 7 เม็ด รวม 0.5 ct ตัวเรือนทองคำ 18K',
        'price' => 45000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1603561596112-0a132b757442?w=400',
        'image_count' => 5,
        'updated_at' => '2026-01-08T14:30:00Z',
        'attributes' => ['color' => 'yellow gold', 'material' => '18k gold', 'carat' => '0.5ct']
    ],
    [
        'ref_id' => 'P-2026-000012',
        'product_code' => 'GLD-RNG-001',
        'title' => 'แหวนทองคำ 96.5% ลายดอกไม้',
        'brand' => 'Thai Gold',
        'category' => 'ring',
        'description' => 'แหวนทองคำแท้ 96.5% น้ำหนัก 1 สลึง ลายดอกไม้คลาสสิก',
        'price' => 9500,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1515377905703-c4788e51af15?w=400',
        'image_count' => 4,
        'updated_at' => '2026-01-07T09:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => '96.5% gold', 'weight' => '1 สลึง']
    ],
    [
        'ref_id' => 'P-2026-000013',
        'product_code' => 'CAR-RNG-001',
        'title' => 'Cartier Love Ring White Gold',
        'brand' => 'Cartier',
        'category' => 'ring',
        'description' => 'แหวน Cartier Love ทองขาว 18K ไซส์ 52 สภาพ 95%',
        'price' => 75000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1602751584552-8ba73aad10e1?w=400',
        'image_count' => 6,
        'updated_at' => '2026-01-06T11:30:00Z',
        'attributes' => ['color' => 'white gold', 'material' => '18k white gold', 'size' => '52', 'condition' => '95%']
    ],

    // =====================================================
    // NECKLACES (สร้อยคอ)
    // =====================================================
    [
        'ref_id' => 'P-2026-000020',
        'product_code' => 'DIA-NCK-001',
        'title' => 'สร้อยคอเพชร Tennis Necklace',
        'brand' => 'เพชรวิบวับ',
        'category' => 'necklace',
        'description' => 'สร้อยคอเพชรแท้ รวม 5 กะรัต ตัวเรือนทองขาว 18K ความยาว 16 นิ้ว',
        'price' => 450000,
        'currency' => 'THB',
        'availability' => 'reserved',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?w=400',
        'image_count' => 5,
        'updated_at' => '2026-01-08T14:30:00Z',
        'attributes' => ['color' => 'white gold', 'material' => '18k white gold', 'total_carat' => '5ct', 'length' => '16 inch']
    ],
    [
        'ref_id' => 'P-2026-000021',
        'product_code' => 'GLD-NCK-001',
        'title' => 'สร้อยคอทองคำ 96.5% ลายสี่เสา',
        'brand' => 'Thai Gold',
        'category' => 'necklace',
        'description' => 'สร้อยคอทองคำแท้ 96.5% น้ำหนัก 2 บาท ลายสี่เสา ความยาว 20 นิ้ว',
        'price' => 68000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=400',
        'image_count' => 4,
        'updated_at' => '2026-01-05T10:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => '96.5% gold', 'weight' => '2 บาท', 'length' => '20 inch']
    ],
    [
        'ref_id' => 'P-2026-000022',
        'product_code' => 'GLD-NCK-002',
        'title' => 'สร้อยคอทองคำ ลายโซ่ 1 บาท',
        'brand' => 'Thai Gold',
        'category' => 'necklace',
        'description' => 'สร้อยคอทองคำ 96.5% น้ำหนัก 1 บาท ลายโซ่คลาสสิก',
        'price' => 34000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1611085583191-a3b181a88401?w=400',
        'image_count' => 3,
        'updated_at' => '2026-01-04T09:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => '96.5% gold', 'weight' => '1 บาท']
    ],
    [
        'ref_id' => 'P-2026-000023',
        'product_code' => 'BVL-NCK-001',
        'title' => 'Bvlgari B.Zero1 Necklace',
        'brand' => 'Bvlgari',
        'category' => 'necklace',
        'description' => 'สร้อยคอ Bvlgari B.Zero1 ทองคำ 18K สภาพ 90%',
        'price' => 125000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1602751584552-8ba73aad10e1?w=400',
        'image_count' => 5,
        'updated_at' => '2026-01-03T15:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => '18k gold', 'condition' => '90%']
    ],

    // =====================================================
    // BRACELETS (กำไล)
    // =====================================================
    [
        'ref_id' => 'P-2026-000030',
        'product_code' => 'GLD-BRC-001',
        'title' => 'กำไลทองคำแท้ 96.5% ลายโซ่',
        'brand' => 'Thai Gold',
        'category' => 'bracelet',
        'description' => 'กำไลทองคำแท้ 96.5% น้ำหนัก 2 บาท ลายโซ่คลาสสิก',
        'price' => 68000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?w=400',
        'image_count' => 4,
        'updated_at' => '2026-01-07T09:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => '96.5% gold', 'weight' => '2 บาท']
    ],
    [
        'ref_id' => 'P-2026-000031',
        'product_code' => 'CAR-BRC-001',
        'title' => 'Cartier Love Bracelet Yellow Gold',
        'brand' => 'Cartier',
        'category' => 'bracelet',
        'description' => 'กำไล Cartier Love ทองคำ 18K ไซส์ 17 สภาพ 92%',
        'price' => 195000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1573408301185-9146fe634ad0?w=400',
        'image_count' => 6,
        'updated_at' => '2026-01-02T14:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => '18k gold', 'size' => '17', 'condition' => '92%']
    ],
    [
        'ref_id' => 'P-2026-000032',
        'product_code' => 'DIA-BRC-001',
        'title' => 'กำไลเพชร Tennis Bracelet',
        'brand' => 'เพชรวิบวับ',
        'category' => 'bracelet',
        'description' => 'กำไลเพชรแท้ รวม 3 กะรัต ตัวเรือนทองขาว 18K',
        'price' => 280000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?w=400',
        'image_count' => 5,
        'updated_at' => '2026-01-01T10:00:00Z',
        'attributes' => ['color' => 'white gold', 'material' => '18k white gold', 'total_carat' => '3ct']
    ],

    // =====================================================
    // EARRINGS (ต่างหู)
    // =====================================================
    [
        'ref_id' => 'P-2026-000040',
        'product_code' => 'DIA-EAR-001',
        'title' => 'ต่างหูเพชรแท้ 0.5 กะรัต',
        'brand' => 'เพชรวิบวับ',
        'category' => 'earring',
        'description' => 'ต่างหูเพชรแท้ คู่ละ 0.5 ct ตัวเรือนทองขาว 18K',
        'price' => 85000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1535632066927-ab7c9ab60908?w=400',
        'image_count' => 5,
        'updated_at' => '2025-12-28T11:00:00Z',
        'attributes' => ['color' => 'white gold', 'material' => '18k white gold', 'carat' => '0.5ct']
    ],
    [
        'ref_id' => 'P-2026-000041',
        'product_code' => 'GLD-EAR-001',
        'title' => 'ต่างหูทองคำ 96.5% ลายดอกไม้',
        'brand' => 'Thai Gold',
        'category' => 'earring',
        'description' => 'ต่างหูทองคำแท้ 96.5% ลายดอกไม้ น้ำหนัก 1 สลึง',
        'price' => 9500,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1617038260897-41a1f14a8ca0?w=400',
        'image_count' => 4,
        'updated_at' => '2025-12-27T09:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => '96.5% gold', 'weight' => '1 สลึง']
    ],
    [
        'ref_id' => 'P-2026-000042',
        'product_code' => 'CHN-EAR-001',
        'title' => 'Chanel CC Earrings Gold',
        'brand' => 'Chanel',
        'category' => 'earring',
        'description' => 'ต่างหู Chanel CC สีทอง สภาพ 90%',
        'price' => 28000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1535632066927-ab7c9ab60908?w=400',
        'image_count' => 4,
        'updated_at' => '2025-12-26T14:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => 'gold plated', 'condition' => '90%']
    ],

    // =====================================================
    // PENDANTS (จี้)
    // =====================================================
    [
        'ref_id' => 'P-2026-000050',
        'product_code' => 'DIA-PDT-001',
        'title' => 'จี้เพชรแท้ 0.3 กะรัต หัวใจ',
        'brand' => 'เพชรวิบวับ',
        'category' => 'pendant',
        'description' => 'จี้เพชรแท้ รูปหัวใจ 0.3 ct ตัวเรือนทองขาว 18K',
        'price' => 35000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=400',
        'image_count' => 4,
        'updated_at' => '2025-12-25T10:00:00Z',
        'attributes' => ['color' => 'white gold', 'material' => '18k white gold', 'carat' => '0.3ct', 'shape' => 'heart']
    ],
    [
        'ref_id' => 'P-2026-000051',
        'product_code' => 'GLD-PDT-001',
        'title' => 'จี้พระพุทธชินราช ทองคำ',
        'brand' => 'Thai Gold',
        'category' => 'pendant',
        'description' => 'จี้พระพุทธชินราช ทองคำ 75% กรอบทอง 96.5%',
        'price' => 25000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=400',
        'image_count' => 5,
        'updated_at' => '2025-12-24T09:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => '75% gold, 96.5% frame']
    ],

    // =====================================================
    // BROOCHES (เข็มกลัด)
    // =====================================================
    [
        'ref_id' => 'P-2026-000060',
        'product_code' => 'CHN-BRO-001',
        'title' => 'Chanel Camellia Brooch',
        'brand' => 'Chanel',
        'category' => 'brooch',
        'description' => 'เข็มกลัด Chanel รูปดอกคามิเลีย สภาพ 88%',
        'price' => 32000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?w=400',
        'image_count' => 4,
        'updated_at' => '2025-12-23T11:00:00Z',
        'attributes' => ['color' => 'white', 'material' => 'metal, fabric', 'condition' => '88%']
    ],
    [
        'ref_id' => 'P-2026-000061',
        'product_code' => 'DIA-BRO-001',
        'title' => 'เข็มกลัดเพชร ผีเสื้อ',
        'brand' => 'เพชรวิบวับ',
        'category' => 'brooch',
        'description' => 'เข็มกลัดเพชรแท้ รูปผีเสื้อ รวม 1 กะรัต ทองขาว 18K',
        'price' => 95000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=400',
        'image_count' => 5,
        'updated_at' => '2025-12-22T14:00:00Z',
        'attributes' => ['color' => 'white gold', 'material' => '18k white gold', 'total_carat' => '1ct']
    ],

    // =====================================================
    // AMULETS (พระเครื่อง)
    // =====================================================
    [
        'ref_id' => 'P-2026-000070',
        'product_code' => 'AMU-LP-001',
        'title' => 'พระหลวงพ่อโต วัดบางพลีใหญ่',
        'brand' => 'พระเครื่อง',
        'category' => 'amulet',
        'description' => 'พระหลวงพ่อโต วัดบางพลีใหญ่ใน รุ่นแรก พ.ศ.2505 กรอบทองคำ',
        'price' => 55000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=400',
        'image_count' => 6,
        'updated_at' => '2025-12-21T10:00:00Z',
        'attributes' => ['year' => '2505', 'temple' => 'วัดบางพลีใหญ่ใน', 'frame' => 'ทองคำ 75%']
    ],
    [
        'ref_id' => 'P-2026-000071',
        'product_code' => 'AMU-SM-001',
        'title' => 'พระสมเด็จวัดระฆัง พิมพ์ใหญ่',
        'brand' => 'พระเครื่อง',
        'category' => 'amulet',
        'description' => 'พระสมเด็จวัดระฆัง สมเด็จพระพุฒาจารย์โต พิมพ์ใหญ่ กรอบทอง',
        'price' => 120000,
        'currency' => 'THB',
        'availability' => 'reserved',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=400',
        'image_count' => 8,
        'updated_at' => '2025-12-20T09:00:00Z',
        'attributes' => ['temple' => 'วัดระฆัง', 'frame' => 'ทองคำแท้']
    ],

    // =====================================================
    // JEWELRY SETS (ชุดเครื่องประดับ)
    // =====================================================
    [
        'ref_id' => 'P-2026-000080',
        'product_code' => 'SET-DIA-001',
        'title' => 'ชุดเครื่องประดับเพชร สร้อย+ต่างหู+แหวน',
        'brand' => 'เพชรวิบวับ',
        'category' => 'set',
        'description' => 'ชุดเครื่องประดับเพชรแท้ รวม 3 กะรัต ทองขาว 18K ประกอบด้วย สร้อยคอ ต่างหู และแหวน',
        'price' => 385000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?w=400',
        'image_count' => 10,
        'updated_at' => '2025-12-19T14:00:00Z',
        'attributes' => ['color' => 'white gold', 'material' => '18k white gold', 'total_carat' => '3ct', 'pieces' => '3 ชิ้น']
    ],
    [
        'ref_id' => 'P-2026-000081',
        'product_code' => 'SET-GLD-001',
        'title' => 'ชุดทองคำ สร้อย+กำไล รวม 3 บาท',
        'brand' => 'Thai Gold',
        'category' => 'set',
        'description' => 'ชุดทองคำ 96.5% สร้อยคอ 2 บาท + กำไล 1 บาท',
        'price' => 102000,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=400',
        'image_count' => 6,
        'updated_at' => '2025-12-18T10:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => '96.5% gold', 'total_weight' => '3 บาท', 'pieces' => '2 ชิ้น']
    ],

    // =====================================================
    // KEYCHAINS (พวงกุญแจ)
    // =====================================================
    [
        'ref_id' => 'P-2026-000090',
        'product_code' => 'LV-KEY-001',
        'title' => 'Louis Vuitton Monogram Keychain',
        'brand' => 'Louis Vuitton',
        'category' => 'keychain',
        'description' => 'พวงกุญแจ LV ลาย Monogram สภาพ 85%',
        'price' => 12500,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1590874103328-eac38a683ce7?w=400',
        'image_count' => 4,
        'updated_at' => '2025-12-17T11:00:00Z',
        'attributes' => ['color' => 'brown', 'material' => 'canvas, leather', 'condition' => '85%']
    ],
    [
        'ref_id' => 'P-2026-000091',
        'product_code' => 'GUC-KEY-001',
        'title' => 'Gucci GG Keychain',
        'brand' => 'GUCCI',
        'category' => 'keychain',
        'description' => 'พวงกุญแจ Gucci ลาย GG สีทอง สภาพ 90%',
        'price' => 9800,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=400',
        'image_count' => 3,
        'updated_at' => '2025-12-16T09:00:00Z',
        'attributes' => ['color' => 'gold', 'material' => 'metal', 'condition' => '90%']
    ],

    // =====================================================
    // BAGS (กระเป๋า) - BONUS
    // =====================================================
    [
        'ref_id' => 'P-2026-000100',
        'product_code' => 'GUC-MAR-001',
        'title' => 'GUCCI Marmont Mini Bag Black',
        'brand' => 'GUCCI',
        'category' => 'bag',
        'description' => 'กระเป๋า GUCCI Marmont Mini หนังแท้สีดำ สภาพสวย 90%',
        'price' => 45900,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=400',
        'image_count' => 5,
        'updated_at' => '2026-01-11T11:30:00Z',
        'attributes' => ['color' => 'black', 'material' => 'leather', 'size' => 'mini', 'condition' => '90%']
    ],
    [
        'ref_id' => 'P-2026-000101',
        'product_code' => 'LV-NVF-001',
        'title' => 'Louis Vuitton Neverfull MM Damier',
        'brand' => 'Louis Vuitton',
        'category' => 'bag',
        'description' => 'กระเป๋า LV Neverfull MM ลาย Damier สภาพ 85% ใช้งานได้ปกติ',
        'price' => 38500,
        'currency' => 'THB',
        'availability' => 'in_stock',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1590874103328-eac38a683ce7?w=400',
        'image_count' => 4,
        'updated_at' => '2026-01-10T13:00:00Z',
        'attributes' => ['color' => 'brown', 'material' => 'canvas', 'size' => 'MM', 'condition' => '85%']
    ],
    [
        'ref_id' => 'P-2026-000102',
        'product_code' => 'HRM-BKN-001',
        'title' => 'Hermes Birkin 25 Togo Black GHW',
        'brand' => 'Hermes',
        'category' => 'bag',
        'description' => 'กระเป๋า Hermes Birkin 25 หนัง Togo สีดำ อะไหล่ทอง สภาพ 92%',
        'price' => 680000,
        'currency' => 'THB',
        'availability' => 'sold',
        'thumbnail_url' => 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=400',
        'image_count' => 10,
        'updated_at' => '2026-01-06T15:00:00Z',
        'attributes' => ['color' => 'black', 'material' => 'Togo leather', 'size' => '25cm', 'hardware' => 'gold', 'condition' => '92%']
    ],
];

// =====================================================
// SEARCH LOGIC
// =====================================================
$results = [];

foreach ($mockProducts as $product) {
    $match = false;

    // Match by ref_ids
    if (!empty($refIds)) {
        if (in_array($product['ref_id'], $refIds, true)) {
            $match = true;
        }
    }

    // Match by product_code
    if (!empty($productCode)) {
        if (
            stripos($product['product_code'], $productCode) !== false ||
            stripos($product['ref_id'], $productCode) !== false
        ) {
            $match = true;
        }
    }

    // Match by keyword
    if (!empty($keyword)) {
        $searchText = strtolower($product['title'] . ' ' . $product['brand'] . ' ' . $product['description'] . ' ' . $product['product_code']);
        $keywordLower = strtolower($keyword);

        // Check if any word in keyword appears in searchText
        $words = explode(' ', $keywordLower);
        foreach ($words as $word) {
            if (strlen($word) >= 2 && strpos($searchText, $word) !== false) {
                $match = true;
                break;
            }
        }
    }

    if (!$match)
        continue;

    // Apply filters
    if (!empty($filters['brand'])) {
        $brandMatch = false;
        foreach ($filters['brand'] as $brand) {
            if (stripos($product['brand'], $brand) !== false) {
                $brandMatch = true;
                break;
            }
        }
        if (!$brandMatch)
            continue;
    }

    if (!empty($filters['availability'])) {
        if (!in_array($product['availability'], $filters['availability'])) {
            continue;
        }
    }

    if (isset($filters['price_min']) && $filters['price_min'] > 0) {
        if ($product['price'] < $filters['price_min'])
            continue;
    }

    if (isset($filters['price_max']) && $filters['price_max'] > 0) {
        if ($product['price'] > $filters['price_max'])
            continue;
    }

    $results[] = $product;
}

// Apply sorting
$sortBy = $sort['by'] ?? 'relevance';
$sortOrder = $sort['order'] ?? 'desc';

if ($sortBy === 'price') {
    usort($results, function ($a, $b) use ($sortOrder) {
        return $sortOrder === 'asc' ? $a['price'] - $b['price'] : $b['price'] - $a['price'];
    });
} elseif ($sortBy === 'updated_at') {
    usort($results, function ($a, $b) use ($sortOrder) {
        $aTime = strtotime($a['updated_at']);
        $bTime = strtotime($b['updated_at']);
        return $sortOrder === 'asc' ? $aTime - $bTime : $bTime - $aTime;
    });
}

// Apply limit
$results = array_slice($results, 0, $limit);

// Return response
echo json_encode([
    'data' => $results,
    'page' => [
        'limit' => $limit
    ],
    '_mock' => true,
    '_note' => 'This is mock data. Will be replaced by Data team API.'
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
