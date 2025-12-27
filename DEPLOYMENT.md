# AI Automation Portal - Deployment Guide

## Prerequisites

- PHP 7.4+ with extensions: `pdo_mysql`, `curl`, `json`, `mbstring`
- MySQL 5.7+ or MariaDB 10.3+
- Web server (Apache/Nginx)
- Composer (for dependency management)
- Node.js & npm (for frontend build tools, optional)

## Local Development Setup

### 1. Clone & Configure

```bash
# Navigate to your web root
cd /opt/lampp/htdocs

# The project is already in /opt/lampp/htdocs/autobot

# Copy environment file
cd autobot
cp .env.example .env
```

### 2. Configure Environment

Edit `.env` file with your settings:

```bash
# Required: Database
DB_HOST=localhost
DB_NAME=autobot
DB_USER=root
DB_PASS=your_password

# Required: JWT Secret (generate random string)
JWT_SECRET_KEY=$(openssl rand -base64 32)

# Required: Google Cloud API Keys
GOOGLE_VISION_API_KEY=your_key_here
GOOGLE_LANGUAGE_API_KEY=your_key_here

# Optional: Omise Payment (for production)
OMISE_PUBLIC_KEY=pkey_live_xxxxx
OMISE_SECRET_KEY=skey_live_xxxxx
```

### 3. Database Setup

```bash
# Create database and tables
mysql -u root -p < database/schema.sql

# Add admin & API gateway tables
mysql -u root autobot < database/admin_api_gateway_schema.sql

# Add performance indexes
mysql -u root autobot < database/performance_indexes.sql

# Optional: Add sample data
mysql -u root autobot < database/sample_usage_data.sql
```

### 4. Set Permissions

```bash
# Create logs directory
mkdir -p logs
chmod 755 logs

# Set permissions
chmod 644 config.php config-cloud.php
chmod 600 google-service-account.json  # if using service account
```

### 5. Test

```bash
# Start XAMPP
sudo /opt/lampp/lampp start

# Test health endpoint
curl http://localhost/autobot/api/health.php

# Expected output:
# {
#   "status": "healthy",
#   "services": {
#     "database": "connected",
#     ...
#   }
# }
```

## Production Deployment

### Option 1: Traditional Server (VPS/Dedicated)

#### Apache Configuration

```apache
<VirtualHost *:80>
    ServerName api.yourdomain.com
    DocumentRoot /var/www/autobot/public
    
    <Directory /var/www/autobot/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    
    # Logging
    ErrorLog ${APACHE_LOG_DIR}/autobot-error.log
    CustomLog ${APACHE_LOG_DIR}/autobot-access.log combined
</VirtualHost>
```

#### Nginx Configuration

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;
    root /var/www/autobot/public;
    index index.php index.html;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Security
    location ~ /\.(?!well-known).* {
        deny all;
    }
    
    # Disable access to sensitive files
    location ~ /(config|includes|database) {
        deny all;
    }
}
```

### Option 2: Docker Deployment

```bash
# Build image
docker build -t ai-automation:latest .

# Run container
docker run -d \
  --name ai-automation \
  -p 8080:80 \
  -e DB_HOST=your-db-host \
  -e DB_NAME=autobot \
  -e DB_USER=user \
  -e DB_PASS=password \
  -e GOOGLE_VISION_API_KEY=xxx \
  -e GOOGLE_LANGUAGE_API_KEY=xxx \
  ai-automation:latest
```

### Option 3: Google Cloud Run

```bash
# Build and push
gcloud builds submit --tag gcr.io/PROJECT_ID/ai-automation

# Deploy
gcloud run deploy ai-automation \
  --image gcr.io/PROJECT_ID/ai-automation \
  --platform managed \
  --region asia-southeast1 \
  --allow-unauthenticated \
  --set-env-vars="DB_SOCKET=/cloudsql/PROJECT:REGION:INSTANCE" \
  --add-cloudsql-instances=PROJECT:REGION:INSTANCE
```

## Post-Deployment Checklist

- [ ] SSL/TLS certificate installed (Let's Encrypt recommended)
- [ ] Environment variables set correctly
- [ ] Database connection working
- [ ] Google API keys configured and tested
- [ ] Health check endpoint returns `200 OK`
- [ ] Logs directory writable
- [ ] Backups configured (database + files)
- [ ] Monitoring set up (uptime, errors, performance)
- [ ] Rate limiting tested
- [ ] Admin panel accessible
- [ ] Customer portal accessible
- [ ] API gateway tested with sample requests

## Monitoring

### Health Checks

```bash
# Application health
curl https://api.yourdomain.com/autobot/api/health.php

# Database connectivity
curl -I https://api.yourdomain.com/autobot/api/auth/login.php
```

### Logs

```bash
# Application logs (JSON format)
tail -f /path/to/autobot/logs/app-$(date +%Y-%m-%d).log

# Apache/Nginx error logs
tail -f /var/log/apache2/autobot-error.log
tail -f /var/log/nginx/error.log
```

### Performance Monitoring

- Set up APM tool (New Relic, Datadog, or similar)
- Configure error tracking (Sentry, Bugsnag)
- Database query monitoring
- API response time tracking

## Security Hardening

### 1. File Permissions

```bash
# Restrict config files
chmod 640 config*.php
chown www-data:www-data config*.php

# Protect sensitive directories
chmod 750 includes/ database/ logs/
```

### 2. Disable PHP Functions

In `php.ini`:
```ini
disable_functions=exec,passthru,shell_exec,system,proc_open,popen
```

### 3. Rate Limiting

Configure at web server level or use PHP rate limiter.

### 4. Firewall Rules

```bash
# Allow only necessary ports
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 22/tcp  # SSH only from specific IPs
ufw enable
```

## Backup Strategy

### Database Backup

```bash
# Daily backup script
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u root -p autobot > /backups/autobot_$DATE.sql
gzip /backups/autobot_$DATE.sql

# Keep only last 30 days
find /backups -name "autobot_*.sql.gz" -mtime +30 -delete
```

### Application Backup

```bash
# Backup uploads and logs
tar -czf /backups/autobot_files_$DATE.tar.gz \
  /var/www/autobot/uploads \
  /var/www/autobot/logs
```

## Troubleshooting

### Database Connection Failed

```bash
# Check MySQL is running
systemctl status mysql

# Test connection
mysql -u root -p -h localhost autobot
```

### API Gateway Returns 500

```bash
# Check Google API keys
php -r "echo getenv('GOOGLE_VISION_API_KEY');"

# Check logs
tail -f logs/app-$(date +%Y-%m-%d).log | jq
```

### High Memory Usage

```bash
# Check PHP memory limit
php -i | grep memory_limit

# Increase if needed (php.ini)
memory_limit = 256M
```

## Scaling Recommendations

### Database

- Use read replicas for heavy read workloads
- Implement connection pooling
- Regular `OPTIMIZE TABLE` maintenance

### Application

- Use Redis/Memcached for caching
- Load balancer for multiple app servers
- CDN for static assets

### API Gateway

- Implement response caching (Redis)
- Queue system for async processing
- Circuit breaker for Google API failures

## Support

- Documentation: `/docs` directory
- API Docs: `https://yourdomain.com/autobot/public/api-docs.html`
- Health Status: `https://yourdomain.com/autobot/api/health.php`

---

**Default Admin Credentials:**
- Username: `admin`
- Password: `admin123` (⚠️ CHANGE IMMEDIATELY IN PRODUCTION!)

**Default Customer Credentials:**
- Email: `demo@aiautomation.com`
- Password: `demo1234`
