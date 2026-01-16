# 📋 Autobot Implementation Spec - ร้าน ฮ.เฮงเฮง

> **Version:** 1.0  
> **Created:** 2026-01-16  
> **Status:** APPROVED FOR DEVELOPMENT

---

## 📌 ข้อตกลงเบื้องต้น

### User Model
- **1 ร้าน = 1 User** (เจ้าหน้าที่ร้าน/Admin)
- User login เข้า Customer Portal เพื่อจัดการ orders, cases, payments
- ลูกค้าปลายทาง (End Customer) ใช้งานผ่าน LINE/Facebook เท่านั้น ไม่มี login

### External Dependencies (รอทีม Data)
- `productSearch` API - ทีม Data รับผิดชอบ (ยังไม่เสร็จ)
- Bot ตอบ FAQ ได้ แต่ถ้าต้องค้นสินค้าจาก catalog จะ handoff ไป Admin ก่อน

---

## 🎯 Flow หลัก: Chatbot → Order → Push Message

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         MAIN FLOW (SIMPLIFIED)                               │
└─────────────────────────────────────────────────────────────────────────────┘

   ลูกค้า (LINE/FB)                    Admin (Portal)                  ลูกค้า
        │                                    │                            │
        │  1. ทักแชท สอบถามสินค้า            │                            │
        ├───────────────────────────────────▶│                            │
        │                                    │                            │
        │  2. Bot ตอบ FAQ / Handoff          │                            │
        │◀───────────────────────────────────┤                            │
        │                                    │                            │
        │  3. ลูกค้าสนใจซื้อ/ผ่อน/มัดจำ       │                            │
        ├───────────────────────────────────▶│                            │
        │                                    │                            │
        │                    4. Admin สร้าง Order ที่หน้าจอ orders.php     │
        │                       - ระบุ order_type (full/installment/deposit)
        │                       - ระบุ product_code, ราคา                 │
        │                       - ระบุ external_user_id (LINE/FB ID)      │
        │                       - เลือกบัญชีรับโอน                        │
        │                       - พิมพ์ข้อความแจ้งลูกค้า                   │
        │                                    │                            │
        │                    5. กด "บันทึก & ส่งข้อความ"                   │
        │                                    ├───────────────────────────▶│
        │                                    │   Push Message:            │
        │                                    │   "ขอบคุณค่ะ คุณ...        │
        │                                    │    ยอดชำระ 50,000 บาท      │
        │                                    │    บัญชี: กสิกร xxx"       │
        │                                    │                            │
        │  6. ลูกค้าโอนเงิน + ส่งสลิป         │                            │
        ├───────────────────────────────────▶│                            │
        │                                    │                            │
        │                    7. Admin ตรวจสลิป + Verify                   │
        │                                    │                            │
        │                    8. Push ยืนยันการชำระ                        │
        │                                    ├───────────────────────────▶│
        │                                    │   "ได้รับชำระเรียบร้อย     │
        │                                    │    ทางร้านจะดำเนินการ..."  │
        │                                    │                            │
        └────────────────────────────────────┴────────────────────────────┘
