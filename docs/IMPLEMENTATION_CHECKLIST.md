# 📋 Chatbot Commerce Implementation Checklist

> **Project:** ร้านเฮง เฮง เฮง - Chatbot 4 Use Cases  
> **Started:** 2026-01-06  
> **Status:** 🟡 In Progress

---

## 🚦 Quick Status

| Phase | Status | Progress |
|-------|--------|----------|
| Phase 1: Database | ✅ Complete | 100% |
| Phase 2: Bot APIs | ✅ Complete | 100% |
| Phase 3: Admin APIs | ✅ Complete | 100% |
| Phase 4: Bot Profile & Router | 🟡 In Progress | 75% |
| Phase 5: Admin Screens | ✅ Complete | 100% |
| Phase 6: Integration & Testing | 🔴 Not Started | 0% |

---

## ✅ Blockers - RESOLVED (คำตอบจากทีม Sales 2026-01-06)

| Q# | คำถาม | คำตอบ |
|----|-------|-------|
| Q1 | ออม = กันสินค้าไว้ใช่ไหม? | ✅ ใช่ สินค้าถูกกันไว้ระหว่างออม, ออมครบจะนัดส่งหรือจัดส่ง |
| Q2 | ลูกค้าส่งสลิปมามีเลข order ไหม? | ✅ ส่วนใหญ่ไม่มี ลูกค้าส่งสลิปมาเปล่าๆ |
| Q3 | ระบบสร้าง order draft อัตโนมัติได้ไหม? | ✅ ได้ ถ้าได้สลิปแต่ไม่มี order_id ให้สร้าง draft รอ admin link |
| Q4 | NPD ใช้ ref_id หรือ product_code? | ✅ ใช้ `ref_id` เป็น primary key |
| Q5 | Admin monitor รวม FB+LINE ไหม? | ✅ รวมหน้าเดียว มี column บอก channel กรองได้ |

---

## Phase 1: Database & Foundation

### 1.1 ตรวจสอบ Schema ปัจจุบัน
- [x] ตรวจ `orders` มี field ครบ → ต้องเพิ่ม deposit/savings fields
- [x] ตรวจ `payments` มี field ครบ → ต้องเพิ่ม savings_transaction_id
- [x] ตรวจ `installment_schedules` มี field ครบ ✅
- [x] ตรวจ `chat_sessions.last_admin_message_at` มีแล้ว ✅ (migration: add_admin_handoff_timeout.sql)

### 1.2 สร้างตาราง `cases`
- [x] เขียน migration SQL → `2026_01_06_create_cases_and_savings_tables.sql`
- [ ] Test บน local
- [ ] Deploy บน production
- [ ] Verify

### 1.3 สร้างตาราง Savings
- [x] เขียน migration: `savings_accounts` → included in 2026_01_06 migration
- [x] เขียน migration: `savings_transactions` → included in 2026_01_06 migration
- [x] เขียน migration: `case_activities` → included in 2026_01_06 migration
- [ ] Test บน local
- [ ] Deploy บน production
- [ ] Verify

### 1.4 แก้ไขตาราง `orders`
- [x] เพิ่ม payment_type: deposit, savings → included in 2026_01_06 migration
- [x] เพิ่ม deposit_amount, deposit_percent → included in 2026_01_06 migration  
- [x] เพิ่ม reservation_expires_at → included in 2026_01_06 migration
- [x] เพิ่ม product_ref_id → included in 2026_01_06 migration
- [ ] Test ไม่กระทบ data เดิม
- [ ] Deploy บน production

### 1.5 แก้ไขตาราง `payments` และ `chat_sessions`
- [x] เพิ่ม savings_transaction_id → included in 2026_01_06 migration
- [x] เพิ่ม active_case_id to chat_sessions → included in 2026_01_06 migration

---

## Phase 2: Bot APIs

### 2.1 Case Management APIs
- [x] `POST /api/bot/cases` → `/api/bot/cases/index.php` ✅
- [x] `POST /api/bot/cases/{id}/update-slot` ✅
- [x] `POST /api/bot/cases/{id}/status` ✅
- [x] `GET /api/bot/cases/{id}` ✅

### 2.2 Payment APIs
- [x] `POST /api/bot/payments/submit` (ไม่ต้องมี order_id) ✅
- [x] `POST /api/bot/payments/draft-order` ✅
- [x] `GET /api/bot/payments/{id}` ✅
- [x] `GET /api/bot/payments/by-user` ✅

### 2.3 Savings APIs
- [x] `POST /api/bot/savings` (create) ✅
- [x] `POST /api/bot/savings/{id}/deposit` ✅
- [x] `GET /api/bot/savings/{id}/status` ✅
- [x] `GET /api/bot/savings/by-user` ✅

### 2.4 Product Search APIs
- [x] `POST /api/products/npd-search` (proxy to NPD) ✅
- [x] `POST /api/products/image-search` (vector + NPD) ✅

---

## Phase 3: Admin APIs

### 3.1 Case Management
- [x] `GET /api/admin/cases` ✅
- [x] `GET /api/admin/cases/{id}` ✅
- [x] `PUT /api/admin/cases/{id}/assign` ✅
- [x] `PUT /api/admin/cases/{id}/resolve` ✅
- [x] `POST /api/admin/cases/{id}/send-message` ✅
- [x] `POST /api/admin/cases/{id}/note` ✅

