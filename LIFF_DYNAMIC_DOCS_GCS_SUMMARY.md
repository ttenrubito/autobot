# 🎯 LIFF Dynamic Documents + Google Cloud Storage - Implementation Summary

**Date:** January 4, 2026  
**Status:** ✅ READY TO DEPLOY

---

## 📋 What Was Implemented

### 1. ✅ Dynamic Document Fields
- **BEFORE:** Hardcoded `<input id="idCard">` for บัตรประชาชน only
- **AFTER:** Dynamic fields generated from `campaigns.required_documents` JSON

**Example Configuration:**
```json
[
  {"type": "id_card", "label": "บัตรประชาชน", "required": true},
  {"type": "house_registration", "label": "ทะเบียนบ้าน", "required": true},
  {"type": "bank_statement", "label": "Statement 3 เดือน", "required": false}
]
```

### 2. ✅ Google Cloud Storage Integration
- **Package:** `google/cloud-storage` v1.49.0 installed via Composer
- **Helper Class:** `includes/GoogleCloudStorage.php`
- **Service Account:** Moved to `config/gcp/service-account.json`
- **Bucket:** `autobot-documents` (will be auto-created if not exists)

### 3. ✅ Updated Files

| File | Changes |
|------|---------|
| `composer.json` | Added `google/cloud-storage` dependency |
| `includes/GoogleCloudStorage.php` | **NEW** - GCS helper with upload/download/signed URLs |
| `api/lineapp/documents.php` | Updated to upload to GCS instead of local storage |
| `liff/application-form.html` | Dynamic document field rendering + upload |
| `database/migrations/2026_01_04_add_gcs_support.sql` | Add GCS columns to `application_documents` |
| `.env.example` | Added GCS configuration variables |

---

## 🔄 How It Works Now

### Previous Flow (Hardcoded):
```
1. User opens LIFF form
2. Sees hardcoded "บัตรประชาชน" field
3. Uploads file → saves to local /storage/documents/
4. Limited to 1 document type
```

### New Flow (Dynamic + GCS):
```
1. User opens LIFF form
2. Campaign API returns required_documents config
3. Form generates N file input fields dynamically
4. User fills form + uploads documents
5. Application created → returns application_id
6. For each uploaded file:
   - Convert to base64
   - POST to /api/lineapp/documents.php
   - Upload to GCS bucket (with signed URL)
   - Insert record with gcs_path, gcs_signed_url
7. Success message shows all uploaded documents
```

---

## 📊 Database Schema Changes

```sql
ALTER TABLE application_documents
ADD COLUMN gcs_path VARCHAR(500) COMMENT 'Path in GCS bucket',
ADD COLUMN gcs_signed_url TEXT COMMENT 'Temporary signed URL',
ADD COLUMN gcs_signed_url_expires_at DATETIME COMMENT 'URL expiration';
```

**Example Record:**
```
application_id: 123
document_type: "id_card"
file_name: "บัตรประชาชน.jpg"
file_path: NULL (deprecated)
gcs_path: "documents/Uabc123/doc_1704362400_xyz.jpg"
gcs_signed_url: "https://storage.googleapis.com/autobot-documents/..."
```

---

## 🚀 Deployment Checklist

### Pre-Deployment

- [x] Composer dependencies installed (`google/cloud-storage`)
- [x] Service account key moved to `config/gcp/`
- [x] GCS Helper class created and tested
- [x] LIFF form updated with dynamic rendering
- [x] Documents API updated for GCS
- [x] Migration SQL scripts created
- [x] Environment variables documented

### Deployment Steps

**1. Run Database Migration (Production)**

```bash
# SSH to production or use Cloud SQL proxy
mysql -h <PROD_HOST> -u <USER> -p autobot_db

# Run migration
source /path/to/migrations/add_gcs_support_to_documents.sql;

# Optional: Update DEMO2026 campaign for testing
source /path/to/migrations/update_demo_campaign_documents.sql;
```

**2. Deploy to Cloud Run**

```bash
cd /opt/lampp/htdocs/autobot
./deploy_liff_gcs.sh
```

OR use existing deployment:

```bash
./deploy_app_to_production.sh
```

**3. Verify GCS Setup**

```bash
# Check if bucket exists
gsutil ls gs://autobot-documents/

# If not, create it
gsutil mb -l asia-southeast1 -c STANDARD gs://autobot-documents/

# Set permissions (service account should already have access)
gsutil iam get gs://autobot-documents/
```

**4. Test LIFF Form**

