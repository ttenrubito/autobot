# 🎉 LIFF Dynamic Documents + GCS - READY TO USE

**Date:** January 4, 2026  
**Status:** ✅ **DEPLOYED & READY FOR TESTING**  
**URL:** https://autobot.boxdesign.in.th

---

## 📦 What's Deployed

### ✅ Core Features
1. **Dynamic Document Fields** - LIFF form generates upload fields from campaign configuration
2. **Google Cloud Storage** - Files upload to GCS bucket instead of local storage
3. **Signed URLs** - Secure document access with 7-day expiration
4. **Database Schema** - GCS columns added to `application_documents` table
5. **Admin Display** - Documents now appear in admin panel detail view

### ✅ Files Modified/Created
- `liff/application-form.html` - Dynamic document rendering
- `api/lineapp/documents.php` - GCS upload integration
- `includes/GoogleCloudStorage.php` - GCS helper class
- `api/admin/fix-campaign-labels.php` - Campaign fix endpoint
- `api/debug/check-documents.php` - Debug endpoint

---

## 🚀 Quick Start - Test Now

### 1️⃣ **Fix Campaign Labels** (1 minute)

Run this command **once** to fix campaign configuration:

```bash
cd /opt/lampp/htdocs/autobot
./quick_fix_and_test.sh
```

This will:
- Update campaign `DEMO2026` with proper Thai labels
- Verify API returns correct data
- Show current system status

### 2️⃣ **Test LIFF Form** (2 minutes)

**Open in LINE app:**
```
https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026
```

**Expected:**
- ✅ Shows field: **"บัตรประชาชน"** (required, red asterisk)
- ✅ Shows field: **"ทะเบียนบ้าน"** (optional)
- ❌ Should NOT show: "เอกสาร" (generic fallback)

**Actions:**
1. Fill out application form
2. Upload ID card photo (JPG/PNG, < 5MB)
3. Optionally upload house registration
4. Submit form
5. Should see success message

### 3️⃣ **Verify in Admin Panel** (1 minute)

**Open in browser:**
```
https://autobot.boxdesign.in.th/line-applications.php
```

**Steps:**
1. Login as admin
2. Find your test application (latest entry)
3. Click to view details
4. Check **"📄 เอกสาร"** section on the right

**Expected:**
- ✅ Shows document count: "📄 เอกสาร (1)" or (2)
- ✅ Displays document cards with:
  - Type/Label: "บัตรประชาชน"
  - Filename: "xxx.jpg"
  - File size: "XXX KB"
  - Upload timestamp
- ✅ Can click to view/download

### 4️⃣ **Debug If Needed**

**Check documents in database:**
```
https://autobot.boxdesign.in.th/api/debug/check-documents.php
```

Shows all recent applications and their documents.

---

## 🔧 Troubleshooting

### Issue: Labels still show "เอกสาร"

**Solution:**
```bash
# Run fix endpoint directly
curl "https://autobot.boxdesign.in.th/api/admin/fix-campaign-labels.php?secret=fix_demo2026_labels_now"

# Or use script
./quick_fix_and_test.sh
```

### Issue: Documents don't appear in admin

**Check:**
1. Did upload succeed? (check browser console for errors)
2. Are documents in database?
   - Visit debug endpoint: `/api/debug/check-documents.php`
   - Should show documents with `gcs_path`
3. Is admin API returning documents?
   - Check browser Network tab → API call to `/api/admin/line-applications.php?id=X`
   - Response should have `documents` array

**Fix:**
- If `gcs_path` is NULL: GCS upload failed, check service account permissions
- If documents array empty: Database query issue, check application_id

### Issue: GCS upload fails

**Check:**
```bash
# Verify bucket exists
gsutil ls gs://autobot-documents/

# Check service account
gcloud projects get-iam-policy canvas-radio-472913-d4 \
  --flatten="bindings[].members" \
  --filter="bindings.members:factory-backend-uploader*"
```

**Fix:**
- Ensure service account has `Storage Object Creator` role
- Verify `config/gcp/service-account.json` exists in Cloud Run

---

## 📊 System Architecture

```
┌─────────────┐
│  LINE App   │
│   (LIFF)    │
└──────┬──────┘
       │ 1. Fetch campaign config
       ▼
┌─────────────────────┐
│ Campaign API        │
│ required_documents  │◄── Dynamic labels
└──────┬──────────────┘
       │ 2. Render fields
       ▼
┌─────────────────────┐
│ LIFF Form           │
│ - บัตรประชาชน      │
│ - ทะเบียนบ้าน      │
└──────┬──────────────┘
       │ 3. Upload file (base64)
       ▼
┌─────────────────────┐
│ Documents API       │
│ /api/lineapp/       │
│   documents.php     │
└──────┬──────────────┘
       │ 4. Upload to GCS
       ▼
┌─────────────────────┐
│ Google Cloud        │
│   Storage           │
│ autobot-documents   │
└──────┬──────────────┘
       │ 5. Get signed URL
       ▼
┌─────────────────────┐
│ Database            │
│ application_        │
│   documents         │
│ - gcs_path          │
│ - gcs_signed_url    │
└──────┬──────────────┘
       │ 6. Fetch for display
       ▼
┌─────────────────────┐
│ Admin Panel         │
│ line-applications   │
│   .php              │
└─────────────────────┘
```

