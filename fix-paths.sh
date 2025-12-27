#!/bin/bash
# Fix paths for Cloud Run deployment
# Can run on both local and Cloud Shell

echo "🔧 Fixing paths for Cloud Run..."

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

echo "📂 Working directory: $(pwd)"

# Fix paths in public/*.html and public/*.php
echo "1️⃣ Fixing public/*.html and public/*.php..."
find public -maxdepth 1 -type f \( -name "*.html" -o -name "*.php" \) -exec sed -i 's|/autobot/assets/|../assets/|g' {} \;
find public -maxdepth 1 -type f \( -name "*.html" -o -name "*.php" \) -exec sed -i 's|/autobot/public/images/|images/|g' {} \;
find public -maxdepth 1 -type f \( -name "*.html" -o -name "*.php" \) -exec sed -i "s|'\(/autobot/assets/[^']*\)'|'../assets/\1'|g" {} \; 2>/dev/null || true

# Fix API calls
find public -maxdepth 1 -type f \( -name "*.html" -o -name "*.php" \) -exec sed -i 's|/autobot/api/|/api/|g' {} \;

# Fix paths in public/admin/*.html and public/admin/*.php
echo "2️⃣ Fixing public/admin/*.html and public/admin/*.php..."
find public/admin -type f \( -name "*.html" -o -name "*.php" \) -exec sed -i 's|/autobot/assets/|../../assets/|g' {} \;
find public/admin -type f \( -name "*.html" -o -name "*.php" \) -exec sed -i 's|/autobot/public/images/|../images/|g' {} \;
find public/admin -type f \( -name "*.html" -o -name "*.php" \) -exec sed -i 's|/autobot/api/|/api/|g' {} \;

# Fix PHP includes with single-quoted strings containing /autobot/assets/
echo "3️⃣ Fixing PHP array paths..."
find public -type f -name "*.php" -exec sed -i "s|'\(/autobot/assets/[^']*\)'|'../../assets/\$(echo {} | sed 's|[^/]||g' | wc -c)'|g" {} \; 2>/dev/null || true

# Simple replacements for common patterns
find public -type f -name "*.php" -exec sed -i "s|'/autobot/assets/js/|'../assets/js/|g" {} \;
find public -type f -name "*.php" -exec sed -i "s|'/autobot/assets/css/|'../assets/css/|g" {} \;
find public/admin -type f -name "*.php" -exec sed -i "s|'/autobot/assets/js/|'../../assets/js/|g" {} \;
find public/admin -type f -name "*.php" -exec sed -i "s|'/autobot/assets/css/|'../../assets/css/|g" {} \;

echo "✅ Path fixing completed!"

# Show summary
echo ""
echo "📊 Summary of changes:"
echo "   - Fixed CSS/JS links in public/ to use ../assets/"
echo "   - Fixed CSS/JS links in public/admin/ to use ../../assets/"
echo "   - Fixed image paths to use relative paths"
echo "   - Fixed API calls to use /api/ instead of /autobot/api/"
echo ""
echo "🚀 Ready to deploy to Cloud Run!"
