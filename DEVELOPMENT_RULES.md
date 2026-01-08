# Development & Deployment Rules

## 🚨 CRITICAL: No Direct Production Deployment Without Testing

**ALL changes to bot logic MUST pass local tests before deploying to production.**

Production deployment มูลค่าสูง ห้าม deploy โดยไม่ test เด็ดขาด

---

## Mandatory Pre-Deployment Checklist

### 1. **PHP Syntax Check** (ใช้เวลา < 5 วินาที)
```bash
# Check all PHP files for syntax errors
find includes api -name "*.php" -exec php -l {} \;
```

**Expected output:** `No syntax errors detected`  
**If errors found:** ❌ Fix syntax before proceeding

---

### 2. **Unit Test (Mock - ไม่เรียก API จริง)** (ใช้เวลา < 30 วินาที)
```bash
# Run RouterV1Handler unit tests with mock database
./vendor/bin/phpunit tests/bot/RouterV1HandlerTest.php
```

**Expected output:** All tests pass (green ✅)  
**If tests fail:** ❌ Fix logic before proceeding

**Test Coverage Required:**
- ✅ Empty text → greeting
- ✅ Admin command → no reply + handoff activated
- ✅ Repeat spam → anti-spam template
- ✅ Echo message → ignored
- ✅ Admin timeout → bot resumes after 1 hour
- ✅ KB match → immediate answer
- ✅ Out-of-scope → policy template

---

### 3. **Integration Test (Optional - ใช้เมื่อต้องการทดสอบ DB จริง)** (ใช้เวลา < 1 นาที)
```bash
# Test with real DB (localhost test database)
php tests/integration/RouterV1IntegrationTest.php
```

**Expected output:** 
- ✅ Reply: <valid text>
- ✅ Intent: <valid intent or null>
- ✅ Reason: <valid reason>

**If test fails:** ❌ Debug and fix before proceeding

---

### 4. **Webhook Simulation (Manual - สำหรับ debug ละเอียด)** (ใช้เวลา < 2 นาที)
```bash
# Simulate Facebook webhook call
./tests/simulate_facebook_webhook.sh

# Check logs
tail -f logs/app-$(date +%Y-%m-%d).log
```

**Expected:**
- No PHP errors
- Gateway returns valid JSON
- Reply text is appropriate

---

## Deployment Procedure

**ONLY after all checks pass:**

```bash
# Deploy to production (will run tests automatically)
./deploy_app_to_production.sh
```

**The deploy script will:**
1. ✅ Run syntax check
2. ✅ Run unit tests
3. ✅ Ask for confirmation
4. 🚀 Deploy to Cloud Run (only if tests pass)

**Then verify:**
```bash
# Check Cloud Run logs for errors
gcloud run services logs read autobot \
  --project autobot-prod-251215-22549 \
  --region asia-southeast1 \
  --limit 50 \
  --format="table(timestamp,severity,textPayload)"
```

---

## Emergency Deployment (Human Override Only)

In production emergencies (service down), human developer may skip tests with:
```bash
# Emergency hotfix (document reason in commit message)
SKIP_TESTS=1 ./deploy_app_to_production.sh
```

**⚠️ This should be RARE and followed by immediate post-deploy verification.**

---

## Common RouterV1Handler Test Cases

### ✅ Must Pass Tests (Mock Database):

1. **Empty text → greeting**
   - Input: `{text: ""}`
   - Expected: `reply_text` contains greeting template
   - Reason: `empty_text_use_greeting`

2. **Admin command → no reply + timeout set**
   - Input: `{text: "admin"}`
   - Expected: `reply_text = null`, `reason = admin_handoff_manual_command`
   - DB: Must call `UPDATE chat_sessions SET last_admin_message_at = NOW()`

3. **Repeat spam → anti-spam template**
   - Input: Same message 3 times within 30 seconds
   - Expected: `repeat_detected` template
   - Reason: `repeat_detected`

4. **Echo message → ignored**
   - Input: `{is_echo: true}`
   - Expected: `reply_text = null`, `reason = ignore_echo`

5. **Admin timeout expired → bot resumes**
   - Input: User message after 1 hour from last admin message
   - Expected: Normal bot reply (not paused)
   - DB: `last_admin_message_at` should be cleared

6. **KB match → immediate answer**
   - Input: Query matching KB entry
   - Expected: `reply_text` from KB, `reason = knowledge_base_answer`

7. **Out-of-scope → policy template**
   - Input: Query outside policy scope
   - Expected: `out_of_scope` template

---

## Files Critical to Test

| File | Why Critical | Test Command |
|------|--------------|--------------|
| `includes/bot/RouterV1Handler.php` | Main bot logic | `phpunit tests/bot/RouterV1HandlerTest.php` |
| `api/gateway/message.php` | Gateway entry point | `curl -X POST localhost/autobot/api/gateway/message.php` |
| `api/webhooks/facebook.php` | FB webhook handler | `./tests/simulate_facebook_webhook.sh` |

---

## Error Patterns That Must NOT Reach Production

❌ **Parse error** (syntax error)  
❌ **Fatal error** (null is not callable, undefined method)  
❌ **Non-JSON response** from gateway  
❌ **Infinite loop** (bot keeps asking same question)  
❌ **Hallucination** (inventing product info when backend disabled)  
❌ **Admin handoff not working** (bot keeps replying when admin is active)

---

## AI Assistant Instructions

**If you are an AI making changes to this codebase:**

1. ✅ **Always run unit tests before suggesting deployment**
2. ✅ **Show test results in your response**
3. ✅ **Never suggest `./deploy.sh` without test confirmation**
4. ❌ **Do NOT assume "it should work" - prove it with tests**
5. ✅ **Use mock database for unit tests - never call real APIs in tests**

---

## Git Pre-commit Hook

Automatically installed at `.git/hooks/pre-commit`

**What it does:**
- ✅ Checks PHP syntax on all changed `.php` files
- ✅ Runs unit tests if `RouterV1Handler.php` was changed
- ❌ Blocks commit if tests fail

**To bypass (emergency only):**
```bash
git commit --no-verify -m "Emergency fix"
```

---

## Directory Structure for Tests

```
tests/
├── bot/
│   └── RouterV1HandlerTest.php       # Unit tests (mock DB)
├── integration/
│   └── RouterV1IntegrationTest.php   # Integration tests (real DB)
├── simulate_facebook_webhook.sh      # Manual webhook simulation
└── bootstrap.php                     # Test setup
```

---

Last updated: 2025-12-27  
**Version:** 1.0.0
