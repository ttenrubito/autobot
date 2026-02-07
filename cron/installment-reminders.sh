#!/bin/bash
# filepath: /opt/lampp/htdocs/autobot/cron/installment-reminders.sh
# Cron job script to send installment reminders
# 
# Features:
# 1. Send reminders 3 days before due date
# 2. Send reminders 1 day before due date
# 3. Send overdue notices for late payments
#
# Add to crontab:
# 0 9 * * * /opt/lampp/htdocs/autobot/cron/installment-reminders.sh
#
# Or for production (Cloud Run):
# Use Cloud Scheduler to call the API endpoint

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
LOG_FILE="${PROJECT_DIR}/logs/installment-reminders-$(date +%Y%m%d).log"

# Ensure logs directory exists
mkdir -p "${PROJECT_DIR}/logs"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting installment reminder processing..." >> "$LOG_FILE"

# Use PHP CLI to process reminders
cd "$PROJECT_DIR"
/opt/lampp/bin/php -r "
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Logger.php';
require_once 'includes/services/PushNotificationService.php';

try {
    \$db = Database::getInstance();
    \$pushService = new PushNotificationService(\$db);
    
    // 1. Get contracts with due date in 3 days
    \$threeDaysFromNow = date('Y-m-d', strtotime('+3 days'));
    \$contracts3Days = \$db->queryAll(
        \"SELECT c.*, 
            (SELECT COUNT(*) FROM installment_payments WHERE contract_id = c.id AND status = 'paid') as paid_periods
        FROM installment_contracts c 
        WHERE c.status = 'active' 
        AND c.next_due_date = ?
        AND NOT EXISTS (
            SELECT 1 FROM installment_reminders 
            WHERE contract_id = c.id 
            AND reminder_type = 'before_3_days' 
            AND due_date = c.next_due_date
            AND status = 'sent'
        )\",
        [\$threeDaysFromNow]
    );
    
    foreach (\$contracts3Days as \$contract) {
        \$nextPeriod = (\$contract['paid_periods'] ?? 0) + 1;
        
        // Queue notification
        \$pushService->queue(
            \$contract['platform'],
            \$contract['platform_user_id'] ?? \$contract['external_user_id'],
            'installment_reminder',
            [
                'customer_name' => \$contract['customer_name'],
                'period_number' => \$nextPeriod,
                'total_periods' => \$contract['total_periods'],
                'amount' => \$contract['amount_per_period'],
                'due_date' => date('d/m/Y', strtotime(\$contract['next_due_date'])),
                'product_name' => \$contract['product_name'],
                'days_until' => 3
            ]
        );
        
        // Record reminder sent
        \$db->execute(
            \"INSERT INTO installment_reminders (contract_id, reminder_type, due_date, period_number, sent_at, status, created_at) 
             VALUES (?, 'before_3_days', ?, ?, NOW(), 'sent', NOW())\",
            [\$contract['id'], \$contract['next_due_date'], \$nextPeriod]
        );
        
        echo \"Queued 3-day reminder for contract #\" . \$contract['id'] . \"\\n\";
    }
    
    // 2. Get contracts with due date tomorrow
    \$tomorrow = date('Y-m-d', strtotime('+1 day'));
    \$contracts1Day = \$db->queryAll(
        \"SELECT c.*, 
            (SELECT COUNT(*) FROM installment_payments WHERE contract_id = c.id AND status = 'paid') as paid_periods
        FROM installment_contracts c 
        WHERE c.status = 'active' 
        AND c.next_due_date = ?
        AND NOT EXISTS (
            SELECT 1 FROM installment_reminders 
            WHERE contract_id = c.id 
            AND reminder_type = 'before_1_day' 
            AND due_date = c.next_due_date
            AND status = 'sent'
        )\",
        [\$tomorrow]
    );
    
    foreach (\$contracts1Day as \$contract) {
        \$nextPeriod = (\$contract['paid_periods'] ?? 0) + 1;
        
        \$pushService->queue(
            \$contract['platform'],
            \$contract['platform_user_id'] ?? \$contract['external_user_id'],
            'installment_due_tomorrow',
            [
                'customer_name' => \$contract['customer_name'],
                'period_number' => \$nextPeriod,
                'total_periods' => \$contract['total_periods'],
                'amount' => \$contract['amount_per_period'],
                'due_date' => date('d/m/Y', strtotime(\$contract['next_due_date'])),
                'product_name' => \$contract['product_name']
            ]
        );
        
        \$db->execute(
            \"INSERT INTO installment_reminders (contract_id, reminder_type, due_date, period_number, sent_at, status, created_at) 
             VALUES (?, 'before_1_day', ?, ?, NOW(), 'sent', NOW())\",
            [\$contract['id'], \$contract['next_due_date'], \$nextPeriod]
        );
        
        echo \"Queued 1-day reminder for contract #\" . \$contract['id'] . \"\\n\";
    }
    
    // 3. Get overdue contracts (due date is yesterday or earlier)
    \$overdueContracts = \$db->queryAll(
        \"SELECT c.*, 
            (SELECT COUNT(*) FROM installment_payments WHERE contract_id = c.id AND status = 'paid') as paid_periods,
            DATEDIFF(CURDATE(), c.next_due_date) as days_overdue
        FROM installment_contracts c 
        WHERE c.status IN ('active', 'overdue')
        AND c.next_due_date < CURDATE()
        AND DATEDIFF(CURDATE(), c.next_due_date) IN (1, 3, 7, 14)
        AND NOT EXISTS (
            SELECT 1 FROM installment_reminders 
            WHERE contract_id = c.id 
            AND reminder_type = CONCAT('overdue_', DATEDIFF(CURDATE(), c.next_due_date), '_days')
            AND due_date = c.next_due_date
            AND status = 'sent'
        )\"
    );
    
    foreach (\$overdueContracts as \$contract) {
        \$nextPeriod = (\$contract['paid_periods'] ?? 0) + 1;
        \$daysOverdue = \$contract['days_overdue'];
        
        // Update status to overdue if not already
        if (\$contract['status'] === 'active') {
            \$db->execute(\"UPDATE installment_contracts SET status = 'overdue' WHERE id = ?\", [\$contract['id']]);
        }
        
        \$pushService->queue(
            \$contract['platform'],
            \$contract['platform_user_id'] ?? \$contract['external_user_id'],
            'installment_overdue',
            [
                'customer_name' => \$contract['customer_name'],
                'period_number' => \$nextPeriod,
                'total_periods' => \$contract['total_periods'],
                'amount' => \$contract['amount_per_period'],
                'due_date' => date('d/m/Y', strtotime(\$contract['next_due_date'])),
                'product_name' => \$contract['product_name'],
                'days_overdue' => \$daysOverdue
            ]
        );
        
        \$reminderType = 'overdue_' . \$daysOverdue . '_days';
        \$db->execute(
            \"INSERT INTO installment_reminders (contract_id, reminder_type, due_date, period_number, sent_at, status, created_at) 
             VALUES (?, ?, ?, ?, NOW(), 'sent', NOW())\",
            [\$contract['id'], \$reminderType, \$contract['next_due_date'], \$nextPeriod]
        );
        
        echo \"Queued overdue (\" . \$daysOverdue . \" days) reminder for contract #\" . \$contract['id'] . \"\\n\";
    }
    
    // 4. Process all queued notifications
    \$result = \$pushService->processPending(100);
    echo \"Processed \" . (\$result['processed'] ?? 0) . \" notifications\\n\";
    
    echo \"Done\\n\";
    
} catch (Exception \$e) {
    echo \"Error: \" . \$e->getMessage() . \"\\n\";
    Logger::error('Installment Reminder Cron Error: ' . \$e->getMessage());
}
" >> "$LOG_FILE" 2>&1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Installment reminder processing completed." >> "$LOG_FILE"
echo "" >> "$LOG_FILE"
