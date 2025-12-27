# AI Automation Portal - Complete

## 🎉 System Overview

AI Automation Portal is a comprehensive **API Gateway and Management System** for Google Cloud AI services, designed for integration with n8n and other automation platforms.

##  Core Features

### ✅ Customer Portal
- **Dashboard** - Real-time statistics, usage trends with Chart.js
- **Services** - Manage AI services (Facebook/LINE bots, Google Vision/NL)
- **Usage Analytics** - Detailed usage statistics with interactive charts
- **API Documentation** - Complete API reference with code examples
- **Payment Management** - Omise integration for seamless payments
- **Billing & Invoices** - Transaction history and invoices
- **User Profile** - Account settings and API key management

### ✅ Admin Panel
- **Dashboard** - System-wide statistics and metrics
- **API Service Management** - Toggle services on/off globally
- **Subscription Plans** - Create and manage pricing plans
- **Customer Management** - View and manage customer accounts
- **API Access Control** - Grant/revoke API access per customer

### ✅ API Gateway (for n8n Integration)
- **Google Vision API** - Labels, Text (OCR), Faces, Objects detection
- **Google Natural Language API** - Sentiment, Entities, Syntax analysis
- **Authentication** - Secure API key-based auth
- **Rate Limiting** - Per-customer and global rate limits
- **Usage Tracking** - Automatic billing and cost calculation
- **Logging** - Structured JSON logs with request ID tracking

## 🏗️ Technology Stack

- **Backend:** PHP 7.4+ (Native, no frameworks)
- **Frontend:** HTML5, CSS3, Vanilla JavaScript
- **Database:** MySQL 5.7+ / MariaDB 10.3+
- **Payment:** Omise Payment Gateway
- **Charts:** Chart.js
- **External APIs:** Google Cloud Vision & Natural Language APIs

## 📁 Project Structure

```
autobot/
├── api/                    # Backend API endpoints
│   ├── admin/             # Admin APIs
│   ├── auth/              # Authentication
│   ├── billing/           # Billing & invoices
│   ├── dashboard/         # Dashboard stats
│   ├── gateway/           # API Gateway for Google Cloud
│   ├── payment/           # Payment processing
│   ├── services/          # Service management
│   └── user/              # User profile & API keys
├── includes/              # PHP classes & helpers
│   ├── AdminAuth.php      # Admin authentication
│   ├── Auth.php           # Customer authentication
│   ├── CORS.php           # CORS handling
│   ├── Database.php       # Database wrapper
│   ├── JWT.php            # JWT token handler
│   ├── Logger.php         # Structured logging
│   ├── Response.php       # JSON response helper
│   └── Validator.php      # Input validation
├── public/                # Customer portal (frontend)
│   ├── dashboard.html
│   ├── services.html
│   ├── usage.html        # ✨ Newly created
│   ├── api-docs.html     # ✨ Newly created
│   ├── payment.html
│   ├── billing.html
│   └── profile.html
├── admin/                 # Admin panel (frontend)
│   ├── login.html        # ✨ Newly created
│   ├── dashboard.html    # ✨ Newly created
│   └── index.html
├── assets/
│   ├── css/style.css
│   └── js/
│       ├── auth.js
│       ├── dashboard.js
│       ├── payment.js
│       └── services.js
├── database/
│   ├── schema.sql
│   ├── admin_api_gateway_schema.sql  # ✨ New
│   ├── sample_usage_data.sql        # ✨ New
│   └── performance_indexes.sql      # ✨ New
├── logs/                  # Application logs (auto-generated)
├── config.php             # Main configuration
├── config-cloud.php       # Cloud/production config
├── .env.example          # Environment template
├── DEPLOYMENT.md         # Deployment guide
└── README.md             # This file
```

## 🚀 Quick Start

### Prerequisites
- XAMPP/LAMPP or similar (PHP 7.4+, MySQL 5.7+)
- Google Cloud Account (for Vision & Language APIs)
- Omise Account (for payment processing, optional)

### Installation

1. **Clone/Download** project to web root
```bash
cd /opt/lampp/htdocs
# Project is in ./autobot
```

2. **Configure Environment**
```bash
cd autobot
cp .env.example .env
# Edit .env with your settings
```

3. **Setup Database**
```bash
# Create database and import schema
mysql  -u root -p < database/schema.sql
mysql -u root autobot < database/admin_api_gateway_schema.sql
mysql -u root autobot < database/performance_indexes.sql

# Optional: Add sample data
mysql -u root autobot < database/sample_usage_data.sql
```

4. **Start Server**
```bash
sudo /opt/lampp/lampp start
```

5. **Access Application**
- Customer Portal: `http://localhost/autobot/public/`
- Admin Panel: `http://localhost/autobot/admin/login.html`
- API Health: `http://localhost/autobot/api/health.php`

### Default Credentials

**Admin:**
- Username: `admin`
- Password: `admin123`

**Customer (Demo):**
- Email: `demo@aiautomation.com`
- Password: `demo1234`

⚠️ **Change these in production!**

