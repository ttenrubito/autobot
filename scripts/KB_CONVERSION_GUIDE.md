# Quick Reference: Converting KB to Advanced Format

## วิธีแก้ปัญหาแบบรวดเร็ว (Quick Fix)

### ปัญหา
คิวรี "one piece" แมตช์กับ KB entry ที่ตอบเรื่อง "ที่อยู่ร้าน" แม้จะไม่มีคำว่า "ร้าน" ในคิวรี

### วิธีแก้ (3 ขั้นตอน)

#### 1. หา Entry ที่ต้องแก้

```bash
cd /opt/lampp/htdocs/autobot
/opt/lampp/bin/php scripts/convert_kb_to_advanced.php --list --search="one piece"
```

จดเลข `ID` ของ entry ที่ต้องการแก้

#### 2. ดู Preview ก่อนแก้

```bash
/opt/lampp/bin/php scripts/convert_kb_to_advanced.php --convert --id=YOUR_ID --dry-run
```

#### 3. แก้จริง

```bash
/opt/lampp/bin/php scripts/convert_kb_to_advanced.php --convert --id=YOUR_ID
```

หรือแก้ด้วย SQL โดยตรง (ใน phpMyAdmin):

```sql
UPDATE customer_knowledge_base
SET keywords = '{
  "mode": "advanced",
  "require_all": ["ร้าน"],
  "require_any": ["one piece", "วันพีซ", "onepiece"],
  "exclude_any": ["ที่อยู่ของฉัน", "ที่อยู่ผม", "บ้านฉัน", "บ้านผม"],
  "min_query_len": 6
}'
WHERE id = YOUR_ENTRY_ID;
```

---

## รูปแบบ Advanced Keywords

### Template สำหรับที่อยู่ร้าน

```json
{
  "mode": "advanced",
  "require_all": ["ร้าน"],
  "require_any": ["ชื่อร้าน1", "ชื่อร้าน2"],
  "exclude_any": ["ที่อยู่ของฉัน", "บ้านฉัน", "ของฉัน"],
  "min_query_len": 6
}
```

### Template สำหรับข้อมูลสินค้า

```json
{
  "mode": "advanced",
  "require_all": [],
  "require_any": ["ชื่อสินค้า1", "ชื่อสินค้า2"],
  "exclude_any": [],
  "min_query_len": 4
}
```

### Template สำหรับเวลาทำการ

```json
{
  "mode": "advanced",
  "require_all": ["เปิด"],
  "require_any": ["เวลา", "กี่โมง", "ปิด"],
  "exclude_any": [],
  "min_query_len": 5
}
```

---

## คำอธิบาย Fields

| Field | คำอธิบาย | ตัวอย่าง |
|-------|---------|---------|
| `mode` | ต้องเป็น `"advanced"` | `"advanced"` |
| `require_all` | ต้องมีครบทุกคำ | `["ร้าน"]` = ต้องมีคำว่า "ร้าน" |
| `require_any` | ต้องมีอย่างน้อย 1 คำ | `["one piece", "วันพีซ"]` |
| `exclude_any` | ห้ามมีคำเหล่านี้ | `["ของฉัน", "บ้านผม"]` |
| `min_query_len` | ความยาวขั้นต่ำของคำถาม | `6` = ต้องมีอย่างน้อย 6 ตัวอักษร |

---

## Test Cases

หลังจากแก้แล้ว ทดสอบด้วยคำถามเหล่านี้:

### ✅ ควรตอบ (Match)
- "ร้าน one piece อยู่ไหน"
- "ขอที่อยู่ร้าน one piece"
- "ร้านวันพีซพิกัด"
- "ร้าน onepiece ตั้งอยู่ที่ไหน"

### ❌ ไม่ควรตอบ (No Match)
- "one piece" (ขาดคำว่า "ร้าน")
- "วันพีซ" (ขาดคำว่า "ร้าน")
- "ที่อยู่ของฉันอยู่แถว one piece" (มีคำต้องห้าม "ของฉัน")
- "บ้านผม one piece" (มีคำต้องห้าม "บ้านผม")

