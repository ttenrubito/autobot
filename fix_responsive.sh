#!/bin/bash
# Quick fix script for responsive design issues

echo "🔧 Fixing responsive design across all HTML files..."

# Add viewport meta tag if missing
for file in /opt/lampp/htdocs/autobot/public/*.html /opt/lampp/htdocs/autobot/admin/*.html; do
    if [ -f "$file" ]; then
        if ! grep -q "viewport" "$file"; then
            # Add viewport after charset
            sed -i 's/<meta charset="UTF-8">/<meta charset="UTF-8">\n    <meta name="viewport" content="width=device-width, initial-scale=1.0">/' "$file"
            echo "✓ Added viewport to $(basename $file)"
        fi
    fi
done

echo "✅ Done! All files updated."
