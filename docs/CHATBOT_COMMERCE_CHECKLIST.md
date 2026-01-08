# Chatbot Commerce System - Implementation Checklist

**Last Updated:** 2026-01-07

## ✅ COMPLETED

### Phase 1: Database Schema
| # | Task | Status | File |
|---|------|--------|------|
| 1.1 | Create installments migration | ✅ Done | `database/migrations/2026_01_07_create_installments_tables.sql` |
| 1.2 | Create notification templates | ✅ Done | Included in migration (Thai messages) |
| 1.3 | Deploy to production DB | ⏳ Pending | Need to run migration |

### Phase 2: Bot APIs (Customer-facing)
| # | Task | Status | File |
|---|------|--------|------|
| 2.1 | Bot Cases API | ✅ Exists | `api/bot/cases/index.php` |
| 2.2 | Bot Savings API | ✅ Exists | `api/bot/savings/index.php` |
| 2.3 | Bot Payments API | ✅ Exists | `api/bot/payments/index.php` |
| 2.4 | Bot Installments API | ✅ Done | `api/bot/installments/index.php` |

### Phase 3: Admin APIs
| # | Task | Status | File |
|---|------|--------|------|
| 3.1 | Admin Cases API | ✅ Exists | `api/admin/cases/index.php` |
| 3.2 | Admin Savings API (with push) | ✅ Updated | `api/admin/savings/index.php` |
| 3.3 | Admin Payments API (with push) | ✅ Updated | `api/admin/payments.php` |
| 3.4 | Admin Installments API | ✅ Done | `api/admin/installments/index.php` |

### Phase 4: Push Notification System
| # | Task | Status | File |
|---|------|--------|------|
| 4.1 | PushNotificationService class | ✅ Done | `includes/services/PushNotificationService.php` |
| 4.2 | Push Webhook API | ✅ Done | `api/webhook/push-notify.php` |
| 4.3 | Push notification cron script | ✅ Done | `cron/process-push-notifications.sh` |
| 4.4 | Installment reminder cron script | ✅ Done | `cron/installment-reminders.sh` |
| 4.5 | Notification templates (DB) | ✅ Done | In migration SQL (14 templates) |

### Phase 5: Admin UI
| # | Task | Status | File |
|---|------|--------|------|
| 5.1 | Admin Installments page | ✅ Updated | `public/admin/installments.php` |
| 5.2 | Pending payments tab | ✅ Done | In installments.php |
| 5.3 | Verify payment modal | ✅ Done | In installments.php |
| 5.4 | Manual payment recording | ✅ Done | In installments.php |
| 5.5 | Send reminder button | ✅ Done | In installments.php |

### Phase 6: API Router & Config
| # | Task | Status | File |
|---|------|--------|------|
| 6.1 | Add installments routes | ✅ Done | `api/index.php` |
| 6.2 | Add push-notify routes | ✅ Done | `api/index.php` |
| 6.3 | Update path-config.js | ✅ Done | `assets/js/path-config.js` |
| 6.4 | Update bot_profile_config | ✅ Done | `bot_profile_config_generic.json` |

### Phase 7: Documentation
| # | Task | Status | File |
|---|------|--------|------|
| 7.1 | API Documentation | ✅ Done | `docs/CHATBOT_COMMERCE_API.md` |
| 7.2 | Implementation Checklist | ✅ Done | This file |

---

## ⏳ PENDING - Need Manual Action

### 1. Deploy Database Schema
```bash
# Run on production database
mysql -u root -p autobot < /opt/lampp/htdocs/autobot/database/migrations/2026_01_07_create_installments_tables.sql
```

### 2. Setup Cron Jobs
```bash
# Add to crontab
crontab -e

# Push notifications - every 5 minutes
*/5 * * * * /opt/lampp/htdocs/autobot/cron/process-push-notifications.sh

# Installment reminders - daily at 9 AM
0 9 * * * /opt/lampp/htdocs/autobot/cron/installment-reminders.sh
```