```

---

## ✅ งานที่ต้องทำ (Prioritized)

### 🔴 Priority 1: Core Features (ต้องทำก่อน Deploy)

| # | Task | หน้าจอ/ไฟล์ | รายละเอียด | Effort |
|---|------|------------|------------|--------|
| 1.1 | **เพิ่ม Push Message ตอนสร้าง Order** | `orders.php` | เพิ่ม textarea "ข้อความแจ้งลูกค้า" + ปุ่ม "บันทึก & ส่งข้อความ" | 4 ชม. |
| 1.2 | **เพิ่ม Bank Account Selector** | `orders.php` | Dropdown เลือกบัญชีรับโอน (static list ก่อน) | 2 ชม. |
| 1.3 | **Push Message API** | `api/customer/orders.php` | เรียก LINE/FB Push API หลัง save order | 3 ชม. |
| 1.4 | **Handoff Triggers เพิ่ม** | `RouterV1Handler.php` | Auto handoff: มัดจำ, ซื้อ, ผ่อน, ขอส่วนลด, Call/Video | 3 ชม. |
| 1.5 | **Knowledge Base อัพเดท** | `customer_channels.config` | เพิ่มเงื่อนไข เปลี่ยน/คืน, ผ่อน 3 งวด 3%, ฝากจำนำ 2% | 2 ชม. |

### 🟡 Priority 2: Enhancement (ทำหลัง Core เสร็จ)

| # | Task | หน้าจอ/ไฟล์ | รายละเอียด | Effort |
|---|------|------------|------------|--------|
| 2.1 | **Bank Accounts Management** | `admin/settings/bank-accounts.php` | CRUD บัญชีธนาคาร + monthly limit tracking | 6 ชม. |
| 2.2 | **Order Types Enhancement** | `orders.php` | รองรับ order_type: full_payment, installment, deposit | 3 ชม. |
| 2.3 | **Installment 3 งวด + 3% fee** | `installments.php` | ปรับคำนวณตามเงื่อนไขร้าน | 4 ชม. |
| 2.4 | **Shipping Method** | `orders.php` | เพิ่ม dropdown: รับหน้าร้าน / ไปรษณีย์ / Grab | 2 ชม. |

### 🟢 Priority 3: Nice to Have (ทำเมื่อมีเวลา)

| # | Task | รายละเอียด | Effort |
|---|------|------------|--------|
| 3.1 | **Auto Reminder** | แจ้งเตือนงวดผ่อน/ต่อดอก ก่อนครบกำหนด 3 วัน | 6 ชม. |
| 3.2 | **Product Search Integration** | เชื่อม API จากทีม Data (รอ API เสร็จ) | TBD |
| 3.3 | **Receipt/Invoice Generation** | สร้างใบเสร็จ PDF อัตโนมัติ | 8 ชม. |

---

## 📝 Task Details

### 1.1 เพิ่ม Push Message ตอนสร้าง Order

**ไฟล์ที่แก้:** `public/orders.php`, `api/customer/orders.php`

**UI Changes (orders.php):**
```html
<!-- เพิ่มหลังจาก form สร้าง order -->
<div class="form-group">
    <label>บัญชีรับโอน</label>
    <select name="bank_account" id="bankAccount" class="form-control">
        <option value="scb">ไทยพาณิชย์ - บจก เพชรวิบวับ - 1653014242</option>
        <option value="kbank">กสิกร - บจก.เฮงเฮงโฮลดิ้ง - 8000029282</option>
        <option value="bay">กรุงศรี - บจก.เฮงเฮงโฮลดิ้ง - 8000029282</option>
    </select>
</div>

<div class="form-group">
    <label>ข้อความแจ้งลูกค้า</label>
    <textarea name="customer_message" id="customerMessage" rows="5" class="form-control">
ขอบพระคุณค่ะ คุณ{customer_name} 
ยอดชำระ {amount} บาท
ธนาคาร: {bank_name}
ชื่อบัญชี: {account_name}
เลขบัญชี: {account_number}

หากชำระแล้วแจ้งสลิปให้แอดมินได้เลยนะคะ ขอบพระคุณค่ะ 🙏
    </textarea>
</div>

<div class="form-group">
    <label>
        <input type="checkbox" name="send_message" checked> 
        ส่งข้อความแจ้งลูกค้าทันที
    </label>
</div>

<button type="submit" class="btn btn-primary">
    <i class="fas fa-save"></i> บันทึก & ส่งข้อความ
</button>
```

**API Changes (api/customer/orders.php):**
```php
// หลัง INSERT order สำเร็จ
if (!empty($input['send_message']) && !empty($input['customer_message'])) {
    $externalUserId = $input['external_user_id'];
    $platform = $input['platform']; // 'line' or 'facebook'
    $message = $input['customer_message'];
    
    // Replace placeholders
    $message = str_replace('{customer_name}', $input['customer_name'] ?? 'ลูกค้า', $message);
    $message = str_replace('{amount}', number_format($input['total_amount']), $message);
    // ... other replacements
    
    // Call push message service
    $pushService = new PushMessageService($pdo);
    $pushService->send($platform, $externalUserId, $message, $channelId);
}
```

---

### 1.2 Bank Account Selector (Static First)

**เหตุผล:** เริ่มจาก static list ก่อน เพราะบัญชีไม่เปลี่ยนบ่อย ค่อยทำ dynamic ทีหลัง

**ไฟล์ที่สร้าง:** `config/bank_accounts.php`
```php
<?php
return [
    'scb_1' => [
        'bank_code' => 'SCB',
        'bank_name' => 'ไทยพาณิชย์',
        'account_name' => 'บจก เพชรวิบวับ',
        'account_number' => '1653014242',
        'max_per_slip' => 50000, // ≤50K ต่อสลิป
        'monthly_limit' => 300000, // 300K ต่อเดือน
        'note' => 'สำหรับยอดไม่เกิน 50,000 บาท'
    ],
    'kbank_1' => [
        'bank_code' => 'KBANK',
        'bank_name' => 'กสิกร',
        'account_name' => 'บจก.เฮงเฮงโฮลดิ้ง',
        'account_number' => '8000029282',
        'max_per_slip' => null, // ไม่จำกัด
        'monthly_limit' => null,
        'note' => 'สำหรับยอดใหญ่'
    ],
    'bay_1' => [
        'bank_code' => 'BAY',
        'bank_name' => 'กรุงศรี',
        'account_name' => 'บจก.เฮงเฮงโฮลดิ้ง',
        'account_number' => '8000029282',
        'max_per_slip' => null,
        'monthly_limit' => null,
        'note' => 'สำหรับยอดใหญ่'
    ]
];
```

---

### 1.3 Push Message Service

**ไฟล์ที่สร้าง:** `includes/services/PushMessageService.php`

```php
<?php
namespace App\Services;

