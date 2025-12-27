#!/bin/bash
# ============================================================
# Cron Job Setup Script for Facebook Token Auto-Refresh
# ============================================================

echo "Setting up cron job for Facebook token auto-refresh..."

# Create logs directory if not exists
mkdir -p /opt/lampp/htdocs/autobot/logs

# Add cron job (runs daily at 3:00 AM)
CRON_CMD="0 3 * * * /opt/lampp/bin/php /opt/lampp/htdocs/autobot/scripts/refresh_facebook_tokens.php >> /opt/lampp/htdocs/autobot/logs/token_refresh.log 2>&1"

# Check if cron job already exists
(crontab -l 2>/dev/null | grep -q "refresh_facebook_tokens.php") && {
    echo "Cron job already exists. Updating..."
    crontab -l 2>/dev/null | grep -v "refresh_facebook_tokens.php" | crontab -
}

# Add new cron job
(crontab -l 2>/dev/null; echo "$CRON_CMD") | crontab -

echo "✅ Cron job installed successfully!"
echo "Schedule: Daily at 3:00 AM"
echo "Log file: /opt/lampp/htdocs/autobot/logs/token_refresh.log"
echo ""
echo "To verify: crontab -l | grep refresh_facebook_tokens"
echo "To test manually: /opt/lampp/bin/php /opt/lampp/htdocs/autobot/scripts/refresh_facebook_tokens.php --dry-run"
