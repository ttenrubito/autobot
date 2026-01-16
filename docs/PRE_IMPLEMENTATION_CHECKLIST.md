# ✅ Pre-Implementation Checklist

> **วัตถุประสงค์:** ป้องกันการทำพัง โดยกำหนด pattern และ test cases ก่อนเริ่มแก้

---

## 🔍 Step 1: ศึกษา Pattern เดิมก่อนแก้

### 1.1 Pattern ของ orders.php (UI) ✅ REVIEWED

**สรุป Pattern:**
- [x] Form submit ใช้ AJAX (`apiCall()` function)
- [x] API endpoint: `API_ENDPOINTS.CUSTOMER_ORDERS` → `/api/customer/orders`
- [x] Error handling: `showToast('message', 'error')`
- [x] Success message: `showToast('message', 'success')`
- [x] Loading state: `submitBtn.disabled = true; submitBtn.innerHTML = '...'`
- [x] JavaScript file: `assets/js/orders.js`

### 1.2 Pattern ของ api/customer/orders.php (API) ✅ REVIEWED

**สรุป Pattern:**
- [x] Input: `json_decode(file_get_contents('php://input'), true)`
- [x] Response: `echo json_encode(['success' => true, 'data' => [...]])`
- [x] Error: `http_response_code(400); echo json_encode(['success' => false, 'message' => '...'])`
- [x] Transaction: `$pdo->beginTransaction(); ... $pdo->commit();`
- [x] Logging: `error_log("...")`
- [x] Schema detection: Dynamic column detection

### 1.3 Pattern ของ RouterV1Handler.php (Handoff)

**ก่อนแก้ต้องตอบให้ได้:**
- [ ] Handoff logic อยู่ตรงไหน?
- [ ] Handoff triggers เดิมมีอะไรบ้าง?
- [ ] เพิ่ม trigger ใหม่ต้องทำยังไง?
- [ ] มี test case อะไรบ้าง?

---

## 🎯 Step 2: แบ่ง Task เป็นชิ้นเล็ก + Test Case

### Task 1.3: PushMessageService (ทำก่อน - สร้างใหม่ไม่แก้ไฟล์เดิม)

**สิ่งที่ต้องทำ:**
1. [ ] สร้างไฟล์ `includes/services/PushMessageService.php`
2. [ ] ทดสอบ LINE Push API ด้วย test script
3. [ ] ทดสอบ Facebook Send API ด้วย test script

**Test Cases:**
```
TC-1.3.1: ส่ง LINE push message สำเร็จ
  - Input: platform=line, userId=xxx, message="test"
  - Expected: HTTP 200, message delivered

TC-1.3.2: ส่ง LINE push message fail (invalid token)
  - Input: platform=line, userId=xxx, message="test", bad token
  - Expected: Error logged, graceful fail

TC-1.3.3: ส่ง Facebook push message สำเร็จ
  - Input: platform=facebook, userId=xxx, message="test"
  - Expected: HTTP 200, message delivered

TC-1.3.4: ส่ง Facebook push message fail (invalid token)
  - Input: platform=facebook, userId=xxx, message="test", bad token
  - Expected: Error logged, graceful fail
```

**Rollback Plan:**
- ลบไฟล์ `includes/services/PushMessageService.php`

---

### Task 1.2: Bank Account Config (ทำที่สอง - สร้างใหม่)

**สิ่งที่ต้องทำ:**
1. [ ] สร้างไฟล์ `config/bank_accounts.php`
2. [ ] ทดสอบ include ไฟล์ได้ถูกต้อง

**Test Cases:**
```
TC-1.2.1: Load bank accounts config
  - Input: require 'config/bank_accounts.php'
  - Expected: Array with bank data
```

**Rollback Plan:**
- ลบไฟล์ `config/bank_accounts.php`

---

### Task 1.1: Push Message UI ใน orders.php (ทำที่สาม)

**สิ่งที่ต้องทำ:**
1. [ ] ศึกษา orders.php pattern ก่อน
2. [ ] เพิ่ม UI elements (textarea, dropdown, checkbox)
3. [ ] ทดสอบ UI แสดงผลถูกต้อง
4. [ ] เชื่อม API

