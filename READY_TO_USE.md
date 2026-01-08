# ✅ พร้อมใช้งานแล้ว! - ขั้นตอนสุดท้าย

## 🎯 สิ่งที่ทำเสร็จแล้ว:

### 1. ✅ Code พร้อมแล้ว
- Dynamic document fields
- Google Cloud Storage integration  
- Backward compatibility (รองรับทั้งกรณีที่มีและไม่มี GCS columns)

### 2. ✅ Deployment กำลังดำเนินการ
- Service: `autobot`
- Region: `asia-southeast1`
- URL: `https://autobot.boxdesign.in.th`

### 3. ✅ Migration API พร้อมแล้ว
- Endpoint: `/api/admin/migrate-gcs.php`
- รัน migration ผ่าน web browser ได้เลย!

---

## 🚀 ขั้นตอนสุดท้าย (ทำเลย!):

### Step 1: รัน Migration (เลือก 1 วิธี)

**วิธีที่ 1: ใช้ Web Browser (ง่ายที่สุด!) ⭐**

เปิด URL นี้ในเบราว์เซอร์:
```
https://autobot.boxdesign.in.th/api/admin/migrate-gcs.php?secret=migrate-gcs-2026-01-04
```

**คาดหวัง:**
```json
{
    "success": true,
    "message": "Migration completed successfully!",
    "results": [
        "✅ Added gcs_path column",
        "✅ Added gcs_signed_url column",
        "✅ Added gcs_signed_url_expires_at column",
        "✅ Created index on gcs_path",
        "✅ Updated DEMO2026 campaign config"
    ]
}
```

**วิธีที่ 2: ใช้ Terminal**
```bash
curl "https://autobot.boxdesign.in.th/api/admin/migrate-gcs.php?secret=migrate-gcs-2026-01-04"
```

**วิธีที่ 3: ใช้ Cloud Console**
```bash
# Connect to Cloud SQL
gcloud sql connect autobot-db --user=root --database=autobot_db

# Run SQL
ALTER TABLE application_documents ADD COLUMN IF NOT EXISTS gcs_path VARCHAR(500);
ALTER TABLE application_documents ADD COLUMN IF NOT EXISTS gcs_signed_url TEXT;
ALTER TABLE application_documents ADD COLUMN IF NOT EXISTS gcs_signed_url_expires_at DATETIME;
UPDATE campaigns SET required_documents = '[{"type":"id_card","label":"บัตรประชาชน","required":true,"accept":"image/*"},{"type":"house_registration","label":"ทะเบียนบ้าน","required":false,"accept":"image/*,application/pdf"}]' WHERE code = 'DEMO2026';
```

---

### Step 2: ทดสอบทันที!

1. **เปิด LIFF Form:**
   ```
   https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026
   ```

2. **คาดหวัง:**
   - เห็น 2 ช่องอัพโหลด:
     * "บัตรประชาชน" (required) ⭐
     * "ทะเบียนบ้าน" (optional)
   
3. **กรอกฟอร์ม + อัพโหลดไฟล์**

4. **กด "ส่งข้อมูล"**

5. **คาดหวังเห็น:**
   ```
   ✅ ส่งข้อมูลสมัครเรียบร้อยแล้ว!
   📎 อัพโหลดเอกสาร: บัตรประชาชน
   
   ระบบจะแจ้งผลให้ทราบภายหลัง
   ```

---

### Step 3: ตรวจสอบ Admin Panel

```
https://autobot.boxdesign.in.th/admin/line-applications.php
```

หรือ API:
```
https://autobot.boxdesign.in.th/api/admin/line-applications.php?id=<APPLICATION_ID>
```

**คาดหวังเห็น:**
```json
"documents": [
    {
        "id": 1,
        "document_type": "id_card",
        "file_name": "บัตรประชาชน.jpg",
        "gcs_path": "documents/U.../doc_xxx.jpg",
        "gcs_signed_url": "https://storage.googleapis.com/...",
        "uploaded_at": "2026-01-04 ..."
    }
]
```

---

## 🎉 หาก Migration สำเร็จ:

✅ ระบบพร้อมใช้งานแล้ว!
✅ อัพโหลดไฟล์ไปที่ Google Cloud Storage
✅ ดูเอกสารผ่าน Signed URLs
✅ รองรับหลายประเภทเอกสาร (dynamic)

---

## 🔐 Security Note:

**⚠️ ลบไฟล์นี้หลัง migration เสร็จ:**
```bash
rm /opt/lampp/htdocs/autobot/api/admin/migrate-gcs.php
```

หรือ redeploy โดยไม่รวมไฟล์นี้

---

## 📊 ตรวจสอบ GCS Bucket (Optional):

```bash
# ดูไฟล์ที่ upload แล้ว
gsutil ls -r gs://autobot-documents/documents/

# ดูรายละเอียด
gsutil ls -L gs://autobot-documents/documents/U.../doc_xxx.jpg
```

---

## 🐛 Troubleshooting:

### ปัญหา: Migration API ไม่ตอบ
```bash
# ตรวจสอบ deployment status
gcloud run services describe autobot --region=asia-southeast1

# ดู logs
gcloud run services logs read autobot --region=asia-southeast1 --limit=50
```

### ปัญหา: ยังเห็น "documents": []
1. ตรวจสอบว่า migration ทำงานแล้ว:
   ```sql
   SHOW COLUMNS FROM application_documents LIKE 'gcs%';
   ```

2. ตรวจสอบ campaign config:
   ```bash
   curl "https://autobot.boxdesign.in.th/api/lineapp/campaigns.php?code=DEMO2026"
   ```

3. ตรวจสอบ logs:
   ```bash
   gcloud run services logs read autobot --region=asia-southeast1 | grep "LINEAPP_DOCS"
   ```

---

## 📞 Support:

- **Migration API:** `https://autobot.boxdesign.in.th/api/admin/migrate-gcs.php?secret=migrate-gcs-2026-01-04`
- **LIFF Form:** `https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026`
- **Admin Panel:** `https://autobot.boxdesign.in.th/admin/`
- **Logs:** `gcloud run services logs read autobot --region=asia-southeast1`

---

**Status:** ✅ READY - รัน migration แล้วใช้งานได้เลย!

**Date:** 2026-01-04