class PushMessageService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Send push message to customer via LINE or Facebook
     */
    public function send(string $platform, string $externalUserId, string $message, int $channelId): array {
        // Get channel config
        $stmt = $this->pdo->prepare("SELECT config FROM customer_channels WHERE id = ?");
        $stmt->execute([$channelId]);
        $channel = $stmt->fetch();
        $config = json_decode($channel['config'], true);
        
        if ($platform === 'line') {
            return $this->sendLine($externalUserId, $message, $config);
        } elseif ($platform === 'facebook') {
            return $this->sendFacebook($externalUserId, $message, $config);
        }
        
        return ['success' => false, 'error' => 'Unknown platform'];
    }
    
    private function sendLine($userId, $message, $config): array {
        $accessToken = $config['line_channel_access_token'] ?? null;
        if (!$accessToken) {
            return ['success' => false, 'error' => 'Missing LINE access token'];
        }
        
        $payload = [
            'to' => $userId,
            'messages' => [
                ['type' => 'text', 'text' => $message]
            ]
        ];
        
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $response
        ];
    }
    
    private function sendFacebook($userId, $message, $config): array {
        $pageToken = $config['facebook_page_access_token'] ?? null;
        if (!$pageToken) {
            return ['success' => false, 'error' => 'Missing Facebook page token'];
        }
        
        $payload = [
            'recipient' => ['id' => $userId],
            'message' => ['text' => $message]
        ];
        
        $url = 'https://graph.facebook.com/v18.0/me/messages?access_token=' . $pageToken;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $response
        ];
    }
}
```

---

### 1.4 Handoff Triggers

**ไฟล์ที่แก้:** `includes/bot/RouterV1Handler.php`

**เพิ่ม intents ที่ต้อง handoff:**
```php
// ใน detectHandoffTrigger() method
$handoffTriggers = [
    // Existing
    'admin_request',
    'talk_to_human',
    
    // NEW: Business-specific triggers
    'want_to_buy',           // ลูกค้าต้องการซื้อ
    'want_to_deposit',       // ลูกค้าต้องการมัดจำ
    'want_installment',      // ลูกค้าต้องการผ่อน
    'request_discount',      // ลูกค้าขอส่วนลด
    'request_video_call',    // ลูกค้าขอ Video call ดูสินค้า
    'request_phone_call',    // ลูกค้าขอโทรคุย
    'payment_intent',        // ลูกค้าจะชำระเงิน
];

