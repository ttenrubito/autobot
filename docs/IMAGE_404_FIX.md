# 🔧 Image 404 Fix - Root Cause Analysis

## ปัญหา
รูปภาพ (เช่น `logo1.png`) โหลดไม่ได้ (404) บน production แม้จะแก้ไขแล้วก็ยังเกิดซ้ำ

## สาเหตุหลัก
มีหลายไฟล์ใช้ **hardcoded path** แทนที่จะใช้ `PATH` helper:

### ❌ แบบผิด (Hardcoded):
```html
<!-- ผิด: Path นี้ไม่ทำงานบน /autobot subdirectory -->
<img src="/images/logo1.png">
<link rel="icon" href="/images/logo1.png">
<link href="/assets/css/style.css">
```

### ✅ แบบถูก (PATH Helper):
```javascript
// ถูก: ใช้ PATH helper จาก path-config.js
logoImg.src = PATH.image('logo1.png');
favicon.href = PATH.image('logo1.png');
styleLink.href = PATH.asset('css/style.css');
```

---

## ไฟล์ที่ต้องแก้ไข

### 1. `/public/admin/login.html`
**ผิด:** `PATH.image('images/logo1.png')` ← ซ้ำ `images/`
**ถูก:** `PATH.image('logo1.png')`

### 2. `/public/login.html`
**ผิด:** `<link rel="icon" href="/images/logo1.png">`
**ถูก:** ใช้ dynamic script set favicon

---

## วิธีแก้ปัญหาถาวร

### ขั้นตอนที่ 1: แก้ไขไฟล์ที่มีปัญหา
```bash
# ตรวจสอบไฟล์ที่ใช้ hardcoded path
./scripts/fix-hardcoded-paths.sh
```

### ขั้นตอนที่ 2: ใช้ PATH Helper อย่างถูกต้อง

#### สำหรับรูปภาพ:
```javascript
// ใน <head> หรือ window.onload
if (typeof PATH !== 'undefined') {
    document.getElementById('logoImage').src = PATH.image('logo1.png');
    document.getElementById('favicon').href = PATH.image('logo1.png');
}
```

#### สำหรับ CSS/JS:
```javascript
if (typeof PATH !== 'undefined') {
    document.getElementById('styleLink').href = PATH.asset('css/style.css');
}
```

### ขั้นตอนที่ 3: Pre-deployment Check
deployment script จะตรวจสอบอัตโนมัติก่อน deploy:
```bash
./deploy_app_to_production.sh
# จะรัน ./scripts/fix-hardcoded-paths.sh ก่อนอัตโนมัติ
```

---

## PATH Helper Reference

### `PATH.image(filename)`
- Input: `'logo1.png'` (ไม่ต้องใส่ /images/)
- Output (localhost): `/autobot/public/images/logo1.png`
- Output (production): `/public/images/logo1.png`

### `PATH.asset(path)`
- Input: `'css/style.css'` (ไม่ต้องใส่ /assets/)
- Output (localhost): `/autobot/assets/css/style.css`
- Output (production): `/assets/css/style.css`

### `PATH.api(endpoint)`
- Input: `'api/auth/login.php'`
- Output (localhost): `/autobot/api/auth/login.php`
- Output (production): `/api/auth/login.php`

---

## ตรวจสอบ Manually

### บน Localhost:
```bash
# ตรวจสอบว่ารูปโหลดได้
curl -I http://localhost/autobot/public/images/logo1.png
# ควรได้ 200 OK
```

### บน Production:
```bash
# ตรวจสอบว่ารูปโหลดได้
curl -I https://autobot.boxdesign.in.th/public/images/logo1.png
# ควรได้ 200 OK
```

---

## Checklist ก่อน Deploy

- [ ] รัน `./scripts/fix-hardcoded-paths.sh` แล้วผ่าน
- [ ] ทุกไฟล์ HTML/PHP ใช้ `PATH.image()` และ `PATH.asset()`
- [ ] ไม่มี hardcoded `/images/` หรือ `/assets/`
- [ ] Test บน localhost ก่อน
- [ ] Test บน production หลัง deploy

---

## Tools สำหรับ Debug

### 1. ตรวจสอบ PATH ที่ใช้:
เปิด Browser Console และพิมพ์:
```javascript
console.log('Base Path:', PATH.base());
console.log('Logo Path:', PATH.image('logo1.png'));
console.log('Style Path:', PATH.asset('css/style.css'));
```

### 2. ตรวจสอบ Network Tab:
- เปิด DevTools → Network
- โหลดหน้าใหม่
- ดูว่ามี request ไหน 404
- ถ้าเป็น `/images/logo1.png` แทน `/public/images/logo1.png` = ยังใช้ hardcoded path

---

## สรุป

**ปัญหาหลัก:** Hardcoded paths ไม่ทำงานทั้ง localhost (/autobot) และ production (/)

**วิธีแก้:** ใช้ `PATH` helper จาก `path-config.js` เสมอ

**ป้องกัน:** Pre-deployment check script จะบล็อกการ deploy ถ้ายังมี hardcoded paths

---

**Updated:** 2025-12-24
**Status:** ✅ Fixed & Automated
