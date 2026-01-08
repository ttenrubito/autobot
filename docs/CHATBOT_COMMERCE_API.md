# Chatbot Commerce System - API Documentation

## Overview

This document describes the complete API system for the Chatbot Commerce platform supporting:
- **Product Search** - Find products via text/image
- **Full Payment** - Direct purchase with payment slip
- **Installment (ผ่อน)** - Pay in multiple periods
- **Savings (ออม)** - Save towards a product goal
- **Push Notifications** - Real-time updates to customers via LINE/Facebook

---

## 1. Bot APIs (Customer-facing via Chatbot)

### 1.1 Cases API
Base: `/api/bot/cases`

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/bot/cases` | Create new case |
| GET | `/api/bot/cases/{id}` | Get case by ID |
| POST | `/api/bot/cases/{id}/update-slot` | Update case slots |
| POST | `/api/bot/cases/{id}/status` | Update case status |

### 1.2 Savings API
Base: `/api/bot/savings`

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/bot/savings` | Create savings account |
| GET | `/api/bot/savings/by-user` | Get user's savings |
| GET | `/api/bot/savings/{id}` | Get savings by ID |
| GET | `/api/bot/savings/{id}/status` | Get savings status |
| POST | `/api/bot/savings/{id}/deposit` | Submit deposit |

### 1.3 Installments API ⭐ NEW
Base: `/api/bot/installments`

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/bot/installments` | Create installment contract |
| GET | `/api/bot/installments/by-user` | Get user's contracts |
| GET | `/api/bot/installments/{id}` | Get contract by ID |
| GET | `/api/bot/installments/{id}/status` | Get contract status |
| POST | `/api/bot/installments/{id}/pay` | Submit period payment |
| POST | `/api/bot/installments/{id}/extend` | Request extension |

#### Create Installment Contract
```json
POST /api/bot/installments
{
  "channel_id": 1,
  "external_user_id": "U1234567890",
  "platform": "line",
  "product_ref_id": "LV-001",
  "product_name": "Louis Vuitton Neverfull",
  "product_price": 45000,
  "total_periods": 6,
  "down_payment": 5000,
  "customer_name": "สมชาย",
  "customer_phone": "0812345678"
}
```

#### Submit Period Payment
```json
POST /api/bot/installments/{id}/pay
{
  "slip_image_url": "https://...",
  "amount": 7500,
  "period_number": 1,
  "slip_ocr_data": {
    "sender_name": "สมชาย",
    "amount": 7500,
    "transaction_id": "202601071234"
  }
}
```

### 1.4 Payments API
Base: `/api/bot/payments`

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/bot/payments/submit` | Submit payment |
| POST | `/api/bot/payments/draft-order` | Create draft order |
| GET | `/api/bot/payments/by-user` | Get user's payments |
| GET | `/api/bot/payments/{id}` | Get payment by ID |

---

## 2. Admin APIs

### 2.1 Admin Installments API ⭐ NEW
Base: `/api/admin/installments`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/installments` | List contracts |
| GET | `/api/admin/installments?stats=1` | Get statistics |
| GET | `/api/admin/installments/{id}` | Get contract details |
| POST | `/api/admin/installments/{id}/approve` | Approve contract |
| POST | `/api/admin/installments/{id}/verify-payment` | Verify payment |
| POST | `/api/admin/installments/{id}/reject-payment` | Reject payment |
| POST | `/api/admin/installments/{id}/manual-payment` | Add manual payment |
| POST | `/api/admin/installments/{id}/update-due-date` | Update due date |
| POST | `/api/admin/installments/{id}/cancel` | Cancel contract |

#### List Contracts with Filters
```
GET /api/admin/installments?status=active&pending_payments=1&limit=50
```

#### Verify Payment (with Push Notification)
```json
POST /api/admin/installments/{id}/verify-payment
{
  "payment_id": 123,
  "notes": "Verified via slip"
}
```

### 2.2 Admin Payments API (Updated)
Base: `/api/admin/payments`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/payments` | List payments |
| GET | `/api/admin/payments/{id}` | Get payment details |
| POST | `/api/admin/payments/{id}/verify` | Verify with push notification |
| POST | `/api/admin/payments/{id}/reject` | Reject with push notification |
| POST | `/api/admin/payments/manual` | Add manual payment |

### 2.3 Admin Savings API
Base: `/api/admin/savings`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/savings` | List savings accounts |
| GET | `/api/admin/savings/{id}` | Get savings details |
| POST | `/api/admin/savings/{id}/approve-deposit` | Approve deposit (with push) |
| POST | `/api/admin/savings/{id}/cancel` | Cancel savings |
| POST | `/api/admin/savings/{id}/complete` | Mark as completed |

### 2.4 Admin Cases API
Base: `/api/admin/cases`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/cases` | List cases |
| GET | `/api/admin/cases/{id}` | Get case details |
| PUT | `/api/admin/cases/{id}/assign` | Assign to admin |
| PUT | `/api/admin/cases/{id}/resolve` | Resolve case |
| POST | `/api/admin/cases/{id}/send-message` | Send message to customer |
| POST | `/api/admin/cases/{id}/note` | Add internal note |