// Keywords ที่ trigger handoff
$handoffKeywords = [
    'สนใจซื้อ', 'จะซื้อ', 'เอาเลย', 'ตกลง',
    'มัดจำ', 'วางมัดจำ', 'จองไว้',
    'ผ่อน', 'ออมสินค้า', 'แบ่งจ่าย',
    'ขอลด', 'ลดได้ไหม', 'ต่อรอง',
    'ขอดูสินค้า', 'video call', 'โทรคุย',
    'จะโอน', 'โอนได้เลย', 'ขอเลขบัญชี'
];
```

---

### 1.5 Knowledge Base Updates

**ไฟล์ที่แก้:** Channel config (ใน database หรือ JSON)

**เพิ่ม FAQ entries:**
```json
{
  "knowledge_base": {
    "policies": {
      "exchange_return": {
        "diamond_exchange": "เปลี่ยนสินค้าราคาสูงกว่า หัก 10% จากยอดเดิม",
        "diamond_refund": "คืนเงินสด หัก 15% จากราคาที่ซื้อ",
        "diamond_downgrade": "เปลี่ยนราคาต่ำกว่า หัก 15% + ส่วนต่าง",
        "rolex_exchange": "เปลี่ยนตามสภาพ หัก 35% จากราคาขาย",
        "brand_others": "ขายขาดไม่รับคืน",
        "requirement": "ต้องแสดงใบรับประกันตัวจริงทุกครั้ง",
        "minimum_value": "เฉพาะงานเพชร วงเงิน 30,000+ เท่านั้น"
      },
      "installment": {
        "periods": 3,
        "processing_fee": "3%",
        "fee_payment": "ชำระพร้อมงวดแรก",
        "max_days": 60,
        "cancel_policy": "คืนเงินต้น 7 วัน ไม่คืนค่าดำเนินการ 3%",
        "no_change": "สินค้าในแผนผ่อนไม่สามารถเปลี่ยนเป็นชิ้นอื่น"
      },
      "deposit": {
        "percentage": "10% หรือตามแอดมินกำหนด",
        "duration": "2 สัปดาห์",
        "forfeit": "หลุดมัดจำถ้าไม่ชำระภายในกำหนด"
      },
      "pawn": {
        "appraisal_rate": "65-70% ของราคาฝาก",
        "interest_rate": "2% ต่อเดือน",
        "payment_cycle": "ทุก 30 วัน",
        "forfeit_warning": "2 สัปดาห์หลังเลยกำหนด"
      }
    },
    "store_info": {
      "name": "ร้าน ฮ.เฮง เฮง",
      "experience": "25 ปี",
      "location": "ซอยละลายทรัพย์ สีลม5 ตึกทรินิตี้มอล1 ห้อง 41-42",
      "hours": "จันทร์-เสาร์ 10.00-16.00 น. (หยุดอาทิตย์)",
      "phone": ["085-1965466", "085-4455516"],
      "facebook": "fb.com/henghengshoporiginal",
      "map": "https://maps.app.goo.gl/YyRLT4TS2XPjB5oo9"
    }
  }
}
```

---

## ⚠️ Rules & Constraints

### ❌ ห้ามแก้ไข (DO NOT MODIFY)

1. **Authentication flow** - JWT, login, session management
2. **Core database schema** - cases, orders, payments (structure เดิม)
3. **Webhook endpoints** - line.php, facebook.php (logic หลัก)
4. **Multi-tenancy logic** - channel_id filtering ที่แก้ไปแล้ว

### ✅ สิ่งที่แก้ได้

1. **UI/UX** ใน public/*.php - เพิ่ม fields, buttons
2. **API endpoints** ใน api/customer/*.php - เพิ่ม features
3. **Services** ใน includes/services/ - สร้างใหม่ได้
4. **Config files** - เพิ่ม static config
5. **Knowledge Base** - อัพเดท channel config

---

## 🧪 Testing Checklist

### Before Deploy

- [ ] Push message ทำงานได้ทั้ง LINE และ Facebook
- [ ] Bank account selector แสดงถูกต้อง
- [ ] Message template แทนที่ placeholders ถูกต้อง
- [ ] Handoff triggers ทำงาน (ทดสอบทุก keyword)
- [ ] Knowledge base ตอบ FAQ ได้ถูกต้อง

### After Deploy

- [ ] สร้าง order จริง + ส่งข้อความให้ลูกค้า
- [ ] ลูกค้าได้รับข้อความใน LINE/FB
- [ ] ตรวจสลิป + verify ได้
- [ ] Push ยืนยันการชำระได้

---

## 📊 Database Changes (if needed)

### ไม่ต้องเพิ่ม table ใหม่ (Phase 1)

ใช้ static config สำหรับ bank accounts ก่อน

### Phase 2 (ถ้าต้องการ dynamic)

```sql
-- Optional: bank_accounts table
CREATE TABLE bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(50) DEFAULT 'default',
    bank_code VARCHAR(10) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_name VARCHAR(200) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    max_per_slip DECIMAL(15,2) NULL,
    monthly_limit DECIMAL(15,2) NULL,
    current_month_total DECIMAL(15,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    priority INT DEFAULT 0,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_active (tenant_id, is_active)
);
```

---

## 📅 Timeline Estimate

| Phase | Tasks | Duration | Status |
|-------|-------|----------|--------|
| **Phase 1** | 1.1-1.5 (Core) | 2-3 วัน | 🔴 TODO |
| **Phase 2** | 2.1-2.4 (Enhancement) | 3-4 วัน | ⏳ After Phase 1 |
| **Phase 3** | 3.1-3.3 (Nice to have) | 5-7 วัน | ⏳ After Phase 2 |

---

## 📞 Contacts

- **Product Owner:** [TBD]
- **Developer:** [TBD]
- **ทีม Data (Product Search API):** รอ API พร้อม

---

*Document maintained by: Autobot Dev Team*
