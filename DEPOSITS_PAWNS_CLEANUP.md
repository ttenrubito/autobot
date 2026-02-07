# Deposits vs Pawns Cleanup Notes

## สรุป

มีการสับสน logic ระหว่าง 2 ระบบ:

| ระบบ | Table | Purpose | Fields |
|------|-------|---------|--------|
| **Deposits** | `deposits` | ฝากเก็บสินค้า (warehouse storage) | storage_fee_per_day, total_storage_fee |
| **Pawns** | `pawns` | จำนำ (pawn/loan service) | loan_amount, interest_rate, expected_interest_amount |

## Files ที่มี Mixed Logic

### `/opt/lampp/htdocs/autobot/public/deposits.php`
- มี loan/pawn fields: `loanPercentage`, `interestRate`, `depositAmount` as loan
- ควรเปลี่ยนเป็น: storage fee calculation, expected pickup date

### `/opt/lampp/htdocs/autobot/api/customer/deposits.php`
- Lines with pawn logic that should be removed or clarified

## Recommended Actions

### Option 1: Redirect to pawns.php (Recommended)
สำหรับร้าน ฮ.เฮง เฮง ที่ต้องการบริการจำนำ:
- ใช้ `/public/pawns.php` และ `/api/customer/pawns.php` ที่สร้างใหม่
- เก็บ `/public/deposits.php` ไว้สำหรับร้านอื่นที่ต้องการ warehouse storage

### Option 2: Full Revert deposits.php
- Revert public/deposits.php to pure warehouse storage
- Remove all loan/interest fields
- Add storage_fee_per_day, expected_pickup_date logic

## Files Created for Pawn Service (Hybrid A+)

✅ `/opt/lampp/htdocs/autobot/migrations/hybrid_pawn_payment_system.sql`
✅ `/opt/lampp/htdocs/autobot/includes/services/PaymentMatchingService.php`
✅ `/opt/lampp/htdocs/autobot/api/customer/pawns.php` (updated)
✅ `/opt/lampp/htdocs/autobot/public/pawns.php` (updated)
✅ `/opt/lampp/htdocs/autobot/includes/bot/RouterV4Handler.php` (updated)
✅ `/opt/lampp/htdocs/autobot/admin/payment-classify.php`
✅ `/opt/lampp/htdocs/autobot/api/admin/payments/pending-classify.php`
✅ `/opt/lampp/htdocs/autobot/api/admin/payments/classify-summary.php`
✅ `/opt/lampp/htdocs/autobot/api/admin/payments/classify-detail.php`
✅ `/opt/lampp/htdocs/autobot/api/admin/payments/classify.php`

## TODO

1. Run migration: `hybrid_pawn_payment_system.sql`
2. Test `/public/pawns.php` with real data
3. Decide whether to keep deposits.php as-is or fully revert
4. Add link from admin menu to `/admin/payment-classify.php`