1. Open LIFF URL with campaign: `https://liff.line.me/<LIFF_ID>?campaign=DEMO2026`
2. Verify document fields appear (4 fields if using updated DEMO2026)
3. Upload test files
4. Check success message shows all uploaded documents
5. Verify in admin panel that documents appear
6. Check GCS bucket has files: `gsutil ls -r gs://autobot-documents/documents/`

---

## 🧪 Testing Scenarios

### Test 1: Campaign with No Documents
```sql
UPDATE campaigns SET required_documents = NULL WHERE code = 'TEST1';
```
**Expected:** "ไม่มีเอกสารที่ต้องอัพโหลด" message

### Test 2: Campaign with Required Documents
```sql
UPDATE campaigns SET required_documents = '[
  {"type":"id_card","label":"บัตรประชาชน","required":true}
]' WHERE code = 'TEST2';
```
**Expected:** 1 required file field, form won't submit without it

### Test 3: Campaign with Mixed Required/Optional
```sql
UPDATE campaigns SET required_documents = '[
  {"type":"id_card","label":"บัตรประชาชน","required":true},
  {"type":"passport","label":"Passport","required":false}
]' WHERE code = 'TEST3';
```
**Expected:** 2 fields, can submit with only id_card

### Test 4: Large File Upload
- Upload 5MB image
- **Expected:** GCS handles it, local server doesn't run out of space

### Test 5: Multiple File Types
- Upload JPG, PNG, PDF
- **Expected:** All accepted based on `accept` attribute

---

## 🔐 Security Considerations

1. **Service Account Scope:**
   - Only has Storage Object Creator/Viewer role
   - Cannot delete bucket or modify IAM

2. **Signed URLs:**
   - Valid for 7 days by default
   - Can be regenerated on-demand
   - Not publicly accessible without signed URL

3. **File Storage:**
   - Organized by user: `documents/{line_user_id}/`
   - Metadata includes application_id, document_type
   - Original filename preserved in metadata

4. **Environment Variables:**
   - GCS credentials NOT in code
   - Service account key in secure `/config/gcp/`
   - .gitignore ensures key not committed

---

## 📈 Performance Benefits

| Aspect | Before (Local) | After (GCS) |
|--------|---------------|-------------|
| Storage | Limited by disk | Unlimited |
| Backup | Manual | Automatic |
| Scaling | Limited | Infinite |
| CDN | No | Yes (via signed URL) |
| Cost | Server storage | Pay per use |
| Reliability | Single point of failure | 99.99% SLA |

---

## 🐛 Known Issues & Solutions

### Issue 1: "Service account key not found"
**Solution:** Ensure key is at `config/gcp/service-account.json` in Cloud Run

```bash
# In Dockerfile or cloudbuild.yaml
COPY config/gcp/service-account.json /workspace/config/gcp/
```

### Issue 2: "Bucket does not exist"
**Solution:** GCS Helper auto-creates bucket, but check permissions:

```bash
gcloud projects get-iam-policy canvas-radio-472913-d4 \
  --flatten="bindings[].members" \
  --filter="bindings.members:factory-backend-uploader@*"
```

### Issue 3: "Signed URL expired"
**Solution:** Regenerate URL:

```php
$gcs = GoogleCloudStorage::getInstance();
$newUrl = $gcs->generateSignedUrl($gcsPath, '+7 days');

// Update database
$stmt->execute([$newUrl, date('Y-m-d H:i:s', strtotime('+7 days')), $docId]);
```

---

## 📚 Documentation Files

- `docs/LIFF_GCS_INTEGRATION.md` - Full integration guide
- `migrations/add_gcs_support_to_documents.sql` - DB migration
- `migrations/update_demo_campaign_documents.sql` - Sample campaign config
- `deploy_liff_gcs.sh` - Deployment script

---

## ✅ Ready to Deploy!

**Estimated Time:** 15-20 minutes

**Command:**
```bash
cd /opt/lampp/htdocs/autobot
./deploy_liff_gcs.sh
```

**Post-Deployment:**
1. Run DB migration
2. Test LIFF form with DEMO2026
3. Upload test document
4. Verify in GCS: `gsutil ls -r gs://autobot-documents/`
5. Check admin panel shows documents

---

## 🎯 Next Features (Future)

1. **OCR Processing** - Auto-extract data from uploaded ID cards
2. **Image Compression** - Reduce file size before GCS upload
3. **Thumbnail Generation** - Create previews for admin panel
4. **Document Verification** - Mark documents as verified/rejected
5. **Bulk Download** - Download all documents for an application as ZIP

---

**Author:** AI Assistant  
**Date:** 2026-01-04  
**Version:** 1.0