---

## การใช้ Script

### ดู Help
```bash
/opt/lampp/bin/php scripts/convert_kb_to_advanced.php --help
```

### List ทั้งหมด
```bash
# ดู entry ทั้งหมดของ user
/opt/lampp/bin/php scripts/convert_kb_to_advanced.php --list --user-id=1

# ค้นหา entry ที่มีคำว่า "one piece"
/opt/lampp/bin/php scripts/convert_kb_to_advanced.php --list --search="one piece"
```

### Convert ทีละรายการ
```bash
# Preview (ไม่บันทึก)
/opt/lampp/bin/php scripts/convert_kb_to_advanced.php --convert --id=123 --dry-run

# Convert จริง
/opt/lampp/bin/php scripts/convert_kb_to_advanced.php --convert --id=123
```

### Convert แบบ Batch
```bash
# Convert ทั้งหมดของ user
/opt/lampp/bin/php scripts/convert_kb_to_advanced.php --convert-all --user-id=1

# Preview ก่อน (ไม่บันทึก)
/opt/lampp/bin/php scripts/convert_kb_to_advanced.php --convert-all --user-id=1 --dry-run
```

---

## เช็คผลลัพธ์

### ใน Database
```sql
SELECT id, category, keywords, question
FROM customer_knowledge_base
WHERE id = YOUR_ENTRY_ID;
```

ควรเห็น `keywords` เป็น JSON object ที่มี `"mode":"advanced"`

### ทดสอบจริง
ส่งข้อความผ่าน LINE/Facebook ตามตัวอย่าง Test Cases ด้านบน

---

## Best Practices

### ❗ สิ่งที่ควรทำ

1. **อย่าใส่คีย์เวิร์ดซ้ำใน question/answer**
   - ✅ ดี: keywords มี "one piece", question มี "ที่อยู่ร้าน"
   - ❌ แย่: keywords มี "one piece", question มีก "ร้าน one piece อยู่ไหน"

2. **ใช้ require_all สำหรับคำบริบท**
   - ที่อยู่ร้าน → `require_all: ["ร้าน"]`
   - ราคาสินค้า → `require_all: ["ราคา"]`

3. **ใส่คำที่หลากหลายใน require_any**
   - ภาษาอังกฤษ, ภาษาไทย, เขียนติด, เขียนเว้น

4. **ใส่ exclude_any เพื่อกันข้อความส่วนตัว**
   - "ของฉัน", "ของผม", "บ้านฉัน"

5. **ตั้ง min_query_len ให้เหมาะสม**
   - ที่อยู่ร้าน: 6+
   - ชื่อสินค้า: 4+
   - ทั่วไป: 3+

---

## Troubleshooting

### ปัญหา: ยังแมตช์อยู่หลังแก้แล้ว

**สาเหตุที่เป็นไปได้**:
1. มี entry อื่นที่ยังเป็น legacy format
2. Partial search จาก question/answer field (คำว่า "one piece" อยู่ใน question/answer)

**วิธีแก้**:
```bash
# หา entry ทั้งหมดที่เกี่ยวข้อง
php scripts/convert_kb_to_advanced.php --list --search="one piece"

# แก้ทุกรายการ
```

### ปัญหา: ไม่แมตช์เลยหลังแก้

**ตรวจสอบ**:
1. คำใน `require_all` ถูกต้องไหม (เช่น "ร้าน" ไม่ใช่ "รานอาหาร")
2. `require_any` มีตัวเลือกหลากหลายพอไหม
3. `min_query_len` สูงเกินไปไหม

**Debug**:
ดู log ใน `/opt/lampp/htdocs/autobot/logs/app.log`:
```
KB Search: query='...'
KB Entry #123 (priority=100): ADVANCED keywords=...
matchAdvanced: require_all=... require_any=... exclude_any=...
→ Advanced match result: MATCHED/NO MATCH
```
