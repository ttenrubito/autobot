# Omise Webhook Documentation

## Webhook Endpoint

**URL:** `https://yourdomain.com/api/webhooks/omise.php`  
**Method:** POST  
**Authentication:** None (public endpoint, secured by signature verification)

---

## Request Structure (from Omise)

### Headers
```http
POST /api/webhooks/omise.php HTTP/1.1
Content-Type: application/json
Omise-Signature: <signature_hash>
User-Agent: Omise/Webhook
```

### Payload Example: `charge.complete`
```json
{
  "object": "event",
  "id": "evnt_test_5xyzabc123",
  "livemode": false,
  "location": "/events/evnt_test_5xyzabc123",
  "key": "charge.complete",
  "created_at": "2025-12-14T15:30:00Z",
  "data": {
    "object": "charge",
    "id": "chrg_test_5xyz123abc",
    "livemode": false,
    "amount": 500000,
    "currency": "THB",
    "description": "Invoice #INV-20251214-00001-1",
    "status": "successful",
    "paid": true,
    "paid_at": "2025-12-14T15:30:00Z",
    "source": {
      "object": "source",
      "type": "promptpay",
      "flow": "offline",
      "amount": 500000,
      "currency": "THB"
    },
    "transaction": "trxn_test_5xyz789",
    "failure_code": null,
    "failure_message": null,
    "created_at": "2025-12-14T15:25:00Z"
  }
}
```

### Payload Example: `charge.failed`
```json
{
  "object": "event",
  "id": "evnt_test_5xyzfail",
  "key": "charge.failed",
  "created_at": "2025-12-14T15:35:00Z",
  "data": {
    "object": "charge",
    "id": "chrg_test_5xyzfail",
    "amount": 500000,
    "currency": "THB",
    "status": "failed",
    "paid": false,
    "failure_code": "payment_expired",
    "failure_message": "QR code expired",
    "created_at": "2025-12-14T15:25:00Z"
  }
}
```

---

## Response Structure (from your server)

### Success Response
```http
HTTP/1.1 200 OK
Content-Type: application/json

{
  "success": true,
  "message": "Webhook processed"
}
```

### Error Response (still 200 OK to prevent retries)
```http
HTTP/1.1 200 OK
Content-Type: application/json

{
  "success": false,
  "message": "Error message"
}
```

**Important:** Always return 200 OK, even on errors, to prevent Omise from retrying the webhook.

---

## Event Types

| Event Key | Description | Action |
|-----------|-------------|--------|
| `charge.complete` | Payment successful | Update invoice to 'paid' |
| `charge.success` | Payment successful (alias) | Update invoice to 'paid' |
| `charge.failed` | Payment failed | Update invoice to 'failed' |
| `charge.expired` | QR code expired | Update invoice to 'failed' |
| `charge.pending` | Payment processing | Update to 'pending' |

---

## Configuration Steps

### 1. Set Webhook URL in Omise Dashboard

1. Login to Omise Dashboard: https://dashboard.omise.co/
2. Go to **Settings** → **Webhooks**
3. Click **Add Endpoint**
4. Enter URL: `https://yourdomain.com/api/webhooks/omise.php`
5. Select events to listen:
   - ✅ `charge.complete`
   - ✅ `charge.failed`
   - ✅ `charge.expired`
6. Click **Create**

### 2. Local Testing with ngrok

Since Omise needs a public URL, use ngrok for local development:

```bash
# Install ngrok
# Download from https://ngrok.com/

# Start ngrok tunnel
ngrok http 80

# You'll get a URL like: https://abc123.ngrok.io
# Use this in Omise: https://abc123.ngrok.io/autobot/api/webhooks/omise.php
```

### 3. Test Webhook

Use Omise Dashboard to send test webhook:

1. Go to **Webhooks** in dashboard
2. Click on your webhook endpoint
3. Click **Send Test Event**
4. Select `charge.complete`
5. Click **Send**

Check logs at: `/opt/lampp/htdocs/autobot/logs/omise_webhooks.log`

---

## Security: Signature Verification

### How Omise Signs Webhooks

```
signature = HMAC-SHA256(
  data: raw_json_payload,
  key: your_omise_secret_key
)
```

### Verify in PHP

```php
$signature = $headers['Omise-Signature'] ?? '';
$secretKey = getenv('OMISE_SECRET_KEY');
$calculatedSignature = hash_hmac('sha256', $rawPayload, $secretKey);

if ($signature !== $calculatedSignature) {
    http_response_code(401);
    exit('Invalid signature');
}
```

**Note:** Currently disabled in webhook handler for easier testing. Enable in production!

---

## Flow Diagram

```
┌─────────┐                 ┌──────────┐                ┌─────────────┐
│ Customer│                 │  Omise   │                │ Your Server │
└────┬────┘                 └────┬─────┘                └──────┬──────┘
     │                           │                             │
     │ 1. Scan QR Code           │                             │
     ├──────────────────────────>│                             │
     │                           │                             │
     │ 2. Confirm Payment        │                             │
     ├──────────────────────────>│                             │
     │                           │                             │
     │                           │ 3. Send Webhook             │
     │                           │  (charge.complete)          │
     │                           ├────────────────────────────>│
     │                           │                             │
     │                           │ 4. Return 200 OK            │
     │                           │<────────────────────────────┤
     │                           │                             │
     │                           │                      5. Update DB
     │                           │                        (invoice=paid)
     │                           │                             │
     │ 6. Polling detects paid=true                           │
     │<───────────────────────────────────────────────────────┤
     │                           │                             │
```

---

## Webhook Handler Logic

```php
// Pseudo-code
if (event.key === 'charge.complete') {
    // 1. Find transaction by charge_id
    transaction = db.find(charge_id);
    
    // 2. Update transaction status
    db.update(transaction, status='successful');
    
    // 3. Find and update linked invoice
    if (transaction.invoice_id) {
        db.update(invoice, status='paid', paid_at=NOW());
    }
    
    // 4. Log activity
    db.insert(activity_log, action='payment_complete');
}
```

---

## Troubleshooting

### Webhook not received?

1. **Check URL is public:**
   ```bash
   curl https://yourdomain.com/api/webhooks/omise.php
   # Should return JSON, not 404
   ```

2. **Check Omise Dashboard:**
   - Go to Webhooks → Your endpoint
   - Check "Recent Deliveries"
   - Look for failed attempts

3. **Check logs:**
   ```bash
   tail -f /opt/lampp/htdocs/autobot/logs/omise_webhooks.log
   ```

### Webhook received but not working?

1. **Check logs for errors**
2. **Verify charge_id matches transaction**
3. **Check database permissions**
4. **Enable error reporting** in webhook handler

---

## Testing Checklist

- [ ] Webhook URL configured in Omise Dashboard
- [ ] Test event sent successfully (200 OK)
- [ ] Log file created and written
- [ ] Transaction status updated in database
- [ ] Invoice status updated to 'paid'
- [ ] Activity log created
- [ ] Frontend polling detects change
- [ ] Modal shows success message

---

## Production Recommendations

1. ✅ Enable signature verification
2. ✅ Use HTTPS only
3. ✅ Implement retry logic for DB failures
4. ✅ Send email notifications on payment success
5. ✅ Monitor webhook failures
6. ✅ Set up alerting for missed webhooks
