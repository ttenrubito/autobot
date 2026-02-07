# RouterV4 Refactoring Summary

## Overview
เปลี่ยนจาก RouterV1Handler.php (7,700+ บรรทัด) เป็น RouterV4Handler.php + Services Architecture

## Files Created

### Services (`/includes/bot/services/`)

1. **BackendApiService.php**
   - Central API calling with retry logic
   - Timeout handling
   - Usage logging
   - Health check

2. **ChatService.php**
   - Session management (getOrCreateSession, updateSessionContext)
   - Message logging (logIncomingMessage, logOutgoingMessage)
   - Conversation history for LLM
   - Quick state management (get/set/delete)

3. **IntentService.php**
   - Regex-based intent detection (fast)
   - LLM-based intent detection (smart)
   - Intent priority system
   - Routing info provider

4. **ProductService.php**
   - Product search by code/keyword
   - Image search (Vision API)
   - LINE Flex Message formatting
   - Recently viewed tracking

5. **TransactionService.php**
   - Installment checking
   - Pawn status
   - Repair status
   - Savings account
   - Order status

6. **CheckoutService.php**
   - Start checkout flow
   - Confirm/cancel order
   - Payment type selection
   - State management

7. **autoload.php**
   - Autoload all services

### Main Router

**RouterV4Handler.php** (`/includes/bot/`)
- Clean implementation using services
- ~550 lines vs 7,700+ in V1
- Clear intent routing
- Proper error handling
- Trace ID support

### Migrations

**router_v4_tables.sql** (`/migrations/`)
- chat_state
- chat_messages
- product_views
- api_usage_logs
- installments
- pawn_tickets
- repair_orders
- savings_accounts
- products
- customer_profiles
- customers

## Intent Flow

```
Message → IntentService.detect()
       ↓
   Regex Check (fast)
       ↓
   LLM Check (if enabled & needed)
       ↓
   Route to Handler
       ↓
   Service.method()
       ↓
   Response
```

## Supported Intents

| Intent | Priority | Handler |
|--------|----------|---------|
| checkout_confirm | 1 | CheckoutService.confirm |
| checkout_cancel | 2 | CheckoutService.cancel |
| payment_slip | 3 | PaymentService.processSlip |
| admin_handoff | 10 | AdminService.handoff |
| installment_check | 20 | TransactionService.checkInstallment |
| pawn_check | 21 | TransactionService.checkPawn |
| repair_check | 22 | TransactionService.checkRepair |
| savings_check | 23 | TransactionService.checkSavings |
| order_check | 24 | TransactionService.checkOrder |
| product_search | 30 | ProductService.search |
| product_interest | 31 | CheckoutService.startCheckout |
| greeting | 50 | GreetingService.greet |
| thanks | 51 | GreetingService.thanks |
| unknown | 100 | FallbackService.handle |

## Usage

### Switch to RouterV4

ใน `bot_profiles` config:
```json
{
  "router_version": "v4"
}
```

### Run Migrations

```bash
mysql -u root -p database_name < /opt/lampp/htdocs/autobot/migrations/router_v4_tables.sql
```

## Next Steps

1. [ ] Add PaymentService for slip OCR
2. [ ] Add AdminService for notifications
3. [ ] Add unit tests
4. [ ] Performance benchmarking
5. [ ] Gradual rollout to channels

## Backward Compatibility

- RouterV1Handler.php ยังคงอยู่ ไม่ได้แก้ไข
- สามารถ switch ระหว่าง V1 และ V4 ได้โดยเปลี่ยน config
- Tables ใหม่ไม่กระทบ tables เดิม
