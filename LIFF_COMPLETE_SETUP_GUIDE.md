# 🚀 LIFF Complete Setup Guide (แบบละเอียด 100%)

**สำหรับ:** คุณที่ไม่รู้เรื่อง LIFF เลย  
**เป้าหมาย:** ทำให้ Chatbot พร้อมใช้งาน 100%  
**ใช้เวลา:** 30-45 นาที

---

## 📚 สารบัญ

1. [LIFF คืออะไร? (ภาษาคนธรรมดา)](#liff-คืออะไร)
2. [ทำไมต้องใช้ LIFF?](#ทำไมต้องใช้-liff)
3. [Setup LIFF ทีละขั้นตอน](#setup-liff)
4. [สร้างหน้าฟอร์ม LIFF](#สร้างหน้าฟอร์ม)
5. [Test และ Deploy](#test-และ-deploy)

---

## 🎯 LIFF คืออะไร?

### คำตอบสั้นๆ:

**LIFF** = หน้าเว็บที่เปิดใน LINE

### คำอธิบายแบบละเอียด:

LIFF ย่อมาจาก **LINE Front-end Framework**

คือระบบที่ LINE ทำไว้ให้นักพัฒนาสร้างเว็บแอปพลิเคชั่นที่:
- ✅ เปิดใน LINE (ไม่ต้องเปิด Browser)
- ✅ ใช้ข้อมูล LINE Profile (ชื่อ, รูป, userId)
- ✅ ส่งข้อความกลับไปหา User ได้
- ✅ UX สวยงาม เหมือนแอปจริงๆ

### ตัวอย่างการใช้งานจริง:

1. **LINE MAN** - สั่งอาหาร (LIFF)
2. **ธนาคาร** - โอนเงิน (LIFF)
3. **ร้านค้า** - เลือกสินค้า (LIFF)
4. **โรงพยาบาล** - นัดหมาย (LIFF)

---

## 💡 ทำไมต้องใช้ LIFF?

### เปรียบเทียบ: มี vs ไม่มี LIFF

#### 🚫 ไม่มี LIFF (แบบเก่า):

```
Scenario: User ต้องการสมัครสินเชื่อ (30 ข้อมูล)

👤 User: "สมัคร"
🤖 Bot: "กรุณากรอกชื่อ"
👤 User: "สมชาย"
🤖 Bot: "กรุณากรอกนามสกุล"
👤 User: "ใจดี"
🤖 Bot: "กรุณากรอกเบอร์โทร"
👤 User: "0812345678"
... (ต้องแชท 30 ครั้ง!) 😰

❌ ปัญหา:
- ใช้เวลานาน (10-15 นาที)
- User เบื่อ
- ผิดพลาดง่าย
- ไม่มี Validation
- UX แย่มาก
```

#### ✅ มี LIFF (แบบใหม่):

```
Scenario: User ต้องการสมัครสินเชื่อ (30 ข้อมูล)

👤 User: "สมัคร"
🤖 Bot: "👉 คลิกลิงก์นี้เพื่อกรอกฟอร์ม:
        https://liff.line.me/xxx"
        
👤 User: *คลิก*

📱 เปิดหน้าฟอร์มสวยๆ ใน LINE:
   ┌─────────────────────────────┐
   │ 📋 ฟอร์มสมัครสินเชื่อ       │
   ├─────────────────────────────┤
   │ ชื่อ: [________________]    │
   │ นามสกุล: [____________]     │
   │ เบอร์: [_______________]    │
   │ ที่อยู่: [______________]   │
   │ ... (30 ข้อ)                │
   │                             │
   │ อัปโหลดเอกสาร:              │
   │ 📄 [เลือกไฟล์บัตรประชาชน]  │
   │ 📄 [เลือกไฟล์ทะเบียนบ้าน]  │
   │                             │
   │ [✅ ส่งข้อมูล]              │
   └─────────────────────────────┘

✅ ข้อดี:
- ใช้เวลาแค่ 2-3 นาที
- กรอกครั้งเดียวเสร็จ
- มี Validation แบบ Real-time
- อัปโหลดรูปได้
- UX สวยงามมาก
- Conversion Rate สูงกว่า 300%!
```

---

## 🎨 LIFF สามารถทำอะไรได้บ้าง?

### 1️⃣ ฟอร์มกรอกข้อมูล
- Input fields (text, number, email, tel)
- Dropdown / Select
- Radio buttons / Checkboxes
- Date picker
- Validation

### 2️⃣ อัปโหลดไฟล์
- รูปภาพ
- PDF
- เอกสารต่างๆ
- ถ่ายรูปจากกล้อง

### 3️⃣ ใช้ข้อมูล LINE
- ดึงชื่อจาก LINE Profile
- ดึงรูปโปรไฟล์
- ดึง userId (ไม่ต้องให้กรอกเอง)

### 4️⃣ ส่งข้อความกลับ
- ส่งข้อความหา User
- Push notification
- ตอบกลับอัตโนมัติ

### 5️⃣ UI/UX สวยงาม
- ใช้ CSS ได้เต็มที่
- Responsive design
- Animation
- เหมือนแอปจริงๆ

---

## 🚀 Setup LIFF ทีละขั้นตอน

### Phase 1: สร้าง LIFF App (10 นาที)

#### Step 1: เข้า LINE Developers Console

1. เปิดเว็บ: https://developers.line.biz/console/
2. Login ด้วย LINE Account
3. เลือก **Provider** ของคุณ

#### Step 2: เลือก Channel

1. คลิกที่ **Messaging API Channel** ที่ต้องการใช้
2. เข้าไปที่หน้าจัดการ Channel

#### Step 3: สร้าง LIFF App

1. คลิกที่ Tab **"LIFF"** ในเมนูซ้าย
2. คลิกปุ่ม **"Add"** (เพิ่ม LIFF app)
3. กรอกข้อมูล:

```yaml
LIFF app name:
  "Application Form - Autobot"
  # ชื่อนี้ใช้ภายในเท่านั้น (admin เห็น)
  # User ไม่เห็น

Size:
  ✅ Full (แนะนำ - ใช้พื้นที่เต็มจอ)
  # หรือ
  ⚪ Tall (สูง)
  ⚪ Compact (เล็ก)

Endpoint URL:
  https://autobot.boxdesign.in.th/liff/application-form.html
  
  # ⚠️ สำคัญ! ใส่ URL ของหน้าเว็บที่เราจะสร้าง
  # ตอนนี้ยังไม่มีไฟล์นี้ (ไม่เป็นไร ใส่ไว้ก่อน)

Scope:
  ✅ profile (Required - ดึงข้อมูล LINE user)
  ✅ openid (Required - Authentication)
  ⚪ chat_message.write (ถ้าต้องการส่งข้อความ)

Bot link feature:
  ✅ On (Normal) - แนะนำ
  # หรือ
  ✅ On (Aggressive) - บังคับ add bot
  # หรือ
  ⚪ Off
```

4. คลิกปุ่ม **"Add"**
5. **คัดลอก LIFF ID** (จะได้เลขยาวๆ เช่น: `1234567890-AbCdEfGh`)

#### 📋 จดบันทึก LIFF ID:

```
LIFF ID ของคุณ: _______________________
```

---

### Phase 2: Update Database (5 นาที)

#### Option A: ใช้ Cloud Console (แนะนำ)

1. ไปที่: https://console.cloud.google.com/sql/instances
2. เลือก Instance: `autobot-db`
3. คลิก **"Connect using Cloud Shell"**
4. รันคำสั่ง:

```bash
gcloud sql connect autobot-db --user=root \
  --project=autobot-prod-251215-22549
```

5. ใส่ Password (ถ้ามี)
6. รัน SQL:

```sql
-- เลือก Database
USE autobot_prod;

-- Update LIFF ID (เปลี่ยน YOUR_LIFF_ID เป็นของคุณ!)
UPDATE campaigns 
SET liff_id = '1234567890-AbCdEfGh'
WHERE code = 'TEST2026';

-- ตรวจสอบ
SELECT code, name, liff_id, is_active 
FROM campaigns 
WHERE code = 'TEST2026';
```

**ผลลัพธ์ที่ควรเห็น:**
```
+----------+---------------------------+----------------------+-----------+
| code     | name                      | liff_id              | is_active |
+----------+---------------------------+----------------------+-----------+
| TEST2026 | ทดสอบระบบสมัคร 2026       | 1234567890-AbCdEfGh  |         1 |
+----------+---------------------------+----------------------+-----------+
```

✅ **เสร็จแล้ว!** ตอนนี้ Bot จะแสดงลิงก์ LIFF แล้ว!

---

### Phase 3: Test LIFF Link (2 นาที)

#### Step 1: เปิด LINE App

1. Add Bot เป็นเพื่อน (ถ้ายังไม่ได้ add)
2. พิมพ์ **"แคมเปญ"**

#### Step 2: ตรวจสอบผลลัพธ์

**ตอนนี้ควรเห็น:**

```
😊 สวัสดีค่ะ! มีแคมเปญที่เปิดรับสมัครอยู่นะคะ

━━━━━━━━━━━━━━━
📋 ทดสอบระบบสมัคร 2026
   💬 ทดสอบการสมัครผ่าน LINE

   👉 สมัครเลย: https://liff.line.me/1234567890-AbCdEfGh?campaign=TEST2026
━━━━━━━━━━━━━━━

💡 คลิกลิงก์ด้านบนเพื่อเริ่มกรอกใบสมัครได้เลยค่ะ
```

#### Step 3: คลิกลิงก์

- **ถ้าเปิดหน้าเว็บได้** (แม้จะ 404 Not Found) → ✅ **LIFF ID ใช้งานได้!**
- **ถ้า Error** → ตรวจสอบ LIFF ID อีกครั้ง

---

### Phase 4: สร้างหน้าฟอร์ม LIFF (15 นาที)

#### ตอนนี้เราจะสร้างไฟล์:
```
/liff/application-form.html
```

#### ฟีเจอร์ที่จะมี:
- ✅ กรอกฟอร์มข้อมูล
- ✅ ดึงข้อมูล LINE Profile อัตโนมัติ
- ✅ Validation ข้อมูล
- ✅ Submit ข้อมูลเข้า Backend
- ✅ UI สวยงาม Responsive

---

## 📝 สรุป Roadmap

```
┌─────────────────────────────────────────────────────┐
│ 🎯 LIFF Complete Setup                              │
├─────────────────────────────────────────────────────┤
│                                                     │
│ ✅ Phase 1: สร้าง LIFF App (10 นาที)               │
│    - เข้า LINE Developers Console                  │
│    - สร้าง LIFF App                                 │
│    - ได้ LIFF ID                                    │
│                                                     │
│ ✅ Phase 2: Update Database (5 นาที)               │
│    - Connect Cloud SQL                              │
│    - UPDATE campaigns SET liff_id                   │
│    - Verify                                         │
│                                                     │
│ ✅ Phase 3: Test LIFF Link (2 นาที)                │
│    - พิมพ์ "แคมเปญ" ใน LINE                         │
│    - เห็นลิงก์ https://liff.line.me/xxx             │
│    - คลิกทดสอบ                                      │
│                                                     │
│ 🔜 Phase 4: สร้างหน้าฟอร์ม (15 นาที)               │
│    - สร้าง /liff/application-form.html              │
│    - เชื่อม LIFF SDK                                │
│    - Form + Validation                              │
│    - Deploy                                         │
│                                                     │
│ 🔜 Phase 5: Test End-to-End (5 นาที)               │
│    - กรอกฟอร์มจริง                                  │
│    - Submit ข้อมูล                                  │
│    - ตรวจสอบใน Database                             │
│                                                     │
└─────────────────────────────────────────────────────┘
```

---

## ✅ เสร็จแล้วต้องทำอะไร?

### หลัง Setup LIFF ID เรียบร้อย:

1. ✅ Bot จะแสดงลิงก์ LIFF
2. ⚠️ คลิกแล้วจะ 404 (เพราะยังไม่มีไฟล์ HTML)
3. 🔜 ต้องสร้างไฟล์ `/liff/application-form.html`

---

**พร้อมไปต่อที่ Phase 4 (สร้างหน้าฟอร์ม) แล้วหรือยัง?** 🚀
