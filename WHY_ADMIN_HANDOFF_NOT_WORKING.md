# 🔴 ADMIN HANDOFF ไม่ทำงานใน PRODUCTION - สาเหตุและวิธีแก้

## ❌ ปัญหา:
พิมพ์ "admin" หรือ "admin มาตอบ" ใน Facebook/LINE → **Bot ยังตอบต่อ ไม่หยุด**

---

## 🔍 สาเหตุที่เป็นไปได้:

### 1. **Production Database ยังไม่มี Column** (โอกาสสูงสุด 90%)
   - Code ทั้ง RouterV1 และ RouterV2 **พร้อมแล้ว** ✅
   - Deploy สำเร็จแล้ว (revision 00305-b4q) ✅
   - แต่ Production DB **อาจยังไม่มี column `last_admin_message_at`** ❌
   - ถ้าไม่มี column → SQL UPDATE/SELECT จะ fail → Bot ทำงานต่อปกติ

### 2. **Code ไม่ทำงาน** (โอกาส 10%)
   - Regex ไม่ match
   - Handler ไม่ถูกเรียก
   - Session ไม่ถูกสร้าง

---

## ✅ วิธีแก้ - ทำตามนี้เลย:

### ขั้นตอนที่ 1: เช็คว่า Production DB มี Column หรือยัง

**วิธีที่ 1: ใช้ GCP Console (แนะนำ)**

1. เปิด: https://console.cloud.google.com/sql/instances/autobot-db/query?project=autobot-prod-251215-22549

2. Paste SQL นี้:
```sql
SELECT COUNT(*) as column_exists
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'autobot' 
  AND TABLE_NAME = 'chat_sessions' 
  AND COLUMN_NAME = 'last_admin_message_at';
```

3. ดูผลลัพธ์:
   - **0** = ไม่มี column → ต้องเพิ่ม (ไปขั้นตอนที่ 2)
   - **1** = มี column แล้ว → ปัญหาอยู่ที่โค้ด (ไปขั้นตอนที่ 3)

---

### ขั้นตอนที่ 2: เพิ่ม Column ใน Production DB

**ถ้าขั้นตอนที่ 1 ได้ 0 (ไม่มี column):**

1. ใน GCP SQL Editor เดิม Paste SQL นี้:
```sql
ALTER TABLE chat_sessions 
ADD COLUMN last_admin_message_at TIMESTAMP NULL DEFAULT NULL 
COMMENT 'Admin handoff timeout tracking';

CREATE INDEX idx_admin_timeout ON chat_sessions(last_admin_message_at);

SELECT '✅ Column added successfully!' as result;
```

2. คลิก **RUN**

3. รอจนเห็น `✅ Column added successfully!`

4. **ทดสอบทันที:**
   - พิมพ์ "admin มาตอบ" ใน Facebook
   - Bot ควร**หยุดตอบ**

---

### ขั้นตอนที่ 3: ถ้ามี Column แล้วแต่ยังไม่ทำงาน

**ตรวจสอบ Logs:**

```bash
cd /opt/lampp/htdocs/autobot
./test_admin_in_production.sh
```

หรือดูแบบ manual:

```bash
# ดู logs ล่าสุด
gcloud logging read \
  "resource.type=cloud_run_revision AND resource.labels.service_name=autobot" \
  --limit=50 \
  --project=autobot-prod-251215-22549 \
  --format="table(timestamp, textPayload)" \
  --freshness=10m
```

**หา keywords เหล่านี้:**
- ✅ `[ADMIN_HANDOFF] Manual command detected` - ตรวจจับคำสั่งได้
- ✅ `[V2_BOXDESIGN] Bot paused - admin handoff active` - Bot pause แล้ว
- ❌ `Unknown column 'last_admin_message_at'` - DB ไม่มี column
- ❌ `[FACTORY] Instantiating Router...` - ดูว่าใช้ handler อะไร

---

## 📋 Checklist - ตรวจสอบทีละข้อ:

### Local (Development):
- [x] Code มี admin handoff logic (RouterV1Handler.php)
- [x] Code มี admin handoff logic (RouterV2BoxDesignHandler.php)
- [x] Local DB มี column `last_admin_message_at`
- [x] Unit tests ผ่านหมด (7/7)

### Production:
- [x] Deploy สำเร็จ (revision 00305-b4q)
- [ ] **Production DB มี column `last_admin_message_at`** ← ต้องเช็ค!
- [ ] ทดสอบพิมพ์ "admin" → Bot หยุด
- [ ] ตรวจสอบ logs มี `[ADMIN_HANDOFF]`

---

## 🎯 สรุป - ทำอันนี้ก่อน:

1. **เช็ค Production DB:**
   ```
   https://console.cloud.google.com/sql/instances/autobot-db/query
   ```

2. **Paste SQL:**
   ```sql
   SELECT COUNT(*) FROM information_schema.COLUMNS 
   WHERE TABLE_SCHEMA='autobot' AND TABLE_NAME='chat_sessions' 
   AND COLUMN_NAME='last_admin_message_at';
   ```

3. **ถ้าได้ 0:**
   - รัน ALTER TABLE (ตามขั้นตอนที่ 2)
   - ทดสอบใหม่

4. **ถ้าได้ 1:**
   - มีปัญหาที่โค้ด
   - ดู logs: `./test_admin_in_production.sh`

---

## 📞 ถ้ายังไม่ได้:

ส่ง screenshot หรือ log มาให้ดู:

1. **Production DB Check Result:**
   ```sql
   SELECT COUNT(*) FROM information_schema.COLUMNS...
   ```

2. **Recent Logs:**
   ```bash
   gcloud logging read "..." --limit=20 --freshness=10m
   ```

3. **แชทที่ทดสอบ:**
   - ข้อความที่ส่ง
   - Bot ตอบอะไร
   - เวลาที่ส่ง

---

**คาดว่าปัญหาอยู่ที่ Production DB ไม่มี column ครับ!** 🎯