---

## 3. Push Notification API ⭐ NEW

### 3.1 Webhook API (Internal Use)
Base: `/api/webhook/push-notify`

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/webhook/push-notify/send` | Send immediate notification |
| POST | `/api/webhook/push-notify/queue` | Queue for later |
| POST | `/api/webhook/push-notify/process` | Process pending queue |
| GET | `/api/webhook/push-notify/status` | Get notification status |
| GET | `/api/webhook/push-notify/stats` | Get statistics |

#### Send Immediate Notification
```json
POST /api/webhook/push-notify/send
{
  "platform": "line",
  "platform_user_id": "U1234567890",
  "notification_type": "payment_verified",
  "data": {
    "amount": 7500,
    "verified_date": "07/01/2026 14:30"
  },
  "channel_id": 1
}
```

### 3.2 Notification Types

| Type | Description |
|------|-------------|
| `payment_received` | Payment slip received, pending verification |
| `payment_verified` | Payment verified by admin |
| `payment_rejected` | Payment rejected |
| `installment_payment_verified` | Installment period payment verified |
| `installment_completed` | All installments paid |
| `installment_reminder` | Due date reminder |
| `installment_overdue` | Payment overdue notice |
| `savings_deposit_verified` | Savings deposit verified |
| `savings_goal_reached` | Savings target reached |
| `order_confirmed` | Order confirmed |
| `order_shipped` | Order shipped |

### 3.3 PushNotificationService Class

```php
use PushNotificationService;

$pushService = new PushNotificationService($db);

// Send immediate notification
$result = $pushService->send('line', 'U1234567890', 'payment_verified', [
    'amount' => 7500,
    'verified_date' => date('d/m/Y H:i')
], $channelId);

// Queue for later
$notificationId = $pushService->queue('line', 'U1234567890', 'installment_reminder', [...]);

// Process pending (for cron job)
$results = $pushService->processPending(50);
```

---

## 4. Database Schema

### New Tables (Migration: `2026_01_07_create_installments_tables.sql`)

1. **installment_contracts** - Installment contracts
2. **installment_payments** - Period payments
3. **installment_reminders** - Payment reminders
4. **push_notifications** - Notification log
5. **notification_templates** - Message templates

### Run Migration
```bash
mysql -u root autobot < database/migrations/2026_01_07_create_installments_tables.sql
```

---

## 5. Cron Jobs

### Process Push Notifications
```bash
# Every 5 minutes
*/5 * * * * /opt/lampp/htdocs/autobot/cron/process-push-notifications.sh
```

### Check Overdue Installments (TODO)
```bash
# Daily at 9 AM
0 9 * * * /opt/lampp/htdocs/autobot/cron/check-overdue-installments.sh
```

---

## 6. Environment Variables

```env
# Push Notification
INTERNAL_API_KEY=your-internal-api-key

# LINE
LINE_CHANNEL_ACCESS_TOKEN=xxx

# Facebook
FB_PAGE_ACCESS_TOKEN=xxx
```

---

## 7. Frontend Integration (JavaScript)

### API Endpoints (path-config.js)

```javascript
// Installments
API_ENDPOINTS.ADMIN_INSTALLMENTS_API
API_ENDPOINTS.ADMIN_INSTALLMENT_APPROVE(contractId)
API_ENDPOINTS.ADMIN_INSTALLMENT_VERIFY_PAYMENT(contractId)
API_ENDPOINTS.ADMIN_INSTALLMENT_REJECT_PAYMENT(contractId)
API_ENDPOINTS.ADMIN_INSTALLMENT_MANUAL_PAYMENT(contractId)

// Savings
API_ENDPOINTS.ADMIN_SAVINGS_APPROVE_DEPOSIT(savingsId)
API_ENDPOINTS.ADMIN_SAVINGS_CANCEL(savingsId)
API_ENDPOINTS.ADMIN_SAVINGS_COMPLETE(savingsId)

// Payments
API_ENDPOINTS.ADMIN_PAYMENT_VERIFY(paymentId)
API_ENDPOINTS.ADMIN_PAYMENT_REJECT_NEW(paymentId)
API_ENDPOINTS.ADMIN_PAYMENT_MANUAL

// Push Notifications
API_ENDPOINTS.PUSH_NOTIFY_SEND
API_ENDPOINTS.PUSH_NOTIFY_STATS
```

---

## 8. Flow Diagrams

### Installment Flow
```
Customer → Chatbot → Create Contract → Admin Approve → 
→ Customer Pays Period → Admin Verify → Push Notification → 
→ Repeat until complete → Create Order
```

### Savings Flow
```
Customer → Chatbot → Create Savings Account → 
→ Customer Deposits → Admin Verify → Push Notification → 
→ Goal Reached → Create Order
```

### Full Payment Flow
```
Customer → Chatbot → Create Draft Order → 
→ Customer Pays → Admin Verify → Push Notification → 
→ Process Order → Ship
```

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-01-07 | Initial release with all commerce APIs |

