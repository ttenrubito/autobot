#!/bin/bash

# Script to fix asset paths in all HTML files in /public directory
# Adds /autobot prefix for local XAMPP compatibility

echo "Fixing asset paths in HTML files..."

# Define the directory
PUBLIC_DIR="/opt/lampp/htdocs/autobot/public"

# Find all HTML files and replace asset paths
find "$PUBLIC_DIR" -type f -name "*.html" | while read -r file; do
    echo "Processing: $file"
    
    # Replace CSS href paths
    sed -i 's|href="/autobot/assets/css/|href="/autobot/autobot/assets/css/|g' "$file"
    
    # Replace JS src paths
    sed -i 's|src="/autobot/assets/js/|src="/autobot/autobot/assets/js/|g' "$file"
    
    # Replace images/icons paths (if any)
    sed -i 's|src="/autobot/assets/images/|src="/autobot/autobot/assets/images/|g' "$file"
    sed -i 's|href="/autobot/assets/images/|href="/autobot/autobot/assets/images/|g' "$file"
done

echo "Done! All HTML files have been updated."
echo ""
echo "Note: For Cloud Run deployment, run the reverse script to remove /autobot prefix"
