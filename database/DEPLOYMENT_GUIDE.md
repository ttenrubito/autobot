# 🚀 Chatbot E-Commerce Deployment Guide

## 📋 สรุปงานที่ทำ

งานนี้เกี่ยวกับการสร้างระบบ **Chatbot E-Commerce** ที่มีฟีเจอร์:
- 💬 **Chat History** - ประวัติการสนทนากับลูกค้าผ่าน LINE, Facebook
- 📍 **Customer Addresses** - ที่อยู่จัดส่งของลูกค้า (รองรับหลายที่อยู่)
- 📦 **Orders** - คำสั่งซื้อสินค้าแบรนด์เนม (รองรับทั้งจ่ายเต็มและผ่อน)
- 💰 **Payments** - ระบบชำระเงิน (รองรับสลิปโอนเงิน + การตรวจสอบ)
- 📅 **Installment Schedules** - ตารางการชำระผ่อน

---

## 📁 ไฟล์ที่สร้าง

### 1. **DEPLOY_CHATBOT_COMMERCE.sql** ⭐ (ไฟล์หลัก)
รวม 3 scripts ไว้ในไฟล์เดียว:
- ✅ **Part 1**: สร้างตารางทั้งหมด (8 ตาราง)
- ✅ **Part 2**: สร้าง test user `test1@gmail.com` พร้อมข้อมูลพื้นฐาน
- ✅ **Part 3**: เพิ่มข้อมูล mock เพิ่มเติม

### 2. **Scripts ต้นฉบับ** (อยู่ใน `/database/migrations/` และ `/database/`)
- `2025_12_23_create_chatbot_commerce_tables.sql` - Schema เท่านั้น
- `setup_test1_user.sql` - สร้าง test user พร้อมข้อมูลพื้นฐาน
- `add_more_mock_data.sql` - เพิ่มข้อมูลเพิ่มเติม

---

## 🎯 วิธี Deploy

### สำหรับ Localhost (Development)

```bash
# เข้าสู่ MySQL
mysql -u root -p autobot < /opt/lampp/htdocs/autobot/database/DEPLOY_CHATBOT_COMMERCE.sql
```

### สำหรับ Production (Server)

```bash
# 1. อัปโหลดไฟล์ไปยัง server
scp /opt/lampp/htdocs/autobot/database/DEPLOY_CHATBOT_COMMERCE.sql user@server:/path/to/

# 2. รัน SQL script
mysql -u your_db_user -p your_database_name < /path/to/DEPLOY_CHATBOT_COMMERCE.sql
```

---

## 📊 ตารางที่ถูกสร้าง

| ตาราง | จำนวนคอลัมน์ | คำอธิบาย |
|-------|--------------|----------|
| `conversations` | 12 | เก็บ session การสนทนา |
| `chat_messages` | 18 | ข้อความในการสนทนา |
| `chat_events` | 5 | เหตุการณ์พิเศษ (order_placed, payment) |
| `customer_addresses` | 15 | ที่อยู่ของลูกค้า |
| `orders` | 17 | คำสั่งซื้อ |
| `payments` | 17 | การชำระเงิน |
| `installment_schedules` | 9 | ตารางผ่อนชำระ |
| `user_menu_config` | 6 | เมนูสำหรับ user แต่ละคน |

---

## 👤 Test Account

หลังจาก deploy สำเร็จ คุณสามารถ login ทดสอบได้ที่:

```
URL: http://your-domain/autobot/public/login.html
Email: test1@gmail.com
Password: password123
```

### ข้อมูลที่มีใน Test Account:
- ✅ **5 ที่อยู่** (บ้าน, ที่ทำงาน, ฯลฯ)
- ✅ **5 conversations** (LINE, Facebook)
- ✅ **5 orders** (สถานะต่างๆ: pending, processing, shipped, delivered)
- ✅ **5 payments** (verified, pending)
- ✅ **ตารางผ่อน 2 คำสั่งซื้อ** (6 เดือน และ 10 เดือน)

---

## 🔍 ตรวจสอบหลัง Deploy

```sql
-- ดูข้อมูล test user
SELECT * FROM users WHERE email = 'test1@gmail.com';

-- ดูที่อยู่
SELECT * FROM customer_addresses WHERE customer_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');

-- ดู orders
SELECT * FROM orders WHERE customer_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');

-- ดู payments
SELECT * FROM payments WHERE customer_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');

-- ดู conversations
SELECT * FROM conversations WHERE customer_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');
```

---

## 🆕 ถ้าต้องการเพิ่มข้อมูลเพิ่ม

