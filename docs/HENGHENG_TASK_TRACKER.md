# 📋 Task Tracker - ร้าน ฮ.เฮงเฮง Implementation

> **Last Updated:** 2026-01-16

## 🎯 Summary

| Priority | Tasks | Status |
|----------|-------|--------|
| 🔴 High (Core) | 5 tasks | 0/5 done |
| 🟡 Medium | 4 tasks | 0/4 done |
| 🟢 Low | 3 tasks | 0/3 done |

---

## 🔴 Priority 1: Core Features (MUST DO)

### Task 1.1: Push Message ตอนสร้าง Order
- **Status:** ⬜ TODO
- **Assignee:** -
- **Files:** 
  - `public/orders.php` (UI)
  - `api/customer/orders.php` (API)
- **Description:** 
  - เพิ่ม textarea "ข้อความแจ้งลูกค้า" พร้อม template
  - เพิ่ม checkbox "ส่งข้อความทันที"
  - เรียก PushMessageService หลัง save order
- **Effort:** 4 ชม.
- **Notes:** -

---

### Task 1.2: Bank Account Selector
- **Status:** ⬜ TODO
- **Assignee:** -
- **Files:**
  - `config/bank_accounts.php` (สร้างใหม่)
  - `public/orders.php` (เพิ่ม dropdown)
- **Description:**
  - สร้าง static config บัญชีธนาคาร
  - เพิ่ม dropdown ใน orders.php
  - Auto-fill message template ตามบัญชีที่เลือก
- **Effort:** 2 ชม.
- **Notes:** Phase 1 ใช้ static config ก่อน

---

### Task 1.3: PushMessageService
- **Status:** ⬜ TODO
- **Assignee:** -
- **Files:**
  - `includes/services/PushMessageService.php` (สร้างใหม่)
- **Description:**
  - Service class สำหรับส่ง push message
  - รองรับ LINE Push API
  - รองรับ Facebook Send API
  - Log การส่งเก็บไว้
- **Effort:** 3 ชม.
- **Notes:** ใช้ channel config ที่มีอยู่แล้ว

---

### Task 1.4: Handoff Triggers เพิ่มเติม
- **Status:** ⬜ TODO
- **Assignee:** -
- **Files:**
  - `includes/bot/RouterV1Handler.php`
- **Description:**
  - เพิ่ม keywords: สนใจซื้อ, มัดจำ, ผ่อน, ขอลด, video call, ขอเลขบัญชี
  - เพิ่ม intents: want_to_buy, want_to_deposit, want_installment, request_discount
  - Auto handoff เมื่อ detect
- **Effort:** 3 ชม.
- **Notes:** ห้ามแก้ logic อื่น

---

### Task 1.5: Knowledge Base Update
- **Status:** ⬜ TODO
- **Assignee:** -
- **Files:**
  - Channel config (DB หรือ JSON)
- **Description:**
  - เพิ่มเงื่อนไขเปลี่ยน/คืน (เพชร 10%/15%, Rolex 35%)
  - เพิ่มเงื่อนไขผ่อน (3 งวด, 3%, 60 วัน)
  - เพิ่มเงื่อนไขมัดจำ (10%, 2 สัปดาห์)
  - เพิ่มเงื่อนไขจำนำ (65-70%, 2%/เดือน)
  - เพิ่มข้อมูลร้าน (ที่อยู่, เวลา, เบอร์)
- **Effort:** 2 ชม.
- **Notes:** -

---

## 🟡 Priority 2: Enhancement

### Task 2.1: Bank Accounts Management UI
- **Status:** ⬜ TODO
- **Assignee:** -
- **Files:**
  - `admin/settings/bank-accounts.php` (สร้างใหม่)
  - `api/admin/bank-accounts.php` (สร้างใหม่)
  - `database/migrations/xxx_bank_accounts.sql`
- **Description:**
  - หน้าจอ CRUD บัญชีธนาคาร
  - Track monthly limit
  - เตือนเมื่อใกล้ถึง limit
- **Effort:** 6 ชม.
- **Notes:** Optional สำหรับ Phase 2

---

### Task 2.2: Order Types Enhancement
- **Status:** ⬜ TODO
- **Assignee:** -
- **Files:**
  - `public/orders.php`
  - `api/customer/orders.php`
- **Description:**
  - Dropdown: จ่ายเต็ม / ผ่อนชำระ / มัดจำ
  - Logic ต่างกันตาม type
  - ผ่อน → สร้าง installment_contract ด้วย
  - มัดจำ → สร้าง deposit ด้วย
- **Effort:** 3 ชม.
- **Notes:** -

---

### Task 2.3: Installment 3 งวด + 3% Fee
- **Status:** ⬜ TODO
- **Assignee:** -
- **Files:**
  - `public/installments.php`
  - `api/customer/installments.php`
- **Description:**
  - Default 3 งวด (fixed)
  - คำนวณ: งวดแรก = (ยอด/3) + 3%
  - Due date ล็อคตามวันที่เริ่ม
  - Cancel → คืนเงินต้น ไม่คืน 3%
- **Effort:** 4 ชม.
- **Notes:** -

---

### Task 2.4: Shipping Method
- **Status:** ⬜ TODO
- **Assignee:** -
- **Files:**
  - `public/orders.php`
  - `api/customer/orders.php`
- **Description:**
  - Dropdown: รับหน้าร้าน / ไปรษณีย์ / Grab
  - Field แสดงตาม method ที่เลือก
- **Effort:** 2 ชม.
- **Notes:** -

---

## 🟢 Priority 3: Nice to Have

### Task 3.1: Auto Reminder
- **Status:** ⬜ TODO
- **Description:** แจ้งเตือนลูกค้าก่อนครบกำหนด 3 วัน
- **Effort:** 6 ชม.

---

### Task 3.2: Product Search Integration
- **Status:** ⏸️ BLOCKED
- **Description:** เชื่อม productSearch API
- **Blocked By:** รอ API จากทีม Data
- **Effort:** TBD

---

### Task 3.3: Receipt/Invoice PDF
- **Status:** ⬜ TODO
- **Description:** สร้างใบเสร็จ PDF อัตโนมัติ
- **Effort:** 8 ชม.

---

## 📝 Notes

### Dependencies
- Task 1.3 ต้องเสร็จก่อน Task 1.1
- Task 2.1 ควรทำหลัง Task 1.2 (ใช้ static ก่อน)

### Blocked Items
- Product Search API (รอทีม Data)

---

## 📅 Changelog

| Date | Change |
|------|--------|
| 2026-01-16 | Initial task list created |

