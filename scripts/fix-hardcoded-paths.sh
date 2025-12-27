#!/bin/bash
#
# Fix Hardcoded Image/Asset Paths
# ป้องกันปัญหา 404 จากการใช้ hardcode path แทน PATH helper
#
# Usage: ./scripts/fix-hardcoded-paths.sh
#

set -e

echo "🔍 Scanning for hardcoded image/asset paths..."

# Define files to check (exclude backup folders)
FILES=$(find public includes -type f \( -name "*.html" -o -name "*.php" -o -name "*.js" \) \
    ! -path "*/backup/*" \
    ! -path "*/node_modules/*" \
    ! -path "*/vendor/*")

FOUND_ISSUES=0

# Check for hardcoded /images/ or /assets/ paths
for file in $FILES; do
    if grep -q 'src="/images/' "$file" || \
       grep -q 'href="/images/' "$file" || \
       grep -q 'src="/assets/' "$file" || \
       grep -q 'href="/assets/' "$file" || \
       grep -q "src='/images/" "$file" || \
       grep -q "href='/images/" "$file" || \
       grep -q "src='/assets/" "$file" || \
       grep -q "href='/assets/" "$file"; then
        
        echo "⚠️  Found hardcoded path in: $file"
        FOUND_ISSUES=$((FOUND_ISSUES + 1))
        
        # Show the problematic lines
        grep -n -E '(src|href)=["\'"'"']/(images|assets)/' "$file" || true
    fi
done

echo ""
if [ $FOUND_ISSUES -eq 0 ]; then
    echo "✅ No hardcoded paths found! All files use PATH helper correctly."
else
    echo "❌ Found $FOUND_ISSUES file(s) with hardcoded paths."
    echo ""
    echo "📝 How to fix:"
    echo "   Images:  Use PATH.image('logo1.png')  instead of '/images/logo1.png'"
    echo "   Assets:  Use PATH.asset('css/style.css') instead of '/assets/css/style.css'"
    echo ""
    echo "🔧 Common fixes:"
    echo "   ❌ <img src=\"/images/logo1.png\">"
    echo "   ✅ <img src=\"\" id=\"logoImage\"> + logoImage.src = PATH.image('logo1.png')"
    echo ""
    echo "   ❌ <link rel=\"icon\" href=\"/images/logo1.png\">"
    echo "   ✅ <link rel=\"icon\" id=\"favicon\"> + favicon.href = PATH.image('logo1.png')"
    exit 1
fi
