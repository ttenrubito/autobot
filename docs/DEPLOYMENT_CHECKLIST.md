# ☑️ Production Deployment Checklist

**Project:** Customer Portal UX Enhancements v2.0.0  
**Deployment Date:** ________________  
**Deployed By:** ________________

---

## 📅 PRE-DEPLOYMENT (T-24 Hours)

### Infrastructure Setup
- [ ] Google Cloud Project created/verified: `__________________`
- [ ] Cloud SQL instance running and accessible
- [ ] Database name confirmed: `autobot`
- [ ] Cloud Storage buckets created:
  - [ ] Backup bucket: `__________________`
  - [ ] Migration bucket: `__________________`
- [ ] Cloud Run region selected: `asia-southeast1` (or `__________`)

### Secrets Configuration
- [ ] `DB_PASSWORD` secret exists
  ```bash
  gcloud secrets describe DB_PASSWORD --project=[PROJECT_ID]
  ```
- [ ] `JWT_SECRET` secret exists
  ```bash
  gcloud secrets describe JWT_SECRET --project=[PROJECT_ID]
  ```
- [ ] `OMISE_SECRET` secret exists
  ```bash
  gcloud secrets describe OMISE_SECRET --project=[PROJECT_ID]
  ```
- [ ] Service account has `secretAccessor` role
  ```bash
  gcloud projects get-iam-policy [PROJECT_ID]
  ```

### Code Review
- [ ] All 6 files reviewed and committed
- [ ] No console.log or debug code left
- [ ] Environment variables configured correctly
- [ ] API endpoints verified

### Testing Verification
- [ ] Local testing completed and documented
- [ ] Edge cases tested (0 items, 1 item, 1000+ items)
- [ ] Browser compatibility verified (Chrome, Firefox, Safari, Edge)
- [ ] Mobile responsiveness checked
- [ ] Accessibility tested (WAVE, axe DevTools)
- [ ] Performance benchmarks recorded

### Documentation
- [ ] Deployment guide reviewed: `/docs/PRODUCTION_DEPLOYMENT_GUIDE.md`
- [ ] Rollback plan understood and accessible
- [ ] Emergency contacts list updated
- [ ] Team briefed on deployment schedule

### Communication
- [ ] Deployment scheduled and announced
- [ ] Stakeholders notified
- [ ] Users informed (if downtime expected)
- [ ] Support team briefed
- [ ] On-call engineer assigned: `__________________`

**Sign-off:** ________________  Date: ________

---

## 🗄️ DATABASE MIGRATION (T-2 Hours)

### Step 1: Backup Database
- [ ] Navigate to Cloud SQL in console
- [ ] Create backup manually or via command:
  ```bash
  gcloud sql export sql autobot-db \
    gs://[BACKUP_BUCKET]/autobot-backup-$(date +%Y%m%d-%H%M%S).sql \
    --database=autobot --project=[PROJECT_ID]
  ```
- [ ] Backup location recorded: `__________________`
- [ ] Backup verified and downloadable

### Step 2: Verify Current Data
- [ ] Connect to database
  ```bash
  gcloud sql connect autobot-db --user=root --database=autobot
  ```
- [ ] Run verification query:
  ```sql
  SELECT 
      COUNT(*) as total,
      SUM(CASE WHEN slip_image LIKE '/autobot%' THEN 1 ELSE 0 END) as has_autobot,
      SUM(CASE WHEN slip_image LIKE '/public%' THEN 1 ELSE 0 END) as has_public
  FROM payments WHERE slip_image IS NOT NULL;
  ```
- [ ] Record results:
  - Total images: `__________`
  - Has /autobot: `__________`
  - Has /public: `__________`

### Step 3: Upload Migration File
- [ ] Upload SQL to Cloud Storage
  ```bash
  gsutil cp database/fix_slip_image_paths.sql \
    gs://[MIGRATION_BUCKET]/fix_slip_image_paths.sql
  ```
