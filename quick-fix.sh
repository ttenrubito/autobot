#!/bin/bash
# Quick fix script - ให้ API ทำงานได้ทันที โดยไม่ต้องแก้ httpd.conf

echo "=== Quick Fix for API Access ==="
echo ""

# Backup httpd.conf
echo "1. Creating backup..."
sudo cp /opt/lampp/etc/httpd.conf /opt/lampp/etc/httpd.conf.backup.$(date +%Y%m%d_%H%M%S)

# Add Directory directives for autobot
echo "2. Adding Directory config for /autobot and /autobot/api..."
sudo sed -i '354i\<Directory "/opt/lampp/htdocs/autobot">\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n\n<Directory "/opt/lampp/htdocs/autobot/api">\n    Options -Indexes +FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' /opt/lampp/etc/httpd.conf

# Restart Apache
echo "3. Restarting Apache..."
sudo /opt/lampp/lampp restartapache

echo ""
echo "4. Testing API..."
sleep 2
curl -s -X POST http://localhost/autobot/api/auth/login -H "Content-Type: application/json" -d '{}' | head -20

echo ""
echo "=== Done! ==="
echo "Now try login at: http://localhost/autobot/login.html"
