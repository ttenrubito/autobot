# 🎯 ขั้นตอนสุดท้าย - พร้อมทดสอบ

## ⏳ สถานะปัจจุบัน

✅ **แก้ไขโค้ดเสร็จแล้ว:**
- API: บันทึก `document_label` ลง database
- LIFF: ส่ง `document_label` ไปกับ upload request  
- Migration: SQL script พร้อมแก้ไข campaign labels

🔄 **กำลัง Deploy...**
- Deployment task กำลังทำงานอยู่
- ใช้เวลาประมาณ 3-5 นาที

---

## 📋 หลัง Deploy เสร็จ (รอ 3-5 นาที)

### Step 1: รัน Migration Script
```bash
cd /opt/lampp/htdocs/autobot
./run_migration_api.sh
```

**หรือทดสอบด้วยสคริปต์อัตโนมัติ:**
```bash
./test_document_labels.sh
```

---

## 🧪 ทดสอบระบบ

### Test 1: เปิด LIFF Form
```
https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026
```

**ต้องเห็น:**
- ✅ "บัตรประชาชน *" (required, แสดงดอกจันสีแดง)
- ✅ "ทะเบียนบ้าน" (optional)
- ❌ ห้ามเห็น "เอกสาร" (ถ้าเห็นแปลว่า migration ยังไม่ทำ)

### Test 2: อัปโหลดเอกสาร
1. กรอกฟอร์มให้ครบ
2. คลิก "📷 อัพโหลดบัตรประชาชน"
3. เลือกรูปบัตรประชาชน (JPG/PNG < 5MB)
4. ดูที่ preview ต้องเห็นรูป
5. คลิก "✅ ส่งข้อมูล"
6. รอ... ต้องเห็น "✅ ส่งข้อมูลสมัครเรียบร้อยแล้ว"

### Test 3: ตรวจสอบที่ Admin Panel
```
https://autobot.boxdesign.in.th/line-applications.php
```

1. **Login** (ถ้ายังไม่ได้ login)
2. หาใบสมัครที่เพิ่งสร้าง (ด้านบนสุด)
3. **คลิกที่แถว** เพื่อดูรายละเอียด
4. ดูที่ด้านขวา section "📄 เอกสาร"

**ต้องเห็น:**
```
📄 เอกสาร (1)

┌─────────────────────────────────┐
│ บัตรประชาชน                     │ ← ต้องเป็นภาษาไทย!
│ 📎 filename.jpg (123 KB)        │
│ 🕐 2026-01-04 12:30:45          │
└─────────────────────────────────┘
```

**ถ้าไม่เห็น = ยังมีปัญหา!**

---

## 🐛 ถ้ายังไม่เห็นเอกสาร

### Debug 1: เช็ค Browser Console
1. เปิด LIFF ใน LINE
2. กด F12 (Developer Tools)
3. ไปที่ Console tab
4. อัปโหลดไฟล์อีกครั้ง
5. ดู log ต้องเห็น:
   ```
   📤 Uploading document: { documentLabel: "บัตรประชาชน", ... }
   ✅ File converted to base64
   🌐 Uploading to: ...
   ✅ Upload result: { success: true, ... }
   ```

ถ้ามี error = บันทึก error message มา

### Debug 2: เช็ค Database
```bash
curl "https://autobot.boxdesign.in.th/deep_debug_docs.php"
```

ดู section:
- **Campaign Configuration:** ต้องมี labels ภาษาไทย
- **Actual Documents:** ต้องเห็น `document_label` column มีค่า
- **Issue Analysis:** ต้องขึ้น "No obvious issues detected"

### Debug 3: เช็ค API Response
```bash
# ดู campaign config
curl "https://autobot.boxdesign.in.th/api/lineapp/campaigns.php?id=2"

# ควรเห็น
# "label":"บัตรประชาชน"
```

---

## 🎯 Checklist ความสำเร็จ

- [ ] Deployment เสร็จแล้ว (รอ 3-5 นาที)
- [ ] รัน migration script แล้ว
- [ ] LIFF แสดง "บัตรประชาชน" (ไม่ใช่ "เอกสาร")
- [ ] อัปโหลดไฟล์สำเร็จ (console ไม่มี error)
- [ ] Admin panel แสดงเอกสารพร้อม label ภาษาไทย
- [ ] deep_debug_docs.php แสดง "No obvious issues"

**ถ้าครบทุกข้อ = ระบบใช้งานได้แล้ว! 🎉**

---

## 📞 สรุป

**ปัญหา:** เอกสารอัปโหลดแล้วไม่แสดง  
**สาเหตุ:** `document_label` ไม่ได้บันทึกลง database  
**แก้ไข:**
1. API บันทึก label
2. LIFF ส่ง label  
3. Campaign มี labels ภาษาไทย

**ไฟล์ที่แก้:**
- `api/lineapp/documents.php`
- `liff/application-form.html`
- Database (via migration)

**เอกสาร:**
- `CRITICAL_BUG_FIX_DOCUMENT_LABELS.md` - รายละเอียดการแก้ไข
- `test_document_labels.sh` - สคริปต์ทดสอบ
- `deep_debug_docs.php` - หน้า debug

---

**รอ deployment เสร็จ แล้วทดสอบตามขั้นตอนข้างบน!**
