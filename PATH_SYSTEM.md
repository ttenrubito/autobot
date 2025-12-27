# Auto-Path Detection System

## Quick Start

### สำหรับหน้า HTML ใหม่
```html
<head>
    <script src="../assets/js/path-config.js"></script>
    <script>
        loadCSS('assets/css/style.css');
    </script>
</head>
```

### สำหรับ API Calls
```javascript
// ❌ ไม่ใช้แบบนี้
fetch('/api/auth/login.php', { ... });

// ✅ ใช้แบบนี้
fetch(API_ENDPOINTS.AUTH_LOGIN, { ... });
```

### สำหรับ Images
```javascript
// ❌ ไม่ใช้แบบนี้
<img src="/autobot/images/logo.png">

// ✅ ใช้แบบนี้
<img id="logo">
<script>
    document.getElementById('logo').src = PATH.image('images/logo.png');
</script>
```

### สำหรับ Redirects
```javascript
// ❌ ไม่ใช้แบบนี้
window.location.href = '/autobot/public/dashboard.php';

// ✅ ใช้แบบนี้
window.location.href = PAGES.USER_DASHBOARD;
```

## Available Constants

### API_ENDPOINTS
- `AUTH_LOGIN`
- `AUTH_LOGOUT`
- `AUTH_ME`
- `ADMIN_LOGIN`
- `ADMIN_SERVICES`
- `ADMIN_INVOICES`
- `ADMIN_CUSTOMERS`
- และอื่นๆ (ดูเพิ่มเติมใน path-config.js)

### PAGES
- `USER_LOGIN`
- `USER_DASHBOARD`
- `USER_PROFILE`
- `ADMIN_LOGIN`
- `ADMIN_DASHBOARD`

### PATH Helpers
- `PATH.api(endpoint)` - สำหรับ API paths
- `PATH.asset(asset)` - สำหรับ CSS/JS paths
- `PATH.image(image)` - สำหรับ image paths
- `PATH.page(page)` - สำหรับ page paths

## How It Works

ระบบจะ detect environment อัตโนมัติ:
- **Localhost** (`localhost/autobot/*`) → BASE_PATH = `/autobot`
- **Cloud Run** (`*.*.in.th/*`) → BASE_PATH = `` (empty)

## ไฟล์สำคัญ
- `assets/js/path-config.js` - Core script
- `includes/admin/header.php` - Admin header template
- `includes/customer/header.php` - Customer header template

## เอกสารเพิ่มเติม
ดู [walkthrough.md](file:///home/saranyoo/.gemini/antigravity/brain/03e8b4b7-e698-4d39-ad72-b94f081d66c5/walkthrough.md) สำหรับคำอธิบายแบบละเอียด