## 📚 API Gateway Usage (n8n Integration)

### Authentication
All API requests require an API key in the header:
```
X-API-Key: your_api_key_here
```

Get your API key from: Customer Portal → API Docs → Your API Key

### Google Vision API

**Label Detection:**
```bash
POST /autobot/api/gateway/vision/labels
Content-Type: application/json
X-API-Key: your_key

{
  "image": {
    "content": "base64_encoded_image_string"
  }
}
```

**Text Detection (OCR):**
```bash
POST /autobot/api/gateway/vision/text
```

**Face Detection:**
```bash
POST /autobot/api/gateway/vision/faces
```

**Object Detection:**
```bash
POST /autobot/api/gateway/vision/objects
```

### Google Natural Language API

**Sentiment Analysis:**
```bash
POST /autobot/api/gateway/language/sentiment
Content-Type: application/json
X-API-Key: your_key

{
  "text": "I love this product! It's amazing!"
}
```

**Entity Extraction:**
```bash
POST /autobot/api/gateway/language/entities
```

**Syntax Analysis:**
```bash
POST /autobot/api/gateway/language/syntax
```

### Rate Limits
- Configured per customer via admin panel
- Default: 1000 requests/day per service
- HTTP 429 returned when exceeded

### Error Codes
| Code | Meaning |
|------|---------|
| 401 | Invalid/missing API key |
| 403 | No access to service |
| 413 | Request too large |
| 429 | Rate limit exceeded |
| 503 | Service unavailable |

## 🛠️ Development

### File Permissions
```bash
chmod 755 logs/
chmod 644 config*.php
```

### Logging
Logs are written to `logs/app-YYYY-MM-DD.log` in JSON format:
```json
{
  "timestamp": "2025-12-10 10:30:00",
  "level": "INFO",
  "request_id": "req_123abc",
  "message": "API Gateway - Request completed",
  "context": {...}
}
```

### Adding New API Service

1. Update `database/admin_api_gateway_schema.sql`:
```sql
INSERT INTO api_service_config (...) VALUES (...);
```

2. Grant access to customers:
```sql
INSERT INTO customer_api_access (user_id, service_code, ...) VALUES (...);
```

3. Admin can toggle service on/off via dashboard

## 📊 Monitoring

### Health Check
```bash
curl http://localhost/autobot/api/health.php
```

Returns:
```json
{
  "status": "healthy",
  "services": {
    "database": "connected",
    "disk": {"status": "ok", "used_percent": 45.2},
    "google_vision_api": "configured",
    "google_language_api": "configured"
  }
}
```

### Logs
```bash
# View today's logs
tail -f logs/app-$(date +%Y-%m-%d).log | jq

# Search for errors
grep '"level":"ERROR"' logs/*.log | jq
```

## 🔒 Security Features

- ✅ JWT token authentication
- ✅ API key-based gateway access
- ✅ CORS configuration
- ✅ Rate limiting
- ✅ SQL injection protection (prepared statements)
- ✅ Request validation (size, format)
- ✅ Structured logging with request IDs
- ✅ Environment-based configuration

## 📖 Documentation

- **[DEPLOYMENT.md](DEPLOYMENT.md)** - Complete deployment guide
- **[Professional Analysis](brain/professional_analysis.md)** - System analysis & recommendations
- **[Implementation Plan](brain/implementation_plan.md)** - Technical architecture
- **[Walkthrough](brain/walkthrough.md)** - Development summary

## 🎯 Production Readiness

### Critical (Must Do Before Production)
- [ ] Change default passwords
- [ ] Set strong JWT_SECRET_KEY
- [ ] Configure Google API keys
- [ ] Set up SSL/TLS certificate
- [ ] Configure ALLOWED_ORIGINS properly
- [ ] Set APP_ENV=production
- [ ] Configure backups (database + files)

### Recommended
- [ ] Set up monitoring (New Relic, Datadog)
- [ ] Configure error tracking (Sentry)
- [ ] Set up log aggregation (ELK Stack)
- [ ] Implement Redis caching
- [ ] Configure CDN for static assets
- [ ] Database read replicas

See [Professional Analysis](brain/professional_analysis.md) for detailed recommendations.

## 🤝 Support

- **Documentation Website:** `/public/api-docs.html`
- **Health Status:** `/api/health.php`
- **Admin Panel:** `/admin/login.html`

## 📝 License

Proprietary - All rights reserved

## 🎉 What's New

### Recent Additions (December 2024)
- ✅ **Usage Statistics Page** - Interactive charts showing API usage
- ✅ **API Documentation Page** - Complete API reference with examples
- ✅ **Admin Panel** - Full management dashboard for administrators
- ✅ **API Gateway** - Complete integration for Google Cloud APIs
- ✅ **API Key Management** - User-friendly API key generation
- ✅ **Professional Security** - CORS, logging, validation improvements
- ✅ **Health Check Endpoint** - System monitoring
- ✅ **Performance Indexes** - Database optimization
- ✅ **Deployment Guide** - Complete production deployment docs

---

**Built with ❤️ for AI Automation**
