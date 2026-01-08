#!/bin/bash
# filepath: /opt/lampp/htdocs/autobot/cron/process-push-notifications.sh
# Cron job script to process pending push notifications
# 
# Add to crontab:
# */5 * * * * /opt/lampp/htdocs/autobot/cron/process-push-notifications.sh
#
# Or for production (Cloud Run):
# Use Cloud Scheduler to call the API endpoint

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
LOG_FILE="${PROJECT_DIR}/logs/push-notify-$(date +%Y%m%d).log"

# Ensure logs directory exists
mkdir -p "${PROJECT_DIR}/logs"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting push notification processing..." >> "$LOG_FILE"

# Call the API endpoint
if command -v curl &> /dev/null; then
    RESPONSE=$(curl -s -X POST \
        -H "Content-Type: application/json" \
        -H "X-API-Key: ${INTERNAL_API_KEY:-dev-internal-key}" \
        -d '{"limit": 50}' \
        "http://localhost/autobot/api/webhook/push-notify/process" 2>&1)
    
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Response: $RESPONSE" >> "$LOG_FILE"
else
    # Fallback to PHP CLI
    cd "$PROJECT_DIR"
    php -r "
        require_once 'config.php';
        require_once 'includes/Database.php';
        require_once 'includes/services/PushNotificationService.php';
        
        \$db = Database::getInstance();
        \$pushService = new PushNotificationService(\$db);
        \$result = \$pushService->processPending(50);
        echo json_encode(\$result);
    " >> "$LOG_FILE" 2>&1
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Push notification processing completed." >> "$LOG_FILE"
echo "" >> "$LOG_FILE"
