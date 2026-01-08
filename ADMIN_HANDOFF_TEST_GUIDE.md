# 🧪 Admin Handoff Testing Guide

## หลัง Deploy เสร็จแล้ว ทำตามนี้:

---

## ✅ Test 1: ทดสอบคำสั่ง "admin" ใน Facebook

### ขั้นตอน:

1. เปิด **Facebook Page Inbox** (Business Suite หรือ Pages Manager)
2. หาแชทที่ bot กำลังตอบลูกค้า
3. **จาก Page account** (ไม่ใช่ user) พิมพ์: `admin`
4. **Expected Result:**
   - ✅ Bot หยุดตอบทันที
   - ✅ ไม่มีข้อความตอบกลับ

5. ให้ลูกค้าพิมพ์อะไรมาอีก (เช่น "สวัสดี", "มีสินค้าไหม")
6. **Expected Result:**
   - ✅ Bot **ไม่ตอบ** (เก็บข้อความไว้อย่างเดียว)
   - ✅ Admin สามารถตอบแทนได้

7. รอ **1 ชั่วโมง** (หรือ 60+ นาที)
8. ให้ลูกค้าพิมพ์อีกครั้ง
9. **Expected Result:**
   - ✅ Bot **กลับมาตอบปกติ** (timeout หมดแล้ว)

---

## ✅ Test 2: ทดสอบคำสั่ง "/admin" ใน LINE

### ขั้นตอน:

1. เปิด **LINE Official Account Manager**
2. ไปที่ Chat
3. เปิดโหมด Admin (ถ้ามี) หรือใช้ account ที่อยู่ใน `admin_user_ids`
4. พิมพ์: `/admin`
5. **Expected Result:**
   - ✅ Bot หยุดตอบทันที

6. ให้ลูกค้าพิมพ์ข้อความ
7. **Expected Result:**
   - ✅ Bot ไม่ตอบ (admin mode active)

---

## ✅ Test 3: ตรวจสอบ Logs

```bash
# ดู log realtime
gcloud run services logs tail autobot \
  --project=autobot-prod-251215-22549 \
  --region=asia-southeast1

# Filter เฉพาะ admin handoff
gcloud run services logs read autobot \
  --project=autobot-prod-251215-22549 \
  --region=asia-southeast1 \
  --limit=200 \
  | grep -i "ADMIN_HANDOFF"
```

### Log ที่ต้องเจอ:

```json
// เมื่อพิมพ์ "admin"
[ADMIN_HANDOFF] Manual command detected
[ADMIN_HANDOFF] Updated last_admin_message_at

// เมื่อ user พิมพ์ระหว่าง admin active
[ADMIN_HANDOFF] Admin still active - bot paused
{
  "reason": "admin_handoff_active",
  "admin_timeout_remaining_sec": 3540
}

// หลังครบ 1 ชม
[ADMIN_HANDOFF] Timeout expired - resuming bot
[ADMIN_HANDOFF] Cleared last_admin_message_at
```

---

## 🐛 Troubleshooting

### ❌ ปัญหา: Bot ยังตอบต่อแม้พิมพ์ "admin"

**วิธีเช็ค:**

1. **ตรวจสอบว่า deploy สำเร็จ:**
   ```bash
   gcloud run services describe autobot \
     --region=asia-southeast1 \
     --project=autobot-prod-251215-22549 \
     --format="value(status.latestReadyRevisionName,metadata.annotations.'serving.knative.dev/lastModifierTime')"
   ```

2. **ตรวจสอบว่า DB มี column:**
   ```bash
   gcloud sql connect autobot-db \
     --project=autobot-prod-251215-22549 \
     --database=autobot
   
   # แล้วรัน SQL:
   SHOW COLUMNS FROM chat_sessions LIKE 'last_admin_message_at';
   ```

3. **ดู log ว่ามี error:**
   ```bash
   gcloud run services logs read autobot \
     --project=autobot-prod-251215-22549 \
     --region=asia-southeast1 \
     --limit=50 \
     | grep -i error
   ```

---

### ❌ ปัญหา: Column ยังไม่มีใน DB

**แก้:**

```bash
# รัน migration manual
gcloud sql connect autobot-db \
  --project=autobot-prod-251215-22549 \
  --database=autobot

# แล้วรัน SQL นี้:
ALTER TABLE chat_sessions 
ADD COLUMN last_admin_message_at TIMESTAMP NULL DEFAULT NULL;

CREATE INDEX idx_admin_timeout ON chat_sessions(last_admin_message_at);
```

---

### ❌ ปัญหา: Facebook ไม่ detect admin

**สาเหตุ:** `is_echo` flag หรือ `sender_id === page_id` ไม่ทำงาน

**แก้:**

ไปที่ `api/webhooks/facebook.php` เพิ่มการเช็ค:

```php
// ประมาณ line 130
$isEcho = $messaging['message']['is_echo'] ?? false;
$senderId = $messaging['sender']['id'] ?? null;
$pageId = $config['page_id'] ?? null;

if ($isEcho || $senderId === $pageId) {
    $isAdmin = true;
    Logger::info('[FACEBOOK_WEBHOOK] Admin detected', [
        'is_echo' => $isEcho,
        'sender_equals_page' => $senderId === $pageId
    ]);
}
```

---

## 📊 Expected Metrics

หลัง deploy ถ้าทำงานถูกต้อง:

- ✅ Log จะมีข้อความ `[ADMIN_HANDOFF]` ปรากฏ
- ✅ `chat_sessions.last_admin_message_at` จะมีค่าเมื่อ admin พิมพ์
- ✅ Bot จะไม่ส่ง reply เมื่อ `last_admin_message_at` ยังไม่เกิน 1 ชม
- ✅ Gateway response จะมี `"reason": "admin_handoff_active"`

---

## 🎯 Success Criteria

**ถือว่าสำเร็จเมื่อ:**

1. ✅ Admin พิมพ์ "admin" → Bot หยุดทันที
2. ✅ User พิมพ์อะไรมาก็ไม่มี reply (ระหว่าง 1 ชม)
3. ✅ Admin สามารถตอบแทน bot ได้
4. ✅ หลัง 1 ชม bot กลับมาทำงานปกติ
5. ✅ Log มีข้อความ `[ADMIN_HANDOFF]` ครบทุก step

---

Last updated: 2025-12-27
