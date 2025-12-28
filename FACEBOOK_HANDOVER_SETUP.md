# Facebook Handover Protocol Setup Guide

## Overview
The Facebook Handover Protocol allows seamless transfer of conversation control between your bot and human agents (Page Inbox). When properly configured, the bot will automatically pause when an admin takes over and resume when control is returned.

## Prerequisites
✅ You have already enabled `messaging_handovers` permission in your Facebook App

## Step 1: Configure Handover Protocol in Facebook App

### 1.1 Access App Settings
1. Go to [Facebook Developers](https://developers.facebook.com/apps/)
2. Select your app
3. Navigate to **Messenger** → **Settings** → **Advanced Messaging Features**

### 1.2 Set Primary Receiver (Bot)
- **Primary Receiver**: Select your app (the bot)
- This app receives all messages by default
- The bot automatically responds to customer messages

### 1.3 Set Secondary Receiver (Page Inbox)
- **Secondary Receiver**: Select **Page Inbox**
- This is where human agents will see messages when they take over
- Page Inbox app ID is typically: `263902037430900`

### 1.4 Save Settings
Click **Save** to apply the configuration

## Step 2: How It Works

### When Admin Takes Over:
1. Customer sends message → Bot responds
2. Admin opens **Page Inbox** (not Business Suite Meta chat)
3. Admin finds the conversation
4. Admin clicks **"Take Over"** button (or similar action)
5. Facebook sends `pass_thread_control` webhook event to your bot
6. Your webhook (`facebook.php`) receives the event
7. Bot updates database: `last_admin_message_at = NOW()`
8. Bot stops responding for 1 hour (or until admin returns control)

### When Admin Returns Control:
1. Admin clicks **"Return to Bot"** button in Page Inbox
2. Facebook sends `take_thread_control` webhook event
3. Bot clears database: `last_admin_message_at = NULL`
4. Bot resumes automatic responses

### Automatic Timeout (Fallback):
- If admin forgets to return control → Bot automatically resumes after 1 hour
- This prevents bot from being stuck in paused state indefinitely

## Step 3: Testing

### Test 1: Admin Takeover
1. **Setup**: Have a customer send a message to your Page
2. **Verify**: Bot responds automatically
3. **Action**: Admin opens Page Inbox → Takes over conversation
4. **Verify Logs**: Check `/logs/app.log` for:
   ```
   [FB_HANDOVER] 🚨 pass_thread_control received - Admin taking over!
   [FB_HANDOVER] ✅ Bot PAUSED (admin has control)
   ```
5. **Test Bot Pause**: Customer sends another message
6. **Expected**: Bot does NOT respond (admin has control)
7. **Verify Database**:
   ```sql
   SELECT id, external_user_id, last_admin_message_at 
   FROM chat_sessions 
   WHERE external_user_id = '<customer_psid>' 
   ORDER BY created_at DESC LIMIT 1;
   ```
   Should show `last_admin_message_at` with recent timestamp

### Test 2: Bot Resumption
1. **Action**: Admin clicks "Return to Bot" in Page Inbox
2. **Verify Logs**: Check logs for:
   ```
   [FB_HANDOVER] ✅ take_thread_control received - Bot regaining control!
   [FB_HANDOVER] ✅ Bot RESUMED (bot has control)
   ```
3. **Test Bot Resume**: Customer sends message
4. **Expected**: Bot responds automatically again
5. **Verify Database**: `last_admin_message_at` should be `NULL`

### Test 3: Standby Mode
1. While admin has control, customer sends messages
2. **Expected**: Bot receives `standby` events (logged but ignored)
3. **Verify Logs**:
   ```
   [FB_WEBHOOK] Standby event received (bot in standby mode)
   ```

## Step 4: Troubleshooting

### Issue: No handover events received
**Possible Causes:**
- Handover Protocol not configured in App Settings
- Wrong Primary/Secondary receiver setup
- `messaging_handovers` permission not approved

**Solution:**
1. Verify App Settings → Messenger → Advanced Messaging Features
2. Ensure Primary Receiver = Your App
3. Ensure Secondary Receiver = Page Inbox
4. Re-subscribe to webhook events if needed

### Issue: Bot still responds after admin takes over
**Possible Causes:**
- Database column `last_admin_message_at` doesn't exist
- RouterV1Handler not checking timeout properly

**Solution:**
1. Check database schema:
   ```sql
   SHOW COLUMNS FROM chat_sessions LIKE 'last_admin_message_at';
   ```
2. If missing, run migration:
   ```bash
   php /opt/lampp/htdocs/autobot/run_migration_production.sh
   ```

### Issue: Handover events show wrong sender
**Explanation:**
- `sender.id` in handover events refers to the customer (not the admin)
- This is expected behavior - Facebook uses customer's PSID to identify the thread

## Step 5: Production Deployment

### Deploy to Production
```bash
# From project root
cd /opt/lampp/htdocs/autobot

# Verify changes
git diff api/webhooks/facebook.php

# Deploy (if using git deployment)
git add api/webhooks/facebook.php
git commit -m "feat: Add Facebook Handover Protocol support for admin takeover"
git push origin main

# Or if deploying manually, ensure facebook.php is uploaded to production
```

### Monitor Logs
```bash
# Watch live logs
tail -f /opt/lampp/htdocs/autobot/logs/app.log | grep FB_HANDOVER
```

## Benefits of Handover Protocol

✅ **Official Facebook mechanism** - More reliable than keyword detection
✅ **Works without admin typing** - Just clicking "Take Over" is enough
✅ **Bidirectional control** - Handles both admin takeover and bot resumption
✅ **Automatic timeout** - Bot resumes if admin forgets to return control
✅ **Better UX** - Seamless transition between bot and human

## Additional Resources

- [Facebook Handover Protocol Documentation](https://developers.facebook.com/docs/messenger-platform/handover-protocol)
- [Messenger Platform Webhook Events](https://developers.facebook.com/docs/messenger-platform/webhooks)
