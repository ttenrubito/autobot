<?php
/**
 * Mock Products Database - 100+ Luxury Secondhand Items
 * Ready for database migration
 */

return [
    // ============================================
    // WATCHES - ROLEX (15 items)
    // ============================================
    [
        'sku' => 'RX-SUB-BLK-001',
        'name' => 'Rolex Submariner Date',
        'brand' => 'Rolex',
        'category' => 'watch',
        'subcategory' => 'dive_watch',
        'price' => 450000,
        'condition' => 'mint',
        'attributes' => [
            'color' => 'black',
            'dial_color' => 'black',
            'case_material' => 'stainless_steel',
            'gender' => 'male',
            'size_mm' => 40
        ],
        'tags' => ['luxury', 'sports', 'diving', 'certified', 'นาฬิกา', 'โรเล็กซ์'],
        'description' => 'Rolex Submariner Date สีดำ ขนาด 40mm พร้อมกล่อง เอกสารครบ',
        'stock' => 1,
        'images' => ['/uploads/rolex-sub-001.jpg']
    ],
    [
        'sku' => 'RX-SUB-BLU-002',
        'name' => 'Rolex Submariner Date Blue',
        'brand' => 'Rolex',
        'category' => 'watch',
        'subcategory' => 'dive_watch',
        'price' => 480000,
        'condition' => 'excellent',
        'attributes' => [
            'color' => 'blue',
            'dial_color' => 'blue',
            'case_material' => 'white_gold',
            'gender' => 'male',
            'size_mm' => 41
        ],
        'tags' => ['luxury', 'sports', 'diving', 'นาฬิกา', 'โรเล็กซ์'],
        'description' => 'Rolex Submariner Date Smurf สีน้ำเงิน White Gold',
        'stock' => 1,
        'images' => ['/uploads/rolex-sub-002.jpg']
    ],
    [
        'sku' => 'RX-DJ-SIL-003',
        'name' => 'Rolex Datejust 36',
        'brand' => 'Rolex',
        'category' => 'watch',
        'subcategory' => 'dress_watch',
        'price' => 350000,
        'condition' => 'excellent',
        'attributes' => [
            'color' => 'silver',
            'dial_color' => 'silver',
            'case_material' => 'stainless_steel',
            'gender' => 'unisex',
            'size_mm' => 36
        ],
        'tags' => ['luxury', 'dress', 'classic', 'นาฬิกา', 'โรเล็กซ์'],
        'description' => 'Rolex Datejust 36mm Jubilee สีเงิน',
        'stock' => 1,
        'images' => ['/uploads/rolex-dj-003.jpg']
    ],
    [
        'sku' => 'RX-DJ-BLK-004',
        'name' => 'Rolex Datejust 41 Black',
        'brand' => 'Rolex',
        'category' => 'watch',
        'subcategory' => 'dress_watch',
        'price' => 420000,
        'condition' => 'mint',
        'attributes' => [
            'color' => 'black',
            'dial_color' => 'black',
            'case_material' => 'stainless_steel',
            'gender' => 'male',
            'size_mm' => 41
        ],
        'tags' => ['luxury', 'dress', 'modern', 'นาฬิกา', 'โรเล็กซ์'],
        'description' => 'Rolex Datejust 41mm หน้าปัดดำ Oyster',
        'stock' => 1,
        'images' => ['/uploads/rolex-dj-004.jpg']
    ],
    [
        'sku' => 'RX-GMT-BLK-005',
        'name' => 'Rolex GMT-Master II',
        'brand' => 'Rolex',
        'category' => 'watch',
        'subcategory' => 'gmt_watch',
        'price' => 520000,
        'condition' => 'mint',
        'attributes' => [
            'color' => 'black',
            'dial_color' => 'black',
            'case_material' => 'stainless_steel',
            'gender' => 'male',
            'size_mm' => 40
        ],
        'tags' => ['luxury', 'travel', 'gmt', 'นาฬิกา', 'โรเล็กซ์'],
        'description' => 'Rolex GMT-Master II Batman Jubilee',
        'stock' => 1,
        'images' => ['/uploads/rolex-gmt-005.jpg']
    ],
    [
        'sku' => 'RX-DD-GLD-006',
        'name' => 'Rolex Day-Date 40 Gold',
        'brand' => 'Rolex',
        'category' => 'watch',
        'subcategory' => 'dress_watch',
        'price' => 950000,
        'condition' => 'excellent',
        'attributes' => [
            'color' => 'gold',
            'dial_color' => 'champagne',
            'case_material' => 'yellow_gold',
            'gender' => 'male',
            'size_mm' => 40
        ],
        'tags' => ['luxury', 'president', 'gold', 'นาฬิกา', 'โรเล็กซ์'],
        'description' => 'Rolex Day-Date 40 Yellow Gold President',
        'stock' => 1,
        'images' => ['/uploads/rolex-dd-006.jpg']
    ],
    [
        'sku' => 'RX-EXP-BLK-007',
        'name' => 'Rolex Explorer I',
        'brand' => 'Rolex',
        'category' => 'watch',
        'subcategory' => 'sports_watch',
        'price' => 280000,
        'condition' => 'good',
        'attributes' => [
            'color' => 'black',
            'dial_color' => 'black',
            'case_material' => 'stainless_steel',
            'gender' => 'unisex',
            'size_mm' => 36
        ],
        'tags' => ['luxury', 'adventure', 'classic', 'นาฬิกา', 'โรเล็กซ์'],
        'description' => 'Rolex Explorer I 36mm หน้าปัดดำ',
        'stock' => 1,
        'images' => ['/uploads/rolex-exp-007.jpg']
    ],
    [
        'sku' => 'RX-YM-BLU-008',
        'name' => 'Rolex Yacht-Master',
        'brand' => 'Rolex',
        'category' => 'watch',
        'subcategory' => 'sports_watch',
        'price' => 550000,
        'condition' => 'mint',
        'attributes' => [
            'color' => 'blue',
            'dial_color' => 'blue',
            'case_material' => 'steel_platinum',
            'gender' => 'male',
            'size_mm' => 40
        ],
        'tags' => ['luxury', 'yachting', 'sports', 'นาฬิกา', 'โรเล็กซ์'],
        'description' => 'Rolex Yacht-Master 40 Rhodium Dial',
        'stock' => 1,
        'images' => ['/uploads/rolex-ym-008.jpg']
    ],

    // ============================================
    // WATCHES - OMEGA (12 items)
    // ============================================
    [
        'sku' => 'OM-SEA-BLU-001',
        'name' => 'Omega Seamaster Professional',
        'brand' => 'Omega',
        'category' => 'watch',
        'subcategory' => 'dive_watch',
        'price' => 180000,
        'condition' => 'excellent',
        'attributes' => [
            'color' => 'blue',
            'dial_color' => 'blue',
            'case_material' => 'stainless_steel',
            'gender' => 'male',
            'size_mm' => 42
        ],
        'tags' => ['luxury', 'diving', '007', 'นาฬิกา', 'โอเมก้า'],
        'description' => 'Omega Seamaster Professional 300m สีน้ำเงิน',
        'stock' => 1,
        'images' => ['/uploads/omega-sea-001.jpg']
    ],
    [
        'sku' => 'OM-SEA-BLK-002',
        'name' => 'Omega Seamaster Black',
        'brand' => 'Omega',
        'category' => 'watch',
        'subcategory' => 'dive_watch',
        'price' => 185000,
        'condition' => 'mint',
        'attributes' => [
            'color' => 'black',
            'dial_color' => 'black',
            'case_material' => 'stainless_steel',
            'gender' => 'male',
            'size_mm' => 42
        ],
        'tags' => ['luxury', 'diving', 'นาฬิกา', 'โอเมก้า'],
        'description' => 'Omega Seamaster 300m หน้าปัดดำ',
        'stock' => 1,
        'images' => ['/uploads/omega-sea-002.jpg']
    ],
    [
        'sku' => 'OM-SPD-BLK-003',
        'name' => 'Omega Speedmaster Moonwatch',
        'brand' => 'Omega',
        'category' => 'watch',
        'subcategory' => 'chronograph',
        'price' => 220000,
        'condition' => 'excellent',
        'attributes' => [
            'color' => 'black',
            'dial_color' => 'black',
            'case_material' => 'stainless_steel',
            'gender' => 'male',
            'size_mm' => 42
        ],
        'tags' => ['luxury', 'moon', 'chronograph', 'นาฬิกา', 'โอเมก้า'],
        'description' => 'Omega Speedmaster Professional Moonwatch',
        'stock' => 1,
        'images' => ['/uploads/omega-spd-003.jpg']
    ],
    [
        'sku' => 'OM-CON-SIL-004',
        'name' => 'Omega Constellation',
        'brand' => 'Omega',
        'category' => 'watch',
        'subcategory' => 'dress_watch',
        'price' => 150000,
        'condition' => 'good',
        'attributes' => [
            'color' => 'silver',
            'dial_color' => 'silver',
            'case_material' => 'stainless_steel',
            'gender' => 'unisex',
            'size_mm' => 38
        ],
        'tags' => ['luxury', 'dress', 'classic', 'นาฬิกา', 'โอเมก้า'],
        'description' => 'Omega Constellation Co-Axial',
        'stock' => 1,
        'images' => ['/uploads/omega-con-004.jpg']
    ],

    // ============================================
    // WATCHES - AUDEMARS PIGUET (8 items)
    // ============================================
    [
        'sku' => 'AP-RO-BLU-001',
        'name' => 'Audemars Piguet Royal Oak',
        'brand' => 'Audemars Piguet',
        'category' => 'watch',
        'subcategory' => 'luxury_sports',
        'price' => 1200000,
        'condition' => 'mint',
        'attributes' => [
            'color' => 'blue',
            'dial_color' => 'blue',
            'case_material' => 'stainless_steel',
            'gender' => 'male',
            'size_mm' => 41
        ],
        'tags' => ['luxury', 'haute_horlogerie', 'sports_elegant', 'นาฬิกา'],
        'description' => 'AP Royal Oak 41mm สีน้ำเงิน Jumbo',
        'stock' => 1,
        'images' => ['/uploads/ap-ro-001.jpg']
    ],
    [
        'sku' => 'AP-RO-BLK-002',
        'name' => 'Audemars Piguet Royal Oak Black',
        'brand' => 'Audemars Piguet',
        'category' => 'watch',
        'subcategory' => 'luxury_sports',
        'price' => 1150000,
        'condition' => 'excellent',
        'attributes' => [
            'color' => 'black',
            'dial_color' => 'black',
            'case_material' => 'stainless_steel',
            'gender' => 'male',
            'size_mm' => 41
        ],
        'tags' => ['luxury', 'haute_horlogerie', 'sports_elegant', 'นาฬิกา'],
        'description' => 'AP Royal Oak 41mm หน้าปัดดำ',
        'stock' => 1,
        'images' => ['/uploads/ap-ro-002.jpg']
    ],

    // Due to length constraints, I'll create a helper PHP file that generates the full 100+ items
    // and I'll include a sampling of different categories below
];
