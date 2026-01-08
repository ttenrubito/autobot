# 🚨 ADMIN HANDOFF - FINAL DEPLOYMENT STEPS

## ปัญหาที่พบ:
- ✅ Code มี admin handoff logic แล้ว
- ✅ Local database มี column แล้ว  
- ❌ **Production code ยังเป็น version เก่า (15:39 วันนี้)**
- ❓ **Production database ยังไม่มี column (ต้องเช็ค)**

---

## 🎯 ทำตามนี้เพื่อแก้ปัญหา:

### ขั้นตอนที่ 1: Deploy Code ไป Production
```bash
cd /opt/lampp/htdocs/autobot
./DEPLOY_THIS_NOW.sh
```
**รอจนเสร็จ** (ประมาณ 3-5 นาที)

---

### ขั้นตอนที่ 2: เพิ่ม Column ใน Production Database
```bash
cd /opt/lampp/htdocs/autobot
./FIX_PROD_DB_NOW.sh
```
**ใส่รหัสผ่าน MySQL ของ production** เมื่อถูกถาม

---

### ขั้นตอนที่ 3: ทดสอบ
1. เปิด Facebook Messenger
2. พิมพ์: **admin** (ตัวพิมพ์เล็กทั้งหมด)
3. Bot ควร**หยุดตอบ**ทันที
4. ส่งข้อความอื่นๆ - Bot **ไม่ควรตอบเป็นเวลา 1 ชั่วโมง**

---

### ขั้นตอนที่ 4: ตรวจสอบ Logs
```bash
# ดู logs แบบ real-time
gcloud logging tail --service=autobot --project=autobot-prod-251215-22549

# หรือค้นหา admin handoff logs
gcloud logging read "resource.type=cloud_run_revision AND resource.labels.service_name=autobot AND textPayload=~\"ADMIN_HANDOFF\"" \
  --limit=20 \
  --project=autobot-prod-251215-22549 \
  --format="table(timestamp, textPayload)"
```

---

## 📋 คำสั่ง Admin ที่ใช้ได้:
- `admin` (ตัวพิมพ์เล็ก)
- `Admin` (ตัวใหญ่)
- `ADMIN` (ตัวใหญ่ทั้งหมด)
- `/admin`
- `#admin`

---

## 🔍 ตรวจสอบว่า Deploy สำเร็จ:
```bash
gcloud run services describe autobot \
  --region=asia-southeast1 \
  --project=autobot-prod-251215-22549 \
  --format="value(status.latestReadyRevisionName, metadata.generation)"
```

---

## ⚠️ ถ้ายังไม่ทำงาน:

### ตรวจสอบ 1: Code ถูก deploy หรือยัง
```bash
gcloud run revisions describe [REVISION_NAME] \
  --region=asia-southeast1 \
  --project=autobot-prod-251215-22549 \
  --format="value(metadata.creationTimestamp)"
```
**ต้องใหม่กว่า** เวลาที่คุณรัน deploy

### ตรวจสอบ 2: Database มี column หรือยัง
```bash
gcloud sql connect autobot-db --user=root --project=autobot-prod-251215-22549
```
แล้วรัน:
```sql
USE autobot;
SHOW COLUMNS FROM chat_sessions LIKE 'last_admin_message_at';
```

### ตรวจสอบ 3: ดู logs มี error หรือไม่
```bash
gcloud logging read "resource.type=cloud_run_revision AND resource.labels.service_name=autobot AND severity>=ERROR" \
  --limit=50 \
  --project=autobot-prod-251215-22549 \
  --freshness=1h
```

---

## 🎯 Expected Behavior:

1. **User พิมพ์ "admin"** → Bot **ไม่ตอบ**, log จะมี: `[ADMIN_HANDOFF] manual command detected`
2. **User ส่งข้อความอื่น** → Bot **ไม่ตอบ**, log จะมี: `[ADMIN_HANDOFF] Bot paused - admin active`
3. **หลังผ่าน 1 ชั่วโมง** → Bot **กลับมาตอบปกติ**, log จะมี: `[ADMIN_HANDOFF] Timeout expired, resuming bot`

---

## 📞 หากยังมีปัญหา:

ส่ง log ตรงนี้มาให้ดู:
```bash
gcloud logging read "resource.type=cloud_run_revision AND resource.labels.service_name=autobot AND timestamp>=\"$(date -u -d '10 minutes ago' +%Y-%m-%dT%H:%M:%S)Z\"" \
  --limit=100 \
  --project=autobot-prod-251215-22549 \
  --format=json > last_10min_logs.json
```