- [ ] Verify upload
  ```bash
  gsutil ls gs://[MIGRATION_BUCKET]/
  ```

### Step 4: Run Migration
- [ ] Import to Cloud SQL
  ```bash
  gcloud sql import sql autobot-db \
    gs://[MIGRATION_BUCKET]/fix_slip_image_paths.sql \
    --database=autobot --project=[PROJECT_ID]
  ```
- [ ] Migration completed without errors
- [ ] Execution time: `________ seconds`

### Step 5: Verify Migration
- [ ] Run verification query again:
  ```sql
  SELECT 
      COUNT(*) as total,
      SUM(CASE WHEN slip_image LIKE '/autobot%' THEN 1 ELSE 0 END) as has_autobot,
      SUM(CASE WHEN slip_image LIKE '/public%' THEN 1 ELSE 0 END) as has_public,
      SUM(CASE WHEN slip_image LIKE '/uploads/%' THEN 1 ELSE 0 END) as correct
  FROM payments WHERE slip_image IS NOT NULL;
  ```
- [ ] Verify results:
  - Has /autobot: `0` ✅
  - Has /public: `0` ✅
  - Correct format: `______` (should equal total)

**Migration Sign-off:** ________________  Date: ________ Time: ________

---

## 🐳 DOCKER BUILD (T-1 Hour)

### Step 1: Prepare Environment
- [ ] Navigate to project directory
  ```bash
  cd /opt/lampp/htdocs/autobot
  ```
- [ ] Set environment variables
  ```bash
  export PROJECT_ID="__________________"
  export VERSION="v2.0.0"
  ```
- [ ] Verify Dockerfile exists and is correct
- [ ] Verify .dockerignore is configured

### Step 2: Build Image
- [ ] Start build
  ```bash
  gcloud builds submit --tag gcr.io/$PROJECT_ID/autobot:$VERSION
  ```
- [ ] Build started at: `________ (time)`
- [ ] Build completed at: `________ (time)`
- [ ] Build duration: `________ minutes`
- [ ] Build successful ✅

### Step 3: Verify Image
- [ ] List images in registry
  ```bash
  gcloud container images list --repository=gcr.io/$PROJECT_ID
  ```
- [ ] Verify image exists
  ```bash
  gcloud container images describe gcr.io/$PROJECT_ID/autobot:$VERSION
  ```
- [ ] Image size: `________ MB`
- [ ] Image digest: `__________________`

**Build Sign-off:** ________________  Date: ________ Time: ________

---

## 🚀 CLOUD RUN DEPLOYMENT (T-0)

### Step 1: Pre-Deployment Check
- [ ] All previous steps completed
- [ ] No blockers identified
- [ ] Team ready for monitoring
- [ ] Rollback plan accessible

### Step 2: Initial Deployment (10% Traffic)
- [ ] Deploy service with no traffic
  ```bash
  gcloud run deploy autobot \
    --image=gcr.io/$PROJECT_ID/autobot:$VERSION \
    --platform=managed \
    --region=asia-southeast1 \
    --allow-unauthenticated \
    --port=8080 \
    --add-cloudsql-instances=$CLOUD_SQL_INSTANCE \
    --set-env-vars=DB_HOST=localhost,DB_NAME=autobot,DB_USER=root,DB_SOCKET=/cloudsql/$CLOUD_SQL_INSTANCE \
    --set-secrets=DB_PASS=DB_PASSWORD:latest,JWT_SECRET_KEY=JWT_SECRET:latest,OMISE_SECRET_KEY=OMISE_SECRET:latest \
    --memory=1Gi \
    --cpu=2 \
    --max-instances=50 \
    --min-instances=1 \
    --concurrency=80 \
    --timeout=300 \
    --no-traffic
  ```
- [ ] Deployment completed
- [ ] Service URL: `__________________`
- [ ] Latest revision: `__________________`

