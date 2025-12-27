# API Endpoints Checklist

## ✅ Completed APIs

### Authentication
- [x] `POST /api/auth/login.php` - Customer login with rate limiting
- [x] `POST /api/auth/logout.php` - Logout
- [x] `POST /api/auth/register.php` - Register new user
- [x] `POST /api/admin/login.php` - Admin login with rate limiting

### Customer Dashboard
- [x] `GET /api/dashboard/stats.php` - Dashboard statistics
- [x] `GET /api/dashboard/recent-activity.php` - Recent activity

### Services Management
- [x] `GET /api/services/list.php` - List customer services
- [x] `POST /api/services/create.php` - Create new service
- [x] `PUT /api/services/update.php` - Update service
- [x] `DELETE /api/services/delete.php` - Delete service
- [x] `GET /api/services/types.php` - List service types

### Usage Statistics
- [x] `GET /api/usage/summary.php` - Usage summary
- [x] `GET /api/usage/chart-data.php` - Chart data for graphs
- [x] `GET /api/usage/recent-messages.php` - Recent bot messages
- [x] `GET /api/usage/api-breakdown.php` - API usage breakdown

### Payment System
- [x] `GET /api/payment/methods.php` - List payment methods
- [x] `POST /api/payment/add-card.php` - Add credit card (Omise)
- [x] `DELETE /api/payment/remove-card.php` - Remove card
- [x] `PUT /api/payment/set-default.php` - Set default card

### Billing
- [x] `GET /api/billing/invoices.php` - List invoices
- [x] `GET /api/billing/transactions.php` - Transaction history
- [x] `GET /api/billing/current-plan.php` - Current subscription plan

### User Profile
- [x] `GET /api/user/profile.php` - Get profile
- [x] `PUT /api/user/update-profile.php` - Update profile
- [x] `PUT /api/user/change-password.php` - Change password

### API Keys (for n8n)
- [x] `GET /api/user/api-key.php` - Get API key
- [x] `POST /api/user/regenerate-key.php` - Regenerate API key

### Admin APIs
- [x] `GET /api/admin/services/list.php` - List all API services
- [x] `POST /api/admin/services/toggle.php` - Toggle service on/off
- [x] `GET /api/admin/plans/list.php` - List subscription plans

### API Gateway (n8n Integration)
- [x] `POST /api/gateway/vision/labels` - Image label detection
- [x] `POST /api/gateway/vision/text` - Text detection (OCR)
- [x] `POST /api/gateway/vision/faces` - Face detection
- [x] `POST /api/gateway/vision/objects` - Object detection
- [x] `POST /api/gateway/language/sentiment` - Sentiment analysis
- [x] `POST /api/gateway/language/entities` - Entity extraction
- [x] `POST /api/gateway/language/syntax` - Syntax analysis

### System
- [x] `GET /api/health.php` - Health check

## ❌ Missing/Todo APIs

### Admin Panel (จำเป็น)
- [ ] `GET /api/admin/dashboard/stats.php` - Admin dashboard stats
- [ ] `GET /api/admin/customers/list.php` - List all customers
- [ ] `GET /api/admin/customers/{id}/details.php` - Customer details
- [ ] `PUT /api/admin/customers/{id}/update.php` - Update customer
- [ ] `POST /api/admin/customers/{id}/toggle-service.php` - Enable/disable service for customer

### Subscription Management
- [ ] `POST /api/subscription/change-plan.php` - Change subscription plan
- [ ] `POST /api/subscription/cancel.php` - Cancel subscription

### Billing Integration (Omise)
- [ ] `POST /api/payment/create-charge.php` - Create Omise charge
- [ ] `POST /api/payment/webhook.php` - Omise webhook handler
- [ ] `GET /api/billing/upcoming-invoice.php` - Preview next invoice

### Statistics & Reports
- [ ] `GET /api/reports/monthly.php` - Monthly usage report
- [ ] `GET /api/reports/export.php` - Export data (CSV/PDF)

## 📝 Notes

**For Omise Integration:**
You need to create:
1. `/api/payment/create-charge.php` - Process payment
2. `/api/payment/webhook.php` - Handle Omise callbacks
3. `/api/billing/process-subscription.php` - Auto-billing logic

**Database Tables Ready:**
- ✅ users, subscriptions, invoices, transactions
- ✅ payment_methods (stores Omise card tokens)
- ✅ All relationships configured

**Omise Flow:**
```
1. Add Card → Store Omise card token in payment_methods
2. Charge → Use card token to create charge
3. Webhook → Update transaction status
4. Invoice → Mark as paid
```

## 🎯 Priority for You

**High Priority (สำคัญ):**
1. `/api/payment/create-charge.php` - ชาร์จบัตรเครดิต
2. `/api/payment/webhook.php` - รับ callback จาก Omise
3. `/api/billing/process-subscription.php` - Auto-billing รายเดือน

**Medium Priority:**
1. Admin customer management APIs
2. Subscription change/cancel
3. Reports & exports

**Low Priority:**
1. Advanced analytics
2. Bulk operations
3. Email notifications

## 📚 Documentation

API Documentation: `/autobot/public/api-docs.html`
OpenAPI Spec: `/autobot/openapi.yaml`
Testing Guide: `/autobot/API_TESTING.md`
