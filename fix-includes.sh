#!/bin/bash
# Fix all PHP includes to use __DIR__ instead of relative paths
# Works on both localhost and Cloud Run

echo "🔧 Fixing PHP includes to use __DIR__..."

cd /opt/lampp/htdocs/autobot

# Fix ../../includes/ patterns
echo "1️⃣ Fixing ../../includes/ → __DIR__ . '/../../includes/"
find api -type f -name "*.php" -exec sed -i "s|require_once '../../includes/|require_once __DIR__ . '/../../includes/|g" {} \;
find api -type f -name "*.php" -exec sed -i "s|include '../../includes/|include __DIR__ . '/../../includes/|g" {} \;

# Fix ../../../includes/ patterns
echo "2️⃣ Fixing ../../../includes/ → __DIR__ . '/../../../includes/"
find api -type f -name "*.php" -exec sed -i "s|require_once '../../../includes/|require_once __DIR__ . '/../../../includes/|g" {} \;
find api -type f -name "*.php" -exec sed -i "s|include '../../../includes/|include __DIR__ . '/../../../includes/|g" {} \;

# Fix ../../config patterns
echo "3️⃣ Fixing ../../config → __DIR__ . '/../../config"
find api -type f -name "*.php" -exec sed -i "s|require_once '../../config|require_once __DIR__ . '/../../config|g" {} \;

# Fix ../config patterns  
find api -type f -name "*.php" -exec sed -i "s|require_once '../config|require_once __DIR__ . '/../config|g" {} \;

echo "✅ All PHP includes fixed!"
echo ""
echo "📊 Summary:"
echo "   - All require_once using relative paths now use __DIR__"
echo "   - Works on both localhost and Cloud Run"
echo ""
