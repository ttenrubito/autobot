# 🔧 Document Upload Issue - Root Cause & Solution

**Date:** January 4, 2026  
**Issue:** Documents uploaded but not showing in admin panel  
**Status:** 🔴 REQUIRES DATABASE MIGRATION

---

## 🔍 Problem Analysis

### Symptoms:
1. ✅ Application created successfully (ID: 1)
2. ❌ `"documents": []` - Empty documents array
3. ⚠️ `required_documents` has empty label: `"label": ""`

### Root Causes Found:

#### 1. **Database Schema Missing GCS Columns** ⚠️ CRITICAL
```sql
-- These columns don't exist yet in production:
gcs_path VARCHAR(500)
gcs_signed_url TEXT  
gcs_signed_url_expires_at DATETIME
```

**Impact:** When `documents.php` tries to INSERT with GCS columns, it fails silently.

#### 2. **Campaign Configuration Has Empty Label**
```json
{
    "type": "id_card",
    "label": "",  // ← Should be "บัตรประชาชน"
    "required": true
}
```

**Impact:** Form shows "เอกสาร" (fallback) instead of proper label.

---

## ✅ Solution

### Step 1: Apply Database Migration

**Run this SQL on production:**

```sql
-- Add GCS support columns
ALTER TABLE application_documents
ADD COLUMN IF NOT EXISTS gcs_path VARCHAR(500) 
    COMMENT 'Path in Google Cloud Storage bucket' 
    AFTER file_path,
ADD COLUMN IF NOT EXISTS gcs_signed_url TEXT 
    COMMENT 'GCS signed URL (temporary, expires)' 
    AFTER gcs_path,
ADD COLUMN IF NOT EXISTS gcs_signed_url_expires_at DATETIME 
    COMMENT 'Expiration time for signed URL' 
    AFTER gcs_signed_url;

-- Add index
CREATE INDEX IF NOT EXISTS idx_gcs_path 
    ON application_documents(gcs_path);

-- Update file_path comment
ALTER TABLE application_documents
MODIFY COLUMN file_path VARCHAR(500) 
    COMMENT 'Legacy: Local file path (deprecated - use gcs_path instead)';
```

### Step 2: Fix Campaign Configuration

```sql
UPDATE campaigns
SET required_documents = '[
    {
        "type": "id_card",
        "label": "บัตรประชาชน",
        "required": true,
        "accept": "image/*"
    },
    {
        "type": "house_registration",
        "label": "ทะเบียนบ้าน",
        "required": false,
        "accept": "image/*,application/pdf"
    }
]'
WHERE code = 'DEMO2026';
```

### Step 3: Verify

```sql
-- Check GCS columns exist
SHOW COLUMNS FROM application_documents LIKE 'gcs%';

-- Check campaign config
SELECT 
    code,
    JSON_PRETTY(required_documents) as docs
FROM campaigns
WHERE code = 'DEMO2026';
```

---

## 🚀 Quick Fix (Copy-Paste Ready)

### Option A: Use Script (Recommended)

```bash
cd /opt/lampp/htdocs/autobot
./apply_gcs_migration_now.sh
```

### Option B: Manual SQL

```bash
# Connect to Cloud SQL
gcloud sql connect autobot-db --user=root --database=autobot_db

# Paste the SQL from Step 1 & 2 above
```

### Option C: Using Cloud Console

1. Go to: https://console.cloud.google.com/sql/instances/autobot-db
2. Click "Open Cloud Shell"
3. Run:
```sql
USE autobot_db;

-- Paste Step 1 & 2 SQL here
```

---

## 🧪 Testing After Fix

### 1. Test LIFF Form
```
URL: https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026

Expected:
- See 2 document fields: "บัตรประชาชน" (required), "ทะเบียนบ้าน" (optional)
- Upload a file
- See success message with uploaded file name
```

### 2. Check API Response
```bash
curl "https://autobot.boxdesign.in.th/api/admin/line-applications.php?id=1"
```

**Expected:**
```json
{
    "documents": [
        {
            "id": 1,
            "document_type": "id_card",
            "file_name": "บัตรประชาชน.jpg",
            "gcs_path": "documents/U123.../doc_xxx.jpg",
            "gcs_signed_url": "https://storage.googleapis.com/...",
            "uploaded_at": "2026-01-04 ..."
        }
    ]
}
```

### 3. Check GCS Bucket
```bash
gsutil ls -r gs://autobot-documents/documents/
```

---

## 📊 Current vs Expected State

| Component | Current | After Fix |
|-----------|---------|-----------|
| `application_documents` table | Missing GCS columns | ✅ Has `gcs_path`, `gcs_signed_url`, `gcs_signed_url_expires_at` |
| Campaign `required_documents` | Label empty | ✅ Proper Thai labels |
| Document upload | Fails silently | ✅ Works, saves to GCS |
| Admin panel documents | Empty array | ✅ Shows uploaded files |

---

## 🐛 Why This Happened

1. **GCS code was deployed** but **database migration wasn't run**
2. Code tries to INSERT into columns that don't exist
3. MySQL returns error, but code doesn't handle it properly
4. Result: Silent failure, no documents saved

---

## 📝 Prevention (Future)

### Add to Deployment Checklist:
```
[ ] Code changes deployed
[ ] Database migration run
[ ] API tested
[ ] Admin panel tested
[ ] LIFF form tested
```

### Migration Safety:
```sql
-- Always use IF NOT EXISTS
ALTER TABLE xxx ADD COLUMN IF NOT EXISTS yyy ...

-- Check before modifying
SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'application_documents' 
  AND COLUMN_NAME = 'gcs_path';
```

---

## 🔗 Related Files

- Migration: `/migrations/add_gcs_support_to_documents.sql`
- Fix Script: `/apply_gcs_migration_now.sh`
- Campaign Fix: `/fix_demo_campaign_docs.sql`
- API Code: `/api/lineapp/documents.php`
- LIFF Form: `/liff/application-form.html`

---

## ✅ Success Criteria

After applying fixes:

- [x] `application_documents` table has GCS columns
- [x] Campaign has proper document labels
- [ ] Can upload document via LIFF form
- [ ] Document appears in `documents` array
- [ ] File exists in GCS bucket
- [ ] Admin panel shows uploaded documents

---

**Next Action:** Run `./apply_gcs_migration_now.sh` NOW!