### Step 3: Route 10% Traffic
- [ ] Update traffic split
  ```bash
  gcloud run services update-traffic autobot \
    --to-revisions=[NEW_REVISION]=10 \
    --region=asia-southeast1
  ```
- [ ] Traffic split at: `________ (time)`
- [ ] Monitor for 15 minutes

### Step 4: Monitor Initial Traffic
- [ ] View real-time logs
  ```bash
  gcloud run services logs tail autobot --region=asia-southeast1
  ```
- [ ] Check for errors (should be 0)
  - Error count: `________`
- [ ] Check response times
  - Average: `________ ms`
  - P95: `________ ms`
- [ ] Memory usage: `________ MB`
- [ ] CPU usage: `________ %`

**Initial Deployment Sign-off:** ________________  Time: ________

---

## 🧪 SMOKE TESTS (T+15 Minutes)

### Test 1: Health Check
- [ ] Access root URL
  ```bash
  curl -I https://[SERVICE_URL]/
  ```
- [ ] Response code: `200` ✅
- [ ] Response time: `________ ms`

### Test 2: Login Flow
- [ ] Navigate to login page: `https://[SERVICE_URL]/login.php`
- [ ] Page loads successfully ✅
- [ ] Login with test account works ✅
- [ ] JWT token received ✅

### Test 3: Conversations Page
- [ ] Navigate to: `https://[SERVICE_URL]/conversations.php`
- [ ] Page loads in < 1 second ✅
- [ ] Pagination controls visible ✅
- [ ] Search box functional ✅
- [ ] Filter buttons working ✅
- [ ] Keyboard shortcuts (Ctrl+K, ←→) working ✅
- [ ] Empty state displays correctly ✅

### Test 4: Payment History Page
- [ ] Navigate to: `https://[SERVICE_URL]/payment-history.php`
- [ ] Page loads quickly ✅
- [ ] Pagination working ✅
- [ ] Search filters payments ✅
- [ ] Payment slip images load correctly ✅
- [ ] Image paths are correct (/uploads/) ✅

### Test 5: Profile Page
- [ ] Navigate to: `https://[SERVICE_URL]/profile.php`
- [ ] Phone validation working ✅
  - Invalid phone shows red border ✅
  - Valid phone shows green border ✅
- [ ] Password strength meter working ✅
  - Weak password shows 1-2/5 ✅
  - Strong password shows 5/5 ✅
- [ ] Password match validation working ✅

### Test 6: Dashboard
- [ ] Navigate to: `https://[SERVICE_URL]/dashboard.php`
- [ ] Widgets load correctly ✅
- [ ] Charts render ✅
- [ ] Data is accurate ✅

**Smoke Tests Sign-off:** ________________  Time: ________

---

## 📈 GRADUAL ROLLOUT (T+30 Minutes)

### Step 1: 50% Traffic
- [ ] No issues found in monitoring
- [ ] Route 50% traffic
  ```bash
  gcloud run services update-traffic autobot \
    --to-revisions=[NEW_REVISION]=50 \
    --region=asia-southeast1
  ```
- [ ] Traffic split at: `________ (time)`
- [ ] Monitor for 30 minutes
  - [ ] Error rate < 0.1% ✅
  - [ ] Response time < 500ms ✅
  - [ ] No user complaints ✅

### Step 2: 100% Traffic
- [ ] No issues found in monitoring
- [ ] Route 100% traffic
  ```bash
  gcloud run services update-traffic autobot \
    --to-revisions=[NEW_REVISION]=100 \
    --region=asia-southeast1
  ```
- [ ] Full deployment at: `________ (time)`
- [ ] Monitor for 1 hour
  - [ ] Error rate < 0.1% ✅
  - [ ] Response time < 500ms ✅
  - [ ] Memory stable ✅
  - [ ] No crashes ✅

**Rollout Sign-off:** ________________  Time: ________

---

## 🎯 POST-DEPLOYMENT VERIFICATION (T+2 Hours)

