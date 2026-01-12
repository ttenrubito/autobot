#!/bin/bash
# Sync localhost database with production schema
# Run this script to apply all migrations on localhost

echo "========================================="
echo "Syncing localhost DB with production schema"
echo "========================================="

MYSQL_CMD="/opt/lampp/bin/mysql -u root autobot"

# Check if MySQL is running
if ! /opt/lampp/bin/mysql -u root -e "SELECT 1" 2>/dev/null; then
    echo "❌ MySQL is not running. Starting XAMPP MySQL..."
    sudo /opt/lampp/lampp startmysql
    sleep 3
fi

echo ""
echo "Running migrations..."
echo ""

# List of migrations to run in order
MIGRATIONS=(
    "2026_01_11_order_items.sql"
    "2026_01_11_installment_contracts.sql"
    "2026_01_11_deposits.sql"
    "2026_01_11_pawns.sql"
    "2026_01_11_repairs.sql"
    "2026_01_11_add_order_number_column.sql"
)

cd /opt/lampp/htdocs/autobot/database/migrations

for migration in "${MIGRATIONS[@]}"; do
    if [ -f "$migration" ]; then
        echo "📦 Running: $migration"
        $MYSQL_CMD < "$migration" 2>&1 | grep -v "already exists\|Duplicate"
        if [ $? -eq 0 ]; then
            echo "   ✅ Done"
        else
            echo "   ⚠️  May have warnings (table might already exist)"
        fi
    else
        echo "❌ Not found: $migration"
    fi
done

echo ""
echo "========================================="
echo "Verifying tables..."
echo "========================================="

# Verify tables exist
TABLES=("pawns" "pawn_payments" "deposits" "repairs" "repair_parts" "repair_logs" "order_items" "installment_contracts" "installment_payments")

for table in "${TABLES[@]}"; do
    result=$($MYSQL_CMD -N -e "SHOW TABLES LIKE '$table'" 2>/dev/null)
    if [ -n "$result" ]; then
        echo "✅ $table exists"
    else
        echo "❌ $table missing"
    fi
done

echo ""
echo "========================================="
echo "Localhost DB sync complete!"
echo "========================================="
