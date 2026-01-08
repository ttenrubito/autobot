# 🎯 Production Database Migration - Manual Steps

เนื่องจาก IPv6 connection issue ให้รัน SQL แบบ manual ผ่าน GCP Console:

## วิธีที่ 1: ใช้ GCP Console (แนะนำ)

1. เปิด: https://console.cloud.google.com/sql/instances/autobot-db/overview?project=autobot-prod-251215-22549

2. คลิก **"OPEN CLOUD SHELL"** (มุมขวาบน)

3. รันคำสั่งนี้:
```bash
gcloud sql connect autobot-db --user=root --project=autobot-prod-251215-22549
```

4. ใส่รหัสผ่าน MySQL

5. Copy & Paste SQL นี้:

```sql
USE autobot;

-- Check if column exists
SELECT COUNT(*) AS column_exists
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA='autobot' 
  AND TABLE_NAME='chat_sessions' 
  AND COLUMN_NAME='last_admin_message_at';

-- Add column if not exists (MySQL 5.7+)
ALTER TABLE chat_sessions 
ADD COLUMN IF NOT EXISTS last_admin_message_at TIMESTAMP NULL DEFAULT NULL 
COMMENT 'Admin handoff timeout - bot pauses when admin is active'
AFTER summary;

-- Add index
CREATE INDEX IF NOT EXISTS idx_admin_timeout ON chat_sessions(last_admin_message_at);

-- Verify
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA='autobot' 
  AND TABLE_NAME='chat_sessions' 
  AND COLUMN_NAME='last_admin_message_at';

SELECT '✅ Admin handoff column ready!' AS status;
```

6. ถ้าเห็น `✅ Admin handoff column ready!` แสดงว่าสำเร็จ!

---

## วิธีที่ 2: ใช้ Cloud Shell แบบ Beta

```bash
cd /opt/lampp/htdocs/autobot
chmod +x migrate_db_beta.sh
./migrate_db_beta.sh
```

---

## วิธีที่ 3: ใช้ SQL Editor ใน GCP Console

1. ไปที่: https://console.cloud.google.com/sql/instances/autobot-db/query?project=autobot-prod-251215-22549

2. Paste SQL ด้านบน

3. คลิก **"RUN"**

---

## ✅ หลังจาก Migration เสร็จ:

### ทดสอบ Admin Handoff:

1. เปิด Facebook Messenger
2. พิมพ์: **admin**
3. Bot ควรหยุดตอบทันที ✅

### ตรวจสอบ Logs:

```bash
gcloud logging tail \
  --service=autobot \
  --project=autobot-prod-251215-22549 \
  --filter="textPayload=~\"ADMIN_HANDOFF\""
```

คุณควรเห็น:
- `[ADMIN_HANDOFF] manual command detected` - เมื่อพิมพ์ admin
- `[ADMIN_HANDOFF] Bot paused - admin active` - เมื่อส่งข้อความอื่น
- `[ADMIN_HANDOFF] Timeout expired` - หลังผ่าน 1 ชม.

---

## 🎯 Expected Behavior:

| Action | Bot Response | Duration |
|--------|-------------|----------|
| พิมพ์ "admin" | ไม่ตอบ | ทันที |
| ส่งข้อความอื่นใดๆ | ไม่ตอบ | 1 ชั่วโมง |
| หลัง 1 ชม. ไม่มี admin message | กลับมาตอบปกติ | อัตโนมัติ |

---

## 📞 ถ้ายังไม่ทำงาน:

ดู logs แบบละเอียด:
```bash
gcloud logging read \
  "resource.type=cloud_run_revision AND resource.labels.service_name=autobot AND severity>=INFO" \
  --limit=50 \
  --project=autobot-prod-251215-22549 \
  --format="table(timestamp, severity, textPayload)"
```