**Test Cases:**
```
TC-1.1.1: UI แสดง bank dropdown
  - Expected: Dropdown มี 3 บัญชี

TC-1.1.2: UI แสดง message textarea
  - Expected: Textarea มี default template

TC-1.1.3: เลือก bank → template อัพเดท
  - Input: เลือก SCB
  - Expected: Template แสดงข้อมูล SCB

TC-1.1.4: สร้าง order + ส่ง message
  - Input: กรอกข้อมูล + check send message + submit
  - Expected: Order saved + message sent
```

**Rollback Plan:**
- Git revert changes to orders.php

---

### Task 1.4: Handoff Triggers (ทำที่สี่)

**สิ่งที่ต้องทำ:**
1. [ ] ศึกษา RouterV1Handler handoff pattern ก่อน
2. [ ] เพิ่ม keywords ใน array ที่มีอยู่
3. [ ] ทดสอบทุก keyword

**Test Cases:**
```
TC-1.4.1: "สนใจซื้อ" → handoff
TC-1.4.2: "มัดจำได้ไหม" → handoff  
TC-1.4.3: "ขอผ่อน" → handoff
TC-1.4.4: "ลดได้ไหม" → handoff
TC-1.4.5: "ขอเลขบัญชี" → handoff
TC-1.4.6: "video call ดูสินค้า" → handoff
```

**Rollback Plan:**
- Git revert changes to RouterV1Handler.php

---

### Task 1.5: Knowledge Base (ทำที่ห้า)

**สิ่งที่ต้องทำ:**
1. [ ] อัพเดท channel config ใน database
2. [ ] ทดสอบ bot ตอบ FAQ ได้

**Test Cases:**
```
TC-1.5.1: ถาม "เปลี่ยนสินค้าได้ไหม" → ตอบเงื่อนไข
TC-1.5.2: ถาม "ผ่อนได้กี่งวด" → ตอบ 3 งวด 3%
TC-1.5.3: ถาม "มัดจำเท่าไหร่" → ตอบ 10%
TC-1.5.4: ถาม "ร้านอยู่ที่ไหน" → ตอบที่อยู่
```

**Rollback Plan:**
- Restore channel config from backup

---

## 🛡️ Step 3: Safety Rules

### Before Each Task
1. [ ] Git commit สถานะปัจจุบัน
2. [ ] อ่าน code เดิมให้เข้าใจ pattern
3. [ ] เขียน test script ก่อนแก้

### After Each Task  
1. [ ] Run PHP syntax check
2. [ ] Test locally
3. [ ] Test production (ถ้าเป็นไปได้)
4. [ ] Git commit ถ้าผ่าน
5. [ ] บันทึกผลใน task tracker

### If Something Breaks
1. [ ] Git revert ทันที
2. [ ] บันทึก error
3. [ ] วิเคราะห์สาเหตุก่อนลองใหม่

---

## 📋 Execution Order

| Order | Task | Risk | Reason |
|-------|------|------|--------|
| 1 | 1.3 PushMessageService | 🟢 Low | สร้างใหม่ ไม่แตะไฟล์เดิม |
| 2 | 1.2 Bank Config | 🟢 Low | สร้างใหม่ ไม่แตะไฟล์เดิม |
| 3 | 1.1 Orders UI | 🟡 Medium | แก้ไฟล์เดิม แต่เป็น UI |
| 4 | 1.4 Handoff | 🟡 Medium | แก้ไฟล์สำคัญ แต่เพิ่มแค่ array |
| 5 | 1.5 Knowledge Base | 🟢 Low | แก้ config ไม่ใช่ code |

---

## 🧪 Live Test Scenarios

### Scenario A: LINE Customer
1. ลูกค้าทักมา "สนใจนาฬิกา Rolex"
2. Bot ตอบ FAQ
3. ลูกค้าบอก "สนใจซื้อเลย"
4. **Expected:** Handoff to admin
5. Admin สร้าง order + ส่งเลขบัญชี
6. **Expected:** ลูกค้าได้รับ push message

### Scenario B: Facebook Customer
1. ลูกค้าแคปรูปสินค้ามา
2. Bot handoff (รูป)
3. Admin ตอบราคา
4. ลูกค้าบอก "ขอผ่อน 3 งวด"
5. **Expected:** Handoff
6. Admin สร้าง installment order
7. **Expected:** ลูกค้าได้รับ push message

---

## ✅ Sign-off

- [ ] Checklist reviewed
- [ ] Test cases defined
- [ ] Rollback plans ready
- [ ] Ready to start Task 1.3