---

## 🗄️ Database Schema

### `application_documents` Table

**New columns added:**
```sql
gcs_path VARCHAR(500)                  -- Path in GCS bucket
gcs_signed_url TEXT                    -- Signed URL (7 days)
gcs_signed_url_expires_at DATETIME     -- Expiration timestamp
```

**Example record:**
```sql
id: 1
application_id: 42
document_type: 'id_card'
document_label: 'บัตรประชาชน'
file_name: 'id_card_1704355200_a3f2b1c4.jpg'
gcs_path: 'documents/U1234567890/id_card_1704355200_a3f2b1c4.jpg'
gcs_signed_url: 'https://storage.googleapis.com/autobot-documents/...'
gcs_signed_url_expires_at: '2026-01-11 10:30:00'
uploaded_at: '2026-01-04 10:30:00'
```

---

## 📋 Campaign Configuration

### Before (❌ Broken):
```json
{
  "required_documents": [
    {
      "type": "id_card",
      "label": "",          // ← Empty!
      "required": true
    }
  ]
}
```

### After (✅ Fixed):
```json
{
  "required_documents": [
    {
      "type": "id_card",
      "label": "บัตรประชาชน",    // ← Thai label
      "required": true,
      "accept": "image/*"
    },
    {
      "type": "house_registration",
      "label": "ทะเบียนบ้าน",    // ← Thai label
      "required": false,
      "accept": "image/*,application/pdf"
    }
  ]
}
```

---

## 🧪 Automated Tests

Run full system test:
```bash
cd /opt/lampp/htdocs/autobot
./test_system.sh
```

Expected output:
```
✅ PASS: Service is accessible
✅ PASS: Campaign API returns DEMO2026
✅ PASS: Campaign has Thai labels
✅ PASS: LIFF has renderDocumentFields function
✅ PASS: LIFF has no hardcoded document fields
✅ PASS: GoogleCloudStorage has uploadFile method
...
🎉 All tests passed!
```

---

## 🔐 Security

- **Service Account:** `factory-backend-uploader@canvas-radio-472913-d4.iam.gserviceaccount.com`
- **Permissions:** Storage Object Creator (write-only)
- **Bucket:** `autobot-documents` (private, no public access)
- **Access:** Via signed URLs only (7-day expiration)
- **File validation:** 5MB max, image/PDF only

---

## 📞 Support & Next Steps

### ✅ Completed
- [x] Dynamic document fields in LIFF
- [x] GCS upload integration
- [x] Signed URL generation
- [x] Database schema migration
- [x] Admin panel display
- [x] Campaign configuration fix
- [x] Deployment to production

### 🎯 Ready for Production Use
- Test with real users
- Monitor GCS storage usage
- Set up signed URL refresh (before 7-day expiration)
- Consider adding OCR processing

### 🧹 Cleanup (After Testing)
```bash
cd /opt/lampp/htdocs/autobot

# Remove temporary files
rm -f api/admin/migrate-gcs.php
rm -f api/admin/fix-campaign.php
rm -f api/admin/fix-campaign-labels.php
rm -f api/debug/check-documents.php
rm -f run_migration_api.sh
rm -f fix_campaign_direct.sh
rm -f deploy_final_complete.sh
rm -f quick_fix_and_test.sh
rm -f test_system.sh
rm -f *.log
rm -f check_*.sql
```

---

## 📚 Documentation

- **Technical Summary:** `LIFF_DYNAMIC_DOCS_GCS_SUMMARY.md`
- **Testing Guide:** `TESTING_CHECKLIST.md`
- **This File:** `SYSTEM_READY_TO_USE.md`

---

## 🎉 Success Metrics

**System is working correctly when:**

- ✅ LIFF shows Thai document labels
- ✅ Files upload without errors
- ✅ Files appear in GCS bucket
- ✅ Database has `gcs_path` and `gcs_signed_url`
- ✅ Admin panel displays documents
- ✅ Signed URLs are accessible
- ✅ Multiple document types work

---

**🚀 System is READY! Start testing now with the steps above.**

**Questions? Check the troubleshooting section or review logs.**

---

*Last Updated: January 4, 2026*  
*Version: 1.0 - Production Ready*
