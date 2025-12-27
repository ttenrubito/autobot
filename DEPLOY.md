# 🚀 Google Cloud Run Deployment Guide

ขั้นตอนการ deploy AI Automation Portal ขึ้น Google Cloud Run

---

## 📋 เตรียมความพร้อม

### 1. ติดตั้ง Google Cloud SDK
```bash
# Install gcloud CLI
curl https://sdk.cloud.google.com | bash
exec -l $SHELL

# Initialize
gcloud init
```

### 2. สร้าง Google Cloud Project
```bash
# Create project
gcloud projects create autobot-prod --name="AI Automation"

# Set project
gcloud config set project autobot-prod

# Enable required APIs
gcloud services enable run.googleapis.com
gcloud services enable cloudbuild.googleapis.com
gcloud services enable sqladmin.googleapis.com
gcloud services enable secretmanager.googleapis.com
```

---

## 🗄️ สร้าง Cloud SQL Database

### 1. สร้าง MySQL Instance
```bash
gcloud sql instances create autobot-db \
  --database-version=MYSQL_8_0 \
  --tier=db-f1-micro \
  --region=asia-southeast1 \
  --root-password=YOUR_SECURE_PASSWORD
```

### 2. สร้าง Database
```bash
gcloud sql databases create autobot --instance=autobot-db
```

### 3. Import Schema
```bash
# Upload schema file to Cloud Storage first
gsutil cp database/schema.sql gs://YOUR_BUCKET/schema.sql

# Import to Cloud SQL
gcloud sql import sql autobot-db gs://YOUR_BUCKET/schema.sql \
  --database=autobot
```

---

## 🔐 จัดการ Secrets

### 1. สร้าง Secrets
```bash
# Database Password
echo -n "your_db_password" | gcloud secrets create DB_PASSWORD --data-file=-

# JWT Secret Key
echo -n "your_jwt_secret_key_here" | gcloud secrets create JWT_SECRET --data-file=-

# Omise Secret Key
echo -n "skey_live_xxxxx" | gcloud secrets create OMISE_SECRET --data-file=-

# Omise Public Key (for reference)
echo -n "pkey_live_xxxxx" | gcloud secrets create OMISE_PUBLIC --data-file=-
```

### 2. Grant Access
```bash
# Allow Cloud Run to access secrets
gcloud projects add-iam-policy-binding autobot-prod \
  --member=serviceAccount:PROJECT_NUMBER-compute@developer.gserviceaccount.com \
  --role=roles/secretmanager.secretAccessor
```

---

## 🐳 Build และ Deploy

### Option 1: Manual Deploy (ทดสอบครั้งแรก)

```bash
# 1. Build Docker image
gcloud builds submit --tag gcr.io/autobot-prod/autobot

# 2. Deploy to Cloud Run
gcloud run deploy autobot \
  --image gcr.io/autobot-prod/autobot \
  --platform managed \
  --region asia-southeast1 \
  --allow-unauthenticated \
  --port 8080 \
  --add-cloudsql-instances autobot-prod:asia-southeast1:autobot-db \
  --set-env-vars DB_HOST=localhost,DB_NAME=autobot,DB_USER=root,DB_SOCKET=/cloudsql/autobot-prod:asia-southeast1:autobot-db,OMISE_PUBLIC_KEY=pkey_live_xxxxx,BASE_URL=https://autobot-xxxxx.run.app \
  --set-secrets DB_PASS=DB_PASSWORD:latest,JWT_SECRET_KEY=JWT_SECRET:latest,OMISE_SECRET_KEY=OMISE_SECRET:latest \
  --memory 512Mi \
  --cpu 1 \
  --max-instances 10 \
  --min-instances 0
```

### Option 2: Automated Deploy (Cloud Build)

```bash
# 1. Update cloudbuild.yaml substitutions
# แก้ไข _CLOUD_SQL_INSTANCE ใน cloudbuild.yaml

# 2. Create trigger
gcloud builds triggers create github \
  --repo-name=autobot \
  --repo-owner=YOUR_GITHUB_USERNAME \
  --branch-pattern=^main$ \
  --build-config=cloudbuild.yaml

# 3. Push to trigger build
git push origin main
```

---

## 🌐 Custom Domain (Optional)

### 1. Map Domain
```bash
gcloud run domain-mappings create \
  --service autobot \
  --domain autobot.yourdomain.com \
  --region asia-southeast1
```

### 2. Update DNS
เพิ่ม CNAME record ใน DNS:
```
autobot  CNAME  ghs.googlehosted.com
```

---

## 📊 Monitoring & Logging

### View Logs
```bash
# Real-time logs
gcloud run services logs tail autobot --region asia-southeast1

# Recent logs
gcloud run services logs read autobot --region asia-southeast1 --limit 50
```

### Metrics Dashboard
```
https://console.cloud.google.com/run/detail/asia-southeast1/autobot/metrics
```

---

## 🔄 อัพเดทและ Rollback

### Deploy New Version
```bash
# Build and deploy
gcloud builds submit --tag gcr.io/autobot-prod/autobot:v2
gcloud run deploy autobot --image gcr.io/autobot-prod/autobot:v2 --region asia-southeast1
```

### Rollback
```bash
# List revisions
gcloud run revisions list --service autobot --region asia-southeast1

# Rollback to previous revision
gcloud run services update-traffic autobot \
  --to-revisions REVISION_NAME=100 \
  --region asia-southeast1
```

---

## 💰 ประมาณการค่าใช้จ่าย

### Cloud Run (Pay per use)
- **Free Tier**: 2 million requests/month
- **After**: ~$0.00002400 per request
- **Estimate**: 100K requests/month = ~$2-3

### Cloud SQL (MySQL)
- **db-f1-micro**: ~$10/month
- **db-g1-small**: ~$25/month
- **Recommend**: db-g1-small for production

### Total Estimated Cost
- **Development**: ~$10-15/month
- **Production (Low traffic)**: ~$30-50/month
- **Production (High traffic)**: ~$100-200/month

---

## ✅ Checklist หลัง Deploy

- [ ] ทดสอบ Login ที่ URL ที่ได้
- [ ] ตรวจสอบ API endpoints ทำงาน
- [ ] ทดสอบเพิ่มบัตรเครดิต (Omise)
- [ ] ตรวจสอบ logs ไม่มี errors
- [ ] Setup monitoring alerts
- [ ] Enable Cloud CDN (optional)
- [ ] Configure HTTPS redirect
- [ ] Setup automated backups

---

## 🛠️ Troubleshooting

### Service แสดง 500 Error
```bash
# Check logs
gcloud run services logs read autobot --region asia-southeast1 --limit 100

# Common fixes:
# 1. ตรวจสอบ DB connection
# 2. ตรวจสอบ secrets ถูกต้อง
# 3. ตรวจสอบ Cloud SQL instance running
```

### Database Connection Failed
```bash
# Test Cloud SQL connection
gcloud sql connect autobot-db --user=root

# Check instance status
gcloud sql instances describe autobot-db
```

### Permission Denied
```bash
# Grant Cloud Run SA permissions
gcloud projects add-iam-policy-binding autobot-prod \
  --member=serviceAccount:PROJECT_NUMBER-compute@developer.gserviceaccount.com \
  --role=roles/cloudsql.client
```

---

## 📞 Support

หากมีปัญหาติดต่อ:
- Cloud Console: https://console.cloud.google.com
- Support: https://cloud.google.com/support

---

**หมายเหตุ:** ก่อน deploy production ควรทดสอบใน development environment ก่อนเสมอ
