# Facebook Messenger & LINE Setup Guide

## 📱 Facebook Messenger Setup

### Step 1: Create Facebook App
1. Go to https://developers.facebook.com/apps
2. Click "Create App" → Choose "Business"
3. Fill in app details and create

### Step 2: Add Messenger Product
1. In your app dashboard, click "+ Add Product"
2. Select "Messenger" → Click "Set Up"

### Step 3: Get Page Access Token
1. Under Messenger Settings
2. Find "Access Tokens" section
3. Select your Facebook Page
4. Copy the "Page Access Token" (starts with EAA...)

### Step 4: Get App Secret
1. Go to Settings → Basic
2. Click "Show" next to App Secret
3. Copy the App Secret

### Step 5: Setup Webhook in Autobot
1. Login to Autobot Admin: `http://localhost/autobot/admin`
2. Go to Customers → Select customer → Channels tab
3. Click "+ เพิ่มช่องทาง"
4. Fill in:
   - **ชื่อช่องทาง**: Facebook Messenger
   - **ประเภท**: facebook
   - **Page Access Token**: (paste from step 3)
   - **App Secret**: (paste from step 4)
   - **Verify Token**: autobot_verify_2024 (or custom)
   - **Page ID**: Your Facebook Page ID
5. Click บันทึก
6. Copy the generated **Webhook URL**

### Step 6: Configure Facebook Webhook
1. Back to Facebook App → Messenger → Settings
2. Find "Webhooks" section
3. Click "Add Callback URL"
4. Paste webhook URL: `https://yourdomain.com/autobot/api/webhooks/facebook.php`
5. Verify Token: `autobot_verify_2024` (same as step 5)
6. Click "Verify and Save"
7. Subscribe to fields: `messages`, `messaging_postbacks`

### Step 7: Test
1. Send a message to your Facebook Page
2. Bot should reply based on Knowledge Base

---

## 📱 LINE Messaging API Setup

### Step 1: Create LINE Provider & Channel
1. Go to https://developers.line.biz/console/
2. Click "Create a new provider" (or select existing)
3. Click "Create a Messaging API channel"
4. Fill in channel details and create

### Step 2: Get Channel Secret
1. In channel settings → "Basic settings" tab
2. Find "Channel secret"
3. Click "Issue" or copy existing secret

### Step 3: Get Channel Access Token
1. Go to "Messaging API" tab
2. Scroll to "Channel access token (long-lived)"
3. Click "Issue" if not exists
4. Copy the token

### Step 4: Setup Webhook in Autobot
1. Login to Autobot Admin
2. Go to Customers → Select customer → Channels tab
3. Click "+ เพิ่มช่องทาง"
4. Fill in:
   - **ชื่อช่องทาง**: LINE Official
   - **ประเภท**: line
   - **Channel Secret**: (paste from step 2)
   - **Channel Access Token**: (paste from step 3)
5. Click บันทึก
6. Copy the generated **Webhook URL**

### Step 5: Configure LINE Webhook
1. Back to LINE Developers Console
2. Go to "Messaging API" tab
3. Find "Webhook settings"
4. Set Webhook URL: `https://yourdomain.com/autobot/api/webhooks/line.php`
5. Enable "Use webhook"
6. Disable "Auto-reply messages" (optional)
7. Click "Verify" to test connection

### Step 6: Test
1. Add your LINE Official Account as friend
2. Send a message
3. Bot should reply based on Knowledge Base

---

## 🌐 Production Requirements

### SSL Certificate (HTTPS)
Both Facebook and LINE **require HTTPS**. Options:

1. **Let's Encrypt** (Free)
   ```bash
   sudo certbot --apache -d yourdomain.com
   ```

2. **Cloudflare** (Free)
   - Add domain to Cloudflare
   - Enable SSL/TLS → Full

### Testing Locally with ngrok
```bash
# Install ngrok
brew install ngrok  # macOS
# or download from ngrok.com

# Start ngrok
ngrok http 80

# Use the https URL for webhook
# Example: https://abc123.ngrok.io/autobot/api/webhooks/facebook.php
```

---

## 🔧 Troubleshooting

### Facebook not receiving messages
- ✅ Check App is in "Live" mode (not Development)
- ✅ Page subscribed to webhook events
- ✅ Correct Page Access Token
- ✅ Webhook URL returns 200 OK

### LINE not replying
- ✅ Webhook URL verified successfully
- ✅ "Use webhook" is enabled
- ✅ Auto-reply disabled
- ✅ Channel Access Token is long-lived (not short)

### Signature validation failed
- ✅ Check App Secret / Channel Secret matches
- ✅ Webhook payload not modified
- ✅ Header X-Hub-Signature-256 (Facebook) or X-LINE-Signature (LINE) present

---

## 📊 Webhook URLs

```
Facebook: https://yourdomain.com/autobot/api/webhooks/facebook.php
LINE:     https://yourdomain.com/autobot/api/webhooks/line.php
```

Replace `yourdomain.com` with your actual domain or ngrok URL for testing.
