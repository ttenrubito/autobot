# Autobot - AI Coding Agent Instructions

## 🚨 CRITICAL: User Role Separation

| Role | Path | Owns Data |
|------|------|-----------|
| **Super Admin** (Developer) | `/admin/` | System config, subscriptions, user management |
| **Shop Owner** (Users) | `/public/` | cases, orders, pawns, products, payments |
| **End Customer** | Chat (LINE/FB) | Conversations only |

**❌ NEVER:** Create `/admin/cases.php`, `/admin/pawns.php`, or call shop owners "admin"  
**✅ ALWAYS:** Shop data lives in `/public/`, filter queries by `user_id`

```php
// ✅ Correct - always filter by user_id for shop data
$cases = $db->query("SELECT * FROM cases WHERE user_id = ?", [$userId]);
```

---

## Architecture

```
Webhook (FB/LINE) → api/gateway/message.php → BotHandlerFactory → RouterV[1-4]Handler → Response
     ↓                      ↓
Signature verify    Subscription check (402 if expired)
```

| Directory | Purpose |
|-----------|---------|
| `api/webhooks/` | Platform webhooks - signature verification via `customer_channels.config` |
| `api/gateway/message.php` | Auth, subscription validation, bot handler dispatch |
| `includes/bot/` | Handlers: `RouterV1Handler`, `RouterV2BoxDesignHandler`, `RouterV3LineAppHandler`, `RouterV4Handler` |
| `includes/services/` | Business logic: `CaseService`, `OrderService`, `PawnService`, `PaymentService` |
| `public/` | Shop owner dashboard (cases, orders, pawns, payments) |
| `admin/` | Super Admin only (system config, subscriptions) |
| `liff/` | LINE Front-end Framework apps |

---

## Development Commands

```bash
# PHP syntax check (use LAMPP PHP)
/opt/lampp/bin/php -l includes/bot/RouterV1Handler.php

# Run unit tests (uses mock DB from tests/bootstrap.php)
./vendor/bin/phpunit tests/bot/RouterV1HandlerTest.php

# Deploy to Cloud Run (runs tests automatically, blocks on failure)
./deploy_app_to_production.sh

# Skip tests in emergency only
SKIP_TESTS=1 ./deploy_app_to_production.sh
```

**Local environment:** XAMPP at `/opt/lampp/htdocs/autobot/`, MySQL (`root`, no password, db: `autobot`)

---

## Code Patterns

### Database Access
```php
// ✅ Use singleton (not legacy getDB())
$db = Database::getInstance();
$row = $db->queryOne('SELECT * FROM users WHERE id = ?', [$id]);
$rows = $db->query('SELECT * FROM cases WHERE user_id = ?', [$userId]);
$db->execute('UPDATE cases SET status = ? WHERE id = ?', [$status, $id]);
```

### Bot Handler Pattern
```php
// Factory instantiation (includes/bot/BotHandlerFactory.php)
$handler = BotHandlerFactory::get('router_v1'); // or 'router_v2_boxdesign', 'router_v4'
$result = $handler->handleMessage($context);

// Handler response structure
return [
    'reply_text' => 'Message to send',
    'actions' => [],  // Platform-specific (quick replies, buttons)
    'meta' => ['reason' => 'kb_match', 'intent' => 'greeting']
];
```

### Logging (structured JSON, outputs to Cloud Run stderr)
```php
Logger::info('Message processed', ['channel_id' => $channelId, 'reason' => 'kb_match']);
Logger::error('API failed', ['error' => $e->getMessage()]);
```

### API Responses
```php
Response::success($data);           // 200
Response::error('Not found', 404);  // Error with code
Response::error('Expired', 402);    // Subscription expired
```

---

## Testing

Tests use **mock Database and Logger** defined in [tests/bootstrap.php](tests/bootstrap.php). No real DB connections.

```php
// Inject mock via reflection
$this->mockDb = new Database();
$reflection = new ReflectionClass($this->handler);
$property = $reflection->getProperty('db');
$property->setAccessible(true);
$property->setValue($this->handler, $this->mockDb);
```

**Required test scenarios:** Empty text → greeting, Admin command → handoff, KB match → answer, Spam → anti-spam template

---

## Production Environment

| Config | Value |
|--------|-------|
| Platform | Google Cloud Run (`asia-southeast1`) |
| Database | Cloud SQL via unix socket |
| URL | `https://autobot.boxdesign.in.th/` |
| Timezone | `Asia/Bangkok` (UTC+7) |

```php
// Environment detection
if (getenv('INSTANCE_CONN_NAME')) {
    // Production: Cloud SQL socket
    $socket = "/cloudsql/{$instanceConn}";
} else {
    // Development: localhost TCP
}
```

---

## Key Database Tables

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `customer_channels` | FB/LINE channel config | `user_id`, `inbound_api_key`, `config` (JSON with secrets) |
| `subscriptions` | User subscription status | `user_id`, `status`, `current_period_end` |
| `cases` | Customer support cases | `user_id`, `customer_profile_id`, `status` |
| `orders` | Shop orders | `user_id`, `customer_profile_id`, `status` |
| `pawns` | Pawn transactions | `user_id`, `customer_profile_id` |
| `chat_sessions` | Conversation state | `last_admin_message_at` (handoff logic) |

---

## Common Issues

1. **Admin handoff not working**: Check `chat_sessions.last_admin_message_at` in `RouterV1Handler`
2. **Signature verification failed**: Verify `customer_channels.config` JSON matches platform dashboard secrets
3. **402 Subscription errors**: Check `subscriptions.current_period_end >= CURDATE()` and `status = 'active'`
4. **Message deduplication**: Uses `gateway_message_events` table with `external_event_id`

---

## File Reference

| File | Purpose |
|------|---------|
| `config.php` | DB connection, timezone, base URL |
| `config-security.php` | JWT secrets, API keys |
| `bot_profile_*.json` | Bot personality templates |
| [.github/PROJECT_RULES.md](.github/PROJECT_RULES.md) | Extended rules documentation |
