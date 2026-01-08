#!/bin/bash

###############################################################################
# Production Database Migration Script
# Purpose: Add last_admin_message_at column to chat_sessions table
###############################################################################

set -e

echo "🔍 Database Migration Script"
echo "========================================"
echo ""

# Load environment variables
if [ -f .env.production ]; then
    echo "📋 Using .env.production"
    source .env.production
elif [ -f .env ]; then
    echo "📋 Using .env (fallback)"
    source .env
else
    echo "⚠️  No .env file found, using defaults"
fi

# Extract database credentials with defaults
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-autobot}"
DB_USER="${DB_USER:-root}"

# Prompt for password if not set
if [ -z "$DB_PASS" ]; then
    echo "⚠️  DB_PASS not found in environment"
    read -sp "Enter MySQL password (or press Enter for empty): " DB_PASS
    echo ""
fi

echo "📋 Migration Details:"
echo "  Database: $DB_NAME"
echo "  Host: $DB_HOST:$DB_PORT"
echo "  User: $DB_USER"
echo ""

# Use XAMPP MySQL if available
MYSQL_BIN="mysql"
if [ -x "/opt/lampp/bin/mysql" ]; then
    MYSQL_BIN="/opt/lampp/bin/mysql"
fi

# Build MySQL command prefix
if [ -n "$DB_PASS" ]; then
    MYSQL_CMD="$MYSQL_BIN -h$DB_HOST -P$DB_PORT -u$DB_USER -p$DB_PASS"
else
    MYSQL_CMD="$MYSQL_BIN -h$DB_HOST -P$DB_PORT -u$DB_USER"
fi

# Check if column already exists
echo "🔍 Checking if migration is needed..."
COLUMN_EXISTS=$($MYSQL_CMD -D"$DB_NAME" -se \
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='chat_sessions' AND COLUMN_NAME='last_admin_message_at';" 2>/dev/null || echo "0")

if [ "$COLUMN_EXISTS" -eq "1" ]; then
    echo "✅ Column 'last_admin_message_at' already exists!"
    echo "   No migration needed."
    exit 0
fi

echo "⚠️  Column 'last_admin_message_at' NOT found. Migration required."
echo ""

# Show the migration SQL
echo "📝 Migration SQL:"
echo "----------------------------------------"
cat database/migrations/add_admin_handoff_timeout.sql
echo "----------------------------------------"
echo ""

# Confirm before proceeding
if [ "${AUTO_YES}" != "1" ]; then
    read -p "🚀 Execute this migration? (yes/no): " CONFIRM
    if [ "$CONFIRM" != "yes" ]; then
        echo "❌ Migration cancelled by user."
        exit 1
    fi
fi

# Execute migration
echo ""
echo "⚙️  Executing migration..."
$MYSQL_CMD -D"$DB_NAME" < database/migrations/add_admin_handoff_timeout.sql

# Verify migration
echo ""
echo "🔍 Verifying migration..."
COLUMN_EXISTS_AFTER=$($MYSQL_CMD -D"$DB_NAME" -se \
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='chat_sessions' AND COLUMN_NAME='last_admin_message_at';" 2>/dev/null || echo "0")

if [ "$COLUMN_EXISTS_AFTER" -eq "1" ]; then
    echo "✅ Migration completed successfully!"
    echo ""
    echo "📊 Column Details:"
    $MYSQL_CMD -D"$DB_NAME" -se \
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='chat_sessions' AND COLUMN_NAME='last_admin_message_at';"
    
    echo ""
    echo "📊 Indexes:"
    $MYSQL_CMD -D"$DB_NAME" -se \
        "SHOW INDEXES FROM chat_sessions WHERE Key_name='idx_admin_timeout';" 2>/dev/null || echo "  (Index check skipped)"
    
    echo ""
    echo "✅ Admin Handoff feature is now ready!"
else
    echo "❌ Migration verification FAILED!"
    exit 1
fi
