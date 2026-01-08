# Admin Handoff Deployment Checklist

## ✅ สิ่งที่ทำไปแล้ว (Local):

1. ✅ แก้ `RouterV1Handler.php` เพิ่ม admin handoff logic
   - ✅ Manual command: `admin`, `/admin`, `#admin`  
   - ✅ Webhook admin detection (Facebook `is_echo`, LINE whitelist)
   - ✅ 1-hour timeout pause
   - ✅ Auto-resume after timeout

2. ✅ สร้าง migration file: `database/migrations/add_admin_handoff_timeout.sql`
3. ✅ รัน migration ใน localhost DB
4. ✅ สร้าง deployment rules: `DEVELOPMENT_RULES.md`
5. ✅ สร้าง pre-commit hook
6. ✅ สร้าง unit test framework

---

## 🚨 สิ่งที่ต้องทำต่อ (Production):

### Step 1: Deploy Migration to Production DB

```bash
# รัน migration script
./deploy_admin_handoff_migration_to_prod.sh
```

**หรือ manual:**
```bash
gcloud sql connect autobot-db \
  --project=autobot-prod-251215-22549 \
  --database=autobot \
  < database/migrations/add_admin_handoff_timeout.sql
```

**Verify:**
```sql
SHOW COLUMNS FROM chat_sessions LIKE 'last_admin_message_at';
```

---

### Step 2: Deploy Code to Production

```bash
# Deploy พร้อมทดสอบอัตโนมัติ
./deploy_app_to_production.sh
```

**หรือ skip tests (emergency only):**
```bash
SKIP_TESTS=1 ./deploy_app_to_production.sh
```

---

### Step 3: Test in Production

#### Test 1: Manual "admin" command (Facebook)

1. ไปที่ Facebook Page Inbox
2. เปิดแชทกับ user ที่ bot กำลังตอบ
3. พิมพ์ `admin` (จาก Page account)
4. **Expected:** Bot หยุดตอบทันที
5. User พิมพ์อะไรมา → Bot ไม่ตอบ (เก็บข้อความไว้อย่างเดียว)

#### Test 2: Manual "/admin" command (LINE)

1. ไปที่ LINE Official Account Manager
2. เปิดแชท Admin mode
3. พิมพ์ `/admin`
4. **Expected:** Bot หยุดตอบทันที

#### Test 3: Timeout Resume (1 hour later)

1. รอ 1 ชั่วโมง (หรือแก้ timeout ใน code เป็น 60 วินาทีเพื่อทดสอบ)
2. User พิมพ์ข้อความใหม่
3. **Expected:** Bot กลับมาตอบปกติ

---

### Step 4: Monitor Production Logs

```bash
# ดู log แบบ realtime
gcloud run services logs tail autobot \
  --project=autobot-prod-251215-22549 \
  --region=asia-southeast1

# Filter เฉพาะ admin handoff
gcloud run services logs read autobot \
  --project=autobot-prod-251215-22549 \
  --region=asia-southeast1 \
  --limit=100 \
  | grep -i "ADMIN_HANDOFF"
```

**Log ที่ต้องเจอถ้าทำงานถูกต้อง:**

```
[ADMIN_HANDOFF] Manual command detected
[ADMIN_HANDOFF] Updated last_admin_message_at
[ADMIN_HANDOFF] Admin still active - bot paused
```

---

## 🐛 Troubleshooting

### ปัญหา 1: Bot ยังตอบต่อแม้พิมพ์ "admin"

**สาเหตุที่เป็นไปได้:**

1. ❌ Code ยังไม่ deploy (ยังเป็น revision เก่า)
   ```bash
   # Check revision
   gcloud run services describe autobot \
     --region=asia-southeast1 \
     --project=autobot-prod-251215-22549 \
     --format="value(status.latestReadyRevisionName)"
   ```

2. ❌ DB ยังไม่มี column
   ```bash
   gcloud sql connect autobot-db \
     --project=autobot-prod-251215-22549 \
     --database=autobot
   
   SHOW COLUMNS FROM chat_sessions;
   ```

3. ❌ Webhook ไม่ส่ง `is_admin` flag
   - ตรวจสอบ `api/webhooks/facebook.php` line ~130
   - ตรวจสอบ `api/webhooks/line.php` line ~80

### ปัญหา 2: Admin พิมพ์แล้ว bot หยุด แต่ไม่กลับมาตอบหลัง 1 ชม

**สาเหตุ:** Logic clear timeout อาจไม่ทำงาน

**แก้:**
```php
// ใน RouterV1Handler.php ประมาณ line 285
if ($lastAdminTime && $timeSinceAdmin >= $adminActiveThreshold) {
    // Clear timeout และ resume
    $this->db->execute(
        'UPDATE chat_sessions SET last_admin_message_at = NULL WHERE id = ?',
        [$sessionId]
    );
}
```

### ปัญหา 3: Facebook Page reply ไม่ถูก detect เป็น admin

**สาเหตุ:** `is_echo` flag ไม่ทำงาน

**แก้:** เพิ่มเช็ค `sender.id === page.id`
```php
// api/webhooks/facebook.php
$senderId = $messaging['sender']['id'] ?? null;
$recipientId = $messaging['recipient']['id'] ?? null;

if ($senderId === $pageId || $isEcho) {
    $isAdmin = true;
}
```

---

## 📝 Quick Commands

```bash
# 1. Deploy migration
./deploy_admin_handoff_migration_to_prod.sh

# 2. Deploy code
./deploy_app_to_production.sh

# 3. Watch logs
gcloud run services logs tail autobot \
  --project=autobot-prod-251215-22549 \
  --region=asia-southeast1 \
  | grep -i admin

# 4. Test locally first
php test_admin_handoff_local.php
```

---

Last updated: 2025-12-27