### 3.2 Savings Management
- [x] `GET /api/admin/savings` ✅
- [x] `GET /api/admin/savings/{id}` ✅
- [x] `POST /api/admin/savings/{id}/approve-deposit` ✅
- [x] `POST /api/admin/savings/{id}/cancel` ✅
- [x] `POST /api/admin/savings/{id}/complete` ✅

---

## Phase 4: Bot Profile & Router

### 4.1 Bot Profile Update
- [x] อัปเดต `bot_profile_config_generic.json` ✅
  - [x] เพิ่ม case_management config ✅
  - [x] เพิ่ม case_flows (4 types) ✅
  - [x] อัปเดต response_templates ✅
  - [x] เพิ่ม slot_questions ✅
  - [x] อัปเดต endpoints ✅

### 4.2 Router Handler Update
- [x] เพิ่ม CaseEngine class ✅
- [x] Import CaseEngine ใน RouterV1Handler ✅
- [x] เพิ่ม savings intent handlers (savings_new, savings_deposit, savings_inquiry) ✅
- [ ] เพิ่ม Case creation logic (optional - can run standalone)
- [ ] เพิ่ม context management

### 4.3 Router Testing
- [ ] Test: ส่งรูปสินค้า → product search
- [ ] Test: ส่งรูปสลิป → payment
- [ ] Test: พิมพ์ "ผ่อน" → installment
- [ ] Test: พิมพ์ "ออม" → savings
- [ ] Test: Admin handoff still works

---

## Phase 5: Admin Screens

### 5.1 Case Inbox (สำคัญมาก!)
- [x] สร้าง `/public/admin/cases.php` ✅
- [x] สร้าง API endpoints (stats) ✅
- [x] Queue view รวม FB+LINE ✅
- [x] Filter: type, status, channel ✅
- [x] Side panel: chat + slots + actions ✅

### 5.2 Payment Admin
- [x] มีอยู่แล้ว `/public/admin/payments.php` ✅
- [ ] แยก filter: full/installment/savings (enhancement)
- [ ] Quick approve/reject (enhancement)

### 5.3 Installment Dashboard
- [x] สร้าง `/public/admin/installments.php` ✅
- [x] แสดงตารางงวด ✅
- [x] Filter: status, overdue ✅
- [x] ปุ่มส่งเตือน (demo) ✅

### 5.4 Savings Dashboard
- [x] สร้าง `/public/admin/savings.php` ✅
- [x] แสดงบัญชีออม ✅
- [x] Filter: status, deadline ✅
- [x] อนุมัติยอดฝาก ✅

### 5.5 Sidebar & Navigation
- [x] เพิ่ม menu ใน sidebar ✅
- [x] อัปเดต path-config.js ✅

---

## Phase 6: Integration & Testing

### 6.1 External Integration
- [ ] Connect NPD Product Search API
- [ ] Setup Vector Search for images
- [ ] Test NPD API response format

### 6.2 Channel Testing
- [ ] Test Facebook Messenger
- [ ] Test LINE OA
- [ ] Both channels: same bot profile

### 6.3 End-to-End Tests

#### Product Search (4 tests)
- [ ] PS-01: ส่งรูปสินค้า
- [ ] PS-02: พิมพ์ keyword
- [ ] PS-03: ส่งรหัสสินค้า
- [ ] PS-04: ไม่เจอสินค้า

#### Payment Full (4 tests)
- [ ] PF-01: ส่งสลิปพร้อม text
- [ ] PF-02: ส่งสลิปเฉยๆ
- [ ] PF-03: OCR ไม่ชัด
- [ ] PF-04: ไม่รู้สินค้า

#### Installment (5 tests)
- [ ] IN-01: เปิดผ่อนใหม่
- [ ] IN-02: จ่ายงวด
- [ ] IN-03: ต่อดอก
- [ ] IN-04: สอบถามยอด
- [ ] IN-05: จ่ายครบ

#### Savings (4 tests)
- [ ] SV-01: เปิดออมใหม่
- [ ] SV-02: ฝากเงิน
- [ ] SV-03: สอบถามยอด
- [ ] SV-04: ออมครบ

#### Edge Cases (4 tests)
- [ ] EC-01: รูปสินค้า + "โอน" (ambiguous)
- [ ] EC-02: เปลี่ยนเรื่องกลางคัน
- [ ] EC-03: Admin เข้ามาตอบ
- [ ] EC-04: Spam detection

---

## 📝 Notes & Decisions

| Date | Note |
|------|------|
| 2026-01-06 | สร้างเอกสาร spec และ checklist |
| | |

---

## 📁 Files Created/Modified

### Database
- [x] `/database/migrations/2026_01_06_create_cases_and_savings_tables.sql` ✅

### APIs
- [x] `/api/bot/cases/index.php` ✅
- [x] `/api/bot/payments/index.php` ✅
- [x] `/api/bot/savings/index.php` ✅
- [x] `/api/products/npd-search.php` ✅
- [x] `/api/products/image-search.php` ✅
- [x] `/api/admin/cases/index.php` ✅
- [x] `/api/admin/savings/index.php` ✅

### Bot
- [x] `/bot_profile_config_generic.json` (modified) ✅
- [x] `/includes/bot/RouterV1Handler.php` (modified) ✅
- [x] `/includes/bot/CaseEngine.php` (new) ✅

### Admin Screens
- [x] `/public/admin/cases.php` ✅
- [x] `/public/admin/savings.php` ✅
- [x] `/public/admin/installments.php` ✅
- [x] `/includes/admin/sidebar.php` (modified) ✅
- [x] `/assets/js/path-config.js` (modified) ✅

---

*Last updated: 2026-01-06*
