# 🎯 QUICK FIX SUMMARY - Document Upload Issue

## ปัญหา
- อัพโหลดไฟล์แล้ว แต่ไม่ปรากฏใน admin panel
- `"documents": []` ว่างเปล่า

## สาเหตุ
1. ❌ Table `application_documents` ยังไม่มี columns: `gcs_path`, `gcs_signed_url`, `gcs_signed_url_expires_at`
2. ❌ Campaign config มี `"label": ""` (ว่างเปล่า)

## วิธีแก้ (ทำตามลำดับ)

### 1. รัน Migration SQL

```bash
# วิธีที่ 1: ใช้ gcloud (แนะนำ)
gcloud sql connect autobot-db --user=root --database=autobot_db

# จากนั้นรันคำสั่ง SQL:
```

```sql
USE autobot_db;

-- Add GCS columns
ALTER TABLE application_documents
ADD COLUMN IF NOT EXISTS gcs_path VARCHAR(500),
ADD COLUMN IF NOT EXISTS gcs_signed_url TEXT,
ADD COLUMN IF NOT EXISTS gcs_signed_url_expires_at DATETIME;

CREATE INDEX IF NOT EXISTS idx_gcs_path ON application_documents(gcs_path);

-- Fix campaign
UPDATE campaigns
SET required_documents = '[
    {"type":"id_card","label":"บัตรประชาชน","required":true,"accept":"image/*"},
    {"type":"house_registration","label":"ทะเบียนบ้าน","required":false,"accept":"image/*,application/pdf"}
]'
WHERE code = 'DEMO2026';
```

### 2. ทดสอบทันที

1. **เปิด LIFF Form:**
   ```
   https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026
   ```

2. **คาดหวัง:**
   - เห็น 2 ช่องอัพโหลด: "บัตรประชาชน" (required), "ทะเบียนบ้าน" (optional)
   - อัพโหลดไฟล์ได้
   - เห็นข้อความสำเร็จพร้อมชื่อไฟล์

3. **ตรวจสอบ Admin Panel:**
   ```
   https://autobot.boxdesign.in.th/api/admin/line-applications.php?id=1
   ```

4. **ดู documents array:**
   ```json
   "documents": [
       {
           "id": 1,
           "document_type": "id_card",
           "file_name": "บัตรประชาชน.jpg",
           "gcs_path": "documents/U.../doc_xxx.jpg",
           "uploaded_at": "..."
       }
   ]
   ```

### 3. ตรวจสอบ GCS Bucket (Optional)

```bash
gsutil ls -r gs://autobot-documents/documents/
```

---

## หาก Migration สำเร็จแล้ว

✅ ไฟล์จะถูก upload ไปที่ Google Cloud Storage  
✅ ระบบจะ generate signed URL อัตโนมัติ  
✅ Admin panel จะแสดง documents ได้

---

## หากยังไม่ได้

**ตรวจสอบว่า columns ถูกสร้างหรือยัง:**

```sql
SHOW COLUMNS FROM application_documents LIKE 'gcs%';
```

**ควรเห็น:**
- gcs_path
- gcs_signed_url  
- gcs_signed_url_expires_at

---

## สถานะ Migration

⏳ **กำลังรัน:** `gcloud sql connect autobot-db ...`  
📝 **SQL File:** `/tmp/fix_documents.sql`  
🎯 **Target:** Production database `autobot_db`

---

**หลังจากนี้ลองใหม่ทันที!** 🚀