### 3. Configure Push Notification Credentials
In `.env` or environment variables:
```env
INTERNAL_API_KEY=your-secure-key

# LINE (get from LINE Developers Console)
LINE_CHANNEL_ACCESS_TOKEN=xxx

# Facebook (get from Meta Developer Console)
FB_PAGE_ACCESS_TOKEN=xxx
```

### 4. Deploy to Production
```bash
cd /opt/lampp/htdocs/autobot
AUTO_YES=1 ./deploy_app_to_production.sh
```

---

## 🔴 TODO - Future Enhancement

### Admin UI Improvements
| # | Task | Priority | Status |
|---|------|----------|--------|
| UI.1 | Add payment slip preview lightbox | 🟡 Medium | ⬜ |
| UI.2 | Push notification log viewer | 🟢 Low | ⬜ |
| UI.3 | Admin dashboard widgets for installments | 🟢 Low | ⬜ |

### Additional Features
| # | Task | Priority | Status |
|---|------|----------|--------|
| F.1 | OCR slip verification integration | 🟡 Medium | ⬜ |
| F.2 | Customer self-service portal | 🟡 Medium | ⬜ |
| F.3 | Payment receipt PDF generation | 🟢 Low | ⬜ |
| F.4 | Export installments to Excel | 🟢 Low | ⬜ |

---

## 📁 Files Created/Modified

### Created Files
```
api/bot/installments/index.php          - Bot Installments API
api/admin/installments/index.php        - Admin Installments API  
api/webhook/push-notify.php             - Push Notification Webhook
includes/services/PushNotificationService.php - Push Service Class
database/migrations/2026_01_07_create_installments_tables.sql
cron/process-push-notifications.sh      - Push notification cron
cron/installment-reminders.sh           - Installment reminder cron
docs/CHATBOT_COMMERCE_API.md
docs/CHATBOT_COMMERCE_CHECKLIST.md
```

### Modified Files
```
api/index.php                           - Added ~25 new routes
api/admin/payments.php                  - Rewrite with push notification
api/admin/savings/index.php             - Added push notification
public/admin/installments.php           - Complete UI update
assets/js/path-config.js                - Added 15+ new API endpoints
bot_profile_config_generic.json         - Added 5 installment endpoints
```

---

## 🔗 API Endpoints Summary

### Bot APIs (for Chatbot)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/bot/installments` | Create new installment contract |
| GET | `/api/bot/installments/{id}` | Get contract details |
| POST | `/api/bot/installments/{id}/pay` | Submit payment with slip |
| POST | `/api/bot/installments/{id}/extend` | Request extension |
| GET | `/api/bot/installments/{id}/status` | Get payment status |
| GET | `/api/bot/installments/by-user` | List user's contracts |

### Admin APIs
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/installments` | List all contracts |
| GET | `/api/admin/installments?stats=1` | Get statistics |
| GET | `/api/admin/installments?pending_payments=1` | List pending payments |
| GET | `/api/admin/installments/{id}` | Get contract details |
| POST | `/api/admin/installments/{id}/approve` | Approve contract |
| POST | `/api/admin/installments/{id}/verify-payment` | Verify payment |
| POST | `/api/admin/installments/{id}/reject-payment` | Reject payment |
| POST | `/api/admin/installments/{id}/manual-payment` | Add manual payment |
| POST | `/api/admin/installments/{id}/cancel` | Cancel contract |

### Push Notification APIs
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/webhook/push-notify/send` | Send immediate notification |
| POST | `/api/webhook/push-notify/queue` | Queue for later |
| POST | `/api/webhook/push-notify/process` | Process pending queue |
| GET | `/api/webhook/push-notify/status` | Get notification status |
| GET | `/api/webhook/push-notify/stats` | Get statistics |

---

## ✅ Validation Status

All PHP files pass syntax check:
- ✅ `api/bot/installments/index.php`
- ✅ `api/admin/installments/index.php`
- ✅ `includes/services/PushNotificationService.php`
- ✅ `api/webhook/push-notify.php`
- ✅ `api/admin/payments.php`
- ✅ `api/admin/savings/index.php`
- ✅ `api/index.php`
- ✅ `public/admin/installments.php`
