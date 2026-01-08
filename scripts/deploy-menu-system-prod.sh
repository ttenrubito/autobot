#!/bin/bash
# ============================================================================
# Deploy Menu Configuration System - Production
# Date: 2025-12-29
# Description: Deploy user menu configuration system to production
# ============================================================================

set -e  # Exit on error

echo "============================================"
echo "🚀 Deploying Menu System to Production"
echo "============================================"
echo ""

# Configuration
DB_NAME="autobot"
DB_USER="your_prod_user"  # UPDATE THIS
DB_PASS="your_prod_password"  # UPDATE THIS
DB_HOST="localhost"  # UPDATE THIS if remote
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo "📂 Project root: $PROJECT_ROOT"
echo "🗄️  Database: $DB_NAME @ $DB_HOST"
echo ""

# Safety check
read -p "⚠️  You are about to deploy to PRODUCTION. Continue? (yes/no) " -r
echo ""

if [[ ! $REPLY =~ ^yes$ ]]; then
    echo "❌ Deployment cancelled"
    exit 1
fi

# Step 1: Backup database
echo "Step 1/4: Creating database backup..."
BACKUP_FILE="$PROJECT_ROOT/backups/autobot_pre_menu_$(date +%Y%m%d_%H%M%S).sql"
mkdir -p "$PROJECT_ROOT/backups"

mysqldump -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME > "$BACKUP_FILE"

if [ $? -eq 0 ]; then
    echo "✅ Backup created: $BACKUP_FILE"
else
    echo "❌ Backup failed"
    exit 1
fi

echo ""

# Step 2: Run migration
echo "Step 2/4: Running database migration..."
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME < "$PROJECT_ROOT/database/migrations/2025_12_29_add_user_menu_config.sql"

if [ $? -eq 0 ]; then
    echo "✅ Migration completed successfully"
else
    echo "❌ Migration failed"
    echo "🔄 To rollback, restore from: $BACKUP_FILE"
    exit 1
fi

echo ""

# Step 3: Deploy files via rsync or git pull
echo "Step 3/4: Deploying application files..."

# Option A: If using Git
if [ -d "$PROJECT_ROOT/.git" ]; then
    echo "📦 Using Git to deploy files..."
    cd "$PROJECT_ROOT"
    git pull origin main  # Or your branch
    
    if [ $? -eq 0 ]; then
        echo "✅ Files deployed via Git"
    else
        echo "⚠️  Git pull failed (continuing anyway)"
    fi
else
    echo "⏭️  No Git repository found, skipping file deployment"
    echo "    Make sure to manually upload:"
    echo "    - api/user/menu-config.php"
    echo "    - api/admin/user-menu-config.php"
    echo "    - api/admin/users.php"
    echo "    - public/admin/menu-manager.php"
    echo "    - assets/js/admin/menu-manager.js"
    echo "    - includes/customer/sidebar.php"
fi

echo ""

# Step 4: Verify deployment
echo "Step 4/4: Verifying deployment..."

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

# Check database table
echo "Checking database table..."
TABLE_CHECK=$(mysql -h $DB_HOST -u $DB_USER -p$DB_PASS -e "SHOW TABLES LIKE 'user_menu_config';" $DB_NAME | grep -c "user_menu_config" || true)

if [ "$TABLE_CHECK" -eq 1 ]; then
    echo "  ✓ Table user_menu_config exists"
else
    echo "  ✗ Table user_menu_config not found"
    ALL_FILES_EXIST=false
fi

echo ""

if [ "$ALL_FILES_EXIST" = true ]; then
    echo "✅ All checks passed"
else
    echo "⚠️  Some items failed verification"
fi

echo ""
echo "============================================"
echo "✅ Deployment Complete!"
echo "============================================"
echo ""
echo "📝 Next Steps:"
echo "1. Test https://autobot.boxdesign.in.th/api/user/menu-config.php"
echo "2. Login and verify sidebar menu loading"
echo "3. Access /public/admin/menu-manager.php as admin"
echo "4. Monitor logs for errors"
echo ""
echo "🔍 Troubleshooting:"
echo "- Check production logs"
echo "- Test API endpoints directly"
echo "- Verify file permissions"
echo ""
echo "💾 Backup location: $BACKUP_FILE"
echo ""
