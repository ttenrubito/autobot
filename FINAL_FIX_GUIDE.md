# 🔴 ปัญหา: เอกสารที่แนบไม่แสดงใน Admin Panel

## 📊 สถานะปัจจุบัน

### ✅ สิ่งที่ทำงานแล้ว:
- GCS columns เพิ่มแล้ว (`gcs_path`, `gcs_signed_url`, `gcs_signed_url_expires_at`)
- Code พร้อมอัพโหลดไปยัง GCS
- LIFF form มี dynamic document fields

### ❌ ปัญหาที่เหลือ:
- **Campaign `required_documents` label ยังว่างเปล่า**
- ทำให้ LIFF form แสดงเป็น "เอกสาร" (fallback) แทนที่จะเป็น "บัตรประชาชน"
- ผู้ใช้อาจสับสน ไม่รู้ว่าต้องแนบอะไร

## 🎯 การแก้ไข (ทำ 1 ครั้ง):

### วิธีที่แนะนำ: ใช้ Cloud Console

**1. เปิด Cloud SQL Console:**
```
https://console.cloud.google.com/sql/instances/autobot-db/overview?project=canvas-radio-472913-d4
```

**2. คลิก "CONNECT USING CLOUD SHELL"**

**3. ใน Cloud Shell พิมพ์:**
```bash
gcloud sql connect autobot-db --user=root
```

**4. เมื่อเข้า MySQL แล้ว:**
```sql
USE autobot_db;

UPDATE campaigns
SET required_documents = '[
    {"type":"id_card","label":"บัตรประชาชน","required":true,"accept":"image/*"},
    {"type":"house_registration","label":"ทะเบียนบ้าน","required":false,"accept":"image/*,application/pdf"}
]'
WHERE code = 'DEMO2026';

-- ตรวจสอบ
SELECT code, required_documents FROM campaigns WHERE code = 'DEMO2026'\G

-- ออกจาก MySQL
exit
```

## 🧪 ทดสอบหลังแก้:

**1. ตรวจสอบ API:**
```bash
curl "https://autobot.boxdesign.in.th/api/lineapp/campaigns.php?code=DEMO2026" | grep -A 5 "required_documents"
```

**ควรเห็น:**
```json
"required_documents": [
    {
        "type": "id_card",
        "label": "บัตรประชาชน",  ← ต้องมีค่า!
        "required": true
    },
    {
        "type": "house_registration",
        "label": "ทะเบียนบ้าน",
        "required": false
    }
]
```

**2. เปิด LIFF Form:**
```
https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026
```

**ควรเห็น:**
- ✅ บัตรประชาชน (required) *
- ⭕ ทะเบียนบ้าน (optional)

**3. ทดสอบอัพโหลด:**
- เลือกไฟล์จากมือถือ
- กดส่งข้อมูล
- ระบบจะแสดง: "อัพโหลดเอกสาร: บัตรประชาชน, ทะเบียนบ้าน"

**4. ตรวจสอบ Admin Panel:**
```
https://autobot.boxdesign.in.th/line-applications.php
```

**ควรเห็น:**
- Tab "เอกสาร" มีรูปที่อัพโหลด
- มี signed URL สำหรับดู/ดาวน์โหลด

## 🔍 วิธีตรวจสอบว่าแก้สำเร็จ:

```bash
# Test 1: Campaign config
curl -s "https://autobot.boxdesign.in.th/api/lineapp/campaigns.php?code=DEMO2026" | \
  python3 -c "import sys,json; d=json.load(sys.stdin); print('Labels OK!' if d['data']['required_documents'][0]['label'] else 'Still empty!')"

# Test 2: Upload document
echo "Test" | base64 > /tmp/test.b64
curl -X POST "https://autobot.boxdesign.in.th/api/lineapp/documents.php" \
  -H "Content-Type: application/json" \
  -d "{\"application_id\":1,\"document_type\":\"test\",\"file_name\":\"test.txt\",\"file_data\":\"$(cat /tmp/test.b64)\",\"file_type\":\"text/plain\"}" | \
  python3 -c "import sys,json; d=json.load(sys.stdin); print('✅ Upload works!' if d.get('success') else '❌ Failed: '+d.get('message',''))"
```

## 💡 ทำไมเกิดปัญหา?

1. **REST API มีข้อจำกัด:**
   - SQL Admin API `executeStatement` ไม่รองรับ UTF-8/JSON ซับซ้อน
   - ต้องใช้ `mysql` client โดยตรง

2. **Fallback ใช้ได้ แต่ไม่ดี:**
   - Code มี fallback: `label || 'เอกสาร'`
   - ทำให้ไม่ error แต่ user experience แย่

3. **Solution:**
   - แก้ที่ database ให้ label มีค่าที่ถูกต้อง
   - ครั้งต่อไปจะไม่มีปัญหา

## 🚀 Timeline:

```
✅ GCS columns added          - Done (via migration_api.sh)
✅ Code deployed with GCS      - Done
❌ Campaign labels fixed       - ต้องทำใน Cloud Console
⏳ Test upload                 - หลังแก้ campaign
✅ Production ready            - หลังทดสอบสำเร็จ
```

---

**ประมาณเวลา:** 5 นาที (รวมเปิด Cloud Console + รัน SQL)

**ไฟล์ SQL:** `/opt/lampp/htdocs/autobot/FIX_CAMPAIGN_NOW.sql`