### เพิ่มที่อยู่ใหม่
```sql
INSERT INTO customer_addresses (
    customer_id, tenant_id, address_type, recipient_name, phone,
    address_line1, district, province, postal_code, is_default
) VALUES (
    (SELECT id FROM users WHERE email = 'test1@gmail.com'),
    'default', 'shipping', 'ชื่อผู้รับ', '0812345678',
    'ที่อยู่บรรทัด 1', 'เขต', 'กรุงเทพฯ', '10110', 0
);
```

### เพิ่มคำสั่งซื้อใหม่
```sql
INSERT INTO orders (
    order_no, customer_id, tenant_id, product_name,
    quantity, unit_price, total_amount, payment_type,
    shipping_address_id, status, source
) VALUES (
    'ORD-20251223-001',
    (SELECT id FROM users WHERE email = 'test1@gmail.com'),
    'default', 'สินค้าทดสอบ',
    1, 50000.00, 50000.00, 'full',
    (SELECT id FROM customer_addresses WHERE customer_id = (SELECT id FROM users WHERE email = 'test1@gmail.com') AND is_default = 1),
    'pending', 'web'
);
```

---

## 🗑️ ถ้าต้องการ Reset ข้อมูล

```sql
-- ลบข้อมูลทั้งหมดของ test1@gmail.com (แต่เก็บ user ไว้)
SET @test_user_id = (SELECT id FROM users WHERE email = 'test1@gmail.com');

DELETE FROM conversations WHERE customer_id = @test_user_id;
DELETE FROM installment_schedules WHERE order_id IN (SELECT id FROM orders WHERE customer_id = @test_user_id);
DELETE FROM payments WHERE customer_id = @test_user_id;
DELETE FROM orders WHERE customer_id = @test_user_id;
DELETE FROM customer_addresses WHERE customer_id = @test_user_id;

-- จากนั้นรัน setup_test1_user.sql และ add_more_mock_data.sql ใหม่
```

---

## 📝 API Endpoints ที่ต้องใช้กับ Tables นี้

| Endpoint | Method | ตารางที่เกี่ยวข้อง |
|----------|--------|-------------------|
| `/api/chat/history` | GET | conversations, chat_messages |
| `/api/addresses/list` | GET | customer_addresses |
| `/api/addresses/create` | POST | customer_addresses |
| `/api/orders/list` | GET | orders |
| `/api/orders/details/{id}` | GET | orders, payments, installment_schedules |
| `/api/payments/create` | POST | payments |
| `/api/payments/verify` | POST | payments |

---

## ⚠️ สิ่งที่ต้องทำหลัง Deploy

### 1. สร้าง API Endpoints
ต้องสร้างไฟล์ PHP APIs สำหรับ:
- ✅ `/api/chat/history.php` - ดึงประวัติการสนทนา
- ✅ `/api/addresses/list.php` - ดึงรายการที่อยู่
- ✅ `/api/addresses/create.php` - สร้างที่อยู่ใหม่
- ✅ `/api/orders/list.php` - ดึงรายการ orders
- ✅ `/api/orders/details.php` - ดูรายละเอียด order
- ✅ `/api/payments/history.php` - ประวัติการชำระเงิน

### 2. สร้างหน้า Frontend
- ✅ `chat-history.php` - แสดงประวัติสนทนา
- ✅ `addresses.php` - จัดการที่อยู่
- ✅ `orders.php` - แสดงคำสั่งซื้อ
- ✅ `payment-history.php` - ประวัติการชำระเงิน

### 3. เชื่อมต่อกับ Chatbot
- อัพเดต chatbot handlers ให้บันทึกข้อมูลลง `conversations` และ `chat_messages`
- เพิ่มฟีเจอร์ส่งสลิปผ่าน chatbot → บันทึกลง `payments`

---

## 🎉 สรุป

คุณได้ deploy ระบบ E-Commerce Chatbot เรียบร้อยแล้ว! 

**ไฟล์ที่สำคัญ:**
- 📄 `DEPLOY_CHATBOT_COMMERCE.sql` - รัน 1 ครั้งเพื่อสร้างทุกอย่าง
- 📖 `DEPLOYMENT_GUIDE.md` - คู่มือนี้

**Next Steps:**
1. Deploy SQL script ✅
2. สร้าง API endpoints (ถ้ายังไม่มี)
3. สร้างหน้า frontend
4. เชื่อมต่อกับ chatbot
5. ทดสอบด้วย test1@gmail.com

---

**หมายเหตุ:** ถ้ามีคำถามหรือต้องการปรับแต่งเพิ่ม ติดต่อได้เลยครับ! 🚀