### Performance Metrics
- [ ] Check Cloud Run metrics dashboard
  - Link: `https://console.cloud.google.com/run/detail/asia-southeast1/autobot/metrics`
- [ ] Record metrics:
  - Request count: `________`
  - Average latency: `________ ms`
  - Error rate: `________ %`
  - Memory utilization: `________ %`
  - CPU utilization: `________ %`

### User Testing
- [ ] 5 internal users tested features ✅
- [ ] Feedback collected
- [ ] No critical issues reported ✅

### Support Tickets
- [ ] Check support system
- [ ] New tickets related to deployment: `________`
- [ ] All resolved or in progress ✅

### Analytics
- [ ] Google Analytics tracking working
- [ ] Custom events firing correctly
- [ ] Page load times recorded

**Verification Sign-off:** ________________  Time: ________

---

## 📊 24-HOUR MONITORING (T+24 Hours)

### Day 1 Metrics
- [ ] Total requests: `________`
- [ ] Average response time: `________ ms`
- [ ] Error rate: `________ %`
- [ ] Uptime: `________ %`
- [ ] Support tickets: `________`

### Performance Targets (Check if met)
- [ ] Page load time < 1s ✅
- [ ] Error rate < 0.1% ✅
- [ ] Uptime > 99.9% ✅
- [ ] Memory usage < 500MB ✅

### User Feedback
- [ ] Positive feedback collected: `________`
- [ ] Issues reported: `________`
- [ ] Feature requests: `________`

**24-Hour Sign-off:** ________________  Date: ________

---

## 🎉 DEPLOYMENT COMPLETE

### Final Checklist
- [ ] All tests passed
- [ ] No critical errors
- [ ] Performance meets targets
- [ ] User feedback positive
- [ ] Documentation updated
- [ ] Team debriefed

### Success Metrics Achieved
- [ ] 90% faster page loads ✅
- [ ] 80% memory reduction ✅
- [ ] Zero critical bugs ✅
- [ ] > 90% user satisfaction ✅

### Next Steps
- [ ] Continue monitoring for 1 week
- [ ] Collect user feedback via survey
- [ ] Measure business impact (support tickets, retention)
- [ ] Plan Phase 2 enhancements
- [ ] Celebrate success! 🎉

---

## 🔄 ROLLBACK PLAN (If Needed)

### Quick Rollback
```bash
# List revisions
gcloud run revisions list --service=autobot --region=asia-southeast1

# Rollback to previous
gcloud run services update-traffic autobot \
  --to-revisions=[PREVIOUS_REVISION]=100 \
  --region=asia-southeast1
```

### Database Rollback
```bash
# Restore from backup
gcloud sql import sql autobot-db \
  gs://[BACKUP_BUCKET]/autobot-backup-[TIMESTAMP].sql \
  --database=autobot
```

### Rollback Executed
- [ ] Rollback triggered at: `________ (time)`
- [ ] Reason: `__________________`
- [ ] Previous revision: `__________________`
- [ ] Rollback completed at: `________ (time)`
- [ ] Service restored ✅

---

## 📝 NOTES & ISSUES

| Time | Issue | Severity | Resolution | Resolved By |
|------|-------|----------|------------|-------------|
|      |       |          |            |             |
|      |       |          |            |             |
|      |       |          |            |             |

---

## ✅ SIGN-OFF

**Deployment Lead:** ________________  Date: ________ Time: ________

**DevOps Engineer:** ________________  Date: ________ Time: ________

**QA Lead:** ________________  Date: ________ Time: ________

**Project Manager:** ________________  Date: ________ Time: ________

---

**Deployment Status:** [ ] Success  [ ] Partial  [ ] Failed  [ ] Rolled Back

**Final Notes:**
_______________________________________________________________________________
_______________________________________________________________________________
_______________________________________________________________________________
_______________________________________________________________________________

---

**Document Version:** 1.0  
**Last Updated:** December 2024  
**Template for:** Customer Portal UX Enhancements v2.0.0
