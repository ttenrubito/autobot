# ✅ LIFF Dynamic Documents + GCS - Testing Checklist

## 🎯 Deployment Status
- **Date:** January 4, 2026
- **Service:** autobot (Cloud Run)
- **Region:** asia-southeast1
- **URL:** https://autobot.boxdesign.in.th

---

## 📋 Pre-Testing Verification

### 1. Database Schema ✅
```sql
-- Verify GCS columns exist
SHOW COLUMNS FROM application_documents LIKE 'gcs%';

-- Expected:
-- gcs_path
-- gcs_signed_url
-- gcs_signed_url_expires_at
```

### 2. Campaign Configuration ✅
```sql
-- Verify campaign has proper labels
SELECT 
    code, 
    name, 
    JSON_PRETTY(required_documents) 
FROM campaigns 
WHERE code = 'DEMO2026';

-- Expected:
-- [
--   {
--     "type": "id_card",
--     "label": "บัตรประชาชน",
--     "required": true,
--     "accept": "image/*"
--   },
--   {
--     "type": "house_registration",
--     "label": "ทะเบียนบ้าน",
--     "required": false,
--     "accept": "image/*,application/pdf"
--   }
-- ]
```

### 3. GCS Bucket ✅
```bash
# Verify bucket exists
gsutil ls gs://autobot-documents/

# Expected: Bucket accessible
```

---

## 🧪 Test Scenarios

### Test 1: LIFF Form Display
**URL:** `https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026`

**Steps:**
1. Open LIFF in LINE app
2. Check document fields

**Expected Results:**
- ✅ Shows field: "บัตรประชาชน *" (required)
- ✅ Shows field: "ทะเบียนบ้าน" (optional)
- ❌ Should NOT show: "เอกสาร" (fallback)

---

### Test 2: Document Upload
**Steps:**
1. Fill out application form
2. Upload ID card image (JPG/PNG < 5MB)
3. Submit form

**Expected Results:**
- ✅ Upload success message
- ✅ No errors in browser console
- ✅ Application created with status: DOC_PENDING

**Debug:**
```javascript
// Browser Console
localStorage.getItem('liff_state')
// Should show uploaded files
```

---

### Test 3: GCS Upload Verification
**Steps:**
1. After upload, check GCS bucket

```bash
gsutil ls gs://autobot-documents/documents/ -lh
```

**Expected Results:**
- ✅ File appears in bucket under `documents/{LINE_USER_ID}/`
- ✅ File size matches uploaded file
- ✅ Metadata includes application_id, document_type

---

### Test 4: Database Record
**Steps:**
```sql
-- Get latest application
SELECT id, application_no, status 
FROM line_applications 
ORDER BY id DESC LIMIT 1;

-- Check documents
SELECT 
    id,
    document_type,
    document_label,
    file_name,
    gcs_path,
    LEFT(gcs_signed_url, 50) as signed_url_preview,
    gcs_signed_url_expires_at,
    uploaded_at
FROM application_documents 
WHERE application_id = {LATEST_APP_ID};
```

**Expected Results:**
- ✅ `document_type` = 'id_card'
- ✅ `document_label` = 'บัตรประชาชน'
- ✅ `gcs_path` is NOT NULL
- ✅ `gcs_signed_url` is NOT NULL
- ✅ `gcs_signed_url_expires_at` = 7 days from now

---

### Test 5: Admin Panel Display
**URL:** `https://autobot.boxdesign.in.th/line-applications.php`

**Steps:**
1. Login as admin
2. Find the test application
3. Click to view details

**Expected Results:**
- ✅ Shows "📄 เอกสาร (1)" or (2) depending on uploads
- ✅ Document section shows:
  - Document type/label: "บัตรประชาชน"
  - Original filename
  - File size
  - Upload timestamp
- ✅ Can click to view/download document
- ❌ Should NOT show: "ไม่มีเอกสาร"

---

### Test 6: Signed URL Access
**Steps:**
1. Get signed URL from database or API
2. Open URL in browser

```bash
# Test via API
curl "https://autobot.boxdesign.in.th/api/lineapp/documents.php?id={DOC_ID}&signed_url=1"
```

**Expected Results:**
- ✅ Returns JSON with `signed_url`
- ✅ URL is accessible (opens image/PDF)
- ✅ URL expires in 7 days

---

### Test 7: Multiple Document Types
**Steps:**
1. Create new application
2. Upload both:
   - บัตรประชาชน (required)
   - ทะเบียนบ้าน (optional)
3. Submit

**Expected Results:**
- ✅ Both documents appear in admin
- ✅ Both uploaded to GCS
- ✅ Both have signed URLs
- ✅ Both show correct labels

---

## 🐛 Troubleshooting

### Issue: Documents don't show in admin
**Check:**
```sql
-- 1. Does application have documents?
SELECT COUNT(*) FROM application_documents WHERE application_id = ?;

-- 2. Are GCS fields populated?
SELECT gcs_path, gcs_signed_url FROM application_documents WHERE id = ?;
```

**Solution:**
- If count = 0: Upload didn't save to database
- If gcs_path NULL: GCS upload failed
- Check logs: `/var/log/autobot/app.log`

---

### Issue: Labels show "เอกสาร" instead of Thai names
**Check:**
```sql
SELECT required_documents FROM campaigns WHERE code = 'DEMO2026';
```

**Solution:**
- If labels are empty: Run fix endpoint
- `https://autobot.boxdesign.in.th/api/admin/fix-campaign-labels.php?secret=fix_demo2026_labels_now`

---

### Issue: GCS upload fails
**Check:**
```bash
# Test GCS access
gsutil ls gs://autobot-documents/

# Check service account permissions
gcloud projects get-iam-policy canvas-radio-472913-d4 \
  --flatten="bindings[].members" \
  --filter="bindings.members:factory-backend-uploader*"
```

**Solution:**
- Verify service account has `roles/storage.objectCreator`
- Check `config/gcp/service-account.json` exists in Cloud Run

---

## 📊 Success Criteria

All of these must pass:

- [ ] LIFF form shows Thai labels (not "เอกสาร")
- [ ] File upload completes without errors
- [ ] Documents appear in GCS bucket
- [ ] Database has gcs_path, gcs_signed_url
- [ ] Admin panel displays documents
- [ ] Signed URLs are accessible
- [ ] Multiple documents work correctly

---

## 🚀 Post-Testing Cleanup

After successful testing, delete these temporary files:

```bash
cd /opt/lampp/htdocs/autobot

# Delete migration/debug files
rm -f api/admin/migrate-gcs.php
rm -f api/admin/fix-campaign.php
rm -f api/admin/fix-campaign-labels.php
rm -f api/debug/check-documents.php
rm -f run_migration_api.sh
rm -f fix_campaign_direct.sh
rm -f deploy_final_complete.sh
rm -f FIX_CAMPAIGN_NOW.sql
rm -f check_*.sql
rm -f test_*.php
rm -f fix_result.html
rm -f deploy_*.log

echo "✅ Cleanup complete!"
```

---

## 📞 Support Contacts

- **Developer:** GitHub Copilot
- **Project:** autobot LINE Application System
- **Date:** January 4, 2026
- **Version:** 1.0 (LIFF + GCS Integration)
