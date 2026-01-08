# LIFF Dynamic Document Upload with Google Cloud Storage

## 📋 Overview

This implementation provides:

1. **Dynamic Document Fields** - Document upload fields are generated from campaign configuration (`campaigns.required_documents`)
2. **Google Cloud Storage Integration** - Files are uploaded to GCS instead of local filesystem
3. **Signed URLs** - Secure, temporary URLs for document viewing
4. **Flexible Configuration** - Each campaign can require different documents

## 🎯 Features

### 1. Dynamic Document Configuration

Campaigns can now specify required documents in JSON format:

```json
[
  {
    "type": "id_card",
    "label": "บัตรประชาชน",
    "required": true,
    "accept": "image/*"
  },
  {
    "type": "house_registration",
    "label": "ทะเบียนบ้าน",
    "required": true,
    "accept": "image/*,application/pdf"
  },
  {
    "type": "bank_statement",
    "label": "Statement 3 เดือน",
    "required": false,
    "accept": "image/*,application/pdf"
  }
]
```

### 2. Google Cloud Storage

**Benefits:**
- Scalable storage without server disk space concerns
- Automatic backup and redundancy
- Signed URLs for secure access
- Integration with other GCP services

**Configuration:**
- Project: `canvas-radio-472913-d4`
- Bucket: `autobot-documents`
- Service Account: `factory-backend-uploader@canvas-radio-472913-d4.iam.gserviceaccount.com`
- Key Location: `/workspace/config/gcp/service-account.json`

### 3. Automatic File Upload

The LIFF form now:
1. Reads `required_documents` from campaign configuration
2. Generates file upload fields dynamically
3. Validates required documents before submission
4. Uploads all files to GCS after application is created
5. Stores metadata in `application_documents` table

## 📁 File Structure

```
/opt/lampp/htdocs/autobot/
├── config/gcp/
│   └── service-account.json          # GCS credentials
├── includes/
│   └── GoogleCloudStorage.php        # GCS helper class
├── api/lineapp/
│   ├── campaigns.php                 # Returns required_documents config
│   └── documents.php                 # Handles GCS uploads
├── liff/
│   └── application-form.html         # Dynamic form rendering
└── migrations/
    ├── add_gcs_support_to_documents.sql
    └── update_demo_campaign_documents.sql
```

## 🔧 Database Changes

### New Columns in `application_documents`

```sql
gcs_path VARCHAR(500)                    -- Path in GCS bucket
gcs_signed_url TEXT                      -- Temporary signed URL
gcs_signed_url_expires_at DATETIME       -- URL expiration
```

## 🚀 Deployment

### 1. Run Database Migrations

```bash
# Connect to production database
mysql -h <host> -u <user> -p autobot_db

# Run migrations
source migrations/add_gcs_support_to_documents.sql;
source migrations/update_demo_campaign_documents.sql;
```

### 2. Deploy to Cloud Run

```bash
./deploy_liff_gcs.sh
```

### 3. Verify GCS Bucket

```bash
# Check bucket exists
gsutil ls gs://autobot-documents/

# View uploaded files
gsutil ls -r gs://autobot-documents/documents/
```

## 📝 Usage Examples

### Configure Campaign with Required Documents

```sql
UPDATE campaigns
SET required_documents = JSON_ARRAY(
    JSON_OBJECT('type', 'id_card', 'label', 'บัตรประชาชน', 'required', true),
    JSON_OBJECT('type', 'passport', 'label', 'หนังสือเดินทาง', 'required', false)
)
WHERE code = 'MY_CAMPAIGN';
```

### Generate Signed URL (PHP)

```php
$gcs = GoogleCloudStorage::getInstance();
$signedUrl = $gcs->generateSignedUrl('documents/U123/doc_456.jpg', '+7 days');
```

### Upload File Manually (PHP)

```php
$gcs = GoogleCloudStorage::getInstance();
$result = $gcs->uploadFile(
    $fileContent,
    'document.pdf',
    'application/pdf',
    'documents/U123456',
    ['application_id' => '789', 'document_type' => 'id_card']
);

if ($result['success']) {
    $gcsPath = $result['path'];
    $signedUrl = $result['signed_url'];
}
```

## 🔐 Security

1. **Service Account Permissions**: Limited to Storage Object Creator/Viewer
2. **Signed URLs**: Expire after 7 days by default
3. **Private Bucket**: Files not publicly accessible
4. **Metadata**: Application ID, user ID stored in GCS metadata

## 📊 Monitoring

### View GCS Access Logs

```bash
gcloud logging read "resource.type=gcs_bucket" --limit 50
```

### Check Storage Usage

```bash
gsutil du -sh gs://autobot-documents/
```

### Track Upload Statistics

```sql
SELECT 
    DATE(uploaded_at) as date,
    COUNT(*) as uploads,
    SUM(file_size) / 1024 / 1024 as total_mb
FROM application_documents
WHERE gcs_path IS NOT NULL
GROUP BY DATE(uploaded_at)
ORDER BY date DESC
LIMIT 30;
```

## 🐛 Troubleshooting

### Files Not Uploading

1. Check service account key exists:
   ```bash
   ls -la config/gcp/service-account.json
   ```

2. Verify GCS permissions:
   ```bash
   gcloud projects get-iam-policy canvas-radio-472913-d4 \
     --flatten="bindings[].members" \
     --filter="bindings.members:factory-backend-uploader@*"
   ```

3. Check CloudRun environment variables:
   ```bash
   gcloud run services describe autobot --region asia-southeast1 --format="value(spec.template.spec.containers[0].env)"
   ```

### Signed URLs Not Working

1. Check URL expiration:
   ```sql
   SELECT gcs_signed_url_expires_at FROM application_documents WHERE id = ?;
   ```

2. Regenerate URL:
   ```php
   $newUrl = $gcs->generateSignedUrl($gcsPath, '+7 days');
   ```

## 📚 API Reference

### GoogleCloudStorage Class

```php
// Initialize (Singleton)
$gcs = GoogleCloudStorage::getInstance();

// Upload file
$result = $gcs->uploadFile($content, $filename, $mimetype, $folder, $metadata);

// Download file
$result = $gcs->downloadFile($gcsPath);

// Generate signed URL
$url = $gcs->generateSignedUrl($gcsPath, $expiration);

// Delete file
$success = $gcs->deleteFile($gcsPath);

// Check existence
$exists = $gcs->fileExists($gcsPath);
```

## 🎯 Next Steps

1. ✅ Dynamic document fields - **DONE**
2. ✅ GCS integration - **DONE**
3. ⏳ OCR processing for uploaded documents
4. ⏳ Image compression before upload
5. ⏳ Thumbnail generation
6. ⏳ Admin panel GCS viewer

## 📞 Support

- **GCP Console**: https://console.cloud.google.com/storage/browser/autobot-documents
- **Logs**: `gcloud run services logs read autobot --region asia-southeast1`
- **Documentation**: See `/docs/LIFF_INTEGRATION_WITH_CAMPAIGNS.md`
