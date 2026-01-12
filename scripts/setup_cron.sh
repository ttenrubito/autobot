#!/bin/bash
# ============================================================================
# Setup Cron Jobs for Autobot Notifications
# ============================================================================

PROJECT_ROOT="/opt/lampp/htdocs/autobot"
PHP_BIN="/opt/lampp/bin/php"
LOG_DIR="/var/log/autobot"

# Create log directory
sudo mkdir -p $LOG_DIR
sudo chmod 755 $LOG_DIR

echo "Setting up cron jobs for Autobot..."

# Create a crontab entry file
CRON_FILE="/tmp/autobot_cron"

cat > $CRON_FILE << 'EOF'
# ============================================================================
# Autobot Scheduled Tasks
# ============================================================================

# Process notifications every 5 minutes
*/5 * * * * /opt/lampp/bin/php /opt/lampp/htdocs/autobot/cron/process_notifications.php >> /var/log/autobot/notifications.log 2>&1

# Clean old notifications daily at 3 AM
0 3 * * * /opt/lampp/bin/php /opt/lampp/htdocs/autobot/cron/cleanup_notifications.php >> /var/log/autobot/cleanup.log 2>&1

# Generate daily reports at 6 AM
0 6 * * * /opt/lampp/bin/php /opt/lampp/htdocs/autobot/cron/daily_report.php >> /var/log/autobot/reports.log 2>&1

EOF

echo "Cron jobs to be installed:"
cat $CRON_FILE

# Install crontab (append to existing)
echo ""
echo "Installing cron jobs..."
(crontab -l 2>/dev/null | grep -v "autobot"; cat $CRON_FILE) | crontab -

echo ""
echo "Current crontab:"
crontab -l

echo ""
echo "✅ Cron jobs installed successfully!"
echo ""
echo "To manually run notification processor:"
echo "  $PHP_BIN $PROJECT_ROOT/cron/process_notifications.php"
echo ""
echo "To check logs:"
echo "  tail -f $LOG_DIR/notifications.log"

rm -f $CRON_FILE
