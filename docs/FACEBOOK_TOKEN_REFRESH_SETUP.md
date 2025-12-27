# Facebook Token Auto-Refresh - Setup Guide

## 📋 สรุป

ระบบต่ออายุ Facebook Page Access Token อัตโนมัติ เพื่อป้องกัน token หมดอายุ (60 วัน)

---

## 🚀 ขั้นตอนการติดตั้ง

### 1. อัปเดต Database Schema

รัน migration script:

```bash
cd /opt/lampp/htdocs/autobot
mysql -u root -p autobot < database/migrations/2025_12_18_add_token_expiry_tracking.sql
```

หรือรันใน phpMyAdmin:
```sql
ALTER TABLE customer_channels 
ADD COLUMN token_expires_at DATETIME DEFAULT NULL AFTER config,
ADD COLUMN token_last_refreshed_at DATETIME DEFAULT NULL AFTER token_expires_at;

CREATE INDEX idx_token_expiry ON customer_channels(type, token_expires_at, status);
```

### 2. เพิ่ม App ID ใน Config

⚠️ **สำคัญ**: ต้องมี `app_id` ใน config ด้วย (ตอนนี้มีแค่ `app_secret`)

**ตั วเลือก A**: ใช้ Global App ID (แนะนำ)

เพิ่มใน `.env` หรือ environment variable:
```bash
FACEBOOK_APP_ID=your_app_id_here
```

**ตัวเลือก B**: เพิ่มใน config แต่ละ channel

แก้ config ของแต่ละ Facebook channel ให้มี `app_id`:
```json
{
  "page_access_token": "EAA...",
  "app_secret": "abc123...",
  "app_id": "123456789",
  "page_id": "..."
}
```

### 3. ทดสอบ Token Refresh (Dry Run)

```bash
/opt/lampp/bin/php scripts/refresh_facebook_tokens.php --dry-run
```

ผลลัพธ์ควรแสดง:
- จำนวน channels ทั้งหมด
- รายการที่จะถูก refresh
- ไม่มี error

### 4. ทดสอบจริง (ครั้งเดียว)

```bash
/opt/lampp/bin/php scripts/refresh_facebook_tokens.php --force
```

เช็คว่า token ถูกต่ออายุ:
```sql
SELECT id, name, 
       token_expires_at,
       token_last_refreshed_at,
       DATEDIFF(token_expires_at, NOW()) as days_left
FROM customer_channels 
WHERE type = 'facebook';
```

### 5. ติดตั้ง Cron Job

```bash
cd /opt/lampp/htdocs/autobot/scripts
chmod +x setup_facebook_token_cron.sh
./setup_facebook_token_cron.sh
```

ตรวจสอบว่า cron ถูกติดตั้ง:
```bash
crontab -l | grep refresh_facebook_tokens
```

ควรเห็น:
```
0 3 * * * /opt/lampp/bin/php /opt/lampp/htdocs/autobot/scripts/refresh_facebook_tokens.php >> /opt/lampp/htdocs/autobot/logs/token_refresh.log 2>&1
```

---

## 📊 การตรวจสอบ

### ดู Log

```bash
tail -f /opt/lampp/htdocs/autobot/logs/token_refresh.log
```

### เช็ค Token Status

```sql
SELECT 
    id,
    name,
    token_expires_at,
    DATEDIFF(token_expires_at, NOW()) as days_until_expiry,
    CASE 
        WHEN token_expires_at IS NULL THEN '⚠️ No expiry set'
        WHEN DATEDIFF(token_expires_at, NOW()) < 10 THEN '🔴 Needs refresh'
        WHEN DATEDIFF(token_expires_at, NOW()) < 30 THEN '🟡 Will refresh soon'
        ELSE '🟢 OK'
    END as status
FROM customer_channels
WHERE type = 'facebook' AND status = 'active'
ORDER BY token_expires_at ASC;
```

### รัน Manual Refresh

```bash
# Preview only
/opt/lampp/bin/php scripts/refresh_facebook_tokens.php --dry-run

# Force refresh all
/opt/lampp/bin/php scripts/refresh_facebook_tokens.php --force

# Normal (only expiring tokens)
/opt/lampp/bin/php scripts/refresh_facebook_tokens.php
```

---

## ⚙️ การทำงาน

1. **Cron รันทุกวัน 03:00**
2. **เช็คทุก Facebook channel** ที่ active
3. **ต่ออายุถ้า**: 
   - `token_expires_at` < 10 วัน
   - หรือ `token_expires_at` เป็น NULL
4. **เรียก Facebook API**: `/oauth/access_token`
5. **อัปเดต database**:
   - `config.page_access_token` = token ใหม่
   - `token_expires_at` = NOW + 60 days
   - `token_last_refreshed_at` = NOW

---

## 🔍 Troubleshooting

### ปัญหา: "Missing credentials"

**สาเหตุ**: ไม่มี `app_id` หรือ `app_secret`

**วิธีแก้**: 
- ตั้ง `FACEBOOK_APP_ID` ใน environment
- หรือเพิ่ม `app_id` ใน channel config

### ปัญหา: "Token refresh FAILED"

**สาเหตุที่เป็นไปได้**:
1. App secret ไม่ถูกต้อง
2. Token หมดอายุไปนานเกินไป (> 90 วัน) → ต้องสร้างใหม่จาก Facebook
3. App ไม่มีสิทธิ์

**วิธีแก้**:
1. เช็ค log: `tail -f logs/token_refresh.log`
2. ลอง manual refresh: `php scripts/refresh_facebook_tokens.php --force`
3. ถ้ายังไม่ได้ → สร้าง token ใหม่จาก Facebook Developer Console

### ปัญหา: Cron ไม่รัน

**เช็ค**:
```bash
# ตรวจสอบว่า cron job ถูกติดตั้ง
crontab -l

# ดู cron log (Ubuntu/Debian)
grep CRON /var/log/syslog

# ดู script log
cat logs/token_refresh.log
```

---

## 📝 Important Notes

1. **App ID ต้องเหมือนกับ App ที่ออก token**
2. **Token ที่หมดอายุนาน > 90 วัน ต้องสร้างใหม่** (ไม่สามารถต่ออายุได้)
3. **Cron รันทุกวัน แต่ต่ออายุเฉพาะที่ใกล้หมดอายุ** (< 10 วัน)
4. **Token ใหม่จะอายุ 60 วัน** จากวันที่ต่ออายุ

---

## 🎯 Best Practices

1. **Monitor logs ทุกสัปดาห์** เพื่อเช็คว่า refresh สำเร็จ
2. **Set up alert** ถ้า token จะหมดอายุใน 5 วัน
3. **Backup config** ก่อน mass update
4. **Test กับ 1 channel ก่อน** ถ้าจะ force refresh ทั้งหมด

---

## 🔗 Related Files

- Script: [`scripts/refresh_facebook_tokens.php`](file:///opt/lampp/htdocs/autobot/scripts/refresh_facebook_tokens.php)
- Migration: [`database/migrations/2025_12_18_add_token_expiry_tracking.sql`](file:///opt/lampp/htdocs/autobot/database/migrations/2025_12_18_add_token_expiry_tracking.sql)
- Cron Setup: [`scripts/setup_facebook_token_cron.sh`](file:///opt/lampp/htdocs/autobot/scripts/setup_facebook_token_cron.sh)
- Implementation Plan: [implementation_plan.md](file:///home/saranyoo/.gemini/antigravity/brain/80705242- ce0d-48d5-ad3b-ab97c71102cc/implementation_plan.md)
