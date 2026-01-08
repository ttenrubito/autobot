# 🎯 Admin Handoff Testing Guide (CORRECT VERSION)

## ความเข้าใจที่ถูกต้อง

### ❌ เข้าใจผิด (เดิม):
- ลูกค้าพิมพ์ "admin" → Bot หยุด ❌

### ✅ เข้าใจถูก (ปัจจุบัน):
- **Staff/Admin พิมพ์ "admin" ผ่าน Facebook Business Suite** → Bot หยุด 1 ชม. ✅

---

## 📝 วิธีทดสอบที่ถูกต้อง

### ขั้นตอนที่ 1: ลูกค้าส่งข้อความถามมา
1. ใช้ **Facebook Messenger** (ฝั่งลูกค้า)
2. พิมพ์อะไรก็ได้ เช่น "สอบถามข้อมูล"
3. Bot จะตอบกลับทันที ✅

### ขั้นตอนที่ 2: Admin เข้ามาตอบเอง
1. เปิด **https://business.facebook.com/**
2. ไปที่ **Inbox** → เลือกแชทของลูกค้า
3. พิมพ์ **`admin`** (ตัวเดียว) แล้วกด Send
4. ระบบจะ:
   - ✅ ตรวจจับคำว่า "admin" ใน echo event
   - ✅ อัพเดท `last_admin_message_at = NOW()` ใน database
   - ✅ Bot หยุดตอบลูกค้าคนนี้เป็นเวลา 1 ชม.
   - ✅ Log: `[FB_WEBHOOK] 🚨 ADMIN HANDOFF TRIGGERED!`

### ขั้นตอนที่ 3: ทดสอบว่า Bot หยุดจริง
1. ให้ลูกค้าพิมพ์ข้อความใหม่ เช่น "ยังมีหรอ"
2. **Bot ต้องไม่ตอบ** (เงียบ) ✅
3. Admin ตอบเองได้ตลอด

### ขั้นตอนที่ 4: ทดสอบ 1-Hour Timeout
1. รอ 1 ชั่วโมงผ่านไป (หรือลบค่า `last_admin_message_at` ใน DB)
2. ให้ลูกค้าพิมพ์ข้อความใหม่
3. **Bot จะกลับมาตอบอัตโนมัติ** ✅

---

## 🔍 ตรวจสอบ Log

### Log ที่ต้องเห็น (เมื่อ Admin พิมพ์ "admin"):
```
[FB_WEBHOOK_EVENT] → has_message: true
[FB_WEBHOOK] Message received → is_echo: true, text_preview: "admin"
[FB_WEBHOOK] 🚨 ADMIN HANDOFF TRIGGERED! → action: "Pausing bot for 1 hour"
[FB_WEBHOOK] ✅ Admin handoff activated → paused_until: "2025-12-28 04:30:00"
```

### Database Check:
```sql
SELECT 
    cs.id,
    cs.external_user_id,
    cs.last_admin_message_at,
    TIMESTAMPDIFF(MINUTE, cs.last_admin_message_at, NOW()) as minutes_ago,
    IF(cs.last_admin_message_at > NOW() - INTERVAL 1 HOUR, 'PAUSED', 'ACTIVE') as bot_status
FROM chat_sessions cs
WHERE cs.external_user_id = '1833379960012056'  -- ลูกค้าคนนั้น
ORDER BY cs.created_at DESC
LIMIT 1;
```

---

## ✅ Expected Results

| Action | Expected Behavior |
|--------|-------------------|
| ลูกค้าพิมพ์ "สวัสดี" | Bot ตอบทันที |
| Admin พิมพ์ "admin" (ผ่าน Business Suite) | Bot หยุด 1 ชม. |
| ลูกค้าพิมพ์ "ยังมีหรอ" (ระหว่าง pause) | Bot ไม่ตอบ |
| รอ 1 ชม. ผ่านไป + ลูกค้าพิมพ์ใหม่ | Bot กลับมาตอบ |

---

## 🚨 Common Mistakes

### ❌ ผิด: ลูกค้าพิมพ์ "admin"
- จะไม่เกิดอะไร เพราะไม่ใช่ echo event

### ❌ ผิด: Admin พิมพ์ผ่าน Messenger (mobile app)
- ต้องใช้ **Facebook Business Suite** (business.facebook.com)

### ✅ ถูก: Admin พิมพ์ผ่าน Facebook Business Suite
- เป็น echo event → ระบบจับได้ → Bot หยุด

---

## 🎯 Final Check

Deploy เสร็จแล้ว → ทดสอบทันที:

```bash
# 1. ดู log real-time
gcloud logging tail "resource.type=\"cloud_run_revision\" AND resource.labels.service_name=\"autobot\"" \
  --project=autobot-prod-251215-22549 \
  --format="value(timestamp,jsonPayload.message)" \
  | grep -i "admin"

# 2. ตรวจสอบ database
mysql -h [HOST] -u [USER] -p autobot_db -e \
  "SELECT id, external_user_id, last_admin_message_at 
   FROM chat_sessions 
   WHERE last_admin_message_at IS NOT NULL 
   ORDER BY last_admin_message_at DESC LIMIT 5;"
```

---

**Deployed:** Revision `autobot-00311-xxx`  
**Status:** ✅ Ready for testing  
**Next Step:** Admin พิมพ์ "admin" ที่ Facebook Business Suite → Bot ต้องหยุด 1 ชม.
