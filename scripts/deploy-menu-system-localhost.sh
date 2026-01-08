#!/bin/bash
# ============================================================================
# Deploy Menu Configuration System - Localhost
# Date: 2025-12-29
# Description: Deploy user menu configuration system to localhost
# ============================================================================

set -e  # Exit on error

echo "============================================"
echo "🚀 Deploying Menu System to Localhost"
echo "============================================"
echo ""

# Configuration
DB_NAME="autobot"
DB_USER="root"
DB_PASS=""  # Set your localhost password
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo "📂 Project root: $PROJECT_ROOT"
echo "🗄️  Database: $DB_NAME"
echo ""

# Step 1: Run migration
echo "Step 1/3: Running database migration..."
mysql -u $DB_USER -p$DB_PASS $DB_NAME < "$PROJECT_ROOT/database/migrations/2025_12_29_add_user_menu_config.sql"

if [ $? -eq 0 ]; then
    echo "✅ Migration completed successfully"
else
    echo "❌ Migration failed"
    exit 1
fi

echo ""

# Step 2: Setup user_id=4 config (optional)
echo "Step 2/3: Setting up user_id=4 menu configuration..."
read -p "Do you want to setup menu config for user_id=4? (y/n) " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    mysql -u $DB_USER -p$DB_PASS $DB_NAME < "$PROJECT_ROOT/database/setup_userid4_menu_config.sql"
    
    if [ $? -eq 0 ]; then
        echo "✅ User config setup completed"
    else
        echo "⚠️  User config setup failed (continuing anyway)"
    fi
else
    echo "⏭️  Skipped user config setup"
fi

echo ""

# Step 3: Verify files exist
echo "Step 3/3: Verifying deployed files..."

FILES_TO_CHECK=(
    "api/user/menu-config.php"
    "api/admin/user-menu-config.php"
    "api/admin/users.php"
    "public/admin/menu-manager.php"
    "assets/js/admin/menu-manager.js"
    "includes/customer/sidebar.php"
)

ALL_FILES_EXIST=true

for file in "${FILES_TO_CHECK[@]}"; do
    FULL_PATH="$PROJECT_ROOT/$file"
    if [ -f "$FULL_PATH" ]; then
        echo "  ✓ $file"
    else
        echo "  ✗ $file (missing)"
        ALL_FILES_EXIST=false
    fi
done

echo ""

if [ "$ALL_FILES_EXIST" = true ]; then
    echo "✅ All files verified"
else
    echo "⚠️  Some files are missing"
fi

echo ""
echo "============================================"
echo "✅ Deployment Complete!"
echo "============================================"
echo ""
echo "📝 Next Steps:"
echo "1. Login to http://localhost/autobot/public/login.html"
echo "2. Test /api/user/menu-config.php"
echo "3. Access /public/admin/menu-manager.php (as admin)"
echo "4. Check browser console for menu loading"
echo ""
echo "🔍 Troubleshooting:"
echo "- Check database: SHOW TABLES LIKE 'user_menu_config';"
echo "- Check logs: tail -f /opt/lampp/logs/error_log"
echo "- Inspect network tab in DevTools"
echo ""
